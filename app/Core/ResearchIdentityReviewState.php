<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/**
 * Persistent, item-scoped evidence queue, explicitly NOT a verified company-ID
 * decision or source update. All mutations and audits share one transaction.
 * This version handles only pinned d1.0.1 items from a validated server preview.
 */
final class ResearchIdentityReviewState {
    private const STATES=['evidence_review','needs_evidence'];
    private const REASONS=['missing_evidence','identity_unverified','worksite_unverified',
        'vacancy_unverified','overlay_conflict'];
    private const VERSION='d1.0.1';
    private const EVENT='research.identity.review_state';

    public static function validTask(mixed $task):bool {
        return is_array($task)&&count($task)===3
            &&is_string($task['review_id']??null)
            &&preg_match('/\Ar-[a-f0-9]{24}\z/D',$task['review_id'])===1
            &&self::sha($task['source_record_sha256']??null)
            &&is_bool($task['has_evidence']??null);
    }
    public static function trustedTasks(array $preflight):array {
        if(($preflight['research_version']??null)!==self::VERSION
           ||($preflight['counts']['research_records']??null)!==27
           ||($preflight['counts']['approved']??null)!==0
           ||($preflight['may_approve']??null)!==false
           ||($preflight['database_written']??null)!==false
           ||!is_array($preflight['tasks']??null)||count($preflight['tasks'])!==27)
            throw new \DomainException('Invalid immutable research preflight');
        $tasks=[];
        foreach($preflight['tasks'] as $row){
            $task=['review_id'=>$row['review_id']??null,
                'source_record_sha256'=>$row['proposed_evidence']['record_sha256']??null,
                'has_evidence'=>is_array($row['proposed_evidence']??null)
                    &&in_array($row['proposed_evidence']['proposal_type']??null,
                        ['existing_seed_candidate','new_company_candidate'],true)
                    &&!in_array($row['priority']??null,['X','HOLD'],true)];
            if(!$task['has_evidence']){
                // Do not stage deferred, excluded or held source rows.
                continue;
            }
            if(!self::validTask($task)||isset($tasks[$task['review_id']]))throw new \DomainException('Invalid review task');
            $tasks[$task['review_id']]=$task;
        }
        return $tasks;
    }
    private static function sha(mixed $v):bool{return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}

    /** Read the durable item revision without editing it. Current actor must remain active. */
    public static function read(PDO $db,array $actor,int $authVersion,array $preview,array $task,int $now):array {
        return self::operate($db,$actor,$authVersion,$preview,$task,$now,null,null,null);
    }
    /** Optimistic concurrency: expected revision 0 means the item has never been created. */
    public static function transition(PDO $db,array $actor,int $authVersion,array $preview,array $task,
                                      int $expectedRevision,string $target,?string $reason,int $now):array {
        if($expectedRevision<0||$expectedRevision>2147483646||!in_array($target,self::STATES,true))
            throw new \DomainException('Invalid expected revision or unapproved target status');
        if($target==='needs_evidence'&&!in_array($reason,self::REASONS,true))
            throw new \DomainException('Choose a fixed return reason');
        if($target==='evidence_review'&&$reason!==null)
            throw new \DomainException('Evidence review cannot carry a return reason');
        return self::operate($db,$actor,$authVersion,$preview,$task,$now,$expectedRevision,$target,$reason);
    }
    private static function operate(PDO $db,array $actor,int $authVersion,array $preview,array $task,int $now,
                                    ?int $expected,?string $target,?string $reason):array {
        $id=$actor['id']??null;$role=$actor['role']??null;
        if(!is_int($id)||$id<1||!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)
           ||!self::validTask($task)
           ||!ResearchIdentityReturn::validPreview($preview,$id,$authVersion,$now))
            throw new \DomainException('Expired or untrusted per-item review');
        if($db->inTransaction())throw new \RuntimeException('Nested research review transaction');
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT u.status,u.auth_version,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $q->execute([$id]);$actorRow=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($actorRow)||($actorRow['status']??null)!=='active'
               ||($actorRow['role']??null)!==$role
               ||(int)($actorRow['auth_version']??-1)!==$authVersion)
                throw new \DomainException('Administrator permission or authentication revoked');
            $q=$db->prepare('SELECT source_record_sha256,evidence_upload_sha256,state,reason_code,revision FROM research_identity_review_states WHERE research_version=? AND review_id=? FOR UPDATE');
            $q->execute([self::VERSION,$task['review_id']]);$old=$q->fetch(PDO::FETCH_ASSOC);
            $oldRevision=is_array($old)?(int)$old['revision']:0;
            if(is_array($old)&&(!in_array($old['state'],self::STATES,true)
                ||$oldRevision<1||$oldRevision>2147483646))
                throw new \DomainException('Corrupt item review state');
            if(is_array($old)&&!hash_equals($old['source_record_sha256'],$task['source_record_sha256']))
                throw new \DomainException('Research source changed; new version review required');
            if($target===null){$db->commit();return self::result($old,$task['review_id'],false);}
            if($oldRevision!==$expected)throw new \DomainException('Review revision conflict: reload item state');
            // A return can only follow a prior evidence review. New records must have
            // a submitted evidence proposal; this is an *internal queue*, not approval.
            if($target==='evidence_review'&&!$task['has_evidence'])
                throw new \DomainException('Evidence proposal missing');
            if(!is_array($old)&&$target!=='evidence_review')
                throw new \DomainException('Cannot return an item that has not been reviewed');
            if(is_array($old)&&$old['state']==='needs_evidence'&&$target==='evidence_review'
               &&hash_equals($old['evidence_upload_sha256'],$preview['digest']))
                throw new \DomainException('Returned evidence requires a changed upload');
            $newDigest=$preview['digest'];
            if(is_array($old)&&$target==='needs_evidence'
               &&$old['state']==='needs_evidence'
               &&$old['reason_code']===$reason
               &&hash_equals($old['evidence_upload_sha256'],$newDigest)){
                $db->commit();return self::result($old,$task['review_id'],false);
            }
            if(is_array($old)&&$target==='evidence_review'
               &&$old['state']==='evidence_review'
               &&hash_equals($old['evidence_upload_sha256'],$newDigest)){
                $db->commit();return self::result($old,$task['review_id'],false);
            }
            $revision=$oldRevision+1;
            if($old===false){
                $db->prepare('INSERT INTO research_identity_review_states(research_version,review_id,source_record_sha256,evidence_upload_sha256,state,reason_code,revision,last_actor_id) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([self::VERSION,$task['review_id'],$task['source_record_sha256'],$newDigest,$target,$reason,$revision,$id]);
            }else{
                $q=$db->prepare('UPDATE research_identity_review_states SET evidence_upload_sha256=?,state=?,reason_code=?,revision=revision+1,last_actor_id=? WHERE research_version=? AND review_id=? AND revision=?');
                $q->execute([$newDigest,$target,$reason,$id,self::VERSION,$task['review_id'],$expected]);
                if($q->rowCount()!==1)throw new \DomainException('Concurrent reviewer changed the row');
            }
            // No original source text, URLs, human names or applicant data in audit.
            $details=json_encode(['research_version'=>self::VERSION,'review_id'=>$task['review_id'],
                'source_sha256'=>$task['source_record_sha256'],'upload_sha256'=>$newDigest,
                'from_revision'=>$oldRevision,'to_revision'=>$revision,
                'state'=>$target,'reason_code'=>$reason,'result'=>'unapproved_internal_evidence_workflow'],JSON_THROW_ON_ERROR);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                ->execute([$id,self::EVENT,'research_delta',$task['review_id'],$details]);
            $db->commit();
            return ['review_id'=>$task['review_id'],'revision'=>$revision,'state'=>$target,
                'reason_code'=>$reason,'changed'=>true,'approved'=>false,'published'=>false,'notification_queued'=>false];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function result(mixed $row,string $reviewId,bool $changed):array {
        return ['review_id'=>$reviewId,'revision'=>is_array($row)?(int)$row['revision']:0,
            'state'=>is_array($row)?$row['state']:'untracked',
            'reason_code'=>is_array($row)?$row['reason_code']:null,'changed'=>$changed,
            'approved'=>false,'published'=>false,'notification_queued'=>false];
    }
}
