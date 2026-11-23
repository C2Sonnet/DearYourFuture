<?php
declare(strict_types=1);
namespace KMM\Core;

/**
 * Trust-boundary adapter: information-gathering publishes the verified release;
 * the SITE consumes its claims AS PROVIDED. No duplicate factual research.
 * It checks bytes / data structures ONLY to avoid broken or substituted imports.
 * The result is private site staging; stable DB IDs and publication are distinct.
 */
final class ResearchAcceptedFeed {
    public static function make(string $manifestBytes,string $updatesBytes,string $reportBytes): array {
        $review=ResearchDeltaReview::review($manifestBytes,$updatesBytes,$reportBytes);
        // Preserve the entire released record, including reference tokens and
        // future fields, rather than reconstructing its facts from our schema.
        $original=json_decode($updatesBytes,true,128,JSON_THROW_ON_ERROR);
        $originalRows=array_merge($original['new_companies'],$original['existing_updates']);
        $items=[];
        foreach($review['entries'] as $index=>$record){
            $sourceRow=$originalRows[$index];
            if(!is_array($sourceRow)||hash('sha256',json_encode($sourceRow,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))!==$record['raw_record_sha256'])
                throw new \DomainException('Source row identity changed during import');
            // Source decisions remain authoritative: do not silently re-rank,
            // auto-confirm a vacancy, or discard HOLD / X records.
            $priority=$record['priority'];
            $status=match($priority){
                'X'=>'RESEARCH_EXCLUDED',
                'HOLD'=>'RESEARCH_HOLD',
                default=>'RESEARCH_ACCEPTED',
            };
            $items[]=[
                'review_id'=>$record['review_id'],
                'research_version'=>$record['research_version'],
                'source_record_sha256'=>$record['raw_record_sha256'],
                'record_kind'=>$record['kind'],
                'name'=>$record['name'],
                'priority'=>$priority,
                'research_status'=>$record['research_status'],
                'source_claims'=>$record['source_claims'],
                'source_record'=>$sourceRow, // all source fields retained unchanged; no rewritten facts
                'source_url'=>$record['source_url'],
                'checked_on'=>$record['source_checked_on'],
                'site_data_status'=>$status,
                'research_facts_accepted_as_supplied'=>true,
                'site_fact_reverification_required'=>false,
                'site_identity_mapping_status'=>'UNRESOLVED',
                // An acknowledged research release is NOT evidence of remaining slots,
                // eligibility for a specific person, or permission to override an Overlay.
                'actual_vacancy_guaranteed'=>false,
                'specific_applicant_eligibility_guaranteed'=>false,
            ];
        }
        return [
            'format'=>'kmm-site-accepted-research-feed-v1',
            'subproject'=>'site_creation',
            'research_version'=>$review['research_version'],
            'source_release_verified'=>true,
            'manifest_sha256'=>$review['manifest_sha256'],
            'updates_sha256'=>$review['updates_sha256'],
            'report_sha256'=>$review['report_sha256'],
            'source_checked_on'=>$review['source_date'],
            'data_quality_responsibility'=>'information_gathering_subproject',
            'site_responsibility'=>'schema_mapping_access_control_overlay_conflicts_publication_integrity',
            'requires_duplicate_factual_research'=>false,
            'counts'=>[
                'new'=>$review['counts']['new_candidates'],
                'recheck'=>$review['counts']['existing_rechecks'],
                'total'=>$review['counts']['total'],
                'accepted'=>count(array_filter($items,static fn($r)=>$r['site_data_status']==='RESEARCH_ACCEPTED')),
                'hold'=>count(array_filter($items,static fn($r)=>$r['site_data_status']==='RESEARCH_HOLD')),
                'excluded'=>count(array_filter($items,static fn($r)=>$r['site_data_status']==='RESEARCH_EXCLUDED')),
            ],
            'site_db_written'=>false,
            'site_published'=>false,
            'site_notification_queued'=>false,
            'records'=>$items,
        ];
    }
}
