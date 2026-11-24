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
    private static function mapped(PDO $db):array {
        $maps=['companies'=>[],'jobs'=>[]];
        $q=$db->query("SELECT record_type,record_id,base_json FROM seed_entity_states WHERE seed_version IN ('research-d1.0.4-R06','research-d1.0.4-R07-site') AND record_type IN ('companies','jobs') LIMIT 10000");
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $kind=(string)$row['record_type'];if(!isset($maps[$kind]))continue;
            $v=json_decode((string)$row['base_json'],true);
            if(!is_array($v))continue;
            $src=$v[$kind==='companies'?'source_company_id':'source_job_id']??null;
            if(!is_string($src))continue;
            if(isset($maps[$kind][$src]) && $maps[$kind][$src] !== (string)$row['record_id'])throw new \RuntimeException('Conflicting R07 source mapping');
            $maps[$kind][$src]=(string)$row['record_id'];
            if($kind==='companies')foreach($v['source_company_aliases']??[] as $alias){if(is_string($alias)){if(isset($maps['companies'][$alias]) && $maps['companies'][$alias] !== (string)$row['record_id'])throw new \RuntimeException('Conflicting R07 company alias');$maps['companies'][$alias]=(string)$row['record_id'];}}
        }
        return $maps;
    }
    private static function fixedId(string $type,string $sourceId):string{return hash('md5','kmm:r07-site:'.$type.':'.$sourceId);}
    private static function releaseSource():string{return 'resources/research/r07-current/COMPANY_JOB_RELATIONS_d1.0.4_R07_BATCH66_20261124.json';}
    private static function checkedDate(string $date):string {
        return preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$date)?$date:'2026-11-24';
    }
    public static function sync(PDO $db):array {
        if(!$db->inTransaction())throw new \RuntimeException('Research canonical sync requires outer transaction');
        $data=ResearchR07::data();$map=self::mapped($db);
        $sourcePath=self::releaseSource();$checked=self::checkedDate((string)($data['checked_date']??''));
        $insertCompany=$db->prepare("INSERT INTO companies(id,display_name,legal_name,description,visibility,created_by,updated_by) VALUES (?,?,?,?, 'published',NULL,NULL)");
        $insertJob=$db->prepare("INSERT INTO jobs(id,company_id,title,category,graduate_year,recruitment_state,observed_on,deadline_on,note) VALUES (?,?,?,'other',?,'unverified',?,?,?)");
        $insertMap=$db->prepare('INSERT INTO seed_entity_states(seed_key,record_type,record_id,company_id,base_json,seed_version,source_document,source_checked_on) VALUES (?,?,?,?,?,?,?,?)');
        $addC=0;$addJ=0;
        foreach(ResearchR07::companies() as $sourceId=>$r){
            if(isset($map['companies'][$sourceId])){$probe=$db->prepare('SELECT id FROM companies WHERE id=?');$probe->execute([$map['companies'][$sourceId]]);if($probe->fetchColumn()===false)throw new \RuntimeException('Mapped R07 company missing');continue;}
            $uuid=self::fixedId('company',$sourceId);
            $name=mb_substr(ResearchR07::displayName($r),0,240);
            $note='R07サイト採用データ集の収録記録です。求人残枠・応募資格を保証しません。';
            $probe=$db->prepare('SELECT id FROM companies WHERE id=?');$probe->execute([$uuid]);if($probe->fetchColumn()!==false)throw new \RuntimeException('Canonical company UUID collision');
            $insertCompany->execute([$uuid,$name,$name,$note]);
            $base=['source_company_id'=>$sourceId,'source_release'=>'d1.0.4 R07 Batch66','catalogue_sha256'=>ResearchR07::FILE_SHA256];
            $insertMap->execute(['r07-site:companies:'.$sourceId,'companies',$uuid,$uuid,json_encode($base,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),self::SEED_VERSION,$sourcePath,$checked]);
            $map['companies'][$sourceId]=$uuid;$addC++;
        }
        foreach(ResearchR07::jobs() as $sourceId=>$r){
            if(isset($map['jobs'][$sourceId])){$probe=$db->prepare('SELECT id FROM jobs WHERE id=?');$probe->execute([$map['jobs'][$sourceId]]);if($probe->fetchColumn()===false)throw new \RuntimeException('Mapped R07 job missing');continue;}
            $companyId=$map['companies'][$r['company_id']]??null;
            if(!is_string($companyId))throw new \RuntimeException('R07 job cannot map to stable company source ID');
            $uuid=self::fixedId('job',$sourceId);
            $probe=$db->prepare('SELECT id FROM jobs WHERE id=?');$probe->execute([$uuid]);if($probe->fetchColumn()!==false)throw new \RuntimeException('Canonical job UUID collision');
            $title=mb_substr(ResearchR07::jobTitle($r),0,240);
            $year=0;if(preg_match('/\A(20[0-9]{2})(?:年卒|卒)?\z/u',trim((string)($r['recruitment_year']??'')),$m))$year=(int)$m[1];
            $date=null;foreach(['deadline_on','application_deadline'] as $field){
                $value=$r[$field]??null;if(is_string($value)&&preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$value)){$date=$value;break;}
            }
            $note='R07サイト採用資料。公開された求人が現在募集・応募可能という意味ではありません。 元ID: '.$sourceId;
            $insertJob->execute([$uuid,$companyId,$title,$year,$checked,$date,$note]);
            $base=['source_job_id'=>$sourceId,'source_company_id'=>$r['company_id'],'source_release'=>'d1.0.4 R07 Batch66','catalogue_sha256'=>ResearchR07::FILE_SHA256];
            $insertMap->execute(['r07-site:jobs:'.$sourceId,'jobs',$uuid,$companyId,json_encode($base,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),self::SEED_VERSION,$sourcePath,$checked]);
            $map['jobs'][$sourceId]=$uuid;$addJ++;
        }
        // The complete source document is stored separately from user-owned entities.
        $raw=(string)file_get_contents(ResearchR07::root().'/'.basename($sourcePath));
        if(!hash_equals(ResearchR07::FILE_SHA256,hash('sha256',$raw)))throw new \RuntimeException('R07 master integrity changed during import');
        $up=$db->prepare('INSERT INTO system_settings(setting_key,value_json,updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()');
        $up->execute(['research.catalogue.current_snapshot',$raw]);
        $up->execute(['research.catalogue.current_sha256',json_encode(ResearchR07::FILE_SHA256,JSON_THROW_ON_ERROR)]);
        $up->execute(['research.catalogue.site_approved',json_encode(['release'=>'d1.0.4 R07 Batch66','companies'=>113,'jobs'=>146,'review_status'=>'partial_not_final','server_verified_at'=>gmdate('c')],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        // The R07 catalogue mapping may have been cached earlier in this PHP process.
        ResearchR07::invalidateMapping();
        // Audit counts, but do not copy or rewrite historical R06 seed values.
        return ['new_companies'=>$addC,'new_jobs'=>$addJ,'current_companies'=>count(ResearchR07::companies()),'current_jobs'=>count(ResearchR07::jobs())];
    }
}
