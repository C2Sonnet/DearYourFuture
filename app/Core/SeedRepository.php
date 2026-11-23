<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Transactional company-seed reconciler. Never changes visibility or personal data. */
final class SeedRepository {
    public static function validate(array $payload):array {
        $key=$payload['event_key']??null;
        if(!is_string($key)||!preg_match('/\A[a-zA-Z0-9:_-]{8,100}\z/D',$key))throw new \InvalidArgumentException('Invalid seed batch event key');
        $version=SeedData::version((string)($payload['seed_version']??''));
        $doc=BusinessData::url($payload['source_document']??'');
        $date=BusinessData::optionalDate($payload['source_checked_on']??'');
        if($doc===null||$date===null)throw new \InvalidArgumentException('Document URL and checked date required');
        $items=$payload['companies']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>250)throw new \InvalidArgumentException('1–250 company snapshots required');
        $normalized=[];
        foreach($items as $item){
            if(!is_array($item))throw new \InvalidArgumentException('Invalid company snapshot');
            $seedKey=SeedData::key((string)($item['seed_key']??''));
            if(isset($normalized[$seedKey]))throw new \InvalidArgumentException('Duplicate seed key');
            $normalized[$seedKey]=SeedData::snapshot($item);
        }
        ksort($normalized);
        return ['event_key'=>$key,'version'=>$version,'source_document'=>$doc,'source_checked_on'=>$date,'companies'=>$normalized];
    }
    /** Ingestion works only on an installed, initialized schema-8 site and existing stable seed IDs. */
    public static function apply(PDO $db,array $untrusted,?int $actorId=null):array {
        $data=self::validate($untrusted);
        $digest=hash('sha256',SeedData::encode($data));
        $own=!$db->inTransaction();if($own)$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT id,payload_digest,status FROM seed_update_batches WHERE event_key=? FOR UPDATE');$q->execute([$data['event_key']]);$existing=$q->fetch(PDO::FETCH_ASSOC);
            if($existing){
                if(!hash_equals((string)$existing['payload_digest'],$digest))throw new \RuntimeException('Batch key reused with different content');
                if($own)$db->commit();return ['status'=>'duplicate','batch_id'=>$existing['id'],'changed'=>0,'conflicts'=>0,'broadcasts'=>0];
            }
            $batch=BusinessData::id();
            $db->prepare("INSERT INTO seed_update_batches(id,event_key,payload_digest,seed_version,source_document,status) VALUES (?,?,?,?,?,'processing')")
                ->execute([$batch,$data['event_key'],$digest,$data['version'],$data['source_document']]);
            $changed=0;$conflicts=0;
            foreach($data['companies'] as $seedKey=>$incoming){
                $q=$db->prepare('SELECT s.company_id,s.base_json,s.seed_version,c.display_name,c.legal_name,c.description,c.website_url,c.visibility,c.revision FROM seed_record_states s JOIN companies c ON c.id=s.company_id WHERE s.seed_key=? FOR UPDATE');
                $q->execute([$seedKey]);$state=$q->fetch(PDO::FETCH_ASSOC);
                if(!$state)throw new \RuntimeException('Unknown/uninitialized seed key: '.$seedKey.'; manual onboarding is required');
                $q=$db->prepare("SELECT COUNT(*) FROM seed_merge_conflicts WHERE seed_key=? AND status='pending'");$q->execute([$seedKey]);
                if((int)$q->fetchColumn()>0)throw new \RuntimeException('Unresolved conflict exists for '.$seedKey);
                $base=json_decode((string)$state['base_json'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($base))throw new \RuntimeException('Invalid seed baseline');
                $overlays=[];
                $q=$db->prepare('SELECT field_key,value_json FROM seed_user_overlays WHERE seed_key=?');$q->execute([$seedKey]);
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $overlay)$overlays[$overlay['field_key']]=json_decode($overlay['value_json'],true,512,JSON_THROW_ON_ERROR);
                $current=array_intersect_key($state,array_flip(SeedData::FIELDS));
                $plan=SeedData::plan($base,$incoming,$overlays,$current);
                $n=count($plan['changed']);
                if($n>0){
                    $validated=SeedData::snapshot(array_merge($current,$plan['changed']));
                    $set=[];$params=[];
                    foreach($plan['changed'] as $field=>$value){$set[]=$field.'=?';$params[]=$validated[$field];}
                    $params[]=$state['company_id'];$params[]=$state['revision'];
                    $update=$db->prepare('UPDATE companies SET '.implode(',',$set).',revision=revision+1 WHERE id=? AND revision=?');$update->execute($params);
                    if($update->rowCount()!==1)throw new \RuntimeException('Concurrent company edit');
                    $changed++;
                    $db->prepare('INSERT INTO business_change_events(actor_id,record_type,record_id,operation,before_json,after_json) VALUES (NULL,?,?,?, ?,?)')
                        ->execute(['companies',$state['company_id'],'seed_update',SeedData::encode($current),SeedData::encode($plan['changed'])]);
                    if($state['visibility']==='published')self::effect($db,$batch,$state['company_id']);
                }
                foreach($plan['conflicts'] as $field=>$values){
                    $db->prepare('INSERT INTO seed_merge_conflicts(batch_id,seed_key,company_id,field_key,old_json,incoming_json,user_json,effective_json) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$batch,$seedKey,$state['company_id'],$field,SeedData::encode($values['old']),SeedData::encode($values['incoming']),SeedData::encode($values['user']),SeedData::encode($values['effective'])]);
                    $conflicts++;
                }
                // All origin fields can advance to the new baseline only after the complete plan is recorded.
                $db->prepare('UPDATE seed_record_states SET base_json=?,seed_version=?,source_document=?,source_checked_on=? WHERE seed_key=?')
                    ->execute([SeedData::encode($incoming),$data['version'],$data['source_document'],$data['source_checked_on'],$seedKey]);
                $db->prepare('UPDATE project_seed_records SET seed_version=?,source_document=?,source_checked_on=? WHERE seed_key=?')
                    ->execute([$data['version'],$data['source_document'],$data['source_checked_on'],$seedKey]);
            }
            $db->prepare('UPDATE seed_update_batches SET changed_count=?,conflict_count=? WHERE id=?')->execute([$changed,$conflicts,$batch]);
            $sent=self::finishIfResolved($db,$batch);
            if($actorId!==null)self::audit($db,$actorId,'seed.batch.applied',$batch,['changed'=>$changed,'conflicts'=>$conflicts]);
            if($own)$db->commit();return ['status'=>$conflicts?'pending_review':'complete','batch_id'=>$batch,'changed'=>$changed,'conflicts'=>$conflicts,'broadcasts'=>$sent];
        }catch(\Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Called in BusinessRepository::update's existing transaction for every seeded-company edit. */
    public static function captureOverlay(PDO $db,string $type,string $id,array $old,array $fields,int $actorId):void {
        if($type!=='companies')return;
        $q=$db->prepare('SELECT seed_key,base_json FROM seed_record_states WHERE company_id=? LIMIT 1 FOR UPDATE');$q->execute([$id]);$baseline=$q->fetch(PDO::FETCH_ASSOC);
        if(!$baseline)return;
        $base=json_decode((string)$baseline['base_json'],true,512,JSON_THROW_ON_ERROR);
        foreach(SeedData::FIELDS as $field){
            if(!array_key_exists($field,$fields)||$fields[$field]===$old[$field])continue;
            if($fields[$field]===$base[$field])$db->prepare('DELETE FROM seed_user_overlays WHERE seed_key=? AND field_key=?')->execute([$baseline['seed_key'],$field]);
            else $db->prepare('INSERT INTO seed_user_overlays(seed_key,field_key,value_json,author_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),author_id=VALUES(author_id)')
                ->execute([$baseline['seed_key'],$field,SeedData::encode($fields[$field]),$actorId]);
        }
    }
    public static function pending(PDO $db):int {return (int)$db->query("SELECT COUNT(*) FROM seed_merge_conflicts WHERE status='pending'")->fetchColumn();}
    public static function pendingPage(PDO $db,int $page):array {
        if($page<1||$page>1000)throw new \InvalidArgumentException('Invalid conflict page');
        $q=$db->query("SELECT f.*,c.display_name,b.seed_version FROM seed_merge_conflicts f JOIN companies c ON c.id=f.company_id JOIN seed_update_batches b ON b.id=f.batch_id WHERE f.status='pending' ORDER BY f.id ASC LIMIT 25 OFFSET ".(($page-1)*25));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Resolving one field does not invalidate other fields of the same company. */
    public static function resolve(PDO $db,int $id,int $expected,string $choice,?string $manual,int $adminId):void {
        $choice=SeedData::decision($choice);if($choice==='later')return;
        if($id<1||$expected<1||$adminId<1)throw new \InvalidArgumentException('Invalid conflict action');
        $own=!$db->inTransaction();if($own)$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT * FROM seed_merge_conflicts WHERE id=? FOR UPDATE');$q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);
            if(!$c||$c['status']!=='pending'||(int)$c['revision']!==$expected)throw new \RuntimeException('Conflict has changed, reload');
            $f=$c['field_key'];if(!in_array($f,SeedData::FIELDS,true))throw new \RuntimeException('Invalid conflict field');
            $current=BusinessRepository::read($db,'companies',$c['company_id']);if(!$current)throw new \RuntimeException('Company is gone');
            $old= json_decode($c['effective_json'],true,512,JSON_THROW_ON_ERROR);
            if($current[$f]!==$old)throw new \RuntimeException('Field changed since conflict was queued');
            $incoming=json_decode($c['incoming_json'],true,512,JSON_THROW_ON_ERROR);
            $chosen=match($choice){'origin'=>$incoming,'user'=>json_decode($c['user_json'],true,512,JSON_THROW_ON_ERROR),'manual'=>$manual};
            $validated=SeedData::snapshot(array_merge(array_intersect_key($current,array_flip(SeedData::FIELDS)),[$f=>$chosen]));
            $chosen=$validated[$f];
            if($old!==$chosen){
                $q=$db->prepare('UPDATE companies SET '.$f.'=?,revision=revision+1 WHERE id=? AND revision=?');$q->execute([$chosen,$c['company_id'],$current['revision']]);
                if($q->rowCount()!==1)throw new \RuntimeException('Concurrent field update');
                if($current['visibility']==='published')self::effect($db,$c['batch_id'],$c['company_id']);
                $db->prepare('INSERT INTO business_change_events(actor_id,record_type,record_id,operation,before_json,after_json) VALUES (?,?,?,?,?,?)')
                    ->execute([$adminId,'companies',$c['company_id'],'seed_conflict_resolved',SeedData::encode([$f=>$old]),SeedData::encode([$f=>$chosen])]);
            }
            if($chosen===$incoming)$db->prepare('DELETE FROM seed_user_overlays WHERE seed_key=? AND field_key=?')->execute([$c['seed_key'],$f]);
            else $db->prepare('INSERT INTO seed_user_overlays(seed_key,field_key,value_json,author_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),author_id=VALUES(author_id)')
                ->execute([$c['seed_key'],$f,SeedData::encode($chosen),$adminId]);
            $q=$db->prepare("UPDATE seed_merge_conflicts SET status='resolved',revision=revision+1,decision=?,resolver_id=?,resolved_at=UTC_TIMESTAMP() WHERE id=? AND revision=? AND status='pending'");
            $q->execute([$choice,$adminId,$id,$expected]);if($q->rowCount()!==1)throw new \RuntimeException('Concurrent conflict resolution');
            self::audit($db,$adminId,'seed.conflict.resolved',(string)$id,['choice'=>$choice,'field'=>$f]);
            self::finishIfResolved($db,$c['batch_id']);
            if($own)$db->commit();
        }catch(\Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Exactly once per batch, after all conflicts are resolved; users are delivered later by the CLI worker. */
    public static function finishIfResolved(PDO $db,string $batch):int {
        $q=$db->prepare("SELECT COUNT(*) FROM seed_merge_conflicts WHERE batch_id=? AND status='pending'");$q->execute([$batch]);
        $pending=(int)$q->fetchColumn();
        $q=$db->prepare("SELECT COUNT(*) FROM seed_entity_conflicts WHERE batch_id=? AND status='pending'");$q->execute([$batch]);
        $pending+=(int)$q->fetchColumn();
        if($pending>0){$db->prepare("UPDATE seed_update_batches SET status='pending_review' WHERE id=?")->execute([$batch]);return 0;}
        $q=$db->prepare('SELECT c.id,c.display_name FROM seed_batch_effects e JOIN companies c ON c.id=e.company_id WHERE e.batch_id=? AND c.visibility=\'published\' ORDER BY c.display_name');$q->execute([$batch]);
        $companies=$q->fetchAll(PDO::FETCH_ASSOC);$count=0;
        foreach(array_chunk($companies,5) as $chunk){
            $body="企業情報を更新しました。\n";
            foreach($chunk as $c){$characters=preg_split('//u',(string)$c['display_name'],-1,PREG_SPLIT_NO_EMPTY);$short=implode('',array_slice($characters?:[],0,55));$body.=$short."\n/companies/".$c['id']."\n";}
            $body.="公開情報の更新内容をご確認ください。";
            MessageBroadcast::queue($db,'seed:'.hash('sha256',$batch.':'.$count),'company_seed_update','企業情報が更新されました', $body);
            $count++;
        }
        $db->prepare("UPDATE seed_update_batches SET status='complete',finished_at=UTC_TIMESTAMP() WHERE id=?")->execute([$batch]);return $count;
    }
    private static function effect(PDO $db,string $batch,string $companyId):void {$db->prepare('INSERT IGNORE INTO seed_batch_effects(batch_id,company_id) VALUES (?,?)')->execute([$batch,$companyId]);}
    private static function audit(PDO $db,int $actor,string $type,string $key,array $summary):void {
        $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$actor,$type,'seed',$key,SeedData::encode($summary)]);
    }
}
