<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Pinned research transition. Only the normal authenticated updater may commit after verification. */
final class ResearchBatch79AtomicRehearsal {
    public const OLD_SHA='31c5a2cc629600075cdad706ed763eadbfe7ef3cb290674b7da43ad052ce93a6';
    public const NEW_SHA='6d42e6162cb649fdb00cfb17d9b5b1b116b214180e810dfe4b3518f3e3a421cc';
    public const KEYS=['research.catalogue.current_snapshot','research.catalogue.current_sha256','research.catalogue.site_approved','weekly_report.research_changes.v1'];
    public const REQUIRED=['installation_state','users','user_roles','user_profiles','user_guide_steps','user_saved_filters','user_favorite_companies','user_company_notes','messages','message_recipients','edit_proposals','application_tracks','application_audit','audit_logs','companies','jobs','system_settings','application_events','seed_entity_states'];
    private static function decode(string $v):mixed {return json_decode($v,true,128,JSON_THROW_ON_ERROR);}
    private static function encode(mixed $v):string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private static function pinned(string $raw,string $sha):array {
        if(strlen($raw)>8000000||!hash_equals($sha,hash('sha256',$raw)))throw new \RuntimeException('Pinned source mismatch or size limit');
        $d=self::decode($raw);if(!is_array($d)||count($d['companies']??[])!==113||count($d['jobs']??[])!==146)throw new \RuntimeException('Source count mismatch');return $d;
    }
    /** Pure write-set builder: no time-dependent state and no raw values logged. */
    public static function prepare(string $oldRaw,string $newRaw,array $settings,array $mapping, array $companies,array $jobs):array {
        $old=self::pinned($oldRaw,self::OLD_SHA);$new=self::pinned($newRaw,self::NEW_SHA);
        ResearchBatch79Candidate::plan($old,$new);
        $mapped=ResearchBatch79Candidate::mappingPlan($new,$mapping,$companies,$jobs);
        if(!isset($settings[self::KEYS[0]],$settings[self::KEYS[1]],$settings[self::KEYS[2]])||self::decode($settings[self::KEYS[1]])!==self::OLD_SHA||!hash_equals(self::OLD_SHA,hash('sha256',$settings[self::KEYS[0]]))||self::decode($settings[self::KEYS[0]])!==$old)throw new \RuntimeException('Installed snapshot differs from pinned Batch66');
        $approval=self::decode($settings[self::KEYS[2]]);
        if(!is_array($approval)||($approval['release']??null)!=='d1.0.4 R07 Batch66'||($approval['review_status']??null)!=='partial_not_final')throw new \RuntimeException('Installed adoption marker mismatch');
        $pending=isset($settings[self::KEYS[3]])?self::decode($settings[self::KEYS[3]]):[];
        if(!is_array($pending)||!array_is_list($pending))throw new \RuntimeException('Invalid pending ledger');
        foreach($pending as $e){if(!is_array($e))throw new \RuntimeException('Invalid pending event');WeeklyReport::validateEvent($e,true);}
        $diff=WeeklyReport::diff($old,$new);$counts=['companies'=>0,'jobs'=>0];
        foreach($diff as $e){if($e['op']!=='update'||!isset($counts[$e['kind']]))throw new \RuntimeException('Unexpected source change');$counts[$e['kind']]++;$e['reason']='R07 Batch79：'.$e['reason'];if(!in_array($e,$pending,true))$pending[]=$e;}
        if($counts!==['companies'=>5,'jobs'=>9])throw new \RuntimeException('Unexpected semantic diff');
        $write=[self::KEYS[0]=>$newRaw,self::KEYS[1]=>self::encode(self::NEW_SHA),self::KEYS[2]=>self::encode(['release'=>'d1.0.4 R07 Batch79','companies'=>113,'jobs'=>146,'review_status'=>'partial_not_final','rehearsal_only'=>true,'production_approved'=>false]),self::KEYS[3]=>self::encode($pending)];
        return ['write'=>$write,'counts'=>$counts,'mapped'=>$mapped['mapped'],'pending_count'=>count($pending)];
    }
    private static function tables(PDO $db):array {
        $r=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);$out=[];
        foreach($r as $v){$n=$v['TABLE_NAME'];if(!preg_match('/\A[a-zA-Z0-9_]+\z/D',$n)||strcasecmp((string)$v['ENGINE'],'InnoDB')!==0)throw new \RuntimeException('Unsafe table type');$out[]=$n;}
        if(array_diff(self::REQUIRED,$out))throw new \RuntimeException('Required protected table missing');return $out;
    }
    private static function digest(PDO $db,bool $versioned=false,bool $excludeResearch=true):array {
        $out=[];foreach(self::tables($db) as $t){$pk=[];foreach($db->query('SHOW COLUMNS FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC) as $c){if($c['Key']==='PRI'){$n=$c['Field'];if(!preg_match('/\A[a-zA-Z0-9_]+\z/D',$n))throw new \RuntimeException('Unsafe primary key');$pk[]='`'.$n.'`';}}
            if(!$pk)throw new \RuntimeException('Missing primary key: '.$t);
            $q=$db->query('SELECT * FROM `'.$t.'` ORDER BY '.implode(',',$pk));$ctx=hash_init('sha256');$count=0;
            while($row=$q->fetch(PDO::FETCH_ASSOC)){if($excludeResearch&&$t==='system_settings'&&in_array($row['setting_key'],self::KEYS,true))continue;if($versioned&&$t==='installation_state'&&(string)$row['id']==='1')continue;hash_update($ctx,serialize($row)."\n");$count++;}$q->closeCursor();$out[$t]=[$count,hash_final($ctx)];}
        return $out;
    }
    private static function settings(PDO $db):array {return array_column($db->query('SELECT setting_key,value_json FROM system_settings ORDER BY setting_key FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC),'value_json','setting_key');}
    /** Stage within caller-owned transaction. Historical rehearsal must roll back; normal updater may commit after checks. */
    public static function stageVersioned(PDO $db,string $oldRaw,string $newRaw,string $installId,string $confirmation,bool $apply=false):array {
        ResearchBatch79Candidate::pin();
        $target=$apply?'3.0.0':'2.7.0';
        if(($apply?$confirmation!=='OPERATOR_UPDATE:'.self::NEW_SHA:($confirmation!=='ISOLATED_VERSIONED_ROLLBACK_ONLY:'.self::NEW_SHA||getenv('KMM_BATCH79_REHEARSAL')!=='1'))||!$db->inTransaction())throw new \RuntimeException('Versioned transaction required');
        if($db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)throw new \RuntimeException('PDO exception mode required');
        $state=$db->query('SELECT * FROM installation_state WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if(!is_array($state)||($state['install_id']??null)!==$installId||$state['release_version']!=='2.6.1'||(int)$state['schema_version']!==41||$state['status']!=='installed')throw new \RuntimeException('Wrong installed version/schema/identity');
        $settings=self::settings($db);$before=self::digest($db,true);
        $states=$db->query("SELECT record_type,record_id,base_json FROM seed_entity_states WHERE seed_version IN ('research-d1.0.4-R06','research-d1.0.4-R07-site') AND record_type IN ('companies','jobs') LIMIT 10001")->fetchAll(PDO::FETCH_ASSOC);
        if(count($states)>10000)throw new \RuntimeException('Mapping limit');
        $companies=array_column($db->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_ASSOC),null,'id');$jobs=array_column($db->query('SELECT id,company_id FROM jobs')->fetchAll(PDO::FETCH_ASSOC),null,'id');
        $plan=self::prepare($oldRaw,$newRaw,$settings,$states,$companies,$jobs);
        if($apply){
            $stateJson=self::decode($settings['weekly_report.state.v1']??'null');
            if(!is_array($stateJson)||!is_int($stateJson['cursor']??null)||$stateJson['cursor']<0||!is_string($stateJson['since']??null)||!is_string($stateJson['next_due']??null))throw new \RuntimeException('Existing weekly state required');
            WeeklyReport::validateState($stateJson);
            $plan['write'][self::KEYS[2]]=self::encode(['release'=>'d1.0.4 R07 Batch79','companies'=>113,'jobs'=>146,'review_status'=>'partial_not_final','production_approved'=>false,'site_version'=>$target,'server_verified_at'=>gmdate('c')]);
        }
        $up=$db->prepare('INSERT INTO system_settings(setting_key,value_json,updated_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()');
        foreach($plan['write'] as $k=>$v)if(!$up->execute([$k,$v]))throw new \RuntimeException('Write failed');
        $q=$db->prepare('UPDATE installation_state SET release_version=?,schema_version=? WHERE id=1 AND install_id=? AND schema_version=? AND release_version=? AND status=?');
        if(!$q->execute([$target,41,$installId,41,'2.6.1','installed'])||$q->rowCount()!==1)throw new \RuntimeException('Version write failed');
        $after=self::settings($db);foreach($plan['write'] as $k=>$v)if(!isset($after[$k])||!hash_equals(hash('sha256',$v),hash('sha256',$after[$k]))||self::decode($after[$k])!==self::decode($v))throw new \RuntimeException('Write readback mismatch');
        $expected=$state;$expected['release_version']=$target;
        if($db->query('SELECT * FROM installation_state WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC)!==$expected)throw new \RuntimeException('Version row readback mismatch');
        if(self::digest($db,true)!==$before)throw new \RuntimeException('Protected rows changed');
        return ['status'=>$apply?'STAGED_UPDATE':'STAGED_ROLLBACK_REQUIRED','protected_tables'=>count($before),'mapped'=>$plan['mapped'],'changes'=>$plan['counts'],'pending_count'=>$plan['pending_count'],'commit'=>false,'production_approved'=>false];
    }
    /** Same-source upgrade: no research or weekly settings are modified. */
    public static function stageSameSource(PDO $db,string $newRaw,string $installId,string $confirmation,string $previous):array {
        ResearchBatch79Candidate::pin();
        if(!in_array($previous,['2.7.1','2.7.2','2.7.3','2.7.4','2.7.5'],true))throw new \RuntimeException('Unsupported same-source version');
        if($confirmation!=='OPERATOR_UPDATE:'.self::NEW_SHA||!$db->inTransaction()||$db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)throw new \RuntimeException('Versioned transaction required');
        $state=$db->query('SELECT * FROM installation_state WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if(!is_array($state)||($state['install_id']??null)!==$installId||$state['release_version']!==$previous||(int)$state['schema_version']!==41||$state['status']!=='installed')throw new \RuntimeException('Wrong installed version/schema/identity');
        $data=self::pinned($newRaw,self::NEW_SHA);$settings=self::settings($db);
        if(($settings[self::KEYS[0]]??null)!==$newRaw||self::decode($settings[self::KEYS[1]]??'null')!==self::NEW_SHA)throw new \RuntimeException('Installed Batch79 snapshot mismatch');
        $adoption=self::decode($settings[self::KEYS[2]]??'null');
        if(!is_array($adoption)||($adoption['release']??null)!=='d1.0.4 R07 Batch79'||($adoption['review_status']??null)!=='partial_not_final'||(!in_array($adoption['site_version']??null,['2.7.1','2.7.2','2.7.3','2.7.4','2.7.5'],true)||version_compare($adoption['site_version'],$previous,'>')))throw new \RuntimeException('Installed adoption marker mismatch');
        $states=$db->query("SELECT record_type,record_id,base_json FROM seed_entity_states WHERE seed_version IN ('research-d1.0.4-R06','research-d1.0.4-R07-site') AND record_type IN ('companies','jobs') LIMIT 10001")->fetchAll(PDO::FETCH_ASSOC);
        if(count($states)>10000)throw new \RuntimeException('Mapping limit');
        $companies=array_column($db->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_ASSOC),null,'id');$jobs=array_column($db->query('SELECT id,company_id FROM jobs')->fetchAll(PDO::FETCH_ASSOC),null,'id');
        $mapped=ResearchBatch79Candidate::mappingPlan($data,$states,$companies,$jobs);
        $before=self::digest($db,true,false);
        $q=$db->prepare('UPDATE installation_state SET release_version=?,schema_version=? WHERE id=1 AND install_id=? AND schema_version=? AND release_version=? AND status=?');
        if(!$q->execute(['3.0.0',41,$installId,41,$previous,'installed'])||$q->rowCount()!==1)throw new \RuntimeException('Version write failed');
        $state['release_version']='3.0.0';
        if($db->query('SELECT * FROM installation_state WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC)!==$state||self::digest($db,true,false)!==$before)throw new \RuntimeException('Protected rows changed');
        return ['status'=>'STAGED_UPDATE','protected_tables'=>count($before),'mapped'=>$mapped['mapped'],'changes'=>['companies'=>0,'jobs'=>0],'commit'=>false];
    }
    /** Caller supplies an isolated restored DB connection. Never commits, even on success. */
    public static function rehearse(PDO $db,string $oldRaw,string $newRaw,string $confirmation):array {
        ResearchBatch79Candidate::pin();
        if($confirmation!=='ISOLATED_ROLLBACK_ONLY:'.self::NEW_SHA||getenv('KMM_BATCH79_REHEARSAL')!=='1'||$db->inTransaction())throw new \RuntimeException('Isolated rehearsal confirmation required');
        if($db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)throw new \RuntimeException('PDO exception mode required');
        if(!$db->beginTransaction())throw new \RuntimeException('Cannot begin transaction');
        try{
            $state=$db->query('SELECT release_version,schema_version,status FROM installation_state WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if(!is_array($state)||$state['release_version']!=='2.6.1'||(int)$state['schema_version']!==41||$state['status']!=='installed')throw new \RuntimeException('Wrong installed version/schema');
            $settings=self::settings($db);$before=self::digest($db);
            $states=$db->query("SELECT record_type,record_id,base_json FROM seed_entity_states WHERE seed_version IN ('research-d1.0.4-R06','research-d1.0.4-R07-site') AND record_type IN ('companies','jobs') LIMIT 10001")->fetchAll(PDO::FETCH_ASSOC);
            if(count($states)>10000)throw new \RuntimeException('Mapping limit');
            $companies=array_column($db->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_ASSOC),null,'id');$jobs=array_column($db->query('SELECT id,company_id FROM jobs')->fetchAll(PDO::FETCH_ASSOC),null,'id');
            $plan=self::prepare($oldRaw,$newRaw,$settings,$states,$companies,$jobs);
            $up=$db->prepare('INSERT INTO system_settings(setting_key,value_json,updated_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()');
            foreach($plan['write'] as $k=>$v)if(!$up->execute([$k,$v]))throw new \RuntimeException('Write failed');
            $after=self::settings($db);foreach($plan['write'] as $k=>$v)if(!isset($after[$k])||!hash_equals(hash('sha256',$v),hash('sha256',$after[$k]))||self::decode($after[$k])!==self::decode($v))throw new \RuntimeException('Write readback mismatch');
            if(self::digest($db)!==$before)throw new \RuntimeException('Protected rows changed');
            if(!$db->rollBack())throw new \RuntimeException('Rollback failed');
            return ['status'=>'REHEARSED_ROLLED_BACK','protected_tables'=>count($before),'mapped'=>$plan['mapped'],'changes'=>$plan['counts'],'pending_count'=>$plan['pending_count'],'commit'=>false,'production_approved'=>false];
        }catch(\Throwable $e){if($db->inTransaction()&&!$db->rollBack())throw new \RuntimeException('Rollback failed; isolated DB requires restoration',0,$e);throw $e;}
    }
}
