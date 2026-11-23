<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/**
 * PRIVATE read-only review of a *proposed*, unverified company seed snapshot.
 * This never verifies a research-company identity and never persists a decision.
 * Only a pinned research preflight's existing-seed proposal may select seed_key.
 */
final class ResearchLiveThreeWayPreview {
    private const VERSION='d1.0.1';
    /** Candidate key is server-derived, NOT independently verified company identity. */
    public static function trustedCandidates(array $preflight):array {
        if(($preflight['research_version']??null)!==self::VERSION
           ||($preflight['counts']['research_records']??null)!==27
           ||($preflight['counts']['approved']??null)!==0
           ||($preflight['database_written']??null)!==false
           ||($preflight['may_approve']??null)!==false
           ||!is_array($preflight['tasks']??null)||count($preflight['tasks'])!==27)
            throw new \DomainException('Only unapproved, pinned research proposals may be inspected');
        $candidates=[];$claimed=[];
        foreach($preflight['tasks'] as $task){
            if(!is_array($task)||!is_string($task['review_id']??null)
               ||preg_match('/\Ar-[a-f0-9]{24}\z/D',$task['review_id'])!==1)
                throw new \DomainException('Untrusted research review ID');
            $proposal=$task['proposed_evidence']??null;
            if(!is_array($proposal)||($proposal['proposal_type']??null)!=='existing_seed_candidate')continue;
            if(in_array($task['priority']??null,['X','HOLD'],true)
               ||($task['approved']??null)!==false
               ||($proposal['approved']??null)!==false
               ||($proposal['live_db_identity_verified']??null)!==false)
                throw new \DomainException('Excluded or already approved proposal is forbidden');
            $seed=SeedData::key($proposal['seed_key_candidate']??'');
            if(isset($claimed[$seed])||isset($candidates[$task['review_id']]))throw new \DomainException('Duplicate seed or review ID');
            $candidates[$task['review_id']]=$seed;$claimed[$seed]=true;
        }
        return $candidates;
    }
    /** The JSON represents a *draft scenario*, never a confirmed research source. */
    public static function proposedSnapshot(mixed $data):array {
        if(!is_array($data)||array_is_list($data)
           ||count($data)!==count(SeedData::FIELDS)
           ||array_diff(array_keys($data),SeedData::FIELDS)!==[])
            throw new \DomainException('Exactly four complete company fields required');
        return SeedData::snapshot($data);
    }
    public static function inspect(PDO $db,array $actor,int $authVersion,array $preview,array $task,
                                   array $serverCandidates,string $seedKey,array $proposed,
                                   int $expectedRevision,int $now):array {
        $id=$actor['id']??null;$role=$actor['role']??null;
        if(!is_int($id)||$id<1||!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)
           ||$authVersion<0||!ResearchIdentityReviewState::validTask($task)||!$task['has_evidence']
           ||!ResearchIdentityReturn::validPreview($preview,$id,$authVersion,$now)
           ||!isset($serverCandidates[$task['review_id']])
           ||!is_string($serverCandidates[$task['review_id']])
           ||!hash_equals($serverCandidates[$task['review_id']],$seedKey)
           ||$expectedRevision<0||$expectedRevision>2147483646)
            throw new \DomainException('Untrusted, stale or unbound seed comparison');
        SeedData::key($seedKey);
        $incoming=self::proposedSnapshot($proposed);
        if($db->inTransaction())throw new \RuntimeException('Cannot inspect inside another transaction');
        $db->beginTransaction();
        try {
            // Held locks provide a consistent inspection snapshot. No writes at all.
            $q=$db->prepare('SELECT u.status,u.auth_version,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $q->execute([$id]);$a=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($a)||($a['status']??null)!=='active'
               ||($a['role']??null)!==$role||(int)($a['auth_version']??-1)!==$authVersion)
                throw new \DomainException('Administrator permissions changed');
            $q=$db->prepare('SELECT s.company_id,s.base_json,s.seed_version,c.seed_key,c.revision,c.display_name,c.legal_name,c.description,c.website_url FROM seed_record_states s JOIN project_seed_records p ON p.seed_key=s.seed_key AND p.company_id=s.company_id JOIN companies c ON c.id=s.company_id AND c.seed_key=s.seed_key WHERE s.seed_key=? FOR UPDATE');
            $q->execute([$seedKey]);$source=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($source)||($source['seed_key']??null)!==$seedKey
               ||!is_string($source['company_id']??null)
               ||preg_match('/\A[a-f0-9]{32}\z/D',$source['company_id'])!==1
               ||!is_string($source['base_json']??null)
               ||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)($source['revision']??''))
               ||(int)$source['revision']>2147483646 )
                throw new \DomainException('Seed is not an independently established DB record');
            $base=json_decode($source['base_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($base))throw new \DomainException('Invalid stored origin baseline');
            $base=SeedData::snapshot($base);
            $current=SeedData::snapshot(array_intersect_key($source,array_flip(SeedData::FIELDS)));
            $q=$db->prepare('SELECT field_key,value_json FROM seed_user_overlays WHERE seed_key=? FOR UPDATE');
            $q->execute([$seedKey]);$raw=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($raw)||count($raw)>count(SeedData::FIELDS))throw new \DomainException('Invalid personal overlay set');
            $overlay=[];
            foreach($raw as $row){
                if(!is_array($row)||!is_string($row['field_key']??null)
                   ||!in_array($row['field_key'],SeedData::FIELDS,true)
                   ||array_key_exists($row['field_key'],$overlay)
                   ||!is_string($row['value_json']??null))
                    throw new \DomainException('Invalid private overlay field');
                $value=json_decode($row['value_json'],true,32,JSON_THROW_ON_ERROR);
                if($value!==null&&!is_string($value))throw new \DomainException('Invalid private overlay value');
                $overlay[$row['field_key']]=$value;
            }
            // External research was never independently ID-verified; this is only a
            // draft/what-if comparison against a real, *seed-bound* DB snapshot.
            $out=ResearchThreeWaySafety::inspect('companies',$base,$incoming,$overlay,$current,
                $expectedRevision,(int)($source['revision']??-1));
            $db->commit();
            return $out+['research_version'=>self::VERSION,'comparison_scope'=>'unverified_seed_draft_only',
                'independent_identity_verified'=>false,'source_officially_verified'=>false,
                'database_written'=>false,'audit_written'=>false];
        } catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
