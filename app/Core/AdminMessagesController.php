<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

final class AdminMessagesController {
    /** Sensitive moderation and broadcasts need a fresh authenticated session. */
    public static function freshAt(int $authenticatedAt,int $now):bool {
        return $authenticatedAt>0 && $authenticatedAt>=$now-900 && $authenticatedAt<=$now+60;
    }
    private static function fresh():bool {
        return self::freshAt((int)($_SESSION['authenticated_at']??0),time());
    }
    public static function reviewAction(string $action,mixed $reason,mixed $status=null):array {
        if(!in_array($action,['inspect','moderate','report'],true))throw new \InvalidArgumentException('Unsupported management action');
        $reason=MessageData::text($reason,400,true);
        if($action==='moderate' && (!is_string($status)||!in_array($status,['active','hidden'],true)))throw new \InvalidArgumentException('Unsupported moderation state');
        if($action==='report' && (!is_string($status)||!in_array($status,['reviewed','dismissed'],true)))throw new \InvalidArgumentException('Unsupported report decision');
        return ['reason'=>$reason,'status'=>$action==='inspect'?null:$status];
    }
    /** Reject stale web forms before any moderation write or audit insertion. */
    public static function moderationTransition(mixed $expected,mixed $target):array {
        if(!is_string($expected)||!in_array($expected,['active','hidden'],true)
            ||!is_string($target)||!in_array($target,['active','hidden'],true)
            ||$expected===$target)throw new \InvalidArgumentException('Invalid or unchanged moderation transition');
        return ['from'=>$expected,'to'=>$target];
    }
    /** Lock, compare-and-swap, and audit in a single transaction. Never overwrite a stale review. */
    public static function moderate(PDO $db,int $id,int $actor,array $transition,string $reason):void {
        if($id<1||$actor<1)throw new \InvalidArgumentException('Invalid message or moderator');
        $normalized=self::moderationTransition($transition['from']??null,$transition['to']??null);
        $reason=MessageData::text($reason,400,true);
        $db->beginTransaction();
        try{
            $lock=$db->prepare('SELECT moderation_state FROM messages WHERE id=? FOR UPDATE');
            $lock->execute([$id]);$current=$lock->fetchColumn();
            if($current===false||$current!==$normalized['from'])throw new \RuntimeException('Message moderation changed since page was loaded');
            $q=$db->prepare('UPDATE messages SET moderation_state=? WHERE id=? AND moderation_state=?');
            $q->execute([$normalized['to'],$id,$normalized['from']]);
            if($q->rowCount()!==1)throw new \RuntimeException('Concurrent moderation action');
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                ->execute([$actor,'message.moderation','message',(string)$id,json_encode(['from'=>$normalized['from'],'to'=>$normalized['to'],'reason'=>$reason],JSON_THROW_ON_ERROR)]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function isRoute(string $path):bool {
        return in_array($path,['/admin/messages','/admin/messages/broadcasts','/admin/messages/reports'],true)
            || preg_match('~\A/admin/messages/broadcasts/[1-9][0-9]{0,17}/state\z~D',$path)===1
            || preg_match('~\A/admin/messages/reports/[1-9][0-9]{0,17}/review\z~D',$path)===1
            || preg_match('~\A/admin/messages/[1-9][0-9]{0,17}(?:/(?:inspect|moderate))?\z~D',$path)===1;
    }
    public static function handle(string $path,string $method,array $user):void {
        if(!in_array((string)$user['role'],['ADMIN','FOUNDER','DEVELOPER'],true)){self::error(403);return;}
        $db=Database::connected();
        // Non-developers cannot inspect or moderate developer-origin messages.
        if(($user['role']??'')!=='DEVELOPER' && preg_match('~\A/admin/messages/([1-9][0-9]{0,17})(?:/(?:inspect|moderate))?\z~D',$path,$sensitive)){
            $q=$db->prepare("SELECT 1 FROM messages m LEFT JOIN user_roles ur ON ur.user_id=m.sender_user_id LEFT JOIN message_recipients mr ON mr.message_id=m.id LEFT JOIN user_roles rr ON rr.user_id=mr.recipient_user_id WHERE m.id=? AND (ur.role_code='DEVELOPER' OR rr.role_code='DEVELOPER') LIMIT 1");
            $q->execute([(int)$sensitive[1]]);
            if($q->fetchColumn()){self::error(404);return;}
        }
        if(preg_match('~\A/admin/messages/broadcasts/([1-9][0-9]{0,17})/state\z~D',$path,$m)){
            if($method!=='POST'){self::error(405);return;}
            if(!Session::validCsrf((string)($_POST['csrf']??''))||!self::fresh()){self::error(403);return;}
            if(($_POST['confirmed']??'')!=='yes'){self::error(422);return;}
            try {
                MessageBroadcast::transition($db,(int)$m[1],(int)$user['id'],
                    (string)($_POST['expected_state']??''),(string)($_POST['action']??''),
                    (string)($_POST['reason']??''));
                header('Location: /admin/messages/broadcasts?updated=1',true,303);
            }catch(\InvalidArgumentException){self::error(422);}
            catch(\Throwable){self::error(409);}
            return;
        }
        if($path==='/admin/messages/broadcasts'){
            if($method==='POST'){
                if(!Session::validCsrf((string)($_POST['csrf']??''))||!self::fresh()){self::error(403);return;}
                if((string)($_POST['confirmed']??'')!=='yes'){self::error(422);return;}
                if(!Throttle::allow('broadcasts:'.(int)$user['id'],4,3600)){self::error(429);return;}
                try{
                    MessageBroadcast::queue($db,'manual:'.bin2hex(random_bytes(16)),'system',(string)($_POST['subject']??''),(string)($_POST['body']??''),(int)$user['id']);
                    header('Location: /admin/messages/broadcasts?queued=1',true,303);
                }catch(\InvalidArgumentException $e){self::error(422);}
                return;
            }
            if($method!=='GET'){self::error(405);return;}
            self::broadcasts($db);return;
        }
        if(preg_match('~\A/admin/messages/reports/([1-9][0-9]{0,17})/review\z~D',$path,$matches)){
            if($method!=='POST'){self::error(405);return;}
            if(!Session::validCsrf((string)($_POST['csrf']??''))||!self::fresh()){self::error(403);return;}
            try{$review=self::reviewAction('report',$_POST['reason']??'',$_POST['status']??null);}
            catch(\InvalidArgumentException){self::error(422);return;}
            $status=$review['status'];$reason=$review['reason'];
            $id=MessageData::id($matches[1]);
            $db->beginTransaction();
            try{
                $q=$db->prepare("SELECT id,message_id,status FROM message_reports WHERE id=? FOR UPDATE");$q->execute([$id]);$report=$q->fetch(PDO::FETCH_ASSOC);
                if(!$report || $report['status']!=='open')throw new \RuntimeException('Report already handled');
                if(($user['role']??'')!=='DEVELOPER'){
                    $sender=$db->prepare("SELECT 1 FROM messages m LEFT JOIN user_roles ur ON ur.user_id=m.sender_user_id LEFT JOIN message_recipients mr ON mr.message_id=m.id LEFT JOIN user_roles rr ON rr.user_id=mr.recipient_user_id WHERE m.id=? AND (ur.role_code='DEVELOPER' OR rr.role_code='DEVELOPER') LIMIT 1");
                    $sender->execute([(int)$report['message_id']]);
                    if($sender->fetchColumn())throw new \DomainException('Developer data protected');
                }
                $q=$db->prepare("UPDATE message_reports SET status=?,reviewer_id=?,reviewed_at=UTC_TIMESTAMP() WHERE id=? AND status='open'");
                $q->execute([$status,(int)$user['id'],$id]);if($q->rowCount()!==1)throw new \RuntimeException('Stale report');
                $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([(int)$user['id'],'message.report.reviewed','message_report',(string)$id,json_encode(['status'=>$status,'reason'=>$reason],JSON_THROW_ON_ERROR)]);
                $db->commit();header('Location: /admin/messages/reports?updated=1',true,303);
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();self::error(409);}
            return;
        }
        if($path==='/admin/messages/reports'){
            if($method!=='GET'){self::error(405);return;}
            $page=MessageData::page($_GET['page']??'1');$offset=($page-1)*20;
            $filter=($user['role']??'')==='DEVELOPER'?'':" AND NOT EXISTS (SELECT 1 FROM user_roles dr LEFT JOIN message_recipients rr ON rr.message_id=m.id WHERE dr.role_code='DEVELOPER' AND (dr.user_id=m.sender_user_id OR dr.user_id=rr.recipient_user_id))";
            $q=$db->query("SELECT r.id,r.message_id,r.reporter_id,r.reason,r.created_at,m.subject FROM message_reports r JOIN messages m ON m.id=r.message_id WHERE r.status='open'".$filter." ORDER BY r.id LIMIT 20 OFFSET ".$offset);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $h='<section class="hero compact"><h1>メッセージ通報の審査</h1><p>本文は表示しません。必要な場合は別途理由を記録し、監査付きで確認してください。</p></section>';
            if(isset($_GET['updated']))$h.='<p class="note" role="status">審査結果を記録しました。</p>';
            foreach($rows as $row){$id=(int)$row['id'];
                $h.='<article class="panel message-row"><h2>通報 #'.$id.'：'.Security::h($row['subject']).'</h2><p>通報者ID '.(int)$row['reporter_id'].' ／ '.Security::h($row['created_at']).'</p><p>申告理由：'.Security::h($row['reason']).'</p><p><a href="/admin/messages/'.(int)$row['message_id'].'">監査付き本文確認・モデレーション</a></p><form method="post" action="/admin/messages/reports/'.$id.'/review" class="setup-form">'.Session::csrfField().'<label>対応<select name="status"><option value="reviewed">審査済み</option><option value="dismissed">問題なし・取下げ</option></select></label><label>判断理由（監査へ記録）<input name="reason" maxlength="400" required></label><button class="btn primary">審査結果を保存</button></form></article>';
            }
            if(!$rows)$h.='<p class="note">未審査の通報はありません。</p>';
            if($page>1)$h.='<a href="?page='.($page-1).'">前へ</a> ';
            if(count($rows)===20)$h.='<a href="?page='.($page+1).'">次へ</a>';
            Kernel::layout('通報審査',$h);return;
        }
        if($path==='/admin/messages'){
            if($method!=='GET'){self::error(405);return;}
            $page=MessageData::page($_GET['page']??'1');$offset=($page-1)*20;
            $filter=($user['role']??'')==='DEVELOPER'?'':" WHERE NOT EXISTS (SELECT 1 FROM user_roles dr LEFT JOIN message_recipients rr ON rr.message_id=m.id WHERE dr.role_code='DEVELOPER' AND (dr.user_id=m.sender_user_id OR dr.user_id=rr.recipient_user_id))";
            $q=$db->query('SELECT m.id,m.kind,m.subject,m.created_at,m.moderation_state,m.sender_user_id,COUNT(r.recipient_user_id) AS recipient_count FROM messages m LEFT JOIN message_recipients r ON r.message_id=m.id'.$filter.' GROUP BY m.id,m.kind,m.subject,m.created_at,m.moderation_state,m.sender_user_id ORDER BY m.id DESC LIMIT 20 OFFSET '.$offset);
            $h='<section class="hero compact"><h1>メッセージ運用管理</h1><p>私信本文は一覧に表示しません。必要時には理由と監査記録が必要です。</p></section><p><a class="btn primary" href="/admin/messages/broadcasts">一斉配信</a> <a class="btn ghost" href="/admin/messages/reports">通報を審査</a></p>';
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$h.='<article class="panel message-row"><p>'.Security::h(MessageData::kindLabel($r['kind'])).' ／ '.Security::h($r['created_at']).' ／ 宛先数 '.(int)$r['recipient_count'].'</p><h2>'.Security::h($r['subject']?:'件名なし').'</h2><p>送信者ID: '.Security::h((string)($r['sender_user_id']??'system')).' ／ 状態：'.Security::h($r['moderation_state']).'</p><a href="/admin/messages/'.(int)$r['id'].'">詳細（本文の閲覧は追加確認）</a></article>';}
            if($page>1)$h.='<a class="btn ghost" href="/admin/messages?page='.($page-1).'">前へ</a>';
            $h.='<a class="btn ghost" href="/admin/messages?page='.($page+1).'">次へ</a>';
            Kernel::layout('メッセージ運用管理',$h);return;
        }
        if(preg_match('~\A/admin/messages/([1-9][0-9]{0,17})(?:/(inspect|moderate))?\z~D',$path,$m)){
            $id=MessageData::id($m[1]);$q=$db->prepare('SELECT id,kind,subject,body,moderation_state,created_at FROM messages WHERE id=? LIMIT 1');$q->execute([$id]);$message=$q->fetch(PDO::FETCH_ASSOC);
            if(!$message){self::error(404);return;}
            if(isset($m[2])){
                if($method!=='POST'||!Session::validCsrf((string)($_POST['csrf']??''))||!self::fresh()){self::error(403);return;}
                try{$review=self::reviewAction($m[2],$_POST['reason']??'',$_POST['status']??null);}
                catch(\InvalidArgumentException){self::error(422);return;}
                $reason=$review['reason'];
                if($m[2]==='inspect'){
                    $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$user['id'],'message.private_inspection','message',(string)$id,json_encode(['reason'=>$reason],JSON_THROW_ON_ERROR)]);
                    Kernel::layout('監査付き本文確認','<section class="hero compact"><h1>本文確認</h1></section><article class="panel message-body">'.MessageData::bodyHtml((string)$message['body']).'</article>',false);return;
                }
                try {$transition=self::moderationTransition($_POST['expected_state']??null,$review['status']);}
                catch(\InvalidArgumentException){self::error(422);return;}
                try{self::moderate($db,$id,(int)$user['id'],$transition,$reason);
                    header('Location: /admin/messages/'.$id,true,303);
                }catch(\Throwable $e){self::error(409);}
                return;
            }
            if($method!=='GET'){self::error(405);return;}
            $h='<section class="hero compact"><h1>メッセージ管理 #'.$id.'</h1><p>件名：'.Security::h($message['subject']).' ／ 状態：'.Security::h($message['moderation_state']).'</p></section>';
            $h.='<form method="post" action="/admin/messages/'.$id.'/inspect" class="setup-form">'.Session::csrfField().'<label>本文閲覧の正当な理由（監査記録へ保存）<input name="reason" required maxlength="400"></label><p>15分以内の新しいログインでのみ閲覧できます。</p><button class="btn ghost">理由を記録して閲覧</button></form>';
            $nextState=$message['moderation_state']==='active'?'hidden':'active';
            $h.='<form method="post" action="/admin/messages/'.$id.'/moderate" class="setup-form">'.Session::csrfField().'<input type="hidden" name="expected_state" value="'.Security::h((string)$message['moderation_state']).'"><input type="hidden" name="status" value="'.$nextState.'"><label>変更理由（監査記録）<input name="reason" maxlength="400" required></label><p>現在の状態から「'.($nextState==='hidden'?'非表示':'公開状態へ復元').'」に変更します。別の管理者が先に変更した場合は拒否します。</p><button class="btn primary">変更して監査記録</button></form>';
            Kernel::layout('メッセージ管理',$h);return;
        }
        self::error(404);
    }
    private static function broadcasts(PDO $db):void {
        $q=$db->query('SELECT id,event_key,state,delivered_count,created_at FROM message_broadcasts ORDER BY id DESC LIMIT 20');
        $h='<section class="hero compact"><h1>システム一斉配信</h1><p>送信者を偽装せず、CLIワーカーが100件ずつ安全に配送します。サイト内通知でありメール送信はしません。</p></section>';
        if(isset($_GET['queued']))$h.='<p class="note" role="status">配信ジョブを登録しました。ワーカーが動作すると配送されます。</p>';
        if(isset($_GET['updated']))$h.='<p class="note" role="status">配信状態を変更しました。既に配信されたメッセージは取り消されません。</p>';
        $h.='<form method="post" class="setup-form">'.Session::csrfField().'<label>件名（80文字以内）<input name="subject" maxlength="80" required></label><label>本文（1000文字以内）<textarea name="body" required maxlength="1000" rows="5"></textarea></label><label><input type="checkbox" name="confirmed" value="yes" required>全アクティブユーザーへの配信を確認しました</label><button class="btn primary">一斉配信を予約する</button></form>';
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $id=(int)$r['id'];$state=(string)$r['state'];
            $h.='<article class="panel"><p>ジョブ '.$id.' ／ '.Security::h($state).' ／ 配送件数 '.(int)$r['delivered_count'].' ／ '.Security::h($r['created_at']).'</p>';
            if(in_array($state,['queued','paused'],true)){
                $h.='<form method="post" action="/admin/messages/broadcasts/'.$id.'/state" class="setup-form">'.Session::csrfField()
                    .'<input type="hidden" name="expected_state" value="'.Security::h($state).'">'
                    .'<label>操作<select name="action">';
                if($state==='queued')$h.='<option value="pause">一時停止</option>';
                else $h.='<option value="resume">続きを配信</option>';
                $h.='<option value="cancel">未配信分をキャンセル</option></select></label>'
                    .'<label>理由（監査記録）<input name="reason" maxlength="400" required></label>'
                    .'<label><input type="checkbox" name="confirmed" value="yes" required> 既に配送済みのメッセージは取り消せないことを確認しました</label>'
                    .'<button class="btn ghost" type="submit">配信状態を変更</button></form>';
            }
            $h.='</article>';
        }
        Kernel::layout('一斉配信',$h);
    }
    private static function error(int $status):void {http_response_code($status);Kernel::layout('メッセージ管理','<section class="hero compact"><h1>操作できません</h1></section>',false);}
}
