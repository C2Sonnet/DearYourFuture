<?php
declare(strict_types=1);
namespace KMM\Core;
/** Pinned research metadata; research review status is independent of software release numbering. */
final class ResearchBatch79Candidate {
    private const PIN='2c77d48d7e8050143b1ce3c868fd5fdc63d872dd39fd1467a281e14395113af2';
    private static ?array $projection=null;
    public static function pin():array {
        $path=SiteState::root().'/resources/research/r07-batch79-candidate/candidate_pin.json';$raw=file_get_contents($path);
        if(!is_string($raw)||!hash_equals(self::PIN,hash('sha256',$raw)))throw new \RuntimeException('Candidate pin mismatch');
        $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if($p['production_approved']!==false||$p['source_status']!=='partial_not_final'||$p['source_sha256']!=='6d42e6162cb649fdb00cfb17d9b5b1b116b214180e810dfe4b3518f3e3a421cc')throw new \RuntimeException('Invalid candidate metadata');
        return $p;
    }
    public static function projection():array {
        $p=self::pin();if(self::$projection!==null)return self::$projection;
        $raw=file_get_contents(SiteState::root().'/resources/research/r07-batch79-candidate/projection.json');
        if(!is_string($raw)||!hash_equals($p['projection_sha256'],hash('sha256',$raw)))throw new \RuntimeException('Projection integrity mismatch');
        $v=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
        if($v['production_approved']!==false||$v['source_sha256']!=='6d42e6162cb649fdb00cfb17d9b5b1b116b214180e810dfe4b3518f3e3a421cc'||count($v['companies'])!==112||count($v['jobs'])!==145||count($v['comparison'])!==41)throw new \RuntimeException('Invalid projection');
        foreach(array_merge($v['jobs'],$v['comparison']) as $r)foreach(['active_slots_verified','personal_visa_verified','initial_assignment_guaranteed','strict_40min_verified','current_receipt_verified'] as $key)if(($r[$key]??null)!==false)throw new \RuntimeException('Unconfirmed condition changed');
        return self::$projection=$v;
    }
    private static function flag(mixed $v):bool {return match($v){true,'True'=>true,false,'False'=>false,default=>throw new \RuntimeException('Invalid research boolean')};}
    private static function countValue(mixed $v):int {if(is_int($v)&&$v>=0)return $v;if(is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)&&strlen($v)<10)return (int)$v;throw new \RuntimeException('Invalid research count');}
    private static function hoursValue(mixed $v):float {
        if(!(is_int($v)||is_float($v)||(is_string($v)&&preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D',$v))))throw new \RuntimeException('Invalid research hours');
        $n=(float)$v;if(!is_finite($n)||$n<0)throw new \RuntimeException('Invalid research hours');return $n;
    }
    /** Compare only fields present in the prior compatibility schema; new audit-only notes do not generate notifications. */
    public static function weeklyViews(array $d):array {
        $legacy=$d['r07_batch65_handover']??[];$old=['companies'=>$legacy['companies']??[],'jobs'=>$legacy['jobs']??[],'comparison'=>$d['r07_batch66_comparison']['comparison']??[]];
        $latest=$d['r07_batch79_final_public_scope']??null;
        $modern=$d['site_latest_views']??null;
        if($modern!==null){
            if(!is_array($modern)||array_map('count',$modern)!==['companies'=>145,'jobs'=>180,'comparison'=>79])throw new \RuntimeException('Invalid d2.0.0 weekly views');
            $out=[];foreach($old as $kind=>$template){
                $keys=array_keys($template[0]??[]);if(!$keys)throw new \RuntimeException('Missing comparison schema');$out[$kind]=[];
                foreach($modern[$kind] as $row){$r=[];foreach($keys as $k){$v=$row[$k]??null;if(in_array($k,['active_slots_verified','personal_visa_verified','initial_assignment_guaranteed','strict_40min_verified'],true)&&$v!==false)throw new \RuntimeException('Unexpected verified flag');if($k==='fixed_overtime_hours_record'&&$v!==null)$v=self::hoursValue($v);$r[$k]=$v;} $out[$kind][]=$r;}
            }return $out;
        }
        $out=[];foreach($old as $kind=>$template){
            $rows=$latest===null?$template:($latest[match($kind){'companies'=>'latest_company_view','jobs'=>'latest_job_view','comparison'=>'latest_comparison'}]??throw new \RuntimeException('Latest research view missing'));
            if($latest!==null&&$template===[])throw new \RuntimeException('Prior semantic schema missing');
            $keys=array_keys($template[0]??[]);$out[$kind]=[];
            foreach($rows as $row){$r=[];foreach($keys as $k){$v=$row[$k]??null;
                if(in_array($k,['active_slots_verified','personal_visa_verified','initial_assignment_guaranteed','strict_40min_verified'],true))$v=self::flag($v);
                if(in_array($k,['job_hold_count','company_hold_count','jobs','priority_confirmation','conditional','excluded','hold'],true))$v=self::countValue($v);
                if(in_array($k,['reference_monthly_jpy','fixed_overtime_jpy_record','annual_holidays_record','fixed_overtime_hours_record'],true))$v=$v===null||$v===''?null:($k==='fixed_overtime_hours_record'?self::hoursValue($v):self::countValue($v));
                $r[$k]=$v;
            }$out[$kind][]=$r;}
        }return $out;
    }
    /** Validate installed mappings against actual canonical rows; never propose a repair by name. */
    public static function mappingPlan(array $data,array $states,array $companies,array $jobs):array {
        $map=['companies'=>[],'jobs'=>[]];
        foreach($states as $row){$kind=$row['record_type']??'';if(!isset($map[$kind]))continue;
            $v=json_decode((string)$row['base_json'],true,128,JSON_THROW_ON_ERROR);$uuid=$row['record_id']??null;
            if(!is_array($v)||!is_string($uuid)||!preg_match('/\A[a-f0-9]{32}\z/D',$uuid))throw new \RuntimeException('Malformed installed mapping');
            $ids=[$v[$kind==='companies'?'source_company_id':'source_job_id']??null];
            if($kind==='companies')$ids=array_merge($ids,$v['source_company_aliases']??[]);
            foreach($ids as $id){if(!is_string($id)||!($kind==='companies'?ResearchR07::isCompanyId($id):ResearchR07::isJobId($id)))throw new \RuntimeException('Malformed source mapping');
                if(isset($map[$kind][$id])&&$map[$kind][$id]!==$uuid)throw new \RuntimeException('Conflicting installed mapping');$map[$kind][$id]=$uuid;}
        }
        $counts=['companies'=>0,'jobs'=>0];$seenJobs=[];
        foreach($data['companies'] as $r){$id=$r['company_id'];$uuid=$map['companies'][$id]??null;if($uuid===null||!isset($companies[$uuid]))throw new \RuntimeException('Mapped company missing');$counts['companies']++;}
        foreach($data['jobs'] as $r){$id=$r['job_id'];$uuid=$map['jobs'][$id]??null;$co=$map['companies'][$r['company_id']]??null;
            if($uuid===null||!isset($jobs[$uuid])||$jobs[$uuid]['company_id']!==$co||isset($seenJobs[$uuid]))throw new \RuntimeException('Mapped job missing, duplicated or has changed company');$seenJobs[$uuid]=true;$counts['jobs']++;}
        return ['mapped'=>$counts,'canonical_write_count'=>0];
    }
    /** Operator's future isolated read-only acceptance; this method never opens a connection or writes. */
    public static function databasePlan(\PDO $db):array {
        self::pin();$state=$db->query('SELECT release_version,schema_version,status FROM installation_state WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
        if(!is_array($state)||$state['release_version']!=='2.6.1'||(int)$state['schema_version']!==41||$state['status']!=='installed')throw new \RuntimeException('Wrong installation baseline');
        $oldSha='31c5a2cc629600075cdad706ed763eadbfe7ef3cb290674b7da43ad052ce93a6';
        if(WeeklyReport::get($db,'research.catalogue.current_sha256',null)!==$oldSha)throw new \RuntimeException('Wrong installed research baseline');
        $states=$db->query("SELECT record_type,record_id,base_json FROM seed_entity_states WHERE seed_version IN ('research-d1.0.4-R06','research-d1.0.4-R07-site') AND record_type IN ('companies','jobs') LIMIT 10001")->fetchAll(\PDO::FETCH_ASSOC);
        if(count($states)>10000)throw new \RuntimeException('Mapping result exceeds inspection limit');
        $companies=array_column($db->query('SELECT id FROM companies')->fetchAll(\PDO::FETCH_ASSOC),null,'id');$jobs=array_column($db->query('SELECT id,company_id FROM jobs')->fetchAll(\PDO::FETCH_ASSOC),null,'id');
        return self::mappingPlan(ResearchR07::data(),$states,$companies,$jobs)+['deployment_allowed'=>false,'read_only'=>true];
    }
    /** Read-only plan. No writes, no name matching, no UUID regeneration. */
    public static function plan(array $old,array $new):array {
        $counts=[];foreach(['companies'=>'company_id','jobs'=>'job_id'] as $kind=>$key){$a=array_column($old[$kind],null,$key);$b=array_column($new[$kind],null,$key);
            if(count($a)!==count($old[$kind])||count($b)!==count($new[$kind])||array_diff_key($a,$b)||array_diff_key($b,$a))throw new \RuntimeException('Source ID set mismatch');
            foreach($a as $id=>$r){$field=$kind==='companies'?'job_ids':'company_id';if($r[$field]!==$b[$id][$field])throw new \RuntimeException('Source relationship mismatch');}
            $counts[$kind]=count($a);
        }return ['source_ids'=>$counts,'canonical_insert'=>0,'canonical_update'=>0,'canonical_delete'=>0,'allowed_settings_candidate'=>['research.catalogue.current_snapshot','research.catalogue.current_sha256','research.catalogue.site_approved','weekly_report.research_changes.v1'],'deployment_allowed'=>false,'db_verified'=>false];
    }
}
