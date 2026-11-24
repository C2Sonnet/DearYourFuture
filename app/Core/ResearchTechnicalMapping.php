<?php
declare(strict_types=1);
namespace KMM\Core;

/**
 * Round37: private technical ID mapping plan for a RELEASED, already accepted
 * research feed. This deliberately never reassesses source facts or publishes.
 * A proposed seed binding is an operator input, NOT a verified live DB identity.
 * It must be checked against actual DB/overlays under locks before any merge.
 */
final class ResearchTechnicalMapping {
    private const SOURCE_VERSION = 'd1.0.1';
    private const MANIFEST_SHA256 = 'd85f1a8063fc4093173f9c0a06d3257f40e463d918ac48a5394235219a32ad2d';
    private const UPDATES_SHA256 = '2d82c62c1a6f6e2e6725f7271f21f9e957a744a659d918fa0d4b0002ad2ba7d1';
    private const REPORT_SHA256 = '8f00794bc567fcd4926b07fc4ec20cc656c5b039fb974f86ff8ee47b0978d9fc';
    // This list pins the actual original released source rows. Feed uploads cannot
    // inject new site-visible facts merely by rewriting their own hash fields.
    private const SOURCE_ROWS = [
        'r-c55b02caf7127aae2e449bd0'=>'e0301980083e8caadfa7f92eb22aa64d9751d8db897977c3ba06c7abacc951cd',
        'r-c2c46f5dad3fee70ddd21feb'=>'71177d1bb09aa78f00175b662e2fa3033b02cd27abec1382f829aa2edb0bc5ba',
        'r-98889f467718d1c7184376cc'=>'e2b840fe0526526274dda368464d3d64b84e5dcc5fb901742f061275195f8e19',
        'r-59a9009262a4df1d9b55b3b2'=>'b435ad1b7a057f707b9b98d755c1fc456cc30ff6131ed0419fc506ee5dd6aed3',
        'r-0a27aa71d9e3113ae5e07300'=>'14ce289e7c870f7083f809a473677689e604588de95a427d8eb44cebf904724a',
        'r-656ddf57c7e1586ce7b79cb5'=>'545876082b4a3bad8e3c2899882331a686d512e05eb9c3d26da4ae581ec6128b',
        'r-955f8422f76b50c753d9e55f'=>'8de4b83176805dfefed841f51ec802b4c8e45ecf9d42e998f0efcec04a4ccf4f',
        'r-fc8e7493661fc44e6370f547'=>'83e073991483b81634e7b0bdf1f409909fbbb5d39f4283c5118fa11cd74dcc50',
        'r-bd05db5e1348f62466862884'=>'79bc1430d5a8feec662fd697d029cbe323003d27e1ffce1769eccfdc2541d1f3',
        'r-1860fbe7a14965734f524b60'=>'fceaa8514f5c5e9daee32553513123087ace6033f2f0ee65616d78c6609fb162',
        'r-0343de01548738c210401b00'=>'7b22602a1a44dcbbdc834d09a6cc061edf244df3c1b0ce8ac0269647bf2c343c',
        'r-5069899de4aa358ece8c86a0'=>'5d0cc8a8038080717fb23b1e82a1a8ac92152dbc972cd3dae8bf5915a35e2c36',
        'r-38e3c780904a90cfe8fc827a'=>'ed2c1c35345b6ece95dd583ee9fa47a3c98b2268f8980ee230cdfc7bb7b6dc1f',
        'r-31dc0d8b3a9499107022999d'=>'d3eb19e9edf1867d8b5c83a1e357cd83a00d27e8cfaf9d477c898b4091a4b634',
        'r-2b12b921d083fb11a3a3482c'=>'c882648e2dfc4c8d9460e2362f8ea11caec3f7e7b7cb098c0c533c170b71c979',
        'r-d31d521e593b7a1fe22d25d0'=>'ac473e50ad56802f0f071e979b310b4d1bd390c3db0bd874f450e4948491549e',
        'r-45865b8285668a7c8e6393b6'=>'ae53c7795b9134b510595a2b558c144763efdb00ef4017617409156989c177ac',
        'r-e9a5ae02c8e25074027ec91a'=>'e1a0975a31ff561b1bf7b3284b177bc20861c302598634ff1fa25be3b3048df9',
        'r-6459cf7fc7337a93afae301c'=>'d934bc72a0289c4b48c6d754df5d7a4d59dcd8417c8951220580c319c6fce233',
        'r-b2cb9a3188309d66c14a4106'=>'9ce4c3859ae8b4c48160e4bebd72b1907dc675448d2454acc18fdd9eb4fa5c9f',
        'r-d4581b7d694c36cbf759946a'=>'b33e3ce305059fa86dc673752a97a2ff1eec6728ff680679abbf33c8a8480d7c',
        'r-8ad1a627f27d1a5d04cf023b'=>'d865cc52b36a4a159332aca75ff41968a306ae122ad0d2145f4c02b261f1871f',
        'r-e29f97e97ae68e1267337880'=>'954d35ea3c8b891ee2efc48b5f3afb1d467f7d193ce10107a2c29d38bdbea58d',
        'r-f1380a196a9da13e0ea0621d'=>'50f24000d22f00444276b0d8dde85d941f69ed1c62da991bf464a987493967c3',
        'r-95e114645c17d82c41c71b35'=>'474278e79c51d5a729740849b403fb41648164f091c5d82b7af2993882bce41f',
        'r-4c70a1d2588cbf5c9ea08c4a'=>'0c5f5e48f8c2623a9dc8d4ed19ed48294b9801828a7d9e769a82854cfb006e72',
        'r-26fb76a20d4e69658a553c06'=>'f98a7907cfbaecc21f921af961c5aab63a6973687c9d251fc76b9fdc6f723067',
    ];

    /**
     * Live DB **read-only** consistency check on ONLY explicitly operator-mapped
     * catalogue seed candidates. This confirms the stable seed target exists,
     * not that it is the real-world company named in the research release.
     * Overlay originals and company IDs must never leave this method.
     */
    public static function inspectLinkedCandidates(\PDO $db,array $actor,int $authVersion,
                                                    array $feed,array $catalogue,array $bindings):array {
        $plan=self::plan($feed,$catalogue,$bindings);
        $role=$actor['role']??null;$id=$actor['id']??null;
        if(!is_int($id)||$id<1||$authVersion<0||!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true))
            throw new \DomainException('No administrator authorization');
        if($db->inTransaction())throw new \RuntimeException('Nested technical inspection');
        $db->beginTransaction();
        try {
            $auth=$db->prepare('SELECT u.status,u.auth_version,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $auth->execute([$id]);$account=$auth->fetch(\PDO::FETCH_ASSOC);
            if(!is_array($account)||($account['status']??null)!=='active'
               ||($account['role']??null)!==$role||(int)($account['auth_version']??-1)!==$authVersion)
                throw new \DomainException('Administrator was disabled or role changed');
            $checked=[];
            foreach($plan['items'] as $entry){
                $seed=$entry['proposed_seed_key'];if($seed===null)continue;
                $q=$db->prepare('SELECT s.company_id,s.base_json,s.seed_version,c.seed_key,c.revision,c.display_name,c.legal_name,c.description,c.website_url FROM seed_record_states s JOIN project_seed_records p ON p.seed_key=s.seed_key AND p.company_id=s.company_id JOIN companies c ON c.id=s.company_id AND c.seed_key=s.seed_key WHERE s.seed_key=? FOR UPDATE');
                $q->execute([$seed]);$record=$q->fetch(\PDO::FETCH_ASSOC);
                if(!is_array($record)||($record['seed_key']??null)!==$seed
                   ||!is_string($record['company_id']??null)
                   ||preg_match('/\A[a-f0-9]{32}\z/D',$record['company_id'])!==1
                   ||($record['seed_version']??null)!==$catalogue['seed_version']
                   ||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)($record['revision']??''))
                   ||(int)$record['revision']>2147483646
                   ||!is_string($record['base_json']??null))
                    throw new \DomainException('Existing seed target was not found or has inconsistent provenance');
                $decoded=json_decode($record['base_json'],true,32,JSON_THROW_ON_ERROR);
                if(!is_array($decoded))throw new \DomainException('Invalid baseline');
                $base=SeedData::snapshot($decoded);
                $current=SeedData::snapshot(array_intersect_key($record,array_flip(SeedData::FIELDS)));
                $q=$db->prepare('SELECT field_key,value_json FROM seed_user_overlays WHERE seed_key=? FOR UPDATE');
                $q->execute([$seed]);$data=$q->fetchAll(\PDO::FETCH_ASSOC);
                if(!is_array($data)||count($data)>count(SeedData::FIELDS))
                    throw new \DomainException('Invalid overlays');
                $overlays=[];
                foreach($data as $overlay){
                    $field=$overlay['field_key']??null;
                    if(!is_string($field)||!in_array($field,SeedData::FIELDS,true)
                       ||array_key_exists($field,$overlays)||!is_string($overlay['value_json']??null))
                        throw new \DomainException('Invalid overlay field');
                    $val=json_decode($overlay['value_json'],true,32,JSON_THROW_ON_ERROR);
                    if($val!==null&&!is_string($val))throw new \DomainException('Invalid personal value');
                    $overlays[$field]=$val;
                }
                // Baseline vs live current consistency with the user overlay;
                // deliberately NO research-to-4-field projection or actual mutation.
                try { SeedData::plan($base,$base,$overlays,$current); }
                catch(\RuntimeException $e) {throw new \DomainException('Untracked live edit');}
                $checked[]=['review_id'=>$entry['review_id'],
                    'technical_status'=>'LIVE_SEED_PRESENT_NO_COMPANY_IDENTITY_ATTESTATION',
                    'expected_live_revision'=>(int)$record['revision'],
                    'overlay_field_count'=>count($overlays),
                    'require_human_mapping_decision'=>true,
                    'requires_explicit_research_field_projection'=>true,
                    'may_apply'=>false,'may_publish'=>false,'may_notify'=>false];
            }
            $db->commit();
            return ['research_version'=>self::SOURCE_VERSION,'inspected'=>count($checked),
                'links'=>$checked,'db_identity_or_research_fact_attested'=>false,
                'database_written'=>false,'published'=>false,'notification_queued'=>false];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function plan(array $feed,array $catalogue,array $bindings=[]):array {
        if(($feed['format']??null)!=='kmm-site-accepted-research-feed-v1'
           ||($feed['research_version']??null)!==self::SOURCE_VERSION
           ||($feed['source_release_verified']??null)!==true
           ||($feed['data_quality_responsibility']??null)!=='information_gathering_subproject'
           ||($feed['requires_duplicate_factual_research']??null)!==false
           ||($feed['manifest_sha256']??null)!==self::MANIFEST_SHA256
           ||($feed['updates_sha256']??null)!==self::UPDATES_SHA256
           ||($feed['report_sha256']??null)!==self::REPORT_SHA256
           ||($feed['counts']??null)!==['new'=>14,'recheck'=>13,'total'=>27,'accepted'=>23,'hold'=>1,'excluded'=>3]
           ||($feed['site_db_written']??null)!==false
           ||($feed['site_published']??null)!==false
           ||($feed['site_notification_queued']??null)!==false
           ||!is_array($feed['records']??null)||!array_is_list($feed['records'])
           ||count($feed['records'])!==27)
            throw new \DomainException('Accepted release is not the pinned private site cohort');
        if(($catalogue['schema']??null)!==1
           ||($catalogue['seed_version']??null)!=='research-2026-11-18-v1'
           ||($catalogue['publication_rule']??null)!=='initial_drafts_only; independent source review required'
           ||!is_array($catalogue['companies']??null)||!array_is_list($catalogue['companies'])
           ||count($catalogue['companies'])!==10)
            throw new \DomainException('Unexpected local seed catalogue');
        $allowed=[];
        foreach($catalogue['companies'] as $row){
            if(!is_array($row)||!is_string($row['key']??null))throw new \DomainException('Invalid seed row');
            $key=SeedData::key($row['key']);
            if(isset($allowed[$key]))throw new \DomainException('Duplicate seed catalogue key');
            $allowed[$key]=true;
        }
        if(!is_array($bindings)||count($bindings)>23||array_is_list($bindings)&&$bindings!==[])
            throw new \DomainException('Bindings must be a review-ID-to-seed-key object');
        $previous=[];$seenBindings=[];
        foreach($bindings as $rid=>$seed){
            if(!is_string($rid)||!array_key_exists($rid,self::SOURCE_ROWS)
               ||!is_string($seed)||!isset($allowed[$seed])||isset($seenBindings[$seed]))
                throw new \DomainException('Unrecognised or duplicated technical seed proposal');
            $seenBindings[$seed]=true;
        }
        $items=[];$n=['new'=>0,'recheck'=>0,'accepted'=>0,'hold'=>0,'excluded'=>0,
                      'proposed_seed_links'=>0,'pending_unmapped'=>0];
        $seen=[];$staging=[];
        foreach($feed['records'] as $index=>$row){
            if(!is_array($row)||!is_string($row['review_id']??null)
               ||!array_key_exists($row['review_id'],self::SOURCE_ROWS)
               ||isset($seen[$row['review_id']])
               ||!hash_equals(self::SOURCE_ROWS[$row['review_id']],(string)($row['source_record_sha256']??''))
               ||($row['research_version']??null)!==self::SOURCE_VERSION
               ||!is_array($row['source_record']??null)
               ||!is_array($row['source_claims']??null)
               ||!in_array($row['record_kind']??null,['new_candidate','existing_recheck'],true)
               ||($row['record_kind']!==($index<14?'new_candidate':'existing_recheck'))
               ||!is_string($row['priority']??null)
               ||!in_array($row['priority'],['S','A','B','X','HOLD'],true)
               ||($row['source_record']['priority']??null)!==$row['priority']
               ||($row['source_record']['status']??null)!==($row['research_status']??null)
               ||($row['source_record']['url']??null)!==($row['source_url']??null)
               ||($row['site_identity_mapping_status']??null)!=='UNRESOLVED'
               ||($row['research_facts_accepted_as_supplied']??null)!==true
               ||($row['site_fact_reverification_required']??null)!==false
               ||($row['actual_vacancy_guaranteed']??null)!==false
               ||($row['specific_applicant_eligibility_guaranteed']??null)!==false)
                throw new \DomainException('Untrusted or altered accepted feed row');
            $seen[$row['review_id']]=true;
            $sha=hash('sha256',json_encode($row['source_record'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            if(!hash_equals(self::SOURCE_ROWS[$row['review_id']],$sha))
                throw new \DomainException('Original research row was modified');
            foreach($row['source_claims'] as $field=>$value){
                if(!is_string($field)||!array_key_exists($field,$row['source_record'])
                   ||$row['source_record'][$field]!==$value)
                    throw new \DomainException('Research claims do not match original source record');
            }
            $name=$row['record_kind']==='new_candidate' ? ($row['source_record']['japanese']??null) : ($row['source_record']['name']??null);
            if(($row['name']??null)!==$name||!is_string($name)||$name==='')
                throw new \DomainException('Unexpected research display name');
            $status=match($row['priority']){'X'=>'RESEARCH_EXCLUDED','HOLD'=>'RESEARCH_HOLD',default=>'RESEARCH_ACCEPTED'};
            if(($row['site_data_status']??null)!==$status)throw new \DomainException('Research hold/exclude decision changed');
            $bucket=match($status){'RESEARCH_EXCLUDED'=>'excluded','RESEARCH_HOLD'=>'hold',default=>'accepted'};
            $n[$bucket]++;$n[$row['record_kind']==='new_candidate'?'new':'recheck']++;
            $blocked=$bucket!=='accepted';
            $seed=$bindings[$row['review_id']]??null;
            if($blocked&&$seed!==null)throw new \DomainException('HOLD/X must never receive an active target seed');
            if($seed!==null)$n['proposed_seed_links']++;
            if(!$blocked&&$seed===null)$n['pending_unmapped']++;
            // Namespace uses immutable released record ID, not a company name.
            // This ID is for PRIVATE IMPORT STAGING ONLY, never a verified company ID.
            $fingerprint=hash('sha256','kmm:site:research-stage:v1:'.self::SOURCE_VERSION.':'.$row['review_id']);
            $privateKey='kmm-stage-'.substr($fingerprint,0,24);
            if(isset($staging[$privateKey]))throw new \DomainException('Duplicate deterministic stage key');
            $staging[$privateKey]=true;
            $items[]=[
                'review_id'=>$row['review_id'],
                'record_kind'=>$row['record_kind'],
                'priority'=>$row['priority'],
                'research_status'=>$row['research_status'],
                'site_data_status'=>$status,
                'research_source_sha256'=>$sha,
                'private_stage_key'=>$privateKey,
                'private_stage_id'=>substr($fingerprint,0,32),
                'proposed_seed_key'=>$seed,
                'technical_status'=>$blocked?'BLOCKED_AS_RELEASED':($seed===null?'UNMAPPED_PRIVATE_STAGE':'EXPLICIT_SEED_LINK_UNVERIFIED'),
                'requires_live_db_identity_check'=>$seed!==null,
                'requires_overlay_three_way_check'=>!$blocked,
                'requires_factual_reverification'=>false,
                'company_id'=>null,'brand_id'=>null,'job_id'=>null,'worksite_id'=>null,
                'may_write_db'=>false,'may_publish'=>false,'may_notify'=>false,
                // Full original remains in the source feed; this projection does
                // not rewrite facts, add employment assurances or expose raw data.
            ];
        }
        if(count($seen)!==count(self::SOURCE_ROWS)||$n['new']!==14||$n['recheck']!==13
           ||$n['accepted']!==23||$n['hold']!==1||$n['excluded']!==3)
            throw new \DomainException('Incomplete pinned research release');
        return [
            'format'=>'kmm-private-research-technical-plan-v1',
            'research_version'=>self::SOURCE_VERSION,
            'source_manifest_sha256'=>self::MANIFEST_SHA256,
            'source_updates_sha256'=>self::UPDATES_SHA256,
            'site_seed_catalogue_version'=>$catalogue['seed_version'],
            'trust_boundary'=>'research_facts_accepted_as_supplied_technical_mapping_only',
            'counts'=>$n,'items'=>$items,
            'database_accessed'=>false,'technical_seed_links_live_verified'=>false,
            'company_entities_created'=>0,'jobs_created'=>0,'worksites_created'=>0,
            'database_written'=>false,'published'=>false,'notification_queued'=>false,
        ];
    }
}
