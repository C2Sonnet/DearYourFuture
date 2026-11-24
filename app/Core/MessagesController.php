<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** User-facing inbox. No GET handler mutates state. */
final class MessagesController {
    public static function isRoute(string $path):bool {
        return in_array($path,['/messages','/messages/sent','/messages/sent/trash','/messages/trash','/messages/new','/messages/send','/messages/settings','/messages/recipients','/messages/block-candidates'],true)
            || preg_match('~\A/messages/sent/[1-9][0-9]{0,17}(?:/(?:trash|restore|purge))?\z~D',$path)===1
            || preg_match('~\A/messages/[1-9][0-9]{0,17}(?:/(?:read|unread|trash|restore|purge|star|unstar|report))?\z~D',$path)===1
            || preg_match('~\A/users/[A-Za-z0-9_-]{3,40}\z~D',$path)===1;
    }
    public static function handle(string $path,string $method):void {
        $user=Identity::current();
        if($user===null){header('Location: /account/login?next='.rawurlencode(Session::safeReturn($path)),true,302);return;}
        $db=Database::connected();$uid=(int)$user['id'];
        if(preg_match('~\A/users/([A-Za-z0-9_-]{3,40})\z~D',$path,$m)){
            if($method!=='GET'){self::error('許可されない操作',405);return;}
            self::profile($db,$uid,$m[1]);return;
        }
        if($path==='/messages/recipients'){
            if($method!=='GET'){header('Allow: GET');self::error('この検索はGET専用です',405);return;}
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
            try{$q=MessageInput::query($_GET['q']??'',180);}
            catch(\InvalidArgumentException){http_response_code(422);echo json_encode(['users'=>[]],JSON_THROW_ON_ERROR);return;}
            if(!Throttle::allow('message-recipient-search:'.$uid,60,60)){
                http_response_code(429);echo json_encode(['users'=>[]],JSON_THROW_ON_ERROR);return;
            }
            try{$users=MessageRepository::searchRecipients($db,$uid,$q);echo json_encode(['users'=>$users],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
            catch(\InvalidArgumentException){http_response_code(422);echo json_encode(['users'=>[]],JSON_THROW_ON_ERROR);}
            return;
        }
        if($path==='/messages/block-candidates'){
            if($method!=='GET'){header('Allow: GET');self::error('この検索はGET専用です',405);return;}
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
            if(!MessageRepository::mayBlock($db,$uid)){
                http_response_code(403);echo json_encode(['users'=>[]],JSON_THROW_ON_ERROR);return;
            }
            try{$q=MessageInput::query($_GET['q']??'',180);}
            catch(\InvalidArgumentException){http_response_code(422);echo json_encode(['users'=>[]],JSON_THROW_ON_ERROR);return;}
            if(!Throttle::allow('message-block-search:'.$uid,45,60)){
                http_response_code(429);echo json_encode(['users'=>[]],JSON_THROW_ON_ERROR);return;
            }
            try{$users=MessageRepository::searchBlockCandidates($db,$uid,$q);echo json_encode(['users'=>$users],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
            catch(\InvalidArgumentException){http_response_code(422);echo json_encode(['users'=>[]],JSON_THROW_ON_ERROR);}
            return;
        }
        if($path==='/messages/send'){
            if($method!=='POST'){self::error('POSTが必要です',405);return;}
            if(!(is_string($_POST['csrf']??null)&&Session::validCsrf($_POST['csrf']))){self::error('安全な操作が必要です',403);return;}
            if(!Throttle::allow('direct-msg:'.$uid,15,3600) || !Throttle::allow('direct-msg-ip:'.(string)($_SERVER['REMOTE_ADDR']??''),50,3600)) {self::error('しばらくしてからお試しください',429);return;}
            try{
                $reply=trim(MessageInput::text($_POST['reply_to']??'',20));
                $sender=MessageInput::text($_POST['from_identity']??'self',10);
                if(!in_array($sender,['self','mina'],true)){self::error('送信元を確認してください',422);return;}
                $recipient=trim(MessageInput::text($_POST['to']??'',42));
                if(str_starts_with($recipient,'@'))$recipient=substr($recipient,1);
                // A reply to a developer can omit the identity. Resolve it on the server
                // using only a message the current account actually received.
                if($recipient==='' && $reply!=='' && $sender==='self'){
                    $recipient=MessageRepository::developerReplyHandle($db,$uid,MessageData::id($reply))??'';
                }
                if($sender==='mina'){
                    if(($user['role']??'')!=='DEVELOPER'||$reply!==''){self::error('開発者権限が必要です',403);return;}
                    MessageRepository::sendAsMina($db,$uid,$recipient,MessageInput::text($_POST['subject']??'',340),MessageInput::text($_POST['body']??'',4200));
                }else{
                    MessageRepository::send($db,$uid,$recipient,MessageInput::text($_POST['subject']??'',340),MessageInput::text($_POST['body']??'',4200),$reply!==''?MessageData::id($reply):null);
                }
                header('Location: /messages/sent?sent=1',true,303);
            }catch(\InvalidArgumentException $e){self::error($e->getMessage(),422);}
            catch(\DomainException $e){self::error('アカウント状態が変更されました。再ログインしてください',409);}
            return;
        }
        if($path==='/messages/settings'){
            if($method==='POST'){
                if(!(is_string($_POST['csrf']??null)&&Session::validCsrf($_POST['csrf']))){self::error('安全な操作が必要です',403);return;}
                try{
                    if(array_key_exists('block_handle',$_POST)){
                        $action=MessageInput::text($_POST['block_action']??'',10);
                        if(!in_array($action,['block','unblock'],true)){self::error('操作を確認してください',422);return;}
                        if($action==='block'&&!MessageRepository::mayBlock($db,$uid)){self::error('ブロック機能は開発者の許可が必要です',403);return;}
                        $blockHandle=trim(MessageInput::text($_POST['block_handle'],41));
                        if(str_starts_with($blockHandle,'@'))$blockHandle=substr($blockHandle,1);
                        MessageRepository::block($db,$uid,$blockHandle,$action==='block');
                    }else MessageRepository::saveSettings($db,$uid,MessageInput::checked($_POST,'allow_direct'),MessageInput::checked($_POST,'public_profile'),MessageInput::checked($_POST,'notify_email'));
                    header('Location: /messages/settings?updated=1',true,303);
                }catch(\InvalidArgumentException $e){self::error($e->getMessage(),422);}
                catch(\DomainException $e){self::error('アカウント状態が変更されました。再ログインしてください',409);}
                return;
            }
            if($method!=='GET'){self::error('許可されない操作',405);return;}
            self::settings($db,$uid);return;
        }
        if($path==='/messages/new'){
            if($method!=='GET'){self::error('許可されない操作',405);return;}
            try{$to=MessageInput::text($_GET['to']??'',40);$reply=MessageInput::text($_GET['reply']??'',18);}
            catch(\InvalidArgumentException $e){self::error($e->getMessage(),422);return;}
            if($to!==''&&!preg_match('/\A[A-Za-z0-9_-]{3,40}\z/D',$to)){self::error('宛先IDが正しくありません',422);return;}
            if($reply!==''&&!preg_match('/\A[1-9][0-9]{0,17}\z/D',$reply)){self::error('返信先IDが正しくありません',422);return;}
            self::compose($to,$reply);return;
        }
        if(in_array($path,['/messages','/messages/sent','/messages/sent/trash','/messages/trash'],true)){
            if($method!=='GET'){self::error('許可されない操作',405);return;}
            $folder=match($path){'/messages/sent'=>'sent','/messages/sent/trash'=>'sent_trash','/messages/trash'=>'trash',default=>'inbox'};
            self::index($db,$uid,$folder);return;
        }
        if(preg_match('~\A/messages/sent/([1-9][0-9]{0,17})(?:/(trash|restore|purge))?\z~D',$path,$m)){
            $id=MessageData::id($m[1]);
            if(isset($m[2])){
                if($method!=='POST'){self::error('POSTが必要です',405);return;}
                if(!(is_string($_POST['csrf']??null)&&Session::validCsrf($_POST['csrf']))){self::error('安全な操作が必要です',403);return;}
                if($m[2]==='purge'&&(string)($_POST['confirmed']??'')!=='yes'){self::error('完全削除の確認が必要です',422);return;}
                try{MessageRepository::applySender($db,$uid,$id,$m[2]);header('Location: '.($m[2]==='trash'?'/messages/sent':'/messages/sent/trash'),true,303);}
                catch(\InvalidArgumentException $e){self::error($e->getMessage(),422);}
                catch(\RuntimeException $e){self::error('送信したメッセージが見つかりません',404);}
                return;
            }
            if($method!=='GET'){self::error('許可されない操作',405);return;}
            $sent=MessageRepository::sentDetail($db,$uid,$id);
            if(!$sent){self::error('送信したメッセージが見つかりません',404);return;}
            self::sentDetail($db,$uid,$sent);return;
        }
        if(preg_match('~\A/messages/([1-9][0-9]{0,17})(?:/(read|unread|trash|restore|purge|star|unstar|report))?\z~D',$path,$m)){
            $id=MessageData::id($m[1]);
            if(isset($m[2])){
                if($method!=='POST'){self::error('POSTが必要です',405);return;}
                if(!(is_string($_POST['csrf']??null)&&Session::validCsrf($_POST['csrf']))){self::error('安全な操作が必要です',403);return;}
                if($m[2]==='purge' && (string)($_POST['confirmed']??'')!=='yes'){self::error('完全削除の確認が必要です',422);return;}
                try{MessageRepository::apply($db,$uid,$id,$m[2],MessageInput::text($_POST['reason']??'',3200));
                    $next=in_array($m[2],['trash','purge','restore'],true)?($m[2]==='trash'?'/messages':'/messages/trash'):'/messages/'.$id;
                    header('Location: '.$next,true,303);
                }catch(\RuntimeException $e){self::error('メッセージが見つかりません',404);}catch(\InvalidArgumentException $e){self::error($e->getMessage(),422);}
                return;
            }
            if($method!=='GET'){self::error('許可されない操作',405);return;}
            $message=MessageRepository::detail($db,$uid,$id);
            if(!$message){self::error('メッセージが見つかりません',404);return;}
            self::detail($db,$uid,$message);return;
        }
        self::error('ページがありません',404);
    }
    /** One mailbox navigation; sender and receiver trash remain logically isolated. */
    private static function nav():string {
        $main='<a href="/messages" class="mailbox-link">受信箱</a><a href="/messages/sent" class="mailbox-link">送信済み</a>';
        $end='<a href="/messages/trash" class="mailbox-tool" title="ゴミ箱" aria-label="ゴミ箱">♲</a><a href="/messages/settings" class="mailbox-tool" title="通知・公開設定" aria-label="通知・公開設定">⚙</a>';
        return '<aside class="mailbox-side"><nav aria-label="メッセージメニュー" class="mailbox-desktop"><a class="btn primary mailbox-compose" href="/messages/new">＋ 新規メッセージ</a>'.$main.'<div class="mailbox-side-bottom">'.$end.'</div></nav><details class="mailbox-mobile"><summary class="mailbox-tool" aria-label="メッセージメニューを開く">☰</summary><nav aria-label="モバイルメッセージメニュー"><a href="/messages/new">＋ 新規メッセージ</a>'.$main.'<a href="/messages/trash">ゴミ箱</a><a href="/messages/settings">通知・公開設定</a></nav></details></aside>';
    }
    private static function heading(string $title,string $intro):string {
        return '<section class="hero compact"><span class="eyebrow">PRIVATE MESSAGES</span><h1>'.Security::h($title).'</h1><p>'.Security::h($intro).'</p></section>';
    }
    private static function page(string $title,string $html):void {
        $end=strpos($html,'</section>');
        if($end===false){Kernel::layout($title,$html);return;}
        $end+=strlen('</section>');
        Kernel::layout($title,substr($html,0,$end).'<div class="mailbox-shell">'.self::nav().'<div class="mailbox-main">'.substr($html,$end).'</div></div>');
    }
    private static function personChip(?string $name,?string $handle,bool $system=false):string {
        if($system){$name='ミナヘルパー';$handle='MinaHelper';}
        $name=$name?:'未確認';$handle=$handle?:'unknown';
        return '<span class="mailbox-person"><span class="avatar-symbol" aria-hidden="true">✿</span><span>'.Security::h($name).' <small>(@'.Security::h($handle).')</small></span></span>';
    }
    private static function index(PDO $db,int $uid,string $folder):void {
        if($folder==='trash'){self::combinedTrash($db,$uid);return;}
        $page=MessageData::page($_GET['page']??'1');
        try{$term=MessageInput::query($_GET['q']??'',100);}
        catch(\InvalidArgumentException $e){self::error($e->getMessage(),422);return;}
        if(strlen($term)>100){self::error('検索語が長すぎます',422);return;}
        $rows=MessageRepository::listInbox($db,$uid,$folder,$page,$term);
        $title=match($folder){'sent'=>'送信済み','sent_trash'=>'送信済みゴミ箱','trash'=>'受信ゴミ箱',default=>'受信箱'};
        $h=self::heading($title,'メッセージは本人専用です。閲覧だけでは既読に変更しません。');
        if(isset($_GET['sent']))$h.='<p class="note" role="status">メッセージを送信しました。</p>';
        $h.='<form class="message-search" method="get" role="search"><label>検索 <input type="search" name="q" maxlength="100" value="'.Security::h($term).'"></label><button class="btn ghost">検索</button></form>';
        if($folder==='trash')$h.='<p class="note">受信したメッセージのゴミ箱です。<a href="/messages/sent/trash">送信済みのゴミ箱を確認する</a>（双方は独立しており、相手のデータを消しません）。</p>';
        if(!$rows)$h.='<p class="note">ここにはまだメッセージがありません。</p>';
        foreach($rows as $r){
            $unread=$folder==='inbox'&&$r['read_at']===null;
            $h.='<article class="panel message-row"><div><span class="eyebrow">'.Security::h(MessageData::kindLabel($r['kind'])).'</span>'.($unread?'<strong class="unread-mark">未読</strong>':'').'</div>';
            if(in_array($folder,['sent','sent_trash'],true))$h.='<h2><a href="/messages/sent/'.(int)$r['id'].'">'.Security::h($r['subject']?:'件名なし').'</a></h2>';
            else $h.='<h2><a href="/messages/'.(int)$r['id'].'">'.Security::h($r['subject']?:'件名なし').'</a></h2>';
            $h.='<p>相手：'.Security::h($r['partner_name']??'システム').' ／ '.Security::h($r['created_at']).'</p>';
            if($folder==='trash')$h.=self::postForm((int)$r['id'],'restore','復元する').self::postForm((int)$r['id'],'purge','完全削除',true);
            if($folder==='sent')$h.=self::senderPost((int)$r['id'],'trash','送信済みをゴミ箱へ');
            if($folder==='sent_trash')$h.=self::senderPost((int)$r['id'],'restore','復元').self::senderPost((int)$r['id'],'purge','送信済みを完全削除',true);
            $h.='</article>';
        }
        $url=match($folder){'sent'=>'/messages/sent','sent_trash'=>'/messages/sent/trash','trash'=>'/messages/trash',default=>'/messages'};
        $tail=$term!==''?'&q='.rawurlencode($term):'';
        $h.='<nav class="app-subnav" aria-label="ページ切替">';
        if($page>1)$h.='<a href="'.$url.'?page='.($page-1).$tail.'">前へ</a>';
        if(count($rows)===MessageData::PAGE_SIZE)$h.='<a href="'.$url.'?page='.($page+1).$tail.'">次へ</a>';
        $h.='</nav>';self::page($title,$h);
    }
    /** One trash screen, independent recipient and sender states, independently paginated. */
    private static function combinedTrash(PDO $db,int $uid):void {
        try{$term=MessageInput::query($_GET['q']??'',100);}
        catch(\InvalidArgumentException $e){self::error($e->getMessage(),422);return;}
        if(strlen($term)>100){self::error('検索語が長すぎます',422);return;}
        $receivedPage=MessageData::page($_GET['received_page']??'1');
        $sentPage=MessageData::page($_GET['sent_page']??'1');
        $received=MessageRepository::listInbox($db,$uid,'trash',$receivedPage,$term);
        $sent=MessageRepository::listInbox($db,$uid,'sent_trash',$sentPage,$term);
        $h=self::heading('ゴミ箱','受信・送信のゴミ箱を1画面にまとめています。削除操作は自分のコピーにのみ適用されます。');
        $h.='<form class="message-search" method="get" action="/messages/trash" role="search"><label>検索 <input type="search" name="q" maxlength="100" value="'.Security::h($term).'"></label><button class="btn ghost">検索</button></form>';
        $h.='<section class="mailbox-trash-group" aria-labelledby="trash-received-title"><h2 id="trash-received-title">受信したメッセージ</h2>';
        if(!$received)$h.='<p class="note">受信済みのゴミ箱は空です。</p>';
        foreach($received as $r){
            $id=(int)$r['id'];
            $h.='<article class="panel message-row"><h3><a href="/messages/'.$id.'">'.Security::h($r['subject']?:'件名なし').'</a></h3><p>受信：'.Security::h((string)$r['created_at']).'</p>'.self::postForm($id,'restore','受信箱へ戻す').self::postForm($id,'purge','自分の受信コピーを削除',true).'</article>';
        }
        $query='q='.rawurlencode($term);
        if($receivedPage>1)$h.='<a href="/messages/trash?'.$query.'&received_page='.($receivedPage-1).'&sent_page='.$sentPage.'">受信ゴミ箱・前へ</a>　';
        if(count($received)===MessageData::PAGE_SIZE)$h.='<a href="/messages/trash?'.$query.'&received_page='.($receivedPage+1).'&sent_page='.$sentPage.'">受信ゴミ箱・次へ</a>';
        $h.='</section><section class="mailbox-trash-group" aria-labelledby="trash-sent-title"><h2 id="trash-sent-title">送信したメッセージ</h2>';
        if(!$sent)$h.='<p class="note">送信済みのゴミ箱は空です。</p>';
        foreach($sent as $r){
            $id=(int)$r['id'];
            $h.='<article class="panel message-row"><h3><a href="/messages/sent/'.$id.'">'.Security::h($r['subject']?:'件名なし').'</a></h3><p>送信：'.Security::h((string)$r['created_at']).'</p>'.self::senderPost($id,'restore','送信済みへ戻す').self::senderPost($id,'purge','自分の送信コピーを削除',true).'</article>';
        }
        if($sentPage>1)$h.='<a href="/messages/trash?'.$query.'&received_page='.$receivedPage.'&sent_page='.($sentPage-1).'">送信ゴミ箱・前へ</a>　';
        if(count($sent)===MessageData::PAGE_SIZE)$h.='<a href="/messages/trash?'.$query.'&received_page='.$receivedPage.'&sent_page='.($sentPage+1).'">送信ゴミ箱・次へ</a>';
        $h.='</section>';
        self::page('ゴミ箱',$h);
    }
    private static function detail(PDO $db,int $uid,array $m):void {
        $id=(int)$m['id'];$h=self::heading($m['subject']?:'件名なし','種類：'.MessageData::kindLabel($m['kind']));
        $h.='<article class="panel mailbox-preview"><div class="mailbox-meta"><div>発信者：'.self::personChip(($m['sender_role']??null)==='DEVELOPER'?'開発者':($m['sender_name']??null),($m['sender_role']??null)==='DEVELOPER'?'support':($m['sender_handle']??null),($m['sender_user_id']??null)===null).'</div><div>受信者：'.self::personChip(($m['recipient_role']??null)==='DEVELOPER'?'開発者':($m['recipient_name']??null),($m['recipient_role']??null)==='DEVELOPER'?'support':($m['recipient_handle']??null)).'</div><div>受信日時：<time>'.Security::h((string)($m['delivered_at']??$m['created_at'])).'</time></div><div>件名：<strong>'.Security::h($m['subject']?:'件名なし').'</strong></div></div><div class="message-body">'.MessageData::bodyHtml((string)$m['body'],in_array($m['kind']??null,['system','company_seed_update'],true)).'</div></article>';
        if($m['trashed_at']!==null){$h.=self::postForm($id,'restore','受信箱に戻す');$h.=self::postForm($id,'purge','完全削除',true);}
        else{
            $h.=self::postForm($id,$m['read_at']===null?'read':'unread',$m['read_at']===null?'既読にする':'未読に戻す');
            $h.=self::postForm($id,$m['starred_at']===null?'star':'unstar',$m['starred_at']===null?'重要マーク':'重要マーク解除');
            $h.=self::postForm($id,'trash','ゴミ箱へ移動');
            if($m['kind']==='direct' && $m['sender_handle'] && $m['trashed_at']===null)$h.=self::chat($db,$uid,(int)$m['sender_user_id'],$id,(string)$m['sender_handle']);
            if($m['kind']==='direct')$h.='<form method="post" action="/messages/'.$id.'/report" class="setup-form">'.Session::csrfField().'<label>通報理由<input name="reason" maxlength="800" required></label><button class="btn ghost" type="submit">通報する</button></form>';
        }
        self::page('メッセージ',$h);
    }
    private static function sentDetail(PDO $db,int $uid,array $m):void {
        $id=(int)$m['id'];$h=self::heading('送信済み：'.(string)($m['subject']?:'件名なし'),'受信者は、送信者がゴミ箱へ移動しても引き続き受信済みのコピーを閲覧できます。');
        $h.='<article class="panel mailbox-preview"><div class="mailbox-meta"><div>発信者：'.self::personChip($m['sender_name']??null,$m['sender_handle']??null).'</div><div>受信者：'.self::personChip(($m['recipient_role']??null)==='DEVELOPER'?'開発者':($m['recipient_name']??null),($m['recipient_role']??null)==='DEVELOPER'?'support':($m['recipient_handle']??null)).'</div><div>送信日時：<time>'.Security::h((string)$m['created_at']).'</time></div><div>件名：<strong>'.Security::h($m['subject']?:'件名なし').'</strong></div></div><div class="message-body">'.MessageData::bodyHtml((string)$m['body'],in_array($m['kind']??null,['system','company_seed_update'],true)).'</div></article>';
        if($m['kind']==='direct' && $m['trashed_at']===null)$h.=self::chat($db,$uid,(int)($m['recipient_user_id']??0),$id,(string)$m['recipient_handle']);
        if($m['trashed_at']===null)$h.=self::senderPost($id,'trash','送信済みをゴミ箱へ');
        else $h.=self::senderPost($id,'restore','送信済みに戻す').self::senderPost($id,'purge','自分の送信済みコピーを完全削除',true);
        self::page('送信済みメッセージ',$h);
    }
    private static function chat(PDO $db,int $uid,int $partner,int $threadHint,string $handle):string {
        if($partner<1 || !$handle)return '';
        $conversation=MessageRepository::conversation($db,$uid,$partner);
        $partnerRole=$db->prepare('SELECT role_code FROM user_roles WHERE user_id=? LIMIT 1');
        $partnerRole->execute([$partner]);$developer=$partnerRole->fetchColumn()==='DEVELOPER';
        $h='<section class="panel message-conversation" aria-label="会話"><h2>会話</h2><div class="message-thread">';
        foreach($conversation as $entry){$mine=(int)$entry['sender_user_id']===$uid;
            $h.='<article class="message-bubble '.($mine?'mine':'theirs').'"><small>'.($mine?'自分':($developer?'開発者':'相手')).' · '.Security::h((string)$entry['created_at']).'</small><div>'.MessageData::bodyHtml((string)$entry['body']).'</div></article>';
        }
        $h.='</div>';
        $reply=MessageRepository::lastDirectFrom($db,$uid,$partner);
        if($developer && $reply===null){$h.='<p>開発者から届いたメッセージへの返信のみ可能です。</p></section>';return $h;}
        $reply??=$threadHint;
        $h.='<form class="setup-form message-chat-reply" method="post" action="/messages/send">'
            .Session::csrfField().'<input type="hidden" name="to" value="'.Security::h($developer?'':$handle).'">'
            .'<input type="hidden" name="reply_to" value="'.$reply.'">'
            .'<input type="hidden" name="from_identity" value="self">'
            .'<label>返信内容<textarea name="body" required maxlength="1000" rows="3"></textarea></label>'
            .'<button class="btn primary" type="submit">返信を送信</button></form></section>';
        return $h;
    }
    private static function senderPost(int $id,string $action,string $label,bool $confirm=false):string {
        return '<form class="message-action" method="post" action="/messages/sent/'.$id.'/'.$action.'">'.Session::csrfField().($confirm?'<label><input type="checkbox" name="confirmed" value="yes" required>相手の受信箱には影響しないことを確認</label>':'').'<button class="btn ghost">'.Security::h($label).'</button></form>';
    }
    private static function postForm(int $id,string $action,string $label,bool $confirm=false):string {
        return '<form class="message-action" method="post" action="/messages/'.$id.'/'.$action.'">'.Session::csrfField().($confirm?'<label><input type="checkbox" name="confirmed" value="yes" required>自分の受信箱から完全に削除することを確認</label>':'').'<button class="btn ghost" type="submit">'.Security::h($label).'</button></form>';
    }
    private static function compose(string $to,string $reply):void {
        $h=self::heading('メッセージを送る','一度に送れる相手は1人です。添付ファイルやHTMLは利用できません。');
        $h.='<form method="post" class="setup-form" action="/messages/send">'.Session::csrfField().'<label>宛先ユーザーID（@IDまたは表示名で検索）<input name="to" data-recipient-search data-search-status="recipient-search-status" value="'.Security::h($to).'" maxlength="40" pattern="[A-Za-z0-9_\-]{3,40}" autocomplete="off" list="message-recipient-suggestions" required><datalist id="message-recipient-suggestions"></datalist><small>表示名検索の候補から、受信者のIDを選択してください。</small><small id="recipient-search-status" role="status" aria-live="polite"></small></label>';
        $me=Identity::current();
        if(($me['role']??'')==='DEVELOPER')$h.='<label>発信者<select name="from_identity"><option value="self">自分（@'.Security::h((string)$me['handle']).'）</option><option value="mina">ミナヘルパー (@MinaHelper)</option></select></label>';
        if($reply!=='')$h.='<input type="hidden" name="reply_to" value="'.Security::h($reply).'">';
        $h.='<label>件名（任意）<input name="subject" maxlength="80"></label><label>本文（1000文字以内）<textarea name="body" required rows="8" maxlength="1000"></textarea></label><button class="btn primary">送信する</button></form>';
        self::page('新規メッセージ',$h);
    }
    private static function profile(PDO $db,int $uid,string $handle):void {
        $u=MessageRepository::profile($db,$uid,$handle);
        if(!$u){self::error('公開プロフィールがありません',404);return;}
        $h='<section class="hero compact"><span class="eyebrow">MEMBER</span><h1>'.Security::h($u['display_name']).'</h1><p>ユーザーID：'.Security::h($u['handle']).'</p></section>';
        if((int)$u['id']!==$uid && (int)$u['allow_direct']===1 && !MessageRepository::hasBlocked($db,$uid,(int)$u['id'])){
            $h.='<a class="btn primary" aria-label="'.Security::h($u['display_name']).'にメッセージを送る" href="/messages/new?to='.rawurlencode($handle).'">✉ メッセージを送る</a>';
        }
        Kernel::layout('ユーザープロフィール',$h);
    }
    private static function settings(PDO $db,int $uid):void {
        $s=MessageRepository::settings($db,$uid);
        $h=self::heading('メッセージ設定','非公開情報を他人と共有しないように設定できます。システム重要通知は停止しません。');
        $h.='<p><a class="btn ghost" href="/account/notifications">メール通知カテゴリ別の配信設定</a></p>';
        if(isset($_GET['updated']))$h.='<p role="status" class="note">設定を保存しました。</p>';
        $h.='<form class="setup-form" method="post">'.Session::csrfField();
        foreach(['allow_direct'=>'他の会員からのDMを受け取る','public_profile'=>'ほかのログインユーザーへプロフィールを公開','notify_email'=>'メール通知を希望する（配送を保証する設定ではありません）'] as $key=>$label){$h.='<label class="preference-checkbox"><input type="checkbox" name="'.$key.'" value="1"'.((int)$s[$key]===1?' checked':'').'> '.Security::h($label).'</label>';}
        $h.='<button class="btn primary" type="submit">設定を保存</button></form>';
        // Revoking permission to create blocks must never trap the user's existing blocks.
        $blocked=MessageRepository::blocked($db,$uid);
        if($blocked){
            $h.='<h2>ブロック中のユーザー</h2>';
            foreach($blocked as $handle)$h.='<form class="message-action" method="post">'.Session::csrfField().'<input type="hidden" name="block_handle" value="'.Security::h($handle).'"><input type="hidden" name="block_action" value="unblock"><button class="btn ghost">'.Security::h($handle).'のブロックを解除</button></form>';
        }
        if(MessageRepository::mayBlock($db,$uid)){
            $h.='<form method="post" class="setup-form">'.Session::csrfField().'<label>ブロックするユーザーID（@ID・表示名で候補検索）<input name="block_handle" data-recipient-search data-suggest-url="/messages/block-candidates" data-search-status="block-search-status" list="message-block-suggestions" autocomplete="off" maxlength="41" required pattern="@?[A-Za-z0-9_\-]{3,40}"><datalist id="message-block-suggestions"></datalist><small>候補は公開プロフィールのある会員に限定されます。非公開の会員も正確な@IDが分かれば指定できます。</small><small id="block-search-status" role="status" aria-live="polite"></small></label><input type="hidden" name="block_action" value="block"><button class="btn ghost">ブロック</button></form>';
        }else{$h.='<p class="note">新規ブロックは開発者の許可待ちです。以前設定したブロックは解除できます。</p>';}
        self::page('メッセージ設定',$h);
    }
    private static function error(string $message,int $status):void {http_response_code($status);Kernel::layout('メッセージを表示できません','<section class="hero compact"><h1>'.Security::h($message).'</h1><p><a href="/messages">受信箱へ戻る</a></p></section>',false);}
}
