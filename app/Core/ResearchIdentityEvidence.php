<?php
declare(strict_types=1);
namespace KMM\Core;

/**
 * Offline, read-only evidence intake for d1.0.1 research identity candidates.
 * Neither an operator's statement nor a URL supplied in a proposal is proof of
 * incorporation, DB identity, job vacancy, eligibility, or permission to publish.
 */
final class ResearchIdentityEvidence {
    private const FORMAT='kmm_research_identity_evidence_proposals_v1';
    private const PLAN='kmm_offline_seed_identity_candidates_v1';
    private const TYPES=['existing_seed_candidate','new_company_candidate','defer'];
    /** @return array<string,mixed> */
    public static function preflight(array $plan,array $catalogue,array $submission):array {
        if(($plan['format']??null)!==self::PLAN || ($plan['research_version']??null)!=='d1.0.1'
           ||($plan['seed_catalogue_version']??null)!=='research-2026-11-18-v1'
           ||($plan['counts']['review_items']??null)!==27 ||($plan['counts']['identity_confirmed']??null)!==0
           ||($plan['database_accessed']??null)!==false ||($plan['may_apply_seed_updates']??null)!==false
           ||($plan['may_queue_notifications']??null)!==false
           ||($plan['identity_suggestions_are_not_approved_mappings']??null)!==true
           ||($plan['all_mappings_require_administrator_review']??null)!==true
           ||($plan['counts']['published']??null)!==0
           ||($plan['counts']['notifications']??null)!==0
           ||!self::sha($plan['seed_catalogue_sha256']??null) ||!is_array($plan['tasks']??null)
           ||!array_is_list($plan['tasks'])||count($plan['tasks'])!==27)
            throw new \DomainException('Only a complete, unapproved read-only identity plan is accepted');
        foreach(['review_manifest_sha256','review_updates_sha256'] as $k){
            if(!self::sha($plan[$k]??null))throw new \DomainException('Missing source digest');
        }
        if(($catalogue['schema']??null)!==1 || ($catalogue['seed_version']??null)!==$plan['seed_catalogue_version']
            ||($catalogue['publication_rule']??null)!=='initial_drafts_only; independent source review required'
            ||!is_array($catalogue['companies']??null)||!array_is_list($catalogue['companies']))
            throw new \DomainException('Untrusted seed catalogue');
        $knownSeeds=[];
        foreach($catalogue['companies'] as $item){
            if(!is_array($item))throw new \DomainException('Malformed catalogue row');
            $key=SeedData::key((string)($item['key']??''));
            if(isset($knownSeeds[$key]))throw new \DomainException('Duplicate seed catalogue key');
            $knownSeeds[$key]=true;
        }
        if(count($knownSeeds)!==($plan['counts']['source_catalogue_seeds']??null))throw new \DomainException('Catalogue count drift');
        $catalogueHash=hash('sha256',json_encode($catalogue,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        if(!hash_equals($plan['seed_catalogue_sha256'],$catalogueHash))throw new \DomainException('Seed catalogue differs from the identity plan');
        self::keys($submission,['format','research_version','seed_catalogue_version',
            'review_manifest_sha256','review_updates_sha256','seed_catalogue_sha256','operator_ticket','proposals']);
        if(($submission['format']??null)!==self::FORMAT
           ||($submission['research_version']??null)!==$plan['research_version']
           ||($submission['seed_catalogue_version']??null)!==$plan['seed_catalogue_version']
           ||!is_string($submission['operator_ticket']??null)
           ||preg_match('/\A[A-Z0-9][A-Z0-9_-]{7,63}\z/D',$submission['operator_ticket'])!==1)
            throw new \DomainException('Missing versioned operator evidence submission');
        foreach(['review_manifest_sha256','review_updates_sha256','seed_catalogue_sha256'] as $k){
            if(!self::sha($submission[$k]??null)
                ||!hash_equals($plan[$k],$submission[$k]))throw new \DomainException('Stale research source: re-review before proposing');
        }
        if(!is_array($submission['proposals']??null)||!array_is_list($submission['proposals'])
           ||count($submission['proposals'])>27)throw new \DomainException('Invalid bounded proposal set');
        $sourceTasks=[];
        foreach($plan['tasks'] as $row){
            if(!is_array($row)||!is_string($row['review_id']??null)
                ||preg_match('/\Ar-[a-f0-9]{24}\z/D',$row['review_id'])!==1
                ||isset($sourceTasks[$row['review_id']])||($row['may_write_to_database']??null)!==false
                ||($row['company_seed_identity_approved']??null)!==false
                ||($row['source_and_job_identity_approved']??null)!==false
                ||($row['overlays_checked']??null)!==false
                ||($row['vacancy_confirmed']??null)!==false
                ||($row['eligibility_confirmed']??null)!==false
                ||($row['tokyo_placement_confirmed']??null)!==false
                ||($row['may_write_to_database']??null)!==false
                ||($row['may_publish']??null)!==false||($row['may_notify']??null)!==false
                ||!self::sha($row['source_record_sha256']??null))
                throw new \DomainException('Invalid underlying research identity plan task');
            // Name matching is ONLY a suggestion, never a verified ID. Reject
            // forged candidate flags or unknown catalogue keys before display.
            foreach(['manual_company_seed_id','manual_brand_id','manual_job_id','manual_worksite_id','manual_reviewer_id'] as $field){
                if(!array_key_exists($field,$row)||$row[$field]!==null)
                    throw new \DomainException('Research task contains a claimed formal identity');
            }
            if(!in_array($row['status']??null,['EXCLUDED_RESEARCH_ONLY','AWAITING_INDIVIDUAL_IDENTITY_REVIEW'],true)
               ||!is_array($row['seed_suggestions']??null)||!array_is_list($row['seed_suggestions'])
               ||count($row['seed_suggestions'])>count($knownSeeds))
                throw new \DomainException('Unsafe identity suggestion state');
            $seenSuggestions=[];
            foreach($row['seed_suggestions'] as $suggestion){
                if(!is_array($suggestion)||array_is_list($suggestion)
                   ||!is_string($suggestion['seed_key_candidate']??null)
                   ||!isset($knownSeeds[$suggestion['seed_key_candidate']])
                   ||isset($seenSuggestions[$suggestion['seed_key_candidate']])
                   ||($suggestion['confidence']??null)!=='NAME_SIMILARITY_ONLY'
                   ||($suggestion['identity_verified']??null)!==false
                   ||($suggestion['exists_in_live_database']??null)!==false)
                    throw new \DomainException('Unverified name hint claims a formal company identity');
                $seenSuggestions[$suggestion['seed_key_candidate']]=true;
            }
            $sourceTasks[$row['review_id']]=$row;
        }
        $submitted=[];$claimedSeeds=[];
        foreach($submission['proposals'] as $proposal){
            if(!is_array($proposal)||array_is_list($proposal))throw new \DomainException('Invalid proposal object');
            self::keys($proposal,['review_id','proposal_type','seed_key','record_sha256',
                'evidence_url','evidence_checked_on','evidence_note']);
            $id=$proposal['review_id']??null;
            if(!is_string($id)||!isset($sourceTasks[$id])||isset($submitted[$id]))
                throw new \DomainException('Unknown or duplicate research record');
            $row=$sourceTasks[$id];
            $type=$proposal['proposal_type']??null;
            if(!in_array($type,self::TYPES,true))throw new \DomainException('Unsupported proposal type');
            if(!self::sha($proposal['record_sha256']??null)
                ||!hash_equals($row['source_record_sha256'],$proposal['record_sha256']))
                throw new \DomainException('Stale research row: compare against latest record');
            $seed=$proposal['seed_key']??null;
            if($type==='existing_seed_candidate'){
                if(!is_string($seed)||!isset($knownSeeds[$seed])||isset($claimedSeeds[$seed]))
                    throw new \DomainException('Unknown or multiply proposed seed');
                $claimedSeeds[$seed]=$id;
            }elseif($seed!==null){
                throw new \DomainException('Defer/new-company evidence must not generate or choose a seed ID');
            }
            if(in_array($row['priority'],['X','HOLD'],true)&&$type!=='defer')
                throw new \DomainException('Excluded and hold research entries may only be deferred');
            $note=$proposal['evidence_note']??null;
            if(!is_string($note)||strlen(trim($note))<8||strlen($note)>1500)
                throw new \DomainException('Traceable, bounded evidence note is required');
            $date=$proposal['evidence_checked_on']??null;
            if(!is_string($date)||preg_match('/\A20\d{2}-\d{2}-\d{2}\z/D',$date)!==1
                ||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))
                ||$date<'2026-11-18'||$date>gmdate('Y-m-d',time()+9*3600))
                throw new \DomainException('Invalid evidence date');
            $url=$proposal['evidence_url']??null;
            if($type==='defer'){
                if($url!==null)throw new \DomainException('Deferred proposal must not imply external evidence');
            }else self::https($url);
            $submitted[$id]=[
                'proposal_type'=>$type,'seed_key_candidate'=>$seed,
                'record_sha256'=>$row['source_record_sha256'],
                'evidence_url'=>$url,'evidence_checked_on'=>$date,
                'evidence_note'=>$note,
                'evidence_independently_verified'=>false,
                'live_db_identity_verified'=>false,
                'user_overlay_compared'=>false,
                'rbac_csrf_recent_auth_verified'=>false,
                'approved'=>false,'may_publish'=>false,'may_notify'=>false,
            ];
        }
        $tasks=[];
        foreach($plan['tasks'] as $row){
            $id=$row['review_id'];
            $tasks[]=[
                'review_id'=>$id,'research_name'=>$row['research_name'],
                'priority'=>$row['priority'],'candidate_seed_from_name_only'=>$row['seed_suggestions'],
                'proposed_evidence'=>$submitted[$id]??null,
                'stage'=>isset($submitted[$id])?'UNVERIFIED_EVIDENCE_AWAITING_HUMAN_AND_LIVE_DB':'AWAITING_EVIDENCE',
                'company_id'=>null,'brand_id'=>null,'job_id'=>null,'worksite_id'=>null,
                'verified'=>false,'approved'=>false,'may_publish'=>false,'may_notify'=>false,
            ];
        }
        return [
            'format'=>'kmm_research_identity_evidence_preflight_only_v1',
            'site_target_version'=>trim((string)file_get_contents(SiteState::root().'/VERSION')),
            'research_version'=>'d1.0.1',
            'operator_ticket'=>$submission['operator_ticket'],
            'source_manifest_sha256'=>$plan['review_manifest_sha256'],
            'source_updates_sha256'=>$plan['review_updates_sha256'],
            'seed_catalogue_version'=>$plan['seed_catalogue_version'],
            'source_seed_catalogue_sha256'=>$plan['seed_catalogue_sha256'],
            'counts'=>['research_records'=>27,'evidence_proposals'=>count($submitted),
                'awaiting_evidence'=>27-count($submitted),'confirmed_company_id'=>0,
                'approved'=>0,'published'=>0,'notification_queued'=>0],
            'all_evidence_requires_independent_verification'=>true,
            'must_verify_live_db_identity_and_user_overlay'=>true,
            'database_connected'=>false,'database_written'=>false,
            'review_authorization_checked'=>false,'may_approve'=>false,
            'may_write_to_database'=>false,'may_publish'=>false,'may_notify'=>false,
            'tasks'=>$tasks,
        ];
    }
    private static function sha(mixed $v):bool {
        return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;
    }
    private static function keys(array $v,array $allowed):void {
        foreach(array_keys($v) as $k){if(!is_string($k)||!in_array($k,$allowed,true))
            throw new \DomainException('Unsupported evidence field (possible approval smuggling)');}
        foreach($allowed as $k){if(!array_key_exists($k,$v))throw new \DomainException('Required evidence field missing');}
    }
    private static function https(mixed $url):void {
        if(!is_string($url)||strlen($url)<12||strlen($url)>2048||preg_match('/[\x00-\x20\\\\]/',$url))
            throw new \DomainException('Invalid evidence source URL');
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower($parts['scheme']??'')!=='https'
           ||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])
           ||isset($parts['fragment'])||filter_var($url,FILTER_VALIDATE_URL)===false)
           throw new \DomainException('Evidence must be an HTTPS source URL without credentials or fragment');
    }
}
