<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Opt-in in-site interview reminders. No email or unsolicited push. */
final class ReminderRepository {
    public const HOURS=[1,24,48,72];
    public static function preferences(PDO $db,int $uid):array {
        $q=$db->prepare('SELECT enabled,lead_hours FROM user_reminder_settings WHERE user_id=?');$q->execute([$uid]);
        return $q->fetch(PDO::FETCH_ASSOC)?:['enabled'=>0,'lead_hours'=>24];
    }
    /** Keep preferences and their audit in one unit. Lock the account so a
     * concurrently disabled user cannot turn notifications back on using an
     * already loaded form. This is an internal transaction contract; real MySQL
     * lock ordering is reserved for the final external batch.
     */
    public static function set(PDO $db,int $uid,bool $enabled,int $hours):void {
        if($uid<1||!in_array($hours,self::HOURS,true))throw new \InvalidArgumentException('Invalid reminder preference');
        if($db->inTransaction())throw new \RuntimeException('Reminder preference needs its own transaction');
        $db->beginTransaction();
        try {
            $account=$db->prepare('SELECT status FROM users WHERE id=? FOR UPDATE');
            $account->execute([$uid]);
            if($account->fetchColumn()!=='active')throw new \DomainException('Inactive account cannot change reminders');
            $q=$db->prepare('INSERT INTO user_reminder_settings(user_id,enabled,lead_hours) VALUES(?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),lead_hours=VALUES(lead_hours),updated_at=UTC_TIMESTAMP()');
            $q->execute([$uid,(int)$enabled,$hours]);
            $q=$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)');
            $q->execute([$uid,'user.reminder.preference','user',(string)$uid,json_encode(['enabled'=>$enabled,'lead_hours'=>$hours],JSON_THROW_ON_ERROR)]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function dueSql():string {
        return "SELECT e.id AS event_id,t.user_id,t.company_id,c.display_name AS company_name,e.title,e.event_on,e.event_time,s.lead_hours FROM application_events e JOIN application_tracks t ON t.id=e.track_id JOIN users u ON u.id=t.user_id JOIN companies c ON c.id=t.company_id JOIN user_reminder_settings s ON s.user_id=t.user_id WHERE s.enabled=1 AND u.status='active' AND t.archived_at IS NULL AND e.deleted_at IS NULL AND e.event_kind IN ('interview','information_session','deadline') AND e.event_on IS NOT NULL AND e.event_time IS NOT NULL AND TIMESTAMP(e.event_on,e.event_time)>? AND TIMESTAMP(e.event_on,e.event_time)<=DATE_ADD(?, INTERVAL s.lead_hours HOUR) AND NOT EXISTS (SELECT 1 FROM user_reminder_deliveries d WHERE d.user_id=t.user_id AND d.event_id=e.id) ORDER BY e.event_on,e.event_time,e.id LIMIT 100";
    }
    /** Explicit CLI call. All dates are entered and interpreted in Asia/Tokyo local time. */
    public static function deliver(PDO $db,?\DateTimeImmutable $now=null):array {
        $now=$now??new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo'));
        $now=$now->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s');
        $q=$db->prepare(self::dueSql());$q->execute([$now,$now]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $count=0;
        foreach($rows as $r){
            $uid=(int)$r['user_id'];$eventId=BusinessData::id((string)$r['event_id']);
            $db->beginTransaction();
            try {
                // Recheck under row locks: a user may opt out or move/delete an event
                // after the candidate SELECT but before this transaction starts.
                $q=$db->prepare("SELECT e.id,e.title,e.event_on,e.event_time,c.display_name AS company_name,t.company_id FROM application_events e JOIN application_tracks t ON t.id=e.track_id JOIN companies c ON c.id=t.company_id JOIN user_reminder_settings s ON s.user_id=t.user_id JOIN users u ON u.id=t.user_id WHERE e.id=? AND t.user_id=? AND s.enabled=1 AND u.status='active' AND t.archived_at IS NULL AND e.deleted_at IS NULL AND e.event_kind IN ('interview','information_session','deadline') AND e.event_on IS NOT NULL AND e.event_time IS NOT NULL AND TIMESTAMP(e.event_on,e.event_time)>? AND TIMESTAMP(e.event_on,e.event_time)<=DATE_ADD(?, INTERVAL s.lead_hours HOUR) FOR UPDATE");
                $q->execute([$eventId,$uid,$now,$now]);
                if(!($fresh=$q->fetch(PDO::FETCH_ASSOC))){$db->commit();continue;}
                $q=$db->prepare('INSERT IGNORE INTO user_reminder_deliveries(user_id,event_id) VALUES (?,?)');$q->execute([$uid,$eventId]);
                if($q->rowCount()===0){$db->commit();continue;}
                $key='reminder:'.$eventId.':'.$uid;
                $subject='選考予定のお知らせ';
                $date=(string)$fresh['event_on'].' '.substr((string)$fresh['event_time'],0,5);
                $body='予定：'.(string)$fresh['company_name'].' / '.(string)$fresh['title'].' / '.$date.'（日本時間）。詳しくは /companies/'.(string)$fresh['company_id'].'/timeline を確認してください。';
                $q=$db->prepare("INSERT INTO messages(kind,sender_user_id,subject,body,system_event_key) VALUES ('system',NULL,?,?,?)");$q->execute([$subject,MessageData::text($body,1000,true),$key]);
                $messageId=(int)$db->lastInsertId();
                $db->prepare('INSERT INTO message_recipients(message_id,recipient_user_id) VALUES (?,?)')->execute([$messageId,$uid]);
                $db->prepare('UPDATE user_reminder_deliveries SET message_id=? WHERE user_id=? AND event_id=?')->execute([$messageId,$uid,$eventId]);
                $db->commit();$count++;
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        }
        return ['delivered'=>$count,'candidates'=>count($rows)];
    }
}
