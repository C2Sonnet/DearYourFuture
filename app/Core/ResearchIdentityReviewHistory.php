<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/**
 * Read-only, revision-checked provenance of the unapproved d1.0.1 evidence queue.
 * Never a source of verified corporate identity, approval, user overlay or publication.
 */
final class ResearchIdentityReviewHistory {
    public const VERSION='d1.0.1';
    private const EVENT='research.identity.review_state';
    private const REASONS=['missing_evidence','identity_unverified','worksite_unverified',
        'vacancy_unverified','overlay_conflict'];

    public static function listing(PDO $db,array $actor,int $authVersion,string $reviewId,int $page):array {
        $page=ResearchIdentityReviewBoard::page($page);
        if(preg_match('/\Ar-[a-f0-9]{24}\z/D',$reviewId)!==1)
            throw new \DomainException('Invalid research review ID');
        $id=$actor['id']??null;$role=$actor['role']??null;
        if(!is_int($id)||$id<1||!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)||$authVersion<0)
            throw new \DomainException('Administrator privileges required for review history');
        if($db->inTransaction())throw new \RuntimeException('Cannot read history inside a pending write');
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT u.status,u.auth_version,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $q->execute([$id]);$currentActor=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($currentActor)||($currentActor['status']??null)!=='active'
                ||($currentActor['role']??null)!==$role
                ||(int)($currentActor['auth_version']??-1)!==$authVersion)
                throw new \DomainException('Revoked administrator or authentication');
            $q=$db->prepare('SELECT source_record_sha256,state,reason_code,revision,last_actor_id FROM research_identity_review_states WHERE research_version=? AND review_id=? FOR UPDATE');
            $q->execute([self::VERSION,$reviewId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if($row===false){
                // A review audit without its state row is corruption, not a
                // healthy empty history. Detect it before returning untracked.
                $q=$db->prepare('SELECT id FROM audit_logs WHERE event_type=? AND target_type=? AND target_key=? LIMIT 1 FOR UPDATE');
                $q->execute([self::EVENT,'research_delta',$reviewId]);
                if($q->fetch(PDO::FETCH_ASSOC)!==false)
                    throw new \DomainException('Orphaned research review audit');
                $db->commit();
                return self::result($reviewId,$page,0,'untracked',null,[],false);
            }
            if(!is_array($row))throw new \DomainException('Invalid current review state');
            $currentRevision=self::positiveInt($row['revision']??null);
            self::assertState($row['state']??null,$row['reason_code']??null);
            if(!self::sha($row['source_record_sha256']??null)
               ||$currentRevision<1||(int)($row['last_actor_id']??0)<1)
                throw new \DomainException('Corrupted review state metadata');
            $offset=($page-1)*25;
            // The rows are immutable append-only audits. Select only the exact
            // event and target, and hold locks while the current revision is read.
            $sql="SELECT id,actor_id,details_json FROM audit_logs WHERE event_type=? AND target_type=? AND target_key=? ORDER BY id DESC LIMIT 26 OFFSET ".$offset." FOR UPDATE";
            $q=$db->prepare($sql);
            $q->execute([self::EVENT,'research_delta',$reviewId]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($rows)||count($rows)>26)throw new \DomainException('Invalid audit page');
            // The history is dense: exactly one audit for each state revision.
            // This catches missing pages AND unexpected stale/duplicate events.
            if(count($rows)!==min(26,max(0,$currentRevision-$offset)))
                throw new \DomainException('Incomplete or extra review audit events');
            $hasNext=count($rows)===26;
            $events=[];
            $expectedTop=$currentRevision-$offset;
            foreach(array_slice($rows,0,25) as $event){
                if(!is_array($event))throw new \DomainException('Invalid audit row');
                $entry=self::decodeEvent($event,$reviewId,$row['source_record_sha256']);
                if($entry['to_revision']!==$expectedTop)
                    throw new \DomainException('Missing or out-of-order review audit revisions');
                $events[]=$entry;$expectedTop--;
            }
            // Page 1 must have an audit for every state row. A missing audit
            // means an unverifiable transition, never an empty healthy history.
            if($page===1&&$events===[])
                throw new \DomainException('Current review state has no audit');
            if($page>1&&$offset<$currentRevision&&$events===[])
                throw new \DomainException('Missing archived review audit page');
            if($page===1&&$events!==[]){
                $top=$events[0];
                if($top['state']!==$row['state']||$top['reason_code']!==$row['reason_code']
                   ||$top['actor_id']!==(int)$row['last_actor_id'])
                    throw new \DomainException('Current review state does not match last audit');
            }
            $db->commit();
            return self::result($reviewId,$page,$currentRevision,$row['state'],$row['reason_code'],$events,$hasNext);
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function sha(mixed $v):bool {
        return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;
    }
    private static function positiveInt(mixed $v):int {
        if(!is_int($v)&&(!is_string($v)||preg_match('/\A[1-9][0-9]{0,9}\z/D',$v)!==1))
            throw new \DomainException('Invalid audit revision');
        $n=(int)$v;if($n<1||$n>2147483646)throw new \DomainException('Audit revision overflow');
        return $n;
    }
    private static function positiveId(mixed $v):int {
        if(is_int($v)){
            if($v<1)throw new \DomainException('Invalid audit identifier');
            return $v;
        }
        if(!is_string($v)||preg_match('/\A[1-9][0-9]{0,18}\z/D',$v)!==1
           ||strlen($v)>strlen((string)PHP_INT_MAX)
           ||(strlen($v)===strlen((string)PHP_INT_MAX)&&strcmp($v,(string)PHP_INT_MAX)>0))
            throw new \DomainException('Invalid audit identifier');
        return (int)$v;
    }
    private static function assertState(mixed $state,mixed $reason):void {
        if(!is_string($state)||!in_array($state,['evidence_review','needs_evidence'],true)
           ||($state==='needs_evidence'&&!in_array($reason,self::REASONS,true))
           ||($state==='evidence_review'&&$reason!==null))
            throw new \DomainException('Untrusted audit state/reason');
    }
    private static function decodeEvent(array $row,string $reviewId,string $sourceHash):array {
        $actor=self::positiveId($row['actor_id']??null);
        $auditId=self::positiveId($row['id']??null);
        if(!is_string($row['details_json']??null)||strlen($row['details_json'])>8192)
            throw new \DomainException('Invalid audit document');
        $d=json_decode($row['details_json'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($d)||array_is_list($d)
           ||($d['research_version']??null)!==self::VERSION
           ||($d['review_id']??null)!==$reviewId
           ||!is_string($d['source_sha256']??null)
           ||!hash_equals($sourceHash,$d['source_sha256'])
           ||!self::sha($d['upload_sha256']??null)
           ||($d['result']??null)!=='unapproved_internal_evidence_workflow')
            throw new \DomainException('Untrusted review audit metadata');
        $from=$d['from_revision']??null;
        if(!is_int($from)||$from<0||$from>2147483645)
            throw new \DomainException('Invalid source revision');
        $to=$d['to_revision']??null;
        if(!is_int($to)||$to!==$from+1)
            throw new \DomainException('Non-sequential review audit');
        self::assertState($d['state']??null,$d['reason_code']??null);
        // Strict output allow-list: never disclose upload SHA, source values,
        // URL, applicant, company ID or free-form audit keys.
        return ['audit_id'=>$auditId,'actor_id'=>$actor,
            'from_revision'=>$from,'to_revision'=>$to,
            'state'=>$d['state'],'reason_code'=>$d['reason_code']];
    }
    private static function result(string $id,int $page,int $revision,string $state,?string $reason,
                                   array $events,bool $hasNext):array {
        return ['research_version'=>self::VERSION,'review_id'=>$id,'current_revision'=>$revision,
            'current_state'=>$state,'current_reason_code'=>$reason,'page'=>$page,
            'events'=>$events,'has_next'=>$hasNext,'approved'=>false,'published'=>false,
            'notification_queued'=>false,'database_written'=>false];
    }
}
