<?php
declare(strict_types=1);
namespace KMM\Core;

/**
 * Session-bound, immutable point-in-time fingerprint of a *draft* research
 * comparison. This is NOT an attestation of incorporation, recruitment,
 * applicant eligibility or an authority to approve/apply/publish/notify.
 * Raw candidate values and personal overlay values never leave this class.
 */
final class ResearchReviewSnapshot {
    private const VERSION='d1.0.1';
    private const STATUSES=['unchanged','candidate_changes','overlay_conflicts','untracked_live_edit','stale_revision'];

    private static function hex(mixed $value,int $size):bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{'.$size.'}\z/D',$value)===1;
    }
    private static function exactKeys(array $value,array $keys):bool {
        $found=array_keys($value);sort($found);sort($keys);return $found===$keys;
    }
    /** Only known, value-free comparison fields are permitted. */
    private static function fields(mixed $items):array {
        if(!is_array($items)||!array_is_list($items)||count($items)>count(SeedData::FIELDS))
            throw new \DomainException('Untrusted comparison field list');
        $seen=[];
        foreach($items as $item){
            if(!is_string($item)||!in_array($item,SeedData::FIELDS,true)||isset($seen[$item]))
                throw new \DomainException('Untrusted or repeated comparison field');
            $seen[$item]=true;
        }
        $fields=array_keys($seen);sort($fields);return $fields;
    }
    private static function material(array $actor,int $authVersion,array $preview,array $task,
                                     string $seedKey,array $proposed,array $comparison,int $expectedRevision,
                                     int $issuedAt):array {
        $actorId=$actor['id']??null;$role=$actor['role']??null;
        if(!is_int($actorId)||$actorId<1||!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)
           ||$authVersion<0||!ResearchIdentityReviewState::validTask($task)||!$task['has_evidence']
           ||!ResearchIdentityReturn::validPreview($preview,$actorId,$authVersion,$issuedAt)
           ||!self::hex($task['source_record_sha256'],64)
           ||$expectedRevision<0||$expectedRevision>2147483646
           ||!self::exactKeys($comparison,['status','candidate_field_keys','conflict_field_keys',
                'requires_independent_company_identity','requires_live_db_lock_and_recheck',
                'may_approve','may_apply','may_publish','may_notify','research_version',
                'comparison_scope','independent_identity_verified','source_officially_verified',
                'database_written','audit_written'])
           ||!in_array($comparison['status'],self::STATUSES,true)
           ||($comparison['research_version']??null)!==self::VERSION
           ||($comparison['comparison_scope']??null)!=='unverified_seed_draft_only')
            throw new \DomainException('Unverified evidence/DB comparison is not a review snapshot');
        foreach(['may_approve','may_apply','may_publish','may_notify','independent_identity_verified',
                 'source_officially_verified','database_written','audit_written'] as $flag){
            if($comparison[$flag]!==false)throw new \DomainException('Approval or publication flag is forbidden');
        }
        foreach(['requires_independent_company_identity','requires_live_db_lock_and_recheck'] as $flag){
            if($comparison[$flag]!==true)throw new \DomainException('Missing independent review requirement');
        }
        $candidate=self::fields($comparison['candidate_field_keys']);
        $conflict=self::fields($comparison['conflict_field_keys']);
        if(array_intersect($candidate,$conflict)!==[])
            throw new \DomainException('Overlapping comparison categories');
        if(($comparison['status']==='unchanged'||$comparison['status']==='stale_revision'
             ||$comparison['status']==='untracked_live_edit')&&($candidate!==[]||$conflict!==[]))
            throw new \DomainException('Blocked comparison cannot contain unverified fields');
        if($comparison['status']==='overlay_conflicts'&&$conflict===[])
            throw new \DomainException('Conflict category without evidence');
        if($comparison['status']==='candidate_changes'&&($candidate===[]||$conflict!==[]))
            throw new \DomainException('Change category without changes');
        $seed=SeedData::key($seedKey);
        $normalized=ResearchLiveThreeWayPreview::proposedSnapshot($proposed);
        return ['format'=>'kmm-unapproved-research-comparison-v1','research_version'=>self::VERSION,
            'review_id'=>$task['review_id'], 'research_row_sha256'=>$task['source_record_sha256'],
            'evidence_upload_sha256'=>$preview['digest'],
            'server_seed_key_sha256'=>hash('sha256',$seed),
            'proposed_scenario_sha256'=>hash('sha256',json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
            'actor_id'=>$actorId,'auth_version'=>$authVersion,'preview_nonce'=>$preview['nonce'],
            'expected_live_revision'=>$expectedRevision,'checked_at'=>$issuedAt,
            'expires_at'=>min($issuedAt+600,$preview['until']),
            'comparison_status'=>$comparison['status'],'candidate_field_keys'=>$candidate,
            'conflict_field_keys'=>$conflict];
    }
    private static function sign(array $material,string $secret):string {
        if(!self::hex($secret,64))throw new \DomainException('Missing server-only session signing key');
        return hash_hmac('sha256',json_encode($material,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),hex2bin($secret));
    }
    /** Called only AFTER the DB read-only comparison has returned. */
    public static function issue(array $actor,int $authVersion,array $preview,array $task,
                                 string $seedKey,array $proposed,array $comparison,
                                 int $expectedRevision,int $now,string $serverSecret):array {
        $m=self::material($actor,$authVersion,$preview,$task,$seedKey,$proposed,$comparison,$expectedRevision,$now);
        if($m['expires_at']<$now)throw new \DomainException('Expired review snapshot');
        // Do not return proposed values, seed keys, raw source URLs, company IDs or overlay values.
        return ['binding'=>$m,'fingerprint'=>self::sign($m,$serverSecret),
            'approved'=>false,'may_apply'=>false,'may_publish'=>false,'may_notify'=>false,
            'database_written'=>false,'snapshot_only'=>true];
    }
    /** Checks a saved point-in-time artifact, NOT current DB freshness. Must re-read DB for any later action. */
    public static function validate(mixed $snapshot,array $actor,int $authVersion,array $preview,array $task,
                                    string $seedKey,array $proposed,array $comparison,
                                    int $expectedRevision,int $now,string $serverSecret):bool {
        if(!is_array($snapshot)||!self::exactKeys($snapshot,['binding','fingerprint','approved',
             'may_apply','may_publish','may_notify','database_written','snapshot_only'])
           ||!is_array($snapshot['binding'])||!self::hex($snapshot['fingerprint'],64)
           ||$snapshot['approved']!==false||$snapshot['may_apply']!==false
           ||$snapshot['may_publish']!==false||$snapshot['may_notify']!==false
           ||$snapshot['database_written']!==false||$snapshot['snapshot_only']!==true)
            throw new \DomainException('Untrusted stored review snapshot');
        $saved=$snapshot['binding'];
        if(!is_int($saved['checked_at']??null)||($saved['expires_at']??0)<$now||$now<$saved['checked_at'])
            throw new \DomainException('Review snapshot expired or future dated');
        $again=self::material($actor,$authVersion,$preview,$task,$seedKey,$proposed,$comparison,
            $expectedRevision,$saved['checked_at']);
        if($again!==$saved || !hash_equals($snapshot['fingerprint'],self::sign($again,$serverSecret)))
            throw new \DomainException('Research source, actor, scenario or DB revision changed');
        return true;
    }
}
