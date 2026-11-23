<?php
declare(strict_types=1);
namespace KMM\Core;

/**
 * Offline, read-only identity *suggestions* against a source-control seed catalogue.
 * This is NOT a live database lookup, approval, seed import or publication procedure.
 */
final class ResearchIdentityReconciliation {
    private const SOURCE_VERSION='d1.0.1';
    private const SEED_VERSION='research-2026-11-18-v1';

    public static function plan(array $review,array $seedCatalogue):array {
        if (($review['format']??null)!=='kmm_research_delta_review_only_v1'
            ||($review['research_version']??null)!==self::SOURCE_VERSION
            ||($review['review_required']??null)!==true
            ||($review['database_written']??null)!==false
            ||($review['published']??null)!==false
            ||($review['notification_queued']??null)!==false)
            throw new \DomainException('Reviewed source has not passed the non-public research boundary');
        if (($review['counts']['total']??null)!==27
            ||!is_array($review['entries']??null)
            ||!array_is_list($review['entries'])||count($review['entries'])!==27)
            throw new \DomainException('Expected the complete 27-record research release');
        if (($seedCatalogue['schema']??null)!==1
            ||($seedCatalogue['seed_version']??null)!==self::SEED_VERSION
            ||($seedCatalogue['publication_rule']??null)!=='initial_drafts_only; independent source review required')
            throw new \DomainException('Unexpected seed catalogue identity or publication policy');
        if (!is_array($seedCatalogue['companies']??null)||!array_is_list($seedCatalogue['companies'])
            ||count($seedCatalogue['companies'])<1||count($seedCatalogue['companies'])>250)
            throw new \DomainException('Invalid seed catalogue');
        $seeds=[];
        foreach($seedCatalogue['companies'] as $item){
            if(!is_array($item))throw new \DomainException('Malformed seed catalogue row');
            $key=SeedData::key((string)($item['key']??''));
            $name=BusinessData::text($item['display_name']??null,240,true);
            if(isset($seeds[$key]))throw new \DomainException('Duplicate catalogue seed key');
            $seeds[$key]=$name;
        }
        ksort($seeds,SORT_STRING);
        $rows=[];$seen=[];$suggested=0;$excluded=0;$onHold=0;$new=0;
        foreach($review['entries'] as $entry){
            if(!is_array($entry)||!is_string($entry['review_id']??null)
                ||!preg_match('/\Ar-[a-f0-9]{24}\z/D',$entry['review_id'])
                ||isset($seen[$entry['review_id']])
                ||!in_array($entry['kind']??null,['new_candidate','existing_recheck'],true)
                ||!is_string($entry['name']??null)||trim($entry['name'])===''
                ||($entry['research_version']??null)!==self::SOURCE_VERSION
                ||!in_array($entry['priority']??null,['S','A','B','HOLD','X'],true)
                ||($entry['requires_manual_seed_identity_match']??null)!==true
                ||($entry['may_autopublish']??null)!==false
                ||($entry['may_notify_users']??null)!==false
                ||($entry['job_vacancy_confirmed']??null)!==false
                ||($entry['applicant_eligibility_confirmed']??null)!==false)
                throw new \DomainException('Research record is not safe for manual reconciliation');
            $seen[$entry['review_id']]=true;
            $isExcluded=$entry['priority']==='X';
            if($isExcluded && ($entry['workflow_state']??null)!=='EXCLUDED_FROM_TOKYO_CANDIDATES')
                throw new \DomainException('Excluded research record has inconsistent workflow state');
            if(!$isExcluded && ($entry['workflow_state']??null)!=='PENDING_MANUAL_REVIEW')
                throw new \DomainException('Unapproved record has unexpected workflow state');
            $excluded+=(int)$isExcluded;
            $onHold+=(int)($entry['priority']==='HOLD');
            $new+=(int)($entry['kind']==='new_candidate');
            $candidates=[];
            // Company-name matches only suggest manual review. No stable ID is trusted without
            // an authenticated administrator's explicit mapping and independent DB verification.
            foreach($seeds as $key=>$catalogName){
                if(self::nameCouldReferTo($entry['name'],$catalogName)){
                    $candidates[]=['seed_key_candidate'=>$key,'catalogue_display_name'=>$catalogName,
                                   'confidence'=>'NAME_SIMILARITY_ONLY',
                                   'identity_verified'=>false,'exists_in_live_database'=>false];
                }
            }
            $suggested+=(int)!!$candidates;
            $rows[]=[
                'review_id'=>$entry['review_id'], 'research_name'=>$entry['name'],
                'kind'=>$entry['kind'],'priority'=>$entry['priority'],
                'source_url'=>$entry['source_url']??null,
                'research_status'=>$entry['research_status']??null,
                'source_record_sha256'=>$entry['raw_record_sha256']??null,
                'seed_suggestions'=>$candidates,
                'manual_company_seed_id'=>null,'manual_brand_id'=>null,
                'manual_job_id'=>null,'manual_worksite_id'=>null,
                'manual_reviewer_id'=>null,'company_seed_identity_approved'=>false,
                'source_and_job_identity_approved'=>false,
                'overlays_checked'=>false,'vacancy_confirmed'=>false,
                'eligibility_confirmed'=>false,'tokyo_placement_confirmed'=>false,
                'status'=>$isExcluded?'EXCLUDED_RESEARCH_ONLY':'AWAITING_INDIVIDUAL_IDENTITY_REVIEW',
                'allowed_actions'=>['read','propose_manual_mapping'],
                'may_write_to_database'=>false,'may_publish'=>false,'may_notify'=>false,
            ];
        }
        if($new!==14 ||$excluded!==3 ||$onHold!==1)
            throw new \DomainException('Research cohort or exclusion count changed');
        return [
            'format'=>'kmm_offline_seed_identity_candidates_v1',
            'site_target_version'=>trim((string)file_get_contents(SiteState::root().'/VERSION')),
            'research_version'=>self::SOURCE_VERSION,
            'seed_catalogue_version'=>self::SEED_VERSION,
            'seed_catalogue_sha256'=>hash('sha256',json_encode($seedCatalogue,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            'basis'=>'source_control_seed_catalogue_not_live_db',
            'review_manifest_sha256'=>$review['manifest_sha256']??null,
            'review_updates_sha256'=>$review['updates_sha256']??null,
            'counts'=>['review_items'=>27,'source_catalogue_seeds'=>count($seeds),
                       'items_with_name_suggestions'=>$suggested,
                       'items_without_name_suggestions'=>27-$suggested,
                       'excluded'=>$excluded,'hold'=>$onHold,
                       'identity_confirmed'=>0,'published'=>0,'notifications'=>0],
            'database_accessed'=>false,'all_mappings_require_administrator_review'=>true,
            'identity_suggestions_are_not_approved_mappings'=>true,
            'may_apply_seed_updates'=>false,'may_queue_notifications'=>false,
            'tasks'=>$rows,
        ];
    }
    private static function nameCouldReferTo(string $research,string $catalogue):bool {
        // Deliberately weak heuristic, NEVER an automatic merge decision.
        $clean=static function(string $s):string {
            $s=trim($s);
            $s=preg_replace('/(?:株式会社|有限会社|（株）|\(株\)|\s|　|・|－|-|\.)/u','',$s)??$s;
            return $s;
        };
        $r=$clean($research);$c=$clean($catalogue);
        if(strlen($r)<9 ||strlen($c)<9)return $r===$c && strlen($r)>=4;
        // A documented brand or legal name embedded in a catalogue company name
        // is only an investigative hint. Exclude short ASCII abbreviations.
        return $r===$c || (strlen($r)>=9 && str_contains($c,$r));
    }
}
