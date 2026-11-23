<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/**
 * Round38: complete private cohort import, with fail-closed optional technical ID bindings.
 * A binding is an explicit operator-selected DB record, not real-world identity proof.
 * There is NO company/job/brand/location mutation, public approval, recipient or email action.
 * Outbox is HELD; it is not a notification and has no sending recipient.
 */
final class ResearchImportAtomic {
    private const VERSION='d1.0.1';
    private const ID='/\A[a-f0-9]{32}\z/D';
    private const REVIEW='/\Ar-[a-f0-9]{24}\z/D';
    private const EVENT='/\A[a-zA-Z0-9][a-zA-Z0-9:_-]{7,99}\z/D';
    /** Keep the 27 released source rows intact; only explicit link IDs are projected. */
    public static function plan(array $feed,array $catalogue,array $bindings=[]):array {
        $source=ResearchTechnicalMapping::plan($feed,$catalogue,[]);
        if(!is_array($bindings)||(!array_is_list($bindings)&&count($bindings)>23)
           ||(array_is_list($bindings)&&$bindings!==[]))throw new \DomainException('Invalid operator binding document');
        $seen=[];$normal=[];
        foreach($bindings as $rid=>$binding){
            if(!is_string($rid)||preg_match(self::REVIEW,$rid)!==1||!is_array($binding)
               ||array_is_list($binding)||array_diff(array_keys($binding),['company_id','seed_key','expected_revision','brand_ids','job_ids','worksite_ids','job_worksite_edges'])!==[])
                throw new \DomainException('Untrusted technical binding');
            $company=self::id($binding['company_id']??null);
            $revision=$binding['expected_revision']??null;
            if(!is_int($revision)||$revision<1||$revision>2147483646)throw new \DomainException('Expected company revision required');
            $seed=$binding['seed_key']??null;
            if($seed!==null)SeedData::key($seed);
            $brands=self::ids($binding['brand_ids']??[]);
            $jobs=self::ids($binding['job_ids']??[]);
            $locations=self::ids($binding['worksite_ids']??[]);
            $edges=self::edges($binding['job_worksite_edges']??[],$jobs,$locations);
            $normal[$rid]=['company_id'=>$company,'seed_key'=>$seed,'expected_revision'=>$revision,
                'brand_ids'=>$brands,'job_ids'=>$jobs,'worksite_ids'=>$locations,
                'job_worksite_edges'=>$edges];
            if(isset($seen[$company]) && $seen[$company]!==[$seed,$revision])
                throw new \DomainException('Inconsistent shared company revision or seed identity');
            $seen[$company]=[$seed,$revision];
        }
        ksort($normal);
        $items=[];$allowed=[];$boundCount=0;
        foreach($source['items'] as $p){
            $rid=$p['review_id'];$bind=$normal[$rid]??null;
            if($bind!==null){
                if($p['site_data_status']!=='RESEARCH_ACCEPTED')throw new \DomainException('HOLD/X may not be bound');
                $boundCount++;$allowed[$rid]=true;
            }
            $items[]=['review_id'=>$rid,'private_stage_id'=>$p['private_stage_id'],
                'source_record_sha256'=>$p['research_source_sha256'],
                'priority'=>$p['priority'],'record_status'=>$p['site_data_status'],
                'source_record'=>$feed['records'][count($items)]['source_record'],
                'binding'=>$bind];
        }
        foreach($normal as $rid=>$_)if(!isset($allowed[$rid]))throw new \DomainException('Unknown or disallowed binding target');
        return ['format'=>'kmm-private-atomic-import-v1','research_version'=>self::VERSION,
            'source_manifest_sha256'=>$source['source_manifest_sha256'],
            'record_count'=>27,'bound_count'=>$boundCount,'items'=>$items,
            'all_records_private'=>true,'business_mutation_allowed'=>false,
            'publication_allowed'=>false,'notification_allowed'=>false];
    }
    private static function id(mixed $v):string {
        if(!is_string($v)||preg_match(self::ID,$v)!==1)throw new \DomainException('Explicit DB ID invalid');
        return $v;
    }
    private static function ids(mixed $list):array {
        if(!is_array($list)||!array_is_list($list)||count($list)>16)throw new \DomainException('Invalid multi-entity IDs');
        $out=[];foreach($list as $id){$id=self::id($id);if(isset($out[$id]))throw new \DomainException('Repeated entity ID');$out[$id]=true;}
        return array_keys($out);
    }
    private static function edges(mixed $list,array $jobs,array $locations):array {
        if(!is_array($list)||!array_is_list($list)||count($list)>32)
            throw new \DomainException('Invalid job-worksite edge list');
        $out=[];$seen=[];
        foreach($list as $edge){
            if(!is_array($edge)||array_is_list($edge)||count($edge)!==2
               ||array_diff(array_keys($edge),['job_id','worksite_id'])!==[])
                throw new \DomainException('Invalid job-worksite edge');
            $job=self::id($edge['job_id']??null);$loc=self::id($edge['worksite_id']??null);
            if(!in_array($job,$jobs,true)||!in_array($loc,$locations,true)||isset($seen[$job.':'.$loc]))
                throw new \DomainException('Unbound or repeated job-worksite relationship');
            $seen[$job.':'.$loc]=true;$out[]=['job_id'=>$job,'worksite_id'=>$loc];
        }
        return $out;
    }
    /** Transaction owns all private rows and one HELD outbox marker + minimal audit. */
    public static function apply(PDO $db,array $feed,array $catalogue,array $bindings,
                                 array $actor,int $authVersion,string $eventKey):array {
        $plan=self::plan($feed,$catalogue,$bindings);
        if(!preg_match(self::EVENT,$eventKey))throw new \DomainException('Invalid idempotency key');
        $actorId=$actor['id']??null;$role=$actor['role']??null;
        if(!is_int($actorId)||$actorId<1||!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)||$authVersion<0)
            throw new \DomainException('Administrator privileges required');
        if($db->inTransaction())throw new \RuntimeException('Nested import is forbidden');
        $digest=hash('sha256',SeedData::encode($plan));
        $db->beginTransaction();
        try {
            // Authorization and all ID targets are checked inside the same TX.
            $q=$db->prepare('SELECT u.status,u.auth_version,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $q->execute([$actorId]);$a=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($a)||($a['status']??null)!=='active'||($a['role']??null)!==$role
               ||(int)($a['auth_version']??-1)!==$authVersion)
                throw new \DomainException('Administrator authorization revoked');
            $q=$db->prepare('SELECT id,payload_digest,status FROM research_import_batches WHERE event_key=? FOR UPDATE');
            $q->execute([$eventKey]);$existing=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($existing)){
                if(!is_string($existing['payload_digest']??null)||!hash_equals($existing['payload_digest'],$digest)
                   ||($existing['status']??null)!=='private_complete')throw new \DomainException('Idempotency key reused or incomplete');
                $q=$db->prepare('SELECT COUNT(*) FROM research_import_items WHERE batch_id=?');
                $q->execute([$existing['id']]);
                if((int)$q->fetchColumn()!==27)throw new \DomainException('Previously completed import has missing records');
                $q=$db->prepare('SELECT status,payload_digest FROM research_import_outbox WHERE batch_id=? FOR UPDATE');
                $q->execute([$existing['id']]);$held=$q->fetch(PDO::FETCH_ASSOC);
                if(!is_array($held)||($held['status']??null)!=='held'
                   ||!is_string($held['payload_digest']??null)||!hash_equals($held['payload_digest'],$digest))
                    throw new \DomainException('Previously completed import outbox state is invalid');
                $db->commit();return self::result('duplicate',$existing['id'],$plan,0);
            }
            foreach($plan['items'] as $item){if($item['binding']!==null)self::inspectBinding($db,$item['binding']);}
            $batch=BusinessData::id();
            $db->prepare('INSERT INTO research_import_batches(id,event_key,payload_digest,research_version,actor_id,status,record_count,bound_count) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$batch,$eventKey,$digest,self::VERSION,$actorId,'private_complete',27,$plan['bound_count']]);
            $insert=$db->prepare('INSERT INTO research_import_items(batch_id,review_id,private_stage_id,source_record_sha256,priority,record_status,source_record_json,binding_json) VALUES (?,?,?,?,?,?,?,?)');
            foreach($plan['items'] as $item){
                $insert->execute([$batch,$item['review_id'],$item['private_stage_id'],$item['source_record_sha256'],
                    $item['priority'],$item['record_status'],SeedData::encode($item['source_record']),SeedData::encode($item['binding'])]);
            }
            // Only internal blocked marker, never a sendable mail/broadcast event.
            $outboxKey='ri_'.substr(hash('sha256',$eventKey.':'.$digest),0,40);
            $db->prepare('INSERT INTO research_import_outbox(event_key,batch_id,event_type,status,payload_digest) VALUES (?,?,?,?,?)')
                ->execute([$outboxKey,$batch,'private_import_recorded','held',$digest]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                ->execute([$actorId,'research.import.private','research_import',$batch,SeedData::encode([
                    'research_version'=>self::VERSION,'count'=>27,'bound_count'=>$plan['bound_count'],
                    'digest'=>$digest,'published'=>false,'notifications'=>0])]);
            $db->commit();return self::result('private_complete',$batch,$plan,1);
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function inspectBinding(PDO $db,array $b):void {
        $q=$db->prepare('SELECT id,seed_key,revision,visibility FROM companies WHERE id=? FOR UPDATE');
        $q->execute([$b['company_id']]);$company=$q->fetch(PDO::FETCH_ASSOC);
        // companies has no archived_at in schema; use a separate, precise projection instead.
        if(!is_array($company)||($company['id']??null)!==$b['company_id']
           ||(int)($company['revision']??0)!==$b['expected_revision']
           ||($b['seed_key']!==null&&($company['seed_key']??null)!==$b['seed_key']))
            throw new \DomainException('Company missing, revision stale, or seed identity mismatched');
        if($b['seed_key']!==null){
            $q=$db->prepare('SELECT s.company_id,s.base_json FROM seed_record_states s JOIN project_seed_records p ON p.seed_key=s.seed_key AND p.company_id=s.company_id WHERE s.seed_key=? FOR UPDATE');
            $q->execute([$b['seed_key']]);$origin=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($origin)||($origin['company_id']??null)!==$b['company_id'])throw new \DomainException('Seed origin ownership mismatch');
            $base=json_decode((string)$origin['base_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($base))throw new \DomainException('Seed origin invalid');
            $q=$db->prepare('SELECT field_key,value_json FROM seed_user_overlays WHERE seed_key=? FOR UPDATE');
            $q->execute([$b['seed_key']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($rows))throw new \DomainException('Overlay lookup invalid');
            $overlays=[];
            foreach($rows as $row){
                if(!is_string($row['field_key']??null)||!in_array($row['field_key'],SeedData::FIELDS,true)
                  ||array_key_exists($row['field_key'],$overlays)||!is_string($row['value_json']??null))throw new \DomainException('Invalid overlay');
                $value=json_decode($row['value_json'],true,32,JSON_THROW_ON_ERROR);
                if(!is_string($value)&&$value!==null)throw new \DomainException('Invalid overlay type');
                $overlays[$row['field_key']]=$value;
            }
            $q=$db->prepare('SELECT display_name,legal_name,description,website_url FROM companies WHERE id=? FOR UPDATE');
            $q->execute([$b['company_id']]);$live=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($live))throw new \DomainException('Missing current company snapshot');
            // Reuse existing 3-way protection, never update origin or personal overlay.
            SeedData::plan(SeedData::snapshot($base),SeedData::snapshot($base),$overlays,SeedData::snapshot($live));
        }
        foreach(['brands'=>'brand_ids','jobs'=>'job_ids','company_locations'=>'worksite_ids'] as $table=>$key){
            foreach($b[$key] as $id){
                $q=$db->prepare('SELECT id,company_id,archived_at FROM '.$table.' WHERE id=? FOR UPDATE');
                $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if(!is_array($row)||($row['id']??null)!==$id||($row['company_id']??null)!==$b['company_id']
                   ||($row['archived_at']??null)!==null)throw new \DomainException('Entity missing/archived or wrong owner');
            }
        }
        // Explicit job-to-worksite relations must already exist; same company
        // ownership alone does not establish that a particular job is at that site.
        foreach($b['job_worksite_edges'] as $edge){
            $q=$db->prepare('SELECT job_id,location_id FROM job_locations WHERE job_id=? AND location_id=? FOR UPDATE');
            $q->execute([$edge['job_id'],$edge['worksite_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||($row['job_id']??null)!==$edge['job_id']
               ||($row['location_id']??null)!==$edge['worksite_id'])
                throw new \DomainException('Job-worksite relation is not an existing DB edge');
        }
    }
    private static function result(string $status,string $batch,array $plan,int $audit):array {
        return ['status'=>$status,'batch_id'=>$batch,'research_version'=>self::VERSION,
            'records_staged'=>27,'technical_bindings'=>$plan['bound_count'],'audit_events_added'=>$audit,
            'business_records_changed'=>0,'published'=>false,'notifications_queued'=>0,'outbox_dispatchable'=>false];
    }
}
