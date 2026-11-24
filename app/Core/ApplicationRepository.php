<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** All reads/writes MUST filter by the signed-in user_id. No admin bypass for private notes. */
final class ApplicationRepository {
    public static function tracks(PDO $db,int $userId,?string $status=null,int $limit=50,int $offset=0):array {
        self::user($userId);if($status!==null)ApplicationData::status($status);
        if($limit<1||$limit>100||$offset<0)throw new \InvalidArgumentException('Pagination');
        $sql='SELECT a.*,c.display_name AS company_name,j.title AS job_title,j.deadline_on FROM application_tracks a JOIN companies c ON c.id=a.company_id LEFT JOIN jobs j ON j.id=a.job_id WHERE a.user_id=? AND a.archived_at IS NULL';
        $args=[$userId];if($status!==null){$sql.=' AND a.status=?';$args[]=$status;}
        $sql.=' ORDER BY a.updated_at DESC,a.id LIMIT '.$limit.' OFFSET '.$offset;
        $q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function statusCounts(PDO $db,int $userId):array {
        self::user($userId);$counts=array_fill_keys(ApplicationData::STATUSES,0);
        $q=$db->prepare('SELECT status,COUNT(*) AS n FROM application_tracks WHERE user_id=? AND archived_at IS NULL GROUP BY status');
        $q->execute([$userId]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)if(array_key_exists($row['status'],$counts))$counts[$row['status']]=(int)$row['n'];
        return $counts;
    }
    public static function one(PDO $db,int $userId,string $trackId,bool $lock=false):?array {
        self::user($userId);ApplicationData::id($trackId);
        $q=$db->prepare('SELECT a.*,c.display_name AS company_name,j.title AS job_title,j.deadline_on FROM application_tracks a JOIN companies c ON c.id=a.company_id LEFT JOIN jobs j ON j.id=a.job_id WHERE a.id=? AND a.user_id=? AND a.archived_at IS NULL'.($lock?' FOR UPDATE':''));
        $q->execute([$trackId,$userId]);$a=$q->fetch(PDO::FETCH_ASSOC);return $a?:null;
    }
    /** One query for company-list cards, avoiding an N+1 database query pattern. */
    public static function companyTrackIndex(PDO $db,int $userId,array $companyIds):array {
        self::user($userId);
        if(count($companyIds)>100)throw new \InvalidArgumentException('Too many companies');
        if($companyIds===[])return [];
        foreach($companyIds as $id)ApplicationData::id($id);
        $marks=implode(',',array_fill(0,count($companyIds),'?'));
        $q=$db->prepare('SELECT id,user_id,company_id,job_id,status,revision FROM application_tracks WHERE user_id=? AND job_id IS NULL AND archived_at IS NULL AND company_id IN ('.$marks.')');
        $q->execute(array_merge([$userId],$companyIds));
        $result=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$result[$row['company_id']]=$row;
        return $result;
    }
    public static function companyTracks(PDO $db,int $userId,string $companyId):array {
        self::user($userId);ApplicationData::id($companyId);
        $q=$db->prepare('SELECT a.*,c.display_name AS company_name,j.title AS job_title,j.deadline_on FROM application_tracks a JOIN companies c ON c.id=a.company_id LEFT JOIN jobs j ON j.id=a.job_id WHERE a.user_id=? AND a.company_id=? AND a.archived_at IS NULL ORDER BY a.updated_at DESC');
        $q->execute([$userId,$companyId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function saveStatus(PDO $db,int $userId,string $companyId,?string $jobId,string $status,?int $expectedRevision=null):string {
        self::user($userId);ApplicationData::id($companyId);if($jobId!==null)ApplicationData::id($jobId);
        ApplicationData::status($status);
        if($db->inTransaction())throw new \RuntimeException('Application status requires its own transaction');
        $db->beginTransaction();
        try {
            // Recheck active account while locked. A stale session must not
            // re-create or advance a private track after account deactivation.
            self::requireActiveWriter($db,$userId);
            $jobKey=$jobId??'';
            $q=$db->prepare('SELECT id,status,revision,archived_at FROM application_tracks WHERE user_id=? AND company_id=? AND job_key=? FOR UPDATE');$q->execute([$userId,$companyId,$jobKey]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if(!$old){
                $q=$db->prepare("SELECT c.id FROM companies c WHERE c.id=? AND (c.visibility='published' OR (c.visibility='draft' AND EXISTS (SELECT 1 FROM seed_entity_states r WHERE r.record_type='companies' AND r.record_id=c.id AND r.seed_version='research-d1.0.4-R06'))) ");$q->execute([$companyId]);
                if(!$q->fetchColumn())throw new \InvalidArgumentException('Company unavailable');
                if($jobId!==null){$q=$db->prepare('SELECT 1 FROM jobs WHERE id=? AND company_id=? AND archived_at IS NULL');$q->execute([$jobId,$companyId]);if(!$q->fetchColumn())throw new \InvalidArgumentException('Job does not belong to the company');}
            }
            // Existing personal records remain editable if a public company/job is later archived.
            if($old){
                if($expectedRevision===null||(int)$old['revision']!==$expectedRevision)throw new \RuntimeException('Stale application state');
                $id=$old['id'];$previous=(string)$old['status'];
                if($previous!==$status||$old['archived_at']!==null){
                    $db->prepare('UPDATE application_tracks SET status=?,archived_at=NULL,revision=revision+1 WHERE id=?')->execute([$status,$id]);
                    self::statusEvent($db,$id,$previous,$status);
                    self::audit($db,$userId,$id,null,'status_changed',['from'=>$previous,'to'=>$status]);
                }
            }else {
                if($expectedRevision!==null)throw new \RuntimeException('Track was not found');
                $id=BusinessData::id();
                $db->prepare('INSERT INTO application_tracks(id,user_id,company_id,job_id,job_key,status) VALUES (?,?,?,?,?,?)')->execute([$id,$userId,$companyId,$jobId,$jobKey,$status]);
                self::statusEvent($db,$id,null,$status);
                self::audit($db,$userId,$id,null,'track_created',['to'=>$status]);
            }
            $db->commit();return $id;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function statusEvent(PDO $db,string $trackId,?string $from,string $to):void {
        $id=BusinessData::id();
        $db->prepare('INSERT INTO application_events(id,track_id,event_kind,title,previous_status,new_status,event_on,event_time) VALUES(?,?,?,?,?,?,UTC_DATE(),UTC_TIME())')->execute([$id,$trackId,'status_change','選考状態の変更',$from,$to]);
    }
    public static function events(PDO $db,int $userId,?string $companyId=null,int $limit=100,int $offset=0,bool $calendar=false,?string $from=null,?string $until=null):array {
        self::user($userId);if($companyId!==null)ApplicationData::id($companyId);
        if($limit<1||$limit>150||$offset<0)throw new \InvalidArgumentException('Pagination');
        $sql='SELECT e.*,a.company_id,a.job_id,a.status,c.display_name AS company_name,j.title AS job_title FROM application_events e JOIN application_tracks a ON a.id=e.track_id JOIN companies c ON c.id=a.company_id LEFT JOIN jobs j ON j.id=a.job_id WHERE a.user_id=? AND a.archived_at IS NULL AND e.deleted_at IS NULL';$args=[$userId];
        if($companyId!==null){$sql.=' AND a.company_id=?';$args[]=$companyId;}
        if($calendar)$sql.=' AND e.event_on IS NOT NULL AND e.event_kind<>\'status_change\'';
        if($from!==null){ApplicationData::date($from);$sql.=' AND e.event_on>=?';$args[]=$from;}
        if($until!==null){ApplicationData::date($until);$sql.=' AND e.event_on<?';$args[]=$until;}
        $sql.=' ORDER BY CASE WHEN e.event_on IS NULL THEN 1 ELSE 0 END,e.event_on DESC,e.event_time DESC,e.created_at DESC LIMIT '.$limit.' OFFSET '.$offset;
        $q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function event(PDO $db,int $userId,string $eventId,bool $lock=false):?array {
        self::user($userId);ApplicationData::id($eventId);
        $q=$db->prepare('SELECT e.* FROM application_events e JOIN application_tracks a ON a.id=e.track_id WHERE e.id=? AND a.user_id=? AND a.archived_at IS NULL AND e.deleted_at IS NULL LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([$eventId,$userId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public static function addEvent(PDO $db,int $userId,string $trackId,array $input):string {
        $fields=ApplicationData::fields($input);
        if($db->inTransaction())throw new \RuntimeException('Application event needs its own transaction');
        $db->beginTransaction();
        try {
            self::requireActiveWriter($db,$userId);
            $track=self::one($db,$userId,$trackId,true);if(!$track)throw new \RuntimeException('Private track not found');
            $id=BusinessData::id();$keys=array_keys($fields);
            $db->prepare('INSERT INTO application_events(id,track_id,'.implode(',',$keys).') VALUES(?,? ,'.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_merge([$id,$trackId],array_values($fields)));
            self::audit($db,$userId,$trackId,$id,'event_created',['kind'=>$fields['event_kind'],'date'=>$fields['event_on']]);
            $db->commit();return $id;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function editEvent(PDO $db,int $userId,string $eventId,array $input,int $revision):void {
        $fields=ApplicationData::fields($input);if($revision<1)throw new \InvalidArgumentException('Revision');
        if($db->inTransaction())throw new \RuntimeException('Application event needs its own transaction');
        $db->beginTransaction();
        try {
            self::requireActiveWriter($db,$userId);
            $old=self::event($db,$userId,$eventId,true);if(!$old||$old['event_kind']==='status_change')throw new \RuntimeException('Event unavailable');
            if((int)$old['revision']!==$revision)throw new \RuntimeException('Stale event');
            $sql='UPDATE application_events SET '.implode(',',array_map(static fn($name)=>$name.'=?',array_keys($fields))).',revision=revision+1 WHERE id=? AND revision=?';
            $q=$db->prepare($sql);$q->execute(array_merge(array_values($fields),[$eventId,$revision]));if($q->rowCount()!==1)throw new \RuntimeException('Concurrent event change');
            self::audit($db,$userId,$old['track_id'],$eventId,'event_edited',['kind'=>$fields['event_kind'],'date'=>$fields['event_on']]);$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function removeEvent(PDO $db,int $userId,string $eventId,int $revision):void {
        if($revision<1)throw new \InvalidArgumentException('Revision');
        if($db->inTransaction())throw new \RuntimeException('Application event needs its own transaction');
        $db->beginTransaction();
        try {
            self::requireActiveWriter($db,$userId);
            $old=self::event($db,$userId,$eventId,true);if(!$old||$old['event_kind']==='status_change'||(int)$old['revision']!==$revision)throw new \RuntimeException('Event unavailable');
            $q=$db->prepare('UPDATE application_events SET deleted_at=UTC_TIMESTAMP(),revision=revision+1 WHERE id=? AND revision=? AND deleted_at IS NULL');$q->execute([$eventId,$revision]);
            if($q->rowCount()!==1)throw new \RuntimeException('Concurrent event change');
            self::audit($db,$userId,$old['track_id'],$eventId,'event_removed',['kind'=>$old['event_kind']]);$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** For immutable status events, only the user-facing event date/time is editable.
     * Original event creation audit records and state transitions stay unchanged. */
    public static function rescheduleEvent(PDO $db,int $uid,string $eventId,array $input,int $revision):void {
        if($revision<1)throw new \InvalidArgumentException('Invalid revision');
        $on=ApplicationData::date($input['event_on']??null);
        $time=ApplicationData::time($input['event_time']??null,$on);
        $db->beginTransaction();
        try{
            self::requireActiveWriter($db,$uid);
            $old=self::event($db,$uid,$eventId,true);
            if(!$old||$old['event_kind']!=='status_change'||(int)$old['revision']!==$revision)throw new \RuntimeException('Status event unavailable or changed');
            $q=$db->prepare('UPDATE application_events SET event_on=?,event_time=?,revision=revision+1 WHERE id=? AND revision=? AND deleted_at IS NULL');
            $q->execute([$on,$time,$eventId,$revision]);
            if($q->rowCount()!==1)throw new \RuntimeException('Concurrent event change');
            self::audit($db,$uid,$old['track_id'],$eventId,'status_event_rescheduled',['old_date'=>$old['event_on'],'new_date'=>$on]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function audit(PDO $db,int $uid,string $trackId,?string $eventId,string $operation,array $details):void {
        $db->prepare('INSERT INTO application_audit(actor_user_id,track_id,event_id,operation,details_json) VALUES (?,?,?,?,?)')->execute([$uid,$trackId,$eventId,$operation,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
    /** Verify a user remains active at the instant a private application write starts.
     * This lock also serializes with account deactivation. No session assertion alone
     * may authorize an in-flight edit once the account has been disabled.
     */
    private static function requireActiveWriter(PDO $db,int $userId):void {
        self::user($userId);
        if(!$db->inTransaction())throw new \RuntimeException('Active applicant check requires a transaction');
        $q=$db->prepare('SELECT status FROM users WHERE id=? FOR UPDATE');
        $q->execute([$userId]);
        if($q->fetchColumn()!=='active')throw new \DomainException('Inactive applicant');
    }
    private static function user(int $uid):void {if($uid<1)throw new \InvalidArgumentException('Authenticated user required');}
}
