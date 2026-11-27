<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** v3.0.0 Batch79 -> v3.1.0 d2.0.0. All writes staged in a caller-owned transaction. */
final class ResearchD200Upgrade {
    public const SOURCE='resources/research/d2.0.0/runtime_snapshot.json';
    public const OLD_SOURCE='resources/research/r07-batch79-candidate/COMPANY_JOB_RELATIONS_d1.0.4_R07_BATCH79_20261124.json';
    public const OLD_SHA='6d42e6162cb649fdb00cfb17d9b5b1b116b214180e810dfe4b3518f3e3a421cc';
    public const KEYS=ResearchBatch79AtomicRehearsal::KEYS;
    private static function decode(string $v):mixed{return json_decode($v,true,128,JSON_THROW_ON_ERROR);}
    private static function encode(mixed $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    public static function plan(string $oldRaw,string $newRaw,array $states,array $companies,array $jobs):array {
        if(!hash_equals(self::OLD_SHA,hash('sha256',$oldRaw))||!hash_equals(ResearchD200::RUNTIME_SHA,hash('sha256',$newRaw))||strlen($newRaw)>8000000)throw new \RuntimeException('Research source pin mismatch');
        $old=self::decode($oldRaw);$new=self::decode($newRaw);
        if(count($old['companies'])!==113||count($old['jobs'])!==146||count($new['companies'])!==146||count($new['jobs'])!==181)throw new \RuntimeException('Research count mismatch');
        ResearchBatch79Candidate::mappingPlan($old,$states,$companies,$jobs);
        foreach(['companies'=>'company_id','jobs'=>'job_id'] as $kind=>$key){$a=array_column($old[$kind],null,$key);$b=array_column($new[$kind],null,$key);if(array_diff_key($a,$b)||count($a)!==count($old[$kind])||count($b)!==count($new[$kind]))throw new \RuntimeException('Source ID removed or duplicated');if($kind==='jobs')foreach($a as $id=>$r)if($r['company_id']!==$b[$id]['company_id'])throw new \RuntimeException('Job parent changed');}
        $plan=ResearchR07CanonicalSync::planFresh($new,$states,$companies,$jobs);
        if(count($plan['insert']['companies'])!==33||count($plan['insert']['jobs'])!==35)throw new \RuntimeException('Unexpected new mapping plan');
        // Ensure exact ID set; a previously mapped new ID must not be silently re-used.
        foreach(['companies'=>'company_id','jobs'=>'job_id'] as $kind=>$key){$expected=array_diff(array_column($new[$kind],$key),array_column($old[$kind],$key));$actual=array_keys($plan['insert'][$kind]);sort($expected);sort($actual);if($actual!==$expected)throw new \RuntimeException('Unexpected source addition IDs');}
        return $plan;
    }
    private static function settings(PDO $db):array {return array_column($db->query('SELECT setting_key,value_json FROM system_settings ORDER BY setting_key FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC),'value_json','setting_key');}
    private static function mappings(PDO $db):array {
        $s=$db->query("SELECT record_type,record_id,base_json FROM seed_entity_states WHERE seed_version IN ('research-d1.0.4-R06','research-d1.0.4-R07-site') AND record_type IN ('companies','jobs') LIMIT 10001")->fetchAll(PDO::FETCH_ASSOC);
        if(count($s)>10000)throw new \RuntimeException('Mapping limit');
        return [$s,array_column($db->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_ASSOC),null,'id'),array_column($db->query('SELECT id,company_id FROM jobs')->fetchAll(PDO::FETCH_ASSOC),null,'id')];
    }
    private static function skip(array $r,string $t,array $allowed):bool {
        if($t==='installation_state'&&(string)$r['id']==='1')return true;
        if($t==='system_settings'&&in_array($r['setting_key'],self::KEYS,true))return true;
        return isset($allowed[$t][$t==='seed_entity_states'?($r['seed_key']??''):($r['id']??'')]);
    }
    private static function digest(PDO $db,array $allowed=[]):array {
        $rows=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);$out=[];
        foreach($rows as $row){$t=$row['TABLE_NAME'];if(!preg_match('/\A[a-zA-Z0-9_]+\z/D',$t)||strcasecmp((string)$row['ENGINE'],'InnoDB')!==0)throw new \RuntimeException('Unsafe DB table');$pk=[];foreach($db->query('SHOW COLUMNS FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC) as $c)if($c['Key']==='PRI'){if(!preg_match('/\A[a-zA-Z0-9_]+\z/D',$c['Field']))throw new \RuntimeException('Unsafe primary key');$pk[]='`'.$c['Field'].'`';}if(!$pk)throw new \RuntimeException('Missing primary key');
            $q=$db->query('SELECT * FROM `'.$t.'` ORDER BY '.implode(',',$pk));$ctx=hash_init('sha256');$n=0;while($r=$q->fetch(PDO::FETCH_ASSOC)){if(self::skip($r,$t,$allowed))continue;hash_update($ctx,serialize($r)."\n");$n++;}$q->closeCursor();$out[$t]=[$n,hash_final($ctx)];}
        if(array_diff(ResearchBatch79AtomicRehearsal::REQUIRED,array_keys($out)))throw new \RuntimeException('Protected table missing');return $out;
    }
    private static function write(\PDOStatement $q,array $p):void {if(!$q->execute($p)||$q->rowCount()!==1)throw new \RuntimeException('Expected one inserted row');}
    private static function text(string $s):string{if(!preg_match('/\A.{1,240}/us',$s,$m))throw new \RuntimeException('Invalid research name');return $m[0];}
    private static function date(mixed $v):?string{if(!is_string($v)||!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D',$v,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return null;return $v;}
    private static function checkRow(PDO $db,string $table,string $pk,string $id,array $expected):void {
        if(!in_array($table,['companies','jobs','seed_entity_states'],true)||!in_array($pk,['id','seed_key'],true))throw new \LogicException('Invalid readback table');
        $q=$db->prepare('SELECT * FROM '.$table.' WHERE '.$pk.'=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($r))throw new \RuntimeException('Missing inserted row');foreach($expected as $k=>$v){if(!array_key_exists($k,$r)||($v===null?$r[$k]!==null:(string)$r[$k] !== (string)$v))throw new \RuntimeException('Inserted row readback mismatch: '.$table.'.'.$k);}
    }
    public static function stage(PDO $db,string $oldRaw,string $newRaw,string $installId,string $confirmation):array {
        if(!$db->inTransaction()||$db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION||$confirmation!=='OPERATOR_UPDATE:'.ResearchD200::RUNTIME_SHA)throw new \RuntimeException('Transaction and confirmation required');
        ResearchD200::pin();ResearchD200::projection();ResearchR07Scope::ids();
        $state=$db->query('SELECT * FROM installation_state WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if(!is_array($state)||$state['install_id']!==$installId||$state['release_version']!=='3.0.0'||(int)$state['schema_version']!==41||$state['status']!=='installed')throw new \RuntimeException('Wrong installation baseline');
        $s=self::settings($db);if(($s[self::KEYS[0]]??null)!==$oldRaw||self::decode($s[self::KEYS[1]]??'null')!==self::OLD_SHA)throw new \RuntimeException('Installed Batch79 snapshot mismatch');
        $approval=self::decode($s[self::KEYS[2]]??'null');if(!is_array($approval)||($approval['release']??null)!=='d1.0.4 R07 Batch79'||($approval['review_status']??null)!=='partial_not_final'||!in_array($approval['site_version']??null,['2.7.1','2.7.2','2.7.3','2.7.4','2.7.5','3.0.0'],true))throw new \RuntimeException('Installed adoption marker mismatch');
        WeeklyReport::validateState(self::decode($s['weekly_report.state.v1']??'null'));
        $pending=self::decode($s[self::KEYS[3]]??'[]');if(!is_array($pending)||!array_is_list($pending))throw new \RuntimeException('Invalid pending ledger');foreach($pending as $e){if(!is_array($e))throw new \RuntimeException('Invalid ledger event');WeeklyReport::validateEvent($e,true);}
        [$maps,$companies,$jobs]=self::mappings($db);$plan=self::plan($oldRaw,$newRaw,$maps,$companies,$jobs);$new=self::decode($newRaw);$old=self::decode($oldRaw);
        if($new!==ResearchR07::data())throw new \RuntimeException('Runtime snapshot readback mismatch');
        $checked=self::date($new['checked_date']??null);if($checked===null)throw new \RuntimeException('Bad research date');
        $events=WeeklyReport::diff($old,$new);$counts=['companies'=>['add'=>0,'update'=>0],'jobs'=>['add'=>0,'update'=>0]];
        foreach($events as $e){if(!isset($counts[$e['kind']][$e['op']]))throw new \RuntimeException('Unexpected research removal');$counts[$e['kind']][$e['op']]++;$e['reason']='d2.0.0：'.$e['reason'];WeeklyReport::validateEvent($e,true);if(!in_array($e,$pending,true))$pending[]=$e;}
        if($counts['companies']['add']!==33||$counts['jobs']['add']!==35)throw new \RuntimeException('Weekly additions mismatch');
        // Hash all existing protected rows; only new exact primary keys are excluded after insert.
        $before=self::digest($db);$allowed=['companies'=>[],'jobs'=>[],'seed_entity_states'=>[]];
        $co=$db->prepare("INSERT INTO companies(id,display_name,legal_name,description,visibility,created_by,updated_by) VALUES (?,?,?,?, 'published',NULL,NULL)");
        $jo=$db->prepare("INSERT INTO jobs(id,company_id,title,category,graduate_year,recruitment_state,observed_on,deadline_on,note) VALUES (?,?,?,'other',?,'unverified',?,?,?)");
        $map=$db->prepare('INSERT INTO seed_entity_states(seed_key,record_type,record_id,company_id,base_json,seed_version,source_document,source_checked_on) VALUES (?,?,?,?,?,?,?,?)');
        $meta=['source_release'=>'d2.0.0','source_original_sha256'=>ResearchD200::ORIGINAL_SHA,'catalogue_sha256'=>ResearchD200::RUNTIME_SHA];
        foreach($plan['insert']['companies'] as $id=>$uuid){$r=ResearchR07::company($id);$name=self::text(ResearchR07::displayName($r));$desc='d2.0.0サイト収録記録。求人残枠・応募資格を保証しません。';$seed='r07-site:companies:'.$id;$base=self::encode(['source_company_id'=>$id]+$meta);
            self::write($co,[$uuid,$name,$name,$desc]);self::write($map,[$seed,'companies',$uuid,$uuid,$base,ResearchR07CanonicalSync::SEED_VERSION,self::SOURCE,$checked]);
            self::checkRow($db,'companies','id',$uuid,['display_name'=>$name,'legal_name'=>$name,'description'=>$desc,'visibility'=>'published','created_by'=>null,'updated_by'=>null]);self::checkRow($db,'seed_entity_states','seed_key',$seed,['record_type'=>'companies','record_id'=>$uuid,'company_id'=>$uuid,'base_json'=>$base,'seed_version'=>ResearchR07CanonicalSync::SEED_VERSION,'source_document'=>self::SOURCE,'source_checked_on'=>$checked]);$allowed['companies'][$uuid]=true;$allowed['seed_entity_states'][$seed]=true;}
        foreach($plan['insert']['jobs'] as $id=>$target){$r=ResearchR07::job($id);$uuid=$target['id'];$parent=$target['company_id'];$title=self::text(ResearchR07::jobTitle($r));$year=0;if(preg_match('/\A(20[0-9]{2})(?:年卒|卒)?\z/u',trim((string)($r['recruitment_year']??'')),$m))$year=(int)$m[1];$date=self::date($r['deadline_on']??null)??self::date($r['application_deadline']??null);$note='d2.0.0サイト収録記録。現在募集・応募可能とは限りません。 元ID: '.$id;$seed='r07-site:jobs:'.$id;$base=self::encode(['source_job_id'=>$id,'source_company_id'=>$r['company_id']]+$meta);
            self::write($jo,[$uuid,$parent,$title,$year,$checked,$date,$note]);self::write($map,[$seed,'jobs',$uuid,$parent,$base,ResearchR07CanonicalSync::SEED_VERSION,self::SOURCE,$checked]);self::checkRow($db,'jobs','id',$uuid,['company_id'=>$parent,'title'=>$title,'category'=>'other','graduate_year'=>$year,'recruitment_state'=>'unverified','observed_on'=>$checked,'deadline_on'=>$date,'note'=>$note,'archived_at'=>null]);self::checkRow($db,'seed_entity_states','seed_key',$seed,['record_type'=>'jobs','record_id'=>$uuid,'company_id'=>$parent,'base_json'=>$base,'seed_version'=>ResearchR07CanonicalSync::SEED_VERSION,'source_document'=>self::SOURCE,'source_checked_on'=>$checked]);$allowed['jobs'][$uuid]=true;$allowed['seed_entity_states'][$seed]=true;}
        [$afterMaps,$afterCompanies,$afterJobs]=self::mappings($db);$mapped=ResearchBatch79Candidate::mappingPlan($new,$afterMaps,$afterCompanies,$afterJobs);
        $write=[self::KEYS[0]=>$newRaw,self::KEYS[1]=>self::encode(ResearchD200::RUNTIME_SHA),self::KEYS[2]=>self::encode(['release'=>'d2.0.0','companies'=>146,'jobs'=>181,'review_status'=>'released_with_documented_limitations','source_original_sha256'=>ResearchD200::ORIGINAL_SHA,'production_approved'=>false,'site_version'=>'3.1.0','server_verified_at'=>gmdate('c')]),self::KEYS[3]=>self::encode($pending)];
        $up=$db->prepare('INSERT INTO system_settings(setting_key,value_json,updated_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()');foreach($write as $k=>$v)if(!$up->execute([$k,$v]))throw new \RuntimeException('Setting write failed');
        $q=$db->prepare('UPDATE installation_state SET release_version=?,schema_version=? WHERE id=1 AND install_id=? AND schema_version=? AND release_version=? AND status=?');if(!$q->execute(['3.1.0',41,$installId,41,'3.0.0','installed'])||$q->rowCount()!==1)throw new \RuntimeException('Version write failed');
        $after=self::settings($db);foreach($write as $k=>$v)if(($after[$k]??null)!==$v)throw new \RuntimeException('Setting readback mismatch');$state['release_version']='3.1.0';
        if($db->query('SELECT * FROM installation_state WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC)!==$state||self::digest($db,$allowed)!==$before)throw new \RuntimeException('Protected DB rows changed');
        ResearchR07::invalidateMapping();return ['status'=>'STAGED_UPDATE','protected_tables'=>count($before),'mapped'=>$mapped['mapped'],'changes'=>$counts,'pending_count'=>count($pending),'commit'=>false];
    }
}
