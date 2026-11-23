<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Additive 3-way reconciler for existing, independently enrolled brands/jobs/workplaces. */
final class SeedEntityRepository {
    public static function validate(array $payload):array {
        $key=$payload['event_key']??null;
        if(!is_string($key)||!preg_match('/\A[a-zA-Z0-9:_-]{8,100}\z/D',$key))throw new \InvalidArgumentException('Invalid batch key');
        $version=SeedData::version((string)($payload['seed_version']??''));
        $document=BusinessData::url($payload['source_document']??'');
        $checked=BusinessData::optionalDate($payload['source_checked_on']??'');
        if($document===null||$checked===null)throw new \InvalidArgumentException('Public source URL and checked date required');
        $items=$payload['records']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>250)throw new \InvalidArgumentException('1–250 complete records required');
        $records=[];$ids=[];
        foreach($items as $input){
            if(!is_array($input))throw new \InvalidArgumentException('Invalid entity snapshot');
            $type=SeedEntityData::type((string)($input['record_type']??''));
            $id=BusinessData::id((string)($input['record_id']??''));
            $company=BusinessData::id((string)($input['company_id']??''));
            $seedKey=SeedData::key((string)($input['seed_key']??''));
            if(isset($records[$seedKey])||isset($ids[$type.':'.$id]))throw new \InvalidArgumentException('Duplicate seed identity');
            $snapshot=SeedEntityData::snapshot($type,$input);
            $records[$seedKey]=['record_type'=>$type,'record_id'=>$id,'company_id'=>$company,'snapshot'=>$snapshot];
            $ids[$type.':'.$id]=true;
        }
        ksort($records);
        return ['event_key'=>$key,'version'=>$version,'source_document'=>$document,'source_checked_on'=>$checked,'records'=>$records];
    }
    /** Initial enrollment never edits a business row. Operator must verify origin equals existing state. */
    public static function enroll(PDO $db,array $raw):int {
        $data=self::validate($raw);
        $db->beginTransaction();$count=0;
        try{
            foreach($data['records'] as $key=>$item){
                $row=self::readLocked($db,$item['record_type'],$item['record_id']);
                self::assertOwner($row,$item);
                if(SeedEntityData::snapshot($item['record_type'],$row)!==$item['snapshot'])throw new \RuntimeException('Initial snapshot differs from existing record; reconcile manually first');
                $q=$db->prepare('SELECT seed_key,record_type,record_id FROM seed_entity_states WHERE seed_key=? OR (record_type=? AND record_id=?) FOR UPDATE');
                $q->execute([$key,$item['record_type'],$item['record_id']]);
                if($q->fetch())throw new \RuntimeException('Entity already enrolled; cannot replace an existing origin');
                $db->prepare('INSERT INTO seed_entity_states(seed_key,record_type,record_id,company_id,base_json,seed_version,source_document,source_checked_on) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$key,$item['record_type'],$item['record_id'],$item['company_id'],SeedData::encode($item['snapshot']),$data['version'],$data['source_document'],$data['source_checked_on']]);
                $count++;
            }
            $db->commit();return $count;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function apply(PDO $db,array $raw,?int $actor=null):array {
        $data=self::validate($raw);$digest=hash('sha256',SeedData::encode($data));
        $own=!$db->inTransaction();if($own)$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT id,payload_digest FROM seed_update_batches WHERE event_key=? FOR UPDATE');
            $q->execute([$data['event_key']]);$prior=$q->fetch(PDO::FETCH_ASSOC);
            if($prior){
                if(!hash_equals((string)$prior['payload_digest'],$digest))throw new \RuntimeException('Batch key reused with other content');
                if($own)$db->commit();return ['status'=>'duplicate','batch_id'=>$prior['id'],'changed'=>0,'conflicts'=>0,'broadcasts'=>0];
            }
            $batch=BusinessData::id();$changed=0;$conflicts=0;
            $db->prepare("INSERT INTO seed_update_batches(id,event_key,payload_digest,seed_version,source_document,status) VALUES (?,?,?,?,?,'processing')")
                ->execute([$batch,$data['event_key'],$digest,$data['version'],$data['source_document']]);
            foreach($data['records'] as $key=>$item){
                // Lock the live business row first, matching the admin CRUD lock order.
                $row=self::readLocked($db,$item['record_type'],$item['record_id']);self::assertOwner($row,$item);
                $q=$db->prepare('SELECT * FROM seed_entity_states WHERE seed_key=? FOR UPDATE');$q->execute([$key]);$state=$q->fetch(PDO::FETCH_ASSOC);
                if(!$state||$state['record_type']!==$item['record_type']||$state['record_id']!==$item['record_id']||$state['company_id']!==$item['company_id'])throw new \RuntimeException('Unknown or mismatched enrolled seed entity: '.$key);
                $q=$db->prepare("SELECT COUNT(*) FROM seed_entity_conflicts WHERE seed_key=? AND status='pending'");$q->execute([$key]);
                if((int)$q->fetchColumn()>0)throw new \RuntimeException('Unresolved seed entity conflicts: '.$key);
                $base=json_decode((string)$state['base_json'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($base))throw new \RuntimeException('Invalid baseline data');
                $q=$db->prepare('SELECT field_key,value_json FROM seed_entity_overlays WHERE seed_key=?');$q->execute([$key]);$overlay=[];
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $o)$overlay[$o['field_key']]=json_decode((string)$o['value_json'],true,512,JSON_THROW_ON_ERROR);
                $plan=SeedEntityData::plan($item['record_type'],$base,$item['snapshot'],$overlay,$row);
                if($plan['changed']){
                    self::writeFields($db,$item['record_type'],$item['record_id'],$row,$plan['changed']);$changed++;
                    $q=$db->prepare("SELECT visibility FROM companies WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([$item['company_id']]);
                    if($q->fetchColumn()==='published')self::effect($db,$batch,$item['company_id']);
                    $db->prepare('INSERT INTO business_change_events(actor_id,record_type,record_id,operation,before_json,after_json) VALUES (NULL,?,?,?, ?,?)')
                        ->execute([$item['record_type'],$item['record_id'],'seed_update',SeedData::encode($base),SeedData::encode($plan['changed'])]);
                }
                foreach($plan['conflicts'] as $field=>$c){
                    $db->prepare('INSERT INTO seed_entity_conflicts(batch_id,seed_key,record_type,record_id,company_id,field_key,old_json,incoming_json,user_json,effective_json) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$batch,$key,$item['record_type'],$item['record_id'],$item['company_id'],$field,SeedData::encode($c['old']),SeedData::encode($c['incoming']),SeedData::encode($c['user']),SeedData::encode($c['effective'])]);
                    $conflicts++;
                }
                $db->prepare('UPDATE seed_entity_states SET base_json=?,seed_version=?,source_document=?,source_checked_on=? WHERE seed_key=?')
                    ->execute([SeedData::encode($item['snapshot']),$data['version'],$data['source_document'],$data['source_checked_on'],$key]);
            }
            $db->prepare('UPDATE seed_update_batches SET changed_count=?,conflict_count=? WHERE id=?')->execute([$changed,$conflicts,$batch]);
            $notifications=SeedRepository::finishIfResolved($db,$batch);
            if($actor!==null){$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                ->execute([$actor,'seed.entity.batch.applied','seed',$batch,SeedData::encode(['changed'=>$changed,'conflicts'=>$conflicts])]);}
            if($own)$db->commit();return ['status'=>$conflicts?'pending_review':'complete','batch_id'=>$batch,'changed'=>$changed,'conflicts'=>$conflicts,'broadcasts'=>$notifications];
        }catch(\Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Called on manual/approved updates, within BusinessRepository's original transaction. */
    public static function captureOverlay(PDO $db,string $type,string $id,array $old,array $fields,int $actor):void {
        if(!isset(SeedEntityData::FIELDS[$type]))return;
        $q=$db->prepare('SELECT seed_key,base_json FROM seed_entity_states WHERE record_type=? AND record_id=? LIMIT 1 FOR UPDATE');$q->execute([$type,$id]);$state=$q->fetch(PDO::FETCH_ASSOC);
        if(!$state)return;
        $base=json_decode((string)$state['base_json'],true,512,JSON_THROW_ON_ERROR);
        $canonical=SeedEntityData::snapshot($type,array_merge($old,$fields));
        foreach(SeedEntityData::FIELDS[$type] as $field){
            if(!array_key_exists($field,$fields) || $canonical[$field]===SeedEntityData::snapshot($type,$old)[$field])continue;
            if($canonical[$field]===$base[$field]){
                $db->prepare('DELETE FROM seed_entity_overlays WHERE seed_key=? AND field_key=?')->execute([$state['seed_key'],$field]);
            }else{
                $db->prepare('INSERT INTO seed_entity_overlays(seed_key,field_key,value_json,author_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),author_id=VALUES(author_id)')
                    ->execute([$state['seed_key'],$field,SeedData::encode($canonical[$field]),$actor]);
            }
        }
    }
    public static function pending(PDO $db):int{return (int)$db->query("SELECT COUNT(*) FROM seed_entity_conflicts WHERE status='pending'")->fetchColumn();}
    public static function pendingPage(PDO $db,int $page):array {
        if($page<1||$page>1000)throw new \InvalidArgumentException('Invalid page');
        $q=$db->query("SELECT c.*,b.seed_version,e.seed_key AS origin_key FROM seed_entity_conflicts c JOIN seed_update_batches b ON b.id=c.batch_id JOIN seed_entity_states e ON e.seed_key=c.seed_key WHERE c.status='pending' ORDER BY c.id LIMIT 25 OFFSET ".(($page-1)*25));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function resolve(PDO $db,int $id,int $expected,string $choice,?string $manual,int $actor):void {
        $choice=SeedData::decision($choice);if($choice==='later')return;
        if($id<1||$expected<1||$actor<1)throw new \InvalidArgumentException('Invalid conflict resolution');
        $own=!$db->inTransaction();if($own)$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT * FROM seed_entity_conflicts WHERE id=? FOR UPDATE');$q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);
            if(!$c||$c['status']!=='pending'||(int)$c['revision']!==$expected)throw new \RuntimeException('Stale conflict');
            $type=SeedEntityData::type((string)$c['record_type']);$f=(string)$c['field_key'];
            if(!in_array($f,SeedEntityData::FIELDS[$type],true))throw new \RuntimeException('Invalid seeded field');
            $row=self::readLocked($db,$type,(string)$c['record_id']);
            self::assertOwner($row,['company_id'=>$c['company_id']]);
            $current=SeedEntityData::snapshot($type,$row);
            $effective=json_decode((string)$c['effective_json'],true,512,JSON_THROW_ON_ERROR);
            if($current[$f]!==$effective)throw new \RuntimeException('Live field changed; review again');
            $incoming=json_decode((string)$c['incoming_json'],true,512,JSON_THROW_ON_ERROR);
            $chosen=match($choice){'origin'=>$incoming,'user'=>json_decode((string)$c['user_json'],true,512,JSON_THROW_ON_ERROR),'manual'=>$manual};
            $canonical=SeedEntityData::snapshot($type,array_merge($current,[$f=>$chosen]));
            $chosen=$canonical[$f];
            if($chosen!==$effective){
                self::writeFields($db,$type,(string)$c['record_id'],$row,[$f=>$chosen]);
                $q=$db->prepare("SELECT visibility FROM companies WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([$c['company_id']]);
                if($q->fetchColumn()==='published')self::effect($db,(string)$c['batch_id'],(string)$c['company_id']);
                $db->prepare('INSERT INTO business_change_events(actor_id,record_type,record_id,operation,before_json,after_json) VALUES (?,?,?,?,?,?)')
                    ->execute([$actor,$type,$c['record_id'],'seed_conflict_resolved',SeedData::encode([$f=>$effective]),SeedData::encode([$f=>$chosen])]);
            }
            if($chosen===$incoming)$db->prepare('DELETE FROM seed_entity_overlays WHERE seed_key=? AND field_key=?')->execute([$c['seed_key'],$f]);
            else $db->prepare('INSERT INTO seed_entity_overlays(seed_key,field_key,value_json,author_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),author_id=VALUES(author_id)')
                ->execute([$c['seed_key'],$f,SeedData::encode($chosen),$actor]);
            $q=$db->prepare("UPDATE seed_entity_conflicts SET status='resolved',revision=revision+1,decision=?,resolver_id=?,resolved_at=UTC_TIMESTAMP() WHERE id=? AND revision=? AND status='pending'");
            $q->execute([$choice,$actor,$id,$expected]);if($q->rowCount()!==1)throw new \RuntimeException('Concurrent conflict resolution');
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                ->execute([$actor,'seed.entity.conflict.resolved','seed_entity',(string)$id,SeedData::encode(['field'=>$f,'choice'=>$choice])]);
            SeedRepository::finishIfResolved($db,(string)$c['batch_id']);
            if($own)$db->commit();
        }catch(\Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Never infer published job status or geolocation from the raw import. */
    private static function writeFields(PDO $db,string $type,string $id,array $row,array $changed):void {
        $merged=array_merge($row,$changed);
        if($type==='company_locations')$merged=SeedEntityData::invalidateLocation($row,$merged);
        $fields=BusinessData::fields($type,$merged);BusinessData::requireProvenance($type,$fields);
        if($type==='jobs' && $fields['recruitment_state']==='confirmed_open'){
            $q=$db->prepare("SELECT 1 FROM sources WHERE id=? AND archived_at IS NULL AND publication_state='public' AND source_type IN ('recruitment_official','company_official','job_board') LIMIT 1");
            $q->execute([$fields['source_id']]);if(!$q->fetchColumn())throw new \InvalidArgumentException('Confirmed recruitment requires public recruitment evidence');
        }
        $write=[];foreach($changed as $f=>$_){$write[$f]=$fields[$f];}
        if($type==='company_locations')foreach(['verification_state','latitude','longitude','verified_on'] as $f){
            if($fields[$f]!==($row[$f]??null))$write[$f]=$fields[$f];
        }
        if(!$write)return;
        $columns=[];$params=[];foreach($write as $f=>$v){$columns[]=$f.'=?';$params[]=$v;}
        $params[]=$id;$params[]=$row['revision'];
        $q=$db->prepare('UPDATE '.$type.' SET '.implode(',',$columns).',revision=revision+1 WHERE id=? AND revision=?');
        $q->execute($params);if($q->rowCount()!==1)throw new \RuntimeException('Record edited concurrently');
    }
    private static function readLocked(PDO $db,string $type,string $id):?array {
        $type=SeedEntityData::type($type);BusinessData::id($id);
        $q=$db->prepare('SELECT * FROM '.$type.' WHERE id=? FOR UPDATE');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private static function assertOwner(?array $row,array $item):void {
        if(!$row || $row['company_id']!==$item['company_id'] || ($row['archived_at']??null)!==null)throw new \RuntimeException('Entity is missing, archived or owned by another company');
    }
    private static function effect(PDO $db,string $batch,string $company):void {
        $db->prepare('INSERT IGNORE INTO seed_batch_effects(batch_id,company_id) VALUES (?,?)')->execute([$batch,$company]);
    }
}
