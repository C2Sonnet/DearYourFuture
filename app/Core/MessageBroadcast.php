<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Durable, idempotent in-site message broadcast. No SMTP dependency. */
final class MessageBroadcast {
    public static function queue(PDO $db,string $key,string $kind,string $subject,string $body,?int $actorId=null):int {
        if(!preg_match('/\A[a-zA-Z0-9:_-]{8,100}\z/D',$key))throw new \InvalidArgumentException('Invalid event key');
        if(!in_array($kind,['system','company_seed_update'],true))throw new \InvalidArgumentException('Invalid system message type');
        $subject=MessageData::text($subject,80,true);$body=MessageData::text($body,1000,true);
        // When called from the seed update pipeline, the caller must be in the same DB transaction.
        $own=!$db->inTransaction();if($own)$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT id FROM message_broadcasts WHERE event_key=? LIMIT 1 FOR UPDATE');$q->execute([$key]);$existing=$q->fetchColumn();
            if($existing!==false){if($own)$db->commit();return (int)$existing;}
            $q=$db->prepare('INSERT INTO messages(kind,sender_user_id,subject,body,system_event_key) VALUES (?,NULL,?,?,?)');$q->execute([$kind,$subject,$body,$key]);$messageId=(int)$db->lastInsertId();
            $q=$db->prepare('INSERT INTO message_broadcasts(message_id,event_key,created_by) VALUES (?,?,?)');$q->execute([$messageId,$key,$actorId]);$id=(int)$db->lastInsertId();
            if($actorId!==null)$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$actorId,'message.broadcast.queued','message_broadcast',(string)$id,'{}']);
            if($own)$db->commit();return $id;
        }catch(\Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Admin control is serialized with the delivery worker through the same row lock.
     * Pausing stops future batches; resuming preserves the cursor and unique recipient keys.
     * Cancellation cannot retract recipient copies already committed.
     */
    public static function transition(PDO $db,int $id,int $actor,string $expected,string $action,string $reason):array {
        if($id<1||$actor<1||!in_array($expected,['queued','paused'],true))throw new \InvalidArgumentException('Invalid broadcast transition');
        $next=match($action){'pause'=>'paused','resume'=>'queued','cancel'=>'cancelled',default=>throw new \InvalidArgumentException('Invalid broadcast action')};
        if(($action==='pause'&&$expected!=='queued')||($action==='resume'&&$expected!=='paused'))throw new \InvalidArgumentException('Invalid state transition');
        $reason=MessageData::text($reason,400,true);
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT state,delivered_count FROM message_broadcasts WHERE id=? FOR UPDATE');
            $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['state']!==$expected)throw new \RuntimeException('Broadcast state changed: reload the page');
            $q=$db->prepare("UPDATE message_broadcasts SET state=?,finished_at=CASE WHEN ?='cancelled' THEN UTC_TIMESTAMP() ELSE NULL END WHERE id=? AND state=?");
            $q->execute([$next,$next,$id,$expected]);
            if($q->rowCount()!==1)throw new \RuntimeException('Concurrent broadcast transition');
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                ->execute([$actor,'message.broadcast.'.$action,'message_broadcast',(string)$id,json_encode(['from'=>$expected,'to'=>$next,'reason'=>$reason,'already_delivered'=>(int)$row['delivered_count']],JSON_THROW_ON_ERROR)]);
            $db->commit();return ['state'=>$next,'already_delivered'=>(int)$row['delivered_count']];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** CLI-only; a locked cursor plus recipient unique constraint prevents duplicate delivery on retries. */
    public static function deliver(PDO $db,int $batchSize=100):array {
        if($batchSize<1||$batchSize>500)throw new \InvalidArgumentException('Invalid batch size');
        $db->beginTransaction();
        try{
            $q=$db->query("SELECT * FROM message_broadcasts WHERE state='queued' ORDER BY id LIMIT 1 FOR UPDATE");$batch=$q->fetch(PDO::FETCH_ASSOC);
            if(!$batch){$db->commit();return ['state'=>'idle','delivered'=>0];}
            $q=$db->prepare("SELECT id FROM users WHERE status='active' AND id>? ORDER BY id LIMIT ".$batchSize." FOR UPDATE");
            $q->execute([(int)$batch['cursor_user_id']]);$users=$q->fetchAll(PDO::FETCH_COLUMN);
            if(!$users){$db->prepare("UPDATE message_broadcasts SET state='completed',finished_at=UTC_TIMESTAMP() WHERE id=?")->execute([$batch['id']]);$db->commit();return ['state'=>'completed','delivered'=>0,'batch'=>(int)$batch['id']];}
            $count=0;$last=(int)$batch['cursor_user_id'];
            foreach($users as $userId){$uid=(int)$userId;$last=$uid;
                $q=$db->prepare('INSERT IGNORE INTO message_recipients(message_id,recipient_user_id) VALUES (?,?)');$q->execute([$batch['message_id'],$uid]);$count+=$q->rowCount();
                $db->prepare("INSERT IGNORE INTO message_delivery_jobs(broadcast_id,recipient_user_id,status) VALUES (?,?,'delivered')")->execute([$batch['id'],$uid]);
            }
            $db->prepare('UPDATE message_broadcasts SET cursor_user_id=?,delivered_count=delivered_count+? WHERE id=?')->execute([$last,$count,$batch['id']]);
            $db->commit();return ['state'=>'queued','delivered'=>$count,'batch'=>(int)$batch['id']];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
