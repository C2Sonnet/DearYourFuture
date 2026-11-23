<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** FOUNDER-exact private workspace audit and per-user letter controls. No DEVELOPER access. */
final class FounderPrivacyController {
    private const ALLOW='__kimi_allow_v2';
    private const DENY='__kimi_deny_v2';
    private const ACK='__kimi_letter_ack_v1';
    public static function isRoute(string $p):bool {return $p==='/admin/founder/kimi'||$p==='/admin/founder/activity'||preg_match('~\A/admin/founder/(kimi|activity)/[1-9][0-9]{0,17}\z~D',$p)===1;}
    public static function override(PDO $db,int $uid):?bool {
        $q=$db->prepare('SELECT step_key FROM user_guide_steps WHERE user_id=? AND step_key IN (?,?)');
        $q->execute([$uid,self::ALLOW,self::DENY]);
        $keys=$q->fetchAll(PDO::FETCH_COLUMN);
        if(in_array(self::DENY,$keys,true))return false;
        return in_array(self::ALLOW,$keys,true)?true:null;
    }
    private static function user(PDO $db,int $uid):?array {
        $q=$db->prepare("SELECT u.id,u.handle,u.display_name,u.status,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? AND ur.role_code <> 'DEVELOPER' LIMIT 1");
        $q->execute([$uid]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private static function log(PDO $db,int $founder,string $event,int $uid,array $details):void {
        $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(?,?,?,?,?)')
          ->execute([$founder,$event,'user',(string)$uid,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    private static function error(int $status,string $message):void {http_response_code($status);Kernel::layout('操作を確認してください','<p role="alert">'.Security::h($message).'</p>');}
    public static function handle(PDO $db,string $path,string $method,array $actor):void {
        // Exact FOUNDER: ADMIN and DEVELOPER are both denied regardless of rank.
        if(($actor['role']??null)!=='FOUNDER'){self::error(403,'この画面には創始者だけがアクセスできます。');return;}
        if(preg_match('~\A/admin/founder/(kimi|activity)/([1-9][0-9]{0,17})\z~D',$path,$parts)){$section=$parts[1];$uid=(int)$parts[2];}
        else {$section=str_ends_with($path,'/kimi')?'kimi':'activity';$uid=0;}
        if($method==='POST'){
            if($uid<1 || !Session::validCsrf((string)($_POST['csrf']??'')) || (int)($_SESSION['authenticated_at']??0)<time()-900){self::error(403,'再ログインして操作してください。');return;}
            $reason=trim((string)($_POST['reason']??''));
            if(mb_strlen($reason)<5 || mb_strlen($reason)>400){self::error(422,'変更理由を5～400文字で入力してください。');return;}
            $command=(string)($_POST['action']??'');
            try{
                $db->beginTransaction();
                $locked=$db->prepare("SELECT u.id,u.status,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE");
                $locked->execute([$uid]);$target=$locked->fetch(PDO::FETCH_ASSOC);
                if(!$target || $target['role']==='DEVELOPER')throw new \DomainException('対象ユーザーの変更権限がありません。');
                $details=['action'=>$command,'reason'=>$reason];
                if($section==='kimi' && in_array($command,['enable','disable','reset'],true)){
                    if($command==='enable'||$command==='disable'){
                        $db->prepare('DELETE FROM user_guide_steps WHERE user_id=? AND step_key IN (?,?)')->execute([$uid,self::ALLOW,self::DENY]);
                        $key=$command==='enable'?self::ALLOW:self::DENY;
                        $db->prepare('INSERT INTO user_guide_steps(user_id,step_key) VALUES(?,?)')->execute([$uid,$key]);
                    }else{
                        $db->prepare('DELETE FROM user_guide_steps WHERE user_id=? AND step_key=?')->execute([$uid,self::ACK]);
                        $details['next_login_only']=true;
                    }
                    self::log($db,(int)$actor['id'],'founder.kimi.'.$command,$uid,$details);
                }elseif($section==='activity'){
                    if($command==='clear_history'){
                        $db->prepare('DELETE FROM user_saved_filters WHERE user_id=? AND label=?')->execute([$uid,ViewHistory::LABEL]);
                        self::log($db,(int)$actor['id'],'founder.activity.history_cleared',$uid,$details);
                    }elseif($command==='remove_favorite'){
                        $kind=(string)($_POST['kind']??'');$id=(string)($_POST['item_id']??'');
                        if(!WorkspaceRepository::validFavoriteId($kind,$id))throw new \InvalidArgumentException('お気に入りIDが無効です。');
                        if($kind==='companies'&&preg_match('/\A[a-f0-9]{32}\z/D',$id)===1){
                            $db->prepare('DELETE FROM user_favorite_companies WHERE user_id=? AND company_id=?')->execute([$uid,$id]);
                        }else{
                            $q=$db->prepare('SELECT id,filters_json FROM user_saved_filters WHERE user_id=? AND label=? FOR UPDATE');
                            $q->execute([$uid,WorkspaceRepository::FAVORITE_LABEL]);$row=$q->fetch(PDO::FETCH_ASSOC);
                            if($row){$v=json_decode((string)$row['filters_json'],true);if(is_array($v)){$v[$kind]=array_values(array_filter((array)($v[$kind]??[]),static fn($value)=>$value!==$id));$db->prepare('UPDATE user_saved_filters SET filters_json=? WHERE id=? AND user_id=?')->execute([json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['id'],$uid]);}}
                        }
                        $details['kind']=$kind;$details['item']=$id;
                        self::log($db,(int)$actor['id'],'founder.activity.favorite_removed',$uid,$details);
                    }elseif($command==='status'){
                        $track=(string)($_POST['track']??'');$status=(string)($_POST['status']??'');$revision=(int)($_POST['revision']??0);
                        if(!preg_match('/\A[a-f0-9]{32}\z/D',$track)||!in_array($status,ApplicationData::STATUSES,true)||$revision<1)throw new \InvalidArgumentException('就活状態の入力が無効です。');
                        $q=$db->prepare('SELECT status,revision FROM application_tracks WHERE id=? AND user_id=? FOR UPDATE');$q->execute([$track,$uid]);$old=$q->fetch(PDO::FETCH_ASSOC);
                        if(!$old||(int)$old['revision']!==$revision)throw new \RuntimeException('状態が更新されています。再読み込みしてください。');
                        $q=$db->prepare('UPDATE application_tracks SET status=?,revision=revision+1 WHERE id=? AND user_id=? AND revision=?');$q->execute([$status,$track,$uid,$revision]);
                        if($q->rowCount()!==1)throw new \RuntimeException('同時更新により中止しました。');
                        $details+=['track'=>$track,'from'=>$old['status'],'to'=>$status];
                        self::log($db,(int)$actor['id'],'founder.activity.status_changed',$uid,$details);
                        $db->prepare('INSERT INTO application_audit(actor_user_id,track_id,event_id,operation,details_json) VALUES(?,?,NULL,?,?)')->execute([(int)$actor['id'],$track,'founder_status_change',json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                    }else throw new \InvalidArgumentException('不明な管理操作です。');
                }else throw new \InvalidArgumentException('操作が許可されていません。');
                $db->commit();
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Founder control refused: '.get_class($e));self::error(409,'更新を実行できませんでした。対象・競合・入力を確認してください。');return;}
            header('Location: '.$path.'?saved=1',true,303);return;
        }
        if($method!=='GET'){header('Allow: GET, POST');self::error(405,'GETまたはPOSTのみ利用できます。');return;}
        header('Cache-Control: no-store, private');
        $head='<section class="hero compact"><h1>'.($section==='kimi'?'「キミへ」の閲覧設定':'ユーザーの就職活動情報').'</h1><p>創始者専用です。変更には再ログインと理由を要求し、監査ログを残します。開発者アカウントは対象外です。</p></section>';
        if(isset($_GET['saved']))$head.='<p role="status">変更を保存しました。</p>';
        if($uid===0){
            $q=$db->query("SELECT u.id,u.handle,u.display_name,u.status FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE ur.role_code <> 'DEVELOPER' ORDER BY u.id DESC LIMIT 150");
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$target='/admin/founder/'.$section.'/'.(int)$r['id'];$head.='<p class="panel"><a href="'.$target.'">'.Security::h($r['display_name']).' (@'.Security::h($r['handle']).')</a> · '.Security::h($r['status']).'</p>';}
            Kernel::layout('創始者専用管理',$head);return;
        }
        $target=self::user($db,$uid);if(!$target){self::error(404,'ユーザーが存在しないか、閲覧権限がありません。');return;}
        $head.='<h2>'.Security::h($target['display_name']).' (@'.Security::h($target['handle']).')</h2>';
        $form=static function(string $action,string $button,string $fields='')use($path):string{return '<form class="setup-form" method="post" action="'.Security::h($path).'">'.Session::csrfField().'<input type="hidden" name="action" value="'.Security::h($action).'">'.$fields.'<label>変更理由<input name="reason" required minlength="5" maxlength="400"></label><button type="submit" class="btn ghost">'.Security::h($button).'</button></form>';};
        if($section==='kimi'){
            $ov=self::override($db,$uid);$ack=KimiIntro::acknowledged($db,$uid);
            $head.='<p>表示設定：'.($ov===null?'既存の既定条件':($ov?'許可':'禁止')).' ／ 既読：'.($ack?'はい':'いいえ').'</p>';
            $head.=$form('enable','このユーザーに表示する').$form('disable','このユーザーに表示しない').$form('reset','既読をリセット（次回ログイン時に再表示）');
        }else{
            $history=ViewHistory::read($db,$uid);
            foreach(['companies'=>'最近見た企業','jobs'=>'最近見た求人'] as $kind=>$label){$head.='<h3>'.Security::h($label).'</h3><div class="panel">';$rows=array_slice($history[$kind]??[],0,50,true);if(!$rows)$head.='<p>履歴なし</p>';
                foreach($rows as $id=>$v){$head.='<p>'.Security::h((string)($v['snapshot']['企業名']??$v['snapshot']['求人名']??$id)).' <small>'.Security::h((string)($v['at']??'')).'</small></p>';}$head.='</div>';}
            $head.=$form('clear_history','閲覧履歴を全件消去する');
            $head.='<h3>お気に入り</h3>';
            foreach(['companies'=>'企業','jobs'=>'求人'] as $kind=>$label){$head.='<h4>'.$label.'</h4>';foreach(array_slice(WorkspaceRepository::favoriteKeys($db,$uid,$kind),0,100) as $id){$v=ViewHistory::item($db,$kind,$id);$fields='<input type="hidden" name="kind" value="'.$kind.'"><input type="hidden" name="item_id" value="'.Security::h($id).'">';$head.='<article class="panel"><span>'.Security::h((string)($v['name']??$id)).'</span>'.$form('remove_favorite','お気に入りを解除',$fields).'</article>';}}
            $head.='<h3>応募・選考状況</h3>';
            $q=$db->prepare('SELECT t.id,t.status,t.revision,t.job_id,t.company_id,t.updated_at,c.display_name AS company_name FROM application_tracks t JOIN companies c ON c.id=t.company_id WHERE t.user_id=? AND t.archived_at IS NULL ORDER BY t.updated_at DESC LIMIT 100');$q->execute([$uid]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$opts='';foreach(ApplicationData::LABELS as $status=>$label)$opts.='<option value="'.Security::h($status).'"'.($status===$row['status']?' selected':'').'>'.Security::h($label).'</option>';
                $fields='<input type="hidden" name="track" value="'.Security::h($row['id']).'"><input type="hidden" name="revision" value="'.(int)$row['revision'].'"><label>状態<select name="status">'.$opts.'</select></label>';
                $head.='<article class="panel"><strong>'.Security::h($row['company_name']).'</strong>・'.Security::h((string)$row['updated_at']).$form('status','状況を更新',$fields).'</article>';}
        }
        Kernel::layout('創始者専用管理',$head);
    }
}
