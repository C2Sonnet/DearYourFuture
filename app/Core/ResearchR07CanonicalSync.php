<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/**
 * Site-approved R07 catalogue -> canonical DB additions. No destructive rebuild.
 * R06 legacy rows and personally edited values are never overwritten.
 * Must be called inside the caller's transaction (initial install or signed update).
 */
final class ResearchR07CanonicalSync {
    public const SEED_VERSION='research-d1.0.4-R07-site';
    /** Pure first-install planning: preserve all old rows; only allocate missing source IDs. */
    public static function planFresh(array $data,array $states,array $companies,array $jobs):array {
        $map=['companies'=>[],'jobs'=>[]];
        foreach($states as $row){
            $kind=$row['record_type']??null;$uuid=$row['record_id']??null;
            if(!isset($map[$kind])||!is_string($uuid)||!preg_match('/\A[a-f0-9]{32}\z/D',$uuid))throw new \RuntimeException('Invalid historical mapping');
            $v=json_decode($row['base_json'],true,128,JSON_THROW_ON_ERROR);
            if(!is_array($v))throw new \RuntimeException('Invalid historical mapping data');
            $ids=[$v[$kind==='companies'?'source_company_id':'source_job_id']??null];
            if($kind==='companies')$ids=array_merge($ids,$v['source_company_aliases']??[]);
            foreach($ids as $id){
                if(!is_string($id)||!($kind==='companies'?ResearchR07::isCompanyId($id):ResearchR07::isJobId($id)))throw new \RuntimeException('Invalid historical source ID');
                if(isset($map[$kind][$id])&&$map[$kind][$id]!==$uuid)throw new \RuntimeException('Conflicting historical source mapping');
                $map[$kind][$id]=$uuid;
            }
            if(!isset(($kind==='companies'?$companies:$jobs)[$uuid]))throw new \RuntimeException('Missing historical canonical row');
        }
        $new=['companies'=>[],'jobs'=>[]];$checkStates=$states;$checkCompanies=$companies;$checkJobs=$jobs;
        foreach($data['companies'] as $row){$id=$row['company_id'];if(isset($map['companies'][$id]))continue;
            $uuid=self::fixedId('company',$id);if(isset($checkCompanies[$uuid]))throw new \RuntimeException('Canonical company UUID collision');
            $map['companies'][$id]=$uuid;$new['companies'][$id]=$uuid;$checkCompanies[$uuid]=['id'=>$uuid];
            $checkStates[]=['record_type'=>'companies','record_id'=>$uuid,'base_json'=>json_encode(['source_company_id'=>$id],JSON_THROW_ON_ERROR)];
        }
        foreach($data['jobs'] as $row){$id=$row['job_id'];if(isset($map['jobs'][$id]))continue;
            $uuid=self::fixedId('job',$id);$parent=$map['companies'][$row['company_id']]??null;
            if(!is_string($parent)||isset($checkJobs[$uuid]))throw new \RuntimeException('Canonical job UUID collision or missing parent');
            $new['jobs'][$id]=['id'=>$uuid,'company_id'=>$parent];$checkJobs[$uuid]=$new['jobs'][$id];
            $checkStates[]=['record_type'=>'jobs','record_id'=>$uuid,'base_json'=>json_encode(['source_job_id'=>$id],JSON_THROW_ON_ERROR)];
        }
        $verified=ResearchBatch79Candidate::mappingPlan($data,$checkStates,$checkCompanies,$checkJobs);
        return ['insert'=>$new,'mapped'=>$verified['mapped'],'existing_rows_overwritten'=>0,'existing_rows_deleted'=>0];
    }
    private static function fixedId(string $type,string $sourceId):string{return hash('md5','kmm:r07-site:'.$type.':'.$sourceId);}
    private const SOURCE='resources/research/r07-batch79-candidate/COMPANY_JOB_RELATIONS_d1.0.4_R07_BATCH79_20261124.json';
    public static function inspectSource():array {
        $raw=file_get_contents(SiteState::root().'/'.self::SOURCE);
        if(!is_string($raw)||strlen($raw)>8000000||!hash_equals(ResearchR07::FILE_SHA256,hash('sha256',$raw)))throw new \RuntimeException('Batch79 source integrity mismatch');
        $data=ResearchR07::data();ResearchBatch79Candidate::projection();ResearchR07Scope::ids();ResearchR07FieldIndex::rows('companies','CO-0001');
        if(json_decode($raw,true,128,JSON_THROW_ON_ERROR)!==$data)throw new \RuntimeException('Batch79 source readback mismatch');
        return ['raw'=>$raw,'data'=>$data];
    }
    private static function rows(PDO $db):array {
        $states=$db->query("SELECT record_type,record_id,base_json FROM seed_entity_states WHERE seed_version IN ('research-d1.0.4-R06','research-d1.0.4-R07-site') AND record_type IN ('companies','jobs') LIMIT 10001")->fetchAll(PDO::FETCH_ASSOC);
        if(count($states)>10000)throw new \RuntimeException('Mapping limit');
        return [$states,array_column($db->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_ASSOC),null,'id'),array_column($db->query('SELECT id,company_id FROM jobs')->fetchAll(PDO::FETCH_ASSOC),null,'id')];
    }
    private static function date(mixed $v):?string {
        if(!is_string($v)||!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D',$v,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return null;return $v;
    }
    private static function text240(string $v):string {if(!preg_match('/\A.{1,240}/us',$v,$m))throw new \RuntimeException('Invalid seed text');return $m[0];}
    /** Initial installation only. Caller owns transaction and must roll back on any failure. */
    public static function sync(PDO $db):array {
        if(!$db->inTransaction())throw new \RuntimeException('Research canonical sync requires outer transaction');
        if($db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)throw new \RuntimeException('PDO exception mode required');
        if((int)$db->query('SELECT COUNT(*) FROM installation_state')->fetchColumn()!==0)throw new \RuntimeException('Fresh seed cannot run after installation');
        $pre=$db->query("SELECT COUNT(*) FROM system_settings WHERE setting_key IN ('research.catalogue.current_snapshot','research.catalogue.current_sha256','research.catalogue.site_approved')")->fetchColumn();
        if((int)$pre!==0)throw new \RuntimeException('Research snapshot already exists');
        ['raw'=>$raw,'data'=>$data]=self::inspectSource();
        [$states,$companies,$jobs]=self::rows($db);
        if(count($states)!==231||count($companies)!==103||count($jobs)!==128)throw new \RuntimeException('Expected pinned R06 canonical seed missing');
        $plan=self::planFresh($data,$states,$companies,$jobs);
        if(count($plan['insert']['companies'])!==13||count($plan['insert']['jobs'])!==23)throw new \RuntimeException('Unexpected Batch79 fresh seed plan');
        $checked=self::date($data['checked_date']??null);if($checked===null)throw new \RuntimeException('Invalid Batch79 date');
        $version=trim((string)file_get_contents(SiteState::root().'/VERSION'));
        if(!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D',$version))throw new \RuntimeException('Invalid site version');
        $co=$db->prepare("INSERT INTO companies(id,display_name,legal_name,description,visibility,created_by,updated_by) VALUES (?,?,?,?, 'published',NULL,NULL)");
        $jo=$db->prepare("INSERT INTO jobs(id,company_id,title,category,graduate_year,recruitment_state,observed_on,deadline_on,note) VALUES (?,?,?,'other',?,'unverified',?,?,?)");
        $map=$db->prepare('INSERT INTO seed_entity_states(seed_key,record_type,record_id,company_id,base_json,seed_version,source_document,source_checked_on) VALUES (?,?,?,?,?,?,?,?)');
        $meta=['source_release'=>'d1.0.4 R07 Batch79','catalogue_sha256'=>ResearchR07::FILE_SHA256];
        $encode=static fn($v)=>json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $write=static function(\PDOStatement $q,array $args):void {if(!$q->execute($args))throw new \RuntimeException('Fresh seed write failed');};
        foreach($plan['insert']['companies'] as $id=>$uuid){$r=ResearchR07::company($id);$name=self::text240(ResearchR07::displayName($r));
            $write($co,[$uuid,$name,$name,'R07サイト収録記録。求人残枠・応募資格を保証しません。']);
            $write($map,['r07-site:companies:'.$id,'companies',$uuid,$uuid,$encode(['source_company_id'=>$id]+$meta),self::SEED_VERSION,self::SOURCE,$checked]);}
        foreach($plan['insert']['jobs'] as $id=>$target){$r=ResearchR07::job($id);$year=0;if(preg_match('/\A(20[0-9]{2})(?:年卒|卒)?\z/u',trim((string)($r['recruitment_year']??'')),$m))$year=(int)$m[1];
            $date=self::date($r['deadline_on']??null)??self::date($r['application_deadline']??null);$uuid=$target['id'];$parent=$target['company_id'];
            $write($jo,[$uuid,$parent,self::text240(ResearchR07::jobTitle($r)),$year,$checked,$date,'R07サイト収録記録。現在募集・応募可能とは限りません。 元ID: '.$id]);
            $write($map,['r07-site:jobs:'.$id,'jobs',$uuid,$parent,$encode(['source_job_id'=>$id,'source_company_id'=>$r['company_id']]+$meta),self::SEED_VERSION,self::SOURCE,$checked]);}
        [$afterStates,$afterCo,$afterJo]=self::rows($db);
        if(count($afterStates)!==267||count($afterCo)!==116||count($afterJo)!==151)throw new \RuntimeException('Fresh seed count mismatch');
        $verified=ResearchBatch79Candidate::mappingPlan($data,$afterStates,$afterCo,$afterJo);
        $setting=$db->prepare('INSERT INTO system_settings(setting_key,value_json) VALUES (?,?)');
        $values=['research.catalogue.current_snapshot'=>$raw,'research.catalogue.current_sha256'=>$encode(ResearchR07::FILE_SHA256),'research.catalogue.site_approved'=>$encode(['release'=>'d1.0.4 R07 Batch79','companies'=>113,'jobs'=>146,'review_status'=>'partial_not_final','production_approved'=>false,'site_version'=>$version,'server_verified_at'=>gmdate('c')])];
        foreach($values as $k=>$v){$write($setting,[$k,$v]);$q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=?');$q->execute([$k]);if($q->fetchColumn()!==$v)throw new \RuntimeException('Fresh snapshot readback mismatch');}
        ResearchR07::invalidateMapping();
        return ['new_companies'=>13,'new_jobs'=>23,'current_companies'=>$verified['mapped']['companies'],'current_jobs'=>$verified['mapped']['jobs']];
    }
}
