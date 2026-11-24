<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** All direct messages are single-recipient. Reads and mutations are owner-scoped. */
final class MessageRepository {
    public static function unread(PDO $db,int $userId):int {
        $q=$db->prepare("SELECT COUNT(*) FROM message_recipients r JOIN messages m ON m.id=r.message_id WHERE r.recipient_user_id=? AND r.read_at IS NULL AND r.trashed_at IS NULL AND r.purged_at IS NULL AND m.moderation_state='active'");
        $q->execute([$userId]);return (int)$q->fetchColumn();
    }
    public static function listInbox(PDO $db,int $userId,string $folder='inbox',int $page=1,string $qText=''):array {
        if(!in_array($folder,['inbox','trash','sent','sent_trash'],true))throw new \InvalidArgumentException('Invalid folder');
        if($page<1||$page>1000)throw new \InvalidArgumentException('Invalid page');
        if(strlen($qText)>100)throw new \InvalidArgumentException('Search too long');
        $offset=($page-1)*MessageData::PAGE_SIZE;
        $args=[$userId];
        if(in_array($folder,['sent','sent_trash'],true)) {
            $sql="SELECT m.id,m.kind,m.subject,m.body,m.created_at,m.sender_user_id,m.moderation_state,ss.trashed_at,ss.purged_at,CASE WHEN tr.role_code='DEVELOPER' THEN '開発者' ELSE u.display_name END AS partner_name FROM messages m LEFT JOIN message_recipients mr ON mr.message_id=m.id LEFT JOIN users u ON u.id=mr.recipient_user_id LEFT JOIN user_roles tr ON tr.user_id=u.id LEFT JOIN message_sender_states ss ON ss.message_id=m.id AND ss.sender_user_id=m.sender_user_id WHERE m.sender_user_id=? AND m.sender_deleted_at IS NULL AND m.kind='direct' AND ss.purged_at IS NULL";
            $sql.=$folder==='sent_trash'?' AND ss.trashed_at IS NOT NULL':' AND ss.trashed_at IS NULL';
        }else{
            $sql="SELECT m.id,m.kind,m.subject,m.body,m.created_at,m.sender_user_id,m.moderation_state,r.read_at,r.trashed_at,r.starred_at,CASE WHEN sr.role_code='DEVELOPER' THEN '開発者' ELSE s.display_name END AS partner_name FROM message_recipients r JOIN messages m ON m.id=r.message_id LEFT JOIN users s ON s.id=m.sender_user_id LEFT JOIN user_roles sr ON sr.user_id=s.id JOIN users rec ON rec.id=r.recipient_user_id WHERE r.recipient_user_id=? AND r.purged_at IS NULL AND m.moderation_state='active'";
            $sql.=$folder==='trash'?' AND r.trashed_at IS NOT NULL':' AND r.trashed_at IS NULL';
        }
        if($qText!==''){$sql.=' AND (m.subject LIKE ? OR m.body LIKE ?)';$term='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$qText).'%';$args[]=$term;$args[]=$term;}
        $sql.=' ORDER BY m.created_at DESC,m.id DESC LIMIT '.MessageData::PAGE_SIZE.' OFFSET '.$offset;
        $q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function detail(PDO $db,int $userId,int $id):?array {
        $q=$db->prepare("SELECT m.*,r.read_at,r.trashed_at,r.purged_at,r.starred_at,r.delivered_at,s.display_name AS sender_name,s.handle AS sender_handle,sr.role_code AS sender_role,rec.display_name AS recipient_name,rec.handle AS recipient_handle FROM message_recipients r JOIN messages m ON m.id=r.message_id LEFT JOIN users s ON s.id=m.sender_user_id LEFT JOIN user_roles sr ON sr.user_id=s.id JOIN users rec ON rec.id=r.recipient_user_id WHERE r.recipient_user_id=? AND r.message_id=? AND r.purged_at IS NULL AND m.moderation_state='active' LIMIT 1");
        $q->execute([$userId,$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    /** Sender copy is separate from recipient state; recipient deletion is never implied. */
    public static function sentDetail(PDO $db,int $userId,int $messageId):?array {
        $q=$db->prepare("SELECT m.*,ss.trashed_at,ss.purged_at,u.handle AS recipient_handle,u.id AS recipient_user_id,CASE WHEN rr.role_code='DEVELOPER' THEN '開発者' ELSE u.display_name END AS recipient_name,rr.role_code AS recipient_role,send.display_name AS sender_name,send.handle AS sender_handle,sr.role_code AS sender_role FROM messages m JOIN message_recipients r ON r.message_id=m.id JOIN users u ON u.id=r.recipient_user_id LEFT JOIN user_roles rr ON rr.user_id=u.id JOIN users send ON send.id=m.sender_user_id LEFT JOIN user_roles sr ON sr.user_id=send.id LEFT JOIN message_sender_states ss ON ss.message_id=m.id AND ss.sender_user_id=m.sender_user_id WHERE m.id=? AND m.sender_user_id=? AND m.kind='direct' AND m.sender_deleted_at IS NULL AND ss.purged_at IS NULL LIMIT 1");
        $q->execute([$messageId,$userId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    /** Soft delete from the sender view, then optional permanent purge of only that copy. */
    public static function applySender(PDO $db,int $sender,int $id,string $action):void {
        if(!in_array($action,['trash','restore','purge'],true))throw new \InvalidArgumentException('Invalid sender action');
        $db->beginTransaction();
        try{
            $q=$db->prepare("SELECT m.sender_user_id,s.trashed_at,s.purged_at FROM messages m LEFT JOIN message_sender_states s ON s.message_id=m.id AND s.sender_user_id=m.sender_user_id WHERE m.id=? AND m.sender_user_id=? AND m.kind='direct' AND m.sender_deleted_at IS NULL FOR UPDATE");
            $q->execute([$id,$sender]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['purged_at']!==null)throw new \RuntimeException('Sent message not available');
            if($action!=='trash'&&$row['trashed_at']===null)throw new \InvalidArgumentException('Message must be in the sent trash');
            if($action==='trash'){
                $db->prepare('INSERT INTO message_sender_states(message_id,sender_user_id,trashed_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE trashed_at=UTC_TIMESTAMP()')->execute([$id,$sender]);
            }else{
                $field=$action==='restore'?'trashed_at=NULL':'purged_at=UTC_TIMESTAMP()';
                $db->prepare('UPDATE message_sender_states SET '.$field.' WHERE message_id=? AND sender_user_id=? AND purged_at IS NULL')->execute([$id,$sender]);
            }
            self::audit($db,$sender,'message.sent.'.$action,(string)$id);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Read account rows in a stable lock order so blocking, opt-out and disabling
     * cannot be committed between the recipient eligibility check and insertion.
     * The external MySQL simultaneous-writer proof remains a deferred acceptance.
     */
    private static function lockAccounts(PDO $db,int $first,int $second):array {
        $ids=array_values(array_unique([$first,$second]));sort($ids,SORT_NUMERIC);
        $users=[];
        foreach($ids as $id){
            $q=$db->prepare('SELECT id,handle,status FROM users WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new \DomainException('Account unavailable');
            $users[$id]=$row;
        }
        return $users;
    }
    public static function send(PDO $db,int $from,string $toHandle,string $subject,string $body,?int $replyTo=null):int {
        if($from<1||ReservedAccounts::reserved($toHandle)||!preg_match('/\A[A-Za-z0-9_-]{3,40}\z/D',$toHandle))throw new \InvalidArgumentException('宛先IDを確認してください');
        $subject=MessageData::text($subject,80);$body=MessageData::text($body,1000,true);
        // Resolve the target ID before locking, then revalidate its handle under the lock.
        $q=$db->prepare('SELECT id FROM users WHERE handle=? LIMIT 1');$q->execute([$toHandle]);
        $to=(int)$q->fetchColumn();
        if($to<1||$to===$from)throw new \InvalidArgumentException('このユーザーには送信できません');
        if($db->inTransaction())throw new \RuntimeException('Message send requires its own transaction');
        $db->beginTransaction();
        try {
            $users=self::lockAccounts($db,$from,$to);
            if($users[$from]['status']!=='active'||$users[$to]['status']!=='active'||ReservedAccounts::reserved($users[$from]['handle'])||ReservedAccounts::reserved($users[$to]['handle'])||$users[$to]['handle']!==$toHandle)
                throw new \InvalidArgumentException('このユーザーには送信できません');
            $q=$db->prepare('SELECT allow_direct FROM message_user_settings WHERE user_id=?');
            $q->execute([$to]);$allowed=$q->fetchColumn();
            if($allowed!==false&&(int)$allowed!==1)throw new \InvalidArgumentException('このユーザーには送信できません');
            $q=$db->prepare('SELECT 1 FROM message_blocks WHERE (user_id=? AND blocked_user_id=?) OR (user_id=? AND blocked_user_id=?) LIMIT 1');
            $q->execute([$to,$from,$from,$to]);if($q->fetchColumn())throw new \InvalidArgumentException('このユーザーには送信できません');
            if($replyTo!==null){
                // A reply must refer to the caller's own currently visible direct-message copy.
                $resolved=self::replyHandle($db,$from,$replyTo);
                if($resolved===null||$resolved!==$toHandle)throw new \InvalidArgumentException('返信先を確認してください');
            }
            $q=$db->prepare("INSERT INTO messages(kind,sender_user_id,subject,body,reply_to_id) VALUES ('direct',?,?,?,?)");
            $q->execute([$from,$subject,$body,$replyTo]);$id=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO message_recipients(message_id,recipient_user_id) VALUES (?,?)')->execute([$id,$to]);
            self::audit($db,$from,'message.direct.sent',(string)$id);
            $db->commit();
            MailNotifications::notify($db,$to,'new_message',['url'=>rtrim((string)Database::settings($db,'site.url'),'/').'/messages/'.$id]);
            return $id;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** A pseudo-user sender (never a login account). Only a freshly authenticated DEVELOPER may invoke this. */
    public static function sendAsMina(PDO $db,int $developerId,string $toHandle,string $subject,string $body):int {
        if($developerId<1||ReservedAccounts::reserved($toHandle)||preg_match('/\A[A-Za-z0-9_-]{3,40}\z/D',$toHandle)!==1)
            throw new \InvalidArgumentException('宛先IDを確認してください');
        $subject=MessageData::text($subject,80);$body=MessageData::text($body,1000,true);
        $q=$db->prepare('SELECT role_code FROM user_roles WHERE user_id=? LIMIT 1');$q->execute([$developerId]);
        if($q->fetchColumn()!=='DEVELOPER')throw new \DomainException('開発者の権限が必要です');
        $q=$db->prepare('SELECT id FROM users WHERE handle=? LIMIT 1');$q->execute([$toHandle]);$target=(int)$q->fetchColumn();
        if($target<1||$target===$developerId)throw new \InvalidArgumentException('この宛先には送れません');
        if($db->inTransaction())throw new \RuntimeException('System sender needs its own transaction');
        $db->beginTransaction();
        try{
            $users=self::lockAccounts($db,$developerId,$target);
            if(($users[$developerId]['status']??'')!=='active'||($users[$target]['status']??'')!=='active'||($users[$target]['handle']??'')!==$toHandle)
                throw new \DomainException('アカウント状態が変わりました');
            $q=$db->prepare('SELECT role_code FROM user_roles WHERE user_id=? LIMIT 1');$q->execute([$developerId]);
            if($q->fetchColumn()!=='DEVELOPER')throw new \DomainException('開発者の権限が必要です');
            $db->prepare("INSERT INTO messages(kind,sender_user_id,subject,body) VALUES ('system',NULL,?,?)")->execute([$subject,$body]);
            $id=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO message_recipients(message_id,recipient_user_id) VALUES (?,?)')->execute([$id,$target]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                ->execute([$developerId,'message.mina.sent','message',(string)$id,json_encode(['sender'=>'MinaHelper','recipient_user_id'=>$target],JSON_THROW_ON_ERROR)]);
            $db->commit();
            MailNotifications::notify($db,$target,'new_message',['url'=>rtrim((string)Database::settings($db,'site.url'),'/').'/messages/'.$id]);
            return $id;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function apply(PDO $db,int $userId,int $id,string $action,?string $reason=null):void {
        MessageData::action($action);
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT r.trashed_at,r.purged_at,r.starred_at,m.kind FROM message_recipients r JOIN messages m ON m.id=r.message_id WHERE r.message_id=? AND r.recipient_user_id=? AND m.moderation_state=\'active\' FOR UPDATE');
            $q->execute([$id,$userId]);$r=$q->fetch(PDO::FETCH_ASSOC);
            if(!$r||$r['purged_at']!==null)throw new \RuntimeException('メッセージが見つかりません');
            if($action==='purge' && $r['trashed_at']===null)throw new \InvalidArgumentException('ゴミ箱からのみ完全削除できます');
            if($action==='restore'&&$r['trashed_at']===null)throw new \InvalidArgumentException('ゴミ箱のメッセージではありません');
            if($action==='report'){
                if($r['kind']!=='direct')throw new \InvalidArgumentException('会員メッセージのみ通報できます');
                $reason=MessageData::text($reason,800,true);
                $db->prepare('INSERT INTO message_reports(message_id,reporter_id,reason) VALUES (?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason),status=\'open\'')->execute([$id,$userId,$reason]);
                self::audit($db,$userId,'message.reported',(string)$id);
            }else {
                $change=match($action){'read'=>'read_at=UTC_TIMESTAMP()','unread'=>'read_at=NULL','trash'=>'trashed_at=UTC_TIMESTAMP()','restore'=>'trashed_at=NULL','purge'=>'purged_at=UTC_TIMESTAMP()','star'=>'starred_at=UTC_TIMESTAMP()','unstar'=>'starred_at=NULL'};
                $db->prepare('UPDATE message_recipients SET '.$change.',revision=revision+1 WHERE message_id=? AND recipient_user_id=?')->execute([$id,$userId]);
                if(in_array($action,['trash','purge'],true))self::audit($db,$userId,'message.'.$action,(string)$id);
            }
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function settings(PDO $db,int $uid):array {
        $q=$db->prepare('SELECT allow_direct,public_profile,notify_email FROM message_user_settings WHERE user_id=?');$q->execute([$uid]);return $q->fetch(PDO::FETCH_ASSOC)?:['allow_direct'=>1,'public_profile'=>0,'notify_email'=>1];
    }
    public static function saveSettings(PDO $db,int $uid,bool $direct,bool $public,bool $email):void {
        if($uid<1)throw new \InvalidArgumentException('Invalid user');
        if($db->inTransaction())throw new \RuntimeException('Message settings need their own transaction');
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT status FROM users WHERE id=? FOR UPDATE');$q->execute([$uid]);
            if($q->fetchColumn()!=='active')throw new \DomainException('Inactive account cannot change messages');
            $db->prepare('INSERT INTO message_user_settings(user_id,allow_direct,public_profile,notify_email) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE allow_direct=VALUES(allow_direct),public_profile=VALUES(public_profile),notify_email=VALUES(notify_email)')->execute([$uid,(int)$direct,(int)$public,(int)$email]);
            self::audit($db,$uid,'message.settings.changed',(string)$uid);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function mayBlock(PDO $db,int $uid):bool {
        $stmt=$db->prepare('SELECT role_code FROM user_roles WHERE user_id=? LIMIT 1');$stmt->execute([$uid]);
        $role=$stmt->fetchColumn();
        if($role==='DEVELOPER')return true;
        try{return Database::settings($db,'messages.blocking_for_members')===true;}
        catch(\RuntimeException){return false;} // Existing installs lacking the new key fail closed.
    }
    public static function block(PDO $db,int $uid,string $handle,bool $blocked):void {
        if($uid<1||ReservedAccounts::reserved($handle)||!preg_match('/\A[A-Za-z0-9_-]{3,40}\z/D',$handle))throw new \InvalidArgumentException('対象ユーザーを確認してください');
        $q=$db->prepare('SELECT u.id,r.role_code FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.handle=? LIMIT 1');$q->execute([$handle]);$target=$q->fetch(PDO::FETCH_ASSOC);$id=(int)($target['id']??0);
        if($blocked&&!self::mayBlock($db,$uid))throw new \DomainException('ブロック機能は現在利用できません');
        if($id<1||$id===$uid||($blocked&&($target['role_code']??null)==='DEVELOPER'))throw new \InvalidArgumentException('対象ユーザーを確認してください');
        if($db->inTransaction())throw new \RuntimeException('Message block needs its own transaction');
        $db->beginTransaction();
        try {
            $users=self::lockAccounts($db,$uid,$id);
            if($users[$uid]['status']!=='active'||$users[$id]['status']!=='active'||$users[$id]['handle']!==$handle)
                throw new \InvalidArgumentException('対象ユーザーを確認してください');
            if($blocked){
                if(!self::mayBlock($db,$uid))throw new \DomainException('ブロックの作成権限が変更されました');
                $role=$db->prepare('SELECT role_code FROM user_roles WHERE user_id=? LIMIT 1');
                $role->execute([$id]);
                if($role->fetchColumn()==='DEVELOPER')throw new \InvalidArgumentException('開発者はブロックできません');
            }
            if($blocked)$db->prepare('INSERT IGNORE INTO message_blocks(user_id,blocked_user_id) VALUES (?,?)')->execute([$uid,$id]);
            else $db->prepare('DELETE FROM message_blocks WHERE user_id=? AND blocked_user_id=?')->execute([$uid,$id]);
            self::audit($db,$uid,'message.block.changed',(string)$id);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function blocked(PDO $db,int $uid):array {
        $q=$db->prepare("SELECT u.handle FROM message_blocks b JOIN users u ON u.id=b.blocked_user_id JOIN user_roles ur ON ur.user_id=u.id WHERE b.user_id=? AND ur.role_code<>'DEVELOPER' ORDER BY u.handle LIMIT 100");$q->execute([$uid]);return $q->fetchAll(PDO::FETCH_COLUMN);
    }
    /** User-initiated address suggestions: no emails, private profiles, blocked users or pseudo-senders. */
    public static function searchRecipients(PDO $db,int $uid,string $query):array {
        $query=trim($query);
        if($uid<1||strlen($query)>180||preg_match('/[\x00-\x1f\x7f]/',$query))throw new \InvalidArgumentException('Invalid recipient search');
        $needle=ltrim($query,'@');
        if(strlen($needle)<2)return [];
        $escaped=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$needle);
        $stmt=$db->prepare("SELECT u.handle,u.display_name FROM users u JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN message_user_settings s ON s.user_id=u.id WHERE u.status='active' AND u.id<>? AND LOWER(u.handle)<>'minahelper' AND COALESCE(s.public_profile,0)=1 AND COALESCE(s.allow_direct,1)=1 AND (u.handle LIKE ? OR u.display_name LIKE ?) AND NOT EXISTS (SELECT 1 FROM message_blocks b WHERE (b.user_id=? AND b.blocked_user_id=u.id) OR (b.user_id=u.id AND b.blocked_user_id=?)) ORDER BY u.display_name,u.handle LIMIT 12");
        $stmt->execute([$uid,$escaped.'%','%'.$escaped.'%',$uid,$uid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Publicly discoverable accounts only; an exact @ID can still be blocked without search. */
    public static function searchBlockCandidates(PDO $db,int $uid,string $query):array {
        $query=trim($query);
        if($uid<1||strlen($query)>180||preg_match('/[\x00-\x1f\x7f]/',$query))throw new \InvalidArgumentException('Invalid block search');
        $needle=ltrim($query,'@');if(strlen($needle)<2)return [];
        $escaped=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$needle);
        $stmt=$db->prepare("SELECT u.handle,u.display_name FROM users u JOIN user_roles r ON r.user_id=u.id JOIN message_user_settings s ON s.user_id=u.id WHERE u.status='active' AND u.id<>? AND r.role_code<>'DEVELOPER' AND LOWER(u.handle)<>'minahelper' AND s.public_profile=1 AND (u.handle LIKE ? OR u.display_name LIKE ?) ORDER BY u.display_name,u.handle LIMIT 12");
        $stmt->execute([$uid,$escaped.'%','%'.$escaped.'%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function profile(PDO $db,int $uid,string $handle):?array {
        $q=$db->prepare("SELECT u.id,u.handle,u.display_name,u.status,COALESCE(s.public_profile,0) AS is_public,COALESCE(s.allow_direct,1) AS allow_direct FROM users u JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN message_user_settings s ON s.user_id=u.id WHERE u.handle=? AND LOWER(u.handle)<>'minahelper' LIMIT 1");$q->execute([$handle]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['status']!=='active'||((int)$row['is_public']!==1 && (int)$row['id']!==$uid))return null;
        return $row;
    }
    /** Pair conversation: show only direct messages for which the caller owns an unpurged copy. */
    public static function conversation(PDO $db,int $viewer,int $partner,int $limit=100):array {
        if($viewer<1||$partner<1||$viewer===$partner)return [];
        $limit=max(1,min(100,$limit));
        $sql="SELECT m.id,m.body,m.subject,m.reply_to_id,m.sender_user_id,m.created_at
          FROM messages m JOIN message_recipients r ON r.message_id=m.id
          LEFT JOIN message_sender_states ss ON ss.message_id=m.id AND ss.sender_user_id=m.sender_user_id
          WHERE m.kind='direct' AND m.moderation_state='active'
            AND ((m.sender_user_id=? AND r.recipient_user_id=? AND m.sender_deleted_at IS NULL AND ss.purged_at IS NULL AND ss.trashed_at IS NULL)
              OR (m.sender_user_id=? AND r.recipient_user_id=? AND r.purged_at IS NULL AND r.trashed_at IS NULL))
          ORDER BY m.created_at DESC,m.id DESC LIMIT ".$limit;
        $q=$db->prepare($sql);$q->execute([$viewer,$partner,$partner,$viewer]);
        return array_reverse($q->fetchAll(PDO::FETCH_ASSOC));
    }
    public static function lastDirectFrom(PDO $db,int $viewer,int $partner):?int {
        $q=$db->prepare("SELECT m.id FROM messages m JOIN message_recipients r ON r.message_id=m.id WHERE m.sender_user_id=? AND r.recipient_user_id=? AND m.kind='direct' AND m.moderation_state='active' AND r.purged_at IS NULL ORDER BY m.id DESC LIMIT 1");
        $q->execute([$partner,$viewer]);$id=$q->fetchColumn();return $id===false?null:(int)$id;
    }
    /** Resolve the other party from a visible direct message owned by the caller.
     * Never expose a private developer handle in a hidden HTML field or allow system replies.
     */
    public static function replyHandle(PDO $db,int $viewer,int $originalId):?string {
        if($viewer<1 || $originalId<1)return null;
        $q=$db->prepare("SELECT u.handle FROM messages m JOIN message_recipients r ON r.message_id=m.id
            JOIN users u ON u.id=CASE WHEN m.sender_user_id=? THEN r.recipient_user_id ELSE m.sender_user_id END
            LEFT JOIN message_sender_states ss ON ss.message_id=m.id AND ss.sender_user_id=m.sender_user_id
            WHERE m.id=? AND m.kind='direct' AND m.moderation_state='active' AND u.status='active'
              AND u.id<>? AND LOWER(u.handle)<>'minahelper'
              AND ((m.sender_user_id=? AND m.sender_deleted_at IS NULL AND ss.purged_at IS NULL AND ss.trashed_at IS NULL)
                OR (r.recipient_user_id=? AND r.purged_at IS NULL AND r.trashed_at IS NULL)) LIMIT 1");
        $q->execute([$viewer,$originalId,$viewer,$viewer,$viewer]);
        $handle=$q->fetchColumn();return $handle===false||ReservedAccounts::reserved((string)$handle)?null:(string)$handle;
    }
    public static function hasBlocked(PDO $db,int $from,int $to):bool {
        $q=$db->prepare('SELECT 1 FROM message_blocks WHERE (user_id=? AND blocked_user_id=?) OR (user_id=? AND blocked_user_id=?) LIMIT 1');$q->execute([$from,$to,$to,$from]);return (bool)$q->fetchColumn();
    }
    private static function audit(PDO $db,int $actor,string $event,string $target):void {
        $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$actor,$event,'message',$target,'{}']);
    }
}
