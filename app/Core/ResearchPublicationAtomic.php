<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/**
 * R40: source-pinned, explicitly mapped, one-transaction site-side publication.
 * Only supplementary facts and source links are projected; the canonical company/job
 * master tables and user Overlays are NEVER rewritten. Any broadcast stays HELD.
 * Operator must acquire preview hash from the live site DB and explicitly confirm.
 * An offline PDO test is not proof of MySQL transactional correctness.
 */
final class ResearchPublicationAtomic {
    private const ID='/\A[a-f0-9]{32}\z/D';
    private const EVENT='/\A[a-zA-Z0-9][a-zA-Z0-9:_-]{7,99}\z/D';
    private const ROLES=['ADMIN','FOUNDER','DEVELOPER'];
    private static function id(mixed $v):string {
        if(!is_string($v)||preg_match(self::ID,$v)!==1)throw new \DomainException('Explicit site DB ID required');
        return $v;
    }
    private static function revision(mixed $v):int {
        if(!is_int($v)||$v<1||$v>2147483646)throw new \DomainException('Explicit expected DB revision required');
        return $v;
    }
    /** Rebuild source facts from the pinned releases, not a caller-supplied row. */
    public static function plan(array $feed,array $links,array $decisions):array {
        $source=ResearchSupplement::plan($feed,$links);
        if(($source['source_manifest_sha256']??null)!=='d85f1a8063fc4093173f9c0a06d3257f40e463d918ac48a5394235219a32ad2d'
           ||($links['source_catalog_sha256']??null)!==ResearchExternalLinks::CATALOG_SHA256
           ||($source['record_count']??0)!==27||($source['link_count']??0)!==141)
            throw new \DomainException('Unpinned research release');
        if(!is_array($decisions)||array_is_list($decisions)||array_diff(array_keys($decisions),['claims','links'])!==[])
            throw new \DomainException('Unrecognized publication decision');
        $claimInput=$decisions['claims']??[];$linkInput=$decisions['links']??[];
        if(!is_array($claimInput)||!array_is_list($claimInput)||!is_array($linkInput)||!array_is_list($linkInput)
           ||count($claimInput)>112||count($linkInput)>141||$claimInput===[]&&$linkInput===[])
            throw new \DomainException('Invalid publication batch size');
        $claimIndex=[];$linkIndex=[];$approved=[];
        foreach($source['candidate_items'] as $item)$claimIndex[$item['id']]=$item;
        foreach($links['items'] as $item)$linkIndex[$item['id']]=$item;
        $companyNames=[];$stageSources=[];
        foreach($feed['records'] as $record){
            $rid=$record['review_id']??null;
            if(!is_string($rid)||isset($stageSources[$rid])||!is_string($record['source_record_sha256']??null))
                throw new \DomainException('Invalid pinned private-stage reference');
            $stageSources[$rid]=['source_record_sha256'=>$record['source_record_sha256'],
                'record_status'=>$record['site_data_status']];
            if(($record['site_data_status']??'')==='RESEARCH_ACCEPTED')$companyNames[(string)$record['name']]=true;
        }
        if(count($stageSources)!==27)throw new \DomainException('Incomplete pinned private-stage reference');
        ksort($stageSources);
        $normalize=static function(array $input,array $index,bool $isLink)use(&$approved,$companyNames):array {
            $out=[];
            foreach($input as $decision){
                if(!is_array($decision)||array_is_list($decision)||array_diff(array_keys($decision),['id','company_id','company_revision','job_id','job_revision','publish'])!==[])
                    throw new \DomainException('Unsupported operator decision field');
                $key=self::id($decision['id']??null);
                if(isset($out[$key])||!isset($index[$key]))throw new \DomainException('Duplicate or unknown research target');
                $row=$index[$key];$cid=self::id($decision['company_id']??null);
                $crev=self::revision($decision['company_revision']??null);
                $job=$decision['job_id']??null;$jrev=$decision['job_revision']??null;
                if(($job===null)!==($jrev===null))throw new \DomainException('Job ID/revision pair required');
                if($job!==null){$job=self::id($job);$jrev=self::revision($jrev);}
                $publish=$decision['publish']??null;
                if(!is_bool($publish))throw new \DomainException('Explicit publication boolean required');
                if($isLink){
                    if($job===null&&in_array($row['kind'],['job','entry'],true)&&$publish)
                        throw new \DomainException('Job/entry link cannot be published as ambiguous company link');
                    if($publish&&!isset($companyNames[$row['source_company']]))
                        throw new \DomainException('Unknown company or HOLD/X source may not be published');
                }else{
                    if(($row['research_status']??null)!=='RESEARCH_ACCEPTED')throw new \DomainException('HOLD/X claim prohibited');
                    if(($row['target_scope']==='job')!==($job!==null))throw new \DomainException('Job-scoped claim requires exactly one explicit job');
                }
                $out[$key]=['source'=>$row,'company_id'=>$cid,'company_revision'=>$crev,
                    'job_id'=>$job,'job_revision'=>$jrev,'publish'=>$publish];
                if(isset($approved[$cid])&&$approved[$cid]!==$crev)throw new \DomainException('Inconsistent revision for shared company');
                $approved[$cid]=$crev;
            }
            ksort($out);return $out;
        };
        $claims=$normalize($claimInput,$claimIndex,false);
        $linkRows=$normalize($linkInput,$linkIndex,true);
        return ['format'=>'kmm-publication-atomic-v1','research_version'=>'d1.0.1','links_version'=>'d1.0.2',
            'source_manifest_sha256'=>$source['source_manifest_sha256'],
            'source_catalog_sha256'=>$links['source_catalog_sha256'],
            'private_stage_sources'=>$stageSources,
            'claims'=>$claims,'links'=>$linkRows,'source_count'=>112,'link_source_count'=>141,
            'master_business_mutation'=>false,'user_overlay_mutation'=>false,'external_delivery_allowed'=>false];
    }
    /** Read-only preview of actual DB state. No content or personal Overlay is returned. */
    public static function preview(PDO $db,array $plan):array {
        if($db->inTransaction())throw new \DomainException('Preview cannot run in caller transaction');
        $state=self::liveState($db,$plan,false);
        return ['digest'=>hash('sha256',SeedData::encode(['plan'=>$plan,'live'=>$state])),
            'companies'=>count($state['companies']),'jobs'=>count($state['jobs']),
            'claims'=>count($plan['claims']),'links'=>count($plan['links']),
            'source_values_changed'=>false,'publication_not_committed'=>true,
            'warning'=>'This preview does not approve a company or guarantee recruitment eligibility'];
    }
    /** Locks live identity, revisions, user overlays and relations; never infers a name match. */
    private static function liveState(PDO $db,array $plan,bool $lock):array {
        $companies=[];$jobs=[];$pairs=[];
        foreach(array_merge($plan['claims'],$plan['links']) as $item){
            $cid=$item['company_id'];$companies[$cid]=$item['company_revision'];
            if($item['job_id']!==null){
                $jid=$item['job_id'];$pair=[$cid,$item['job_revision']];
                if(isset($pairs[$jid])&&$pairs[$jid]!==$pair)throw new \DomainException('Conflicting job owner/revision');
                $pairs[$jid]=$pair;
            }
        }
        ksort($companies);ksort($pairs);
        $get=static function(string $sql,array $args)use($db):mixed{
            $stmt=$db->prepare($sql);if($stmt===false||!$stmt->execute($args))throw new \RuntimeException('Database query failed');
            return $stmt->fetch(PDO::FETCH_ASSOC);
        };
        $locking=$lock?' FOR UPDATE':'';
        $snapshot=['companies'=>[],'jobs'=>[]];
        foreach($companies as $cid=>$expected){
            $r=$get('SELECT id,seed_key,revision,visibility,display_name,legal_name,description,website_url FROM companies WHERE id=?'.$locking,[$cid]);
            if(!is_array($r)||($r['id']??null)!==$cid||(int)($r['revision']??0)!==$expected)
                throw new \DomainException('Company missing, remapped or stale');
            // A source claim is never used to modify these master business values.
            $seed=$r['seed_key']??null;$overlayHash=null;
            if($seed!==null&&$seed!==''){
                $q=$db->prepare('SELECT field_key,value_json FROM seed_user_overlays WHERE seed_key=? ORDER BY field_key'.$locking);
                if($q===false||!$q->execute([$seed]))throw new \RuntimeException('Unable to inspect personal edits');
                $rows=$q->fetchAll(PDO::FETCH_ASSOC);
                if(!is_array($rows))throw new \RuntimeException('Invalid Overlay result');
                foreach($rows as $ov){
                    if(!is_string($ov['field_key']??null)||!in_array($ov['field_key'],SeedData::FIELDS,true)
                       ||!is_string($ov['value_json']??null))throw new \DomainException('Invalid user Overlay');
                    json_decode($ov['value_json'],true,64,JSON_THROW_ON_ERROR);
                }
                $overlayHash=hash('sha256',SeedData::encode($rows));
            }
            $snapshot['companies'][$cid]=['revision'=>$expected,'visibility'=>$r['visibility'],'seed_key'=>$seed,
                'value_digest'=>hash('sha256',SeedData::encode([$r['display_name'],$r['legal_name'],$r['description'],$r['website_url']])),
                'overlay_digest'=>$overlayHash];
        }
        foreach($pairs as $jid=>[$cid,$expected]){
            $r=$get('SELECT id,company_id,revision,archived_at,title,category,graduate_year,recruitment_state,deadline_on,note FROM jobs WHERE id=?'.$locking,[$jid]);
            if(!is_array($r)||($r['id']??null)!==$jid||($r['company_id']??null)!==$cid
              ||(int)($r['revision']??0)!==$expected||($r['archived_at']??null)!==null)
                throw new \DomainException('Job missing, archived or belongs to a different company');
            $snapshot['jobs'][$jid]=['company_id'=>$cid,'revision'=>$expected,
                'value_digest'=>hash('sha256',SeedData::encode([$r['title'],$r['category'],$r['graduate_year'],$r['recruitment_state'],$r['deadline_on'],$r['note']]))];
        }
        foreach(array_merge($plan['claims'],$plan['links']) as $item){
            if($item['publish']&&$snapshot['companies'][$item['company_id']]['visibility']!=='published')
                throw new \DomainException('Cannot publish into a non-public company');
        }
        return $snapshot;
    }
    /**
     * One atomic DB transaction; HELD marker is not the deliverable notification queue.
     * HTTP handlers MUST verify CSRF; additionally require recent admin login here.
     */
    public static function apply(PDO $db,array $plan,array $actor,int $authVersion,int $authenticatedAt,
                                 int $now,string $eventKey,string $previewDigest,bool $confirm):array {
        if(($plan['format']??null)!=='kmm-publication-atomic-v1'||!preg_match(self::EVENT,$eventKey)
           ||!preg_match('/\A[a-f0-9]{64}\z/D',$previewDigest)||$confirm!==true)
            throw new \DomainException('Explicit preview and confirmation required');
        $uid=$actor['id']??null;$role=$actor['role']??null;
        if(!is_int($uid)||$uid<1||!in_array($role,self::ROLES,true)||$authVersion<0
           ||$authenticatedAt<=0||$authenticatedAt>$now||$now-$authenticatedAt>900)
            throw new \DomainException('Fresh administrator authentication required');
        if($db->inTransaction())throw new \DomainException('Nested publication not allowed');
        $digest=hash('sha256',SeedData::encode($plan));
        $db->beginTransaction();
        try{
            $q=$db->prepare('SELECT u.status,u.auth_version,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $q->execute([$uid]);$live=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($live)||($live['status']??null)!=='active'||($live['role']??null)!==$role
               ||(int)($live['auth_version']??-1)!==$authVersion)
                throw new \DomainException('Administrator privilege revoked');
            $q=$db->prepare('SELECT id,payload_digest,status,claim_count,link_count FROM research_publication_batches WHERE event_key=? FOR UPDATE');
            $q->execute([$eventKey]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($old)){
                if(($old['status']??null)!=='complete'||!hash_equals((string)($old['payload_digest']??''),$digest)
                   ||(int)$old['claim_count']!==count($plan['claims'])||(int)$old['link_count']!==count($plan['links']))
                    throw new \DomainException('Unsafe replay of publication event');
                $q=$db->prepare('SELECT status,payload_digest FROM research_publication_outbox WHERE batch_id=? FOR UPDATE');
                $q->execute([$old['id']]);$box=$q->fetch(PDO::FETCH_ASSOC);
                if(!is_array($box)||($box['status']??null)!=='held'||!hash_equals((string)($box['payload_digest']??''),$digest))
                    throw new \DomainException('Publication ledger is inconsistent');
                $db->commit();return ['status'=>'duplicate','changed'=>0,'notifications_sent'=>0];
            }
            // A completed flag alone is not proof of the pinned 27-record release.
            // Inspect the complete private cohort and its held outbox under the same TX.
            $q=$db->prepare("SELECT id,record_count,payload_digest FROM research_import_batches WHERE research_version='d1.0.1' AND status='private_complete' ORDER BY created_at DESC LIMIT 1 FOR UPDATE");
            $q->execute();$stage=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($stage)||($stage['record_count']??null)===null
               ||(int)$stage['record_count']!==27||!is_string($stage['payload_digest']??null)
               ||preg_match('/\A[a-f0-9]{64}\z/D',$stage['payload_digest'])!==1)
                throw new \DomainException('Pinned private import batch incomplete');
            $q=$db->prepare('SELECT review_id,source_record_sha256,record_status,source_record_json FROM research_import_items WHERE batch_id=? ORDER BY review_id FOR UPDATE');
            $q->execute([$stage['id']]);$staged=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($staged)||count($staged)!==27||count($plan['private_stage_sources']??[])!==27)
                throw new \DomainException('Incomplete private source cohort');
            $stageSources=[];
            foreach($staged as $item){
                $rid=$item['review_id']??null;
                if(!is_string($rid)||isset($stageSources[$rid]))throw new \DomainException('Duplicate private source ID');
                $raw=$item['source_record_json']??null;
                if(!is_string($raw))throw new \DomainException('Missing private source payload');
                $decoded=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
                if(!is_array($decoded)||!hash_equals((string)($item['source_record_sha256']??''),
                    hash('sha256',json_encode($decoded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))))
                    throw new \DomainException('Private source payload does not match fingerprint');
                $stageSources[$rid]=['source_record_sha256'=>$item['source_record_sha256']??null,
                    'record_status'=>$item['record_status']??null];
            }
            ksort($stageSources);
            if($stageSources!==$plan['private_stage_sources'])
                throw new \DomainException('Private-stage source differs from pinned release');
            $q=$db->prepare('SELECT status,payload_digest FROM research_import_outbox WHERE batch_id=? FOR UPDATE');
            $q->execute([$stage['id']]);$held=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($held)||($held['status']??null)!=='held'
               ||!hash_equals($stage['payload_digest'],(string)($held['payload_digest']??'')))
                throw new \DomainException('Private import outbox has changed');
            $state=self::liveState($db,$plan,true);
            if(!hash_equals($previewDigest,hash('sha256',SeedData::encode(['plan'=>$plan,'live'=>$state]))))
                throw new \DomainException('Live database changed since preview; new preview required');
            $batch=BusinessData::id();$changedClaims=0;$changedLinks=0;
            $readClaim=$db->prepare('SELECT research_version,review_id,source_record_sha256,field_key,target_scope,field_label,claim_text,source_url,source_checked_on,research_status,company_id,company_revision,job_id,job_revision,publication_state FROM research_supplement_claims WHERE id=? FOR UPDATE');
            $insertClaim=$db->prepare('INSERT INTO research_supplement_claims(id,research_version,review_id,source_record_sha256,field_key,target_scope,field_label,claim_text,source_url,source_checked_on,research_status,company_id,company_revision,job_id,job_revision,publication_state) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $updateClaim=$db->prepare("UPDATE research_supplement_claims SET company_id=?,company_revision=?,job_id=?,job_revision=?,publication_state=? WHERE id=? AND publication_state='private'");
            foreach($plan['claims'] as $id=>$v){
                $s=$v['source'];$published=$v['publish']?'published':'private';
                $core=[$s['research_version'],$s['review_id'],$s['source_record_sha256'],$s['field_key'],$s['target_scope'],$s['field_label'],$s['claim_text'],$s['source_url'],$s['source_checked_on'],$s['research_status']];
                $readClaim->execute([$id]);$old=$readClaim->fetch(PDO::FETCH_ASSOC);
                if(is_array($old)){
                    foreach(['research_version','review_id','source_record_sha256','field_key','target_scope','field_label','claim_text','source_url','source_checked_on','research_status'] as $n=>$key)
                        if(($old[$key]??null)!==$core[$n])throw new \DomainException('Existing claim provenance differs');
                    if(($old['publication_state']??null)!=='private'||($old['company_id']??null)!==null||($old['job_id']??null)!==null)
                        throw new \DomainException('Previously bound claim needs separate review/unpublication');
                    $updateClaim->execute([$v['company_id'],$v['company_revision'],$v['job_id'],$v['job_revision'],$published,$id]);
                    if($updateClaim->rowCount()!==1)throw new \DomainException('Concurrent claim publication');
                }else{
                    $insertClaim->execute(array_merge([$id],$core,[$v['company_id'],$v['company_revision'],$v['job_id'],$v['job_revision'],$published]));
                }
                $changedClaims++;
            }
            $guardRead=$db->prepare('SELECT company_revision,job_revision FROM research_link_revision_guards WHERE link_id=? FOR UPDATE');
            $guardWrite=$db->prepare('INSERT INTO research_link_revision_guards(link_id,company_revision,job_revision) VALUES (?,?,?)');
            $readLink=$db->prepare('SELECT source_version,source_company,source_classification,link_kind,url,source_status,source_checked_on,source_notes,source_catalog_sha256,company_id,job_id,visibility FROM research_external_links WHERE id=? FOR UPDATE');
            $insertLink=$db->prepare('INSERT INTO research_external_links(id,source_version,source_company,source_classification,link_kind,url,source_status,source_checked_on,source_notes,source_catalog_sha256,company_id,job_id,visibility) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $updateLink=$db->prepare("UPDATE research_external_links SET company_id=?,job_id=?,visibility=? WHERE id=? AND company_id IS NULL AND job_id IS NULL AND visibility='private'");
            foreach($plan['links'] as $id=>$v){
                $s=$v['source'];$vis=$v['publish']?'published':'private';
                $core=[$plan['links_version'],$s['source_company'],$s['classification'],$s['kind'],$s['url'],$s['source_status'],$s['source_checked_on'],$s['source_notes'],$plan['source_catalog_sha256']];
                $readLink->execute([$id]);$old=$readLink->fetch(PDO::FETCH_ASSOC);
                if(is_array($old)){
                    foreach(['source_version','source_company','source_classification','link_kind','url','source_status','source_checked_on','source_notes','source_catalog_sha256'] as $n=>$key)
                        if(($old[$key]??null)!==$core[$n])throw new \DomainException('Existing link source differs');
                    if(($old['visibility']??null)!=='private'||($old['company_id']??null)!==null||($old['job_id']??null)!==null)
                        throw new \DomainException('Previously bound link requires a separate change workflow');
                    $updateLink->execute([$v['company_id'],$v['job_id'],$vis,$id]);
                    if($updateLink->rowCount()!==1)throw new \DomainException('Concurrent link publication');
                }else{
                    $insertLink->execute(array_merge([$id],$core,[$v['company_id'],$v['job_id'],$vis]));
                }
                $guardRead->execute([$id]);
                if($guardRead->fetch(PDO::FETCH_ASSOC)!==false)throw new \DomainException('Unexpected existing revision guard; reviewed reapproval required');
                $guardWrite->execute([$id,$v['company_revision'],$v['job_revision']]);
                $changedLinks++;
            }
            $db->prepare('INSERT INTO research_publication_batches(id,event_key,payload_digest,source_manifest_sha256,link_catalog_sha256,actor_id,status,claim_count,link_count) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$batch,$eventKey,$digest,$plan['source_manifest_sha256'],$plan['source_catalog_sha256'],$uid,'complete',$changedClaims,$changedLinks]);
            $heldKey='rp_'.substr(hash('sha256',$eventKey.':'.$digest),0,40);
            $db->prepare('INSERT INTO research_publication_outbox(event_key,batch_id,event_type,status,payload_digest) VALUES (?,?,?,?,?)')
                ->execute([$heldKey,$batch,'reviewed_site_publication','held',$digest]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                ->execute([$uid,'research.publication.technical','research_publication',$batch,SeedData::encode([
                    'research_version'=>'d1.0.1','links_version'=>'d1.0.2',
                    'claims'=>$changedClaims,'links'=>$changedLinks,'digest'=>$digest,
                    'outbox'=>'held','notifications_sent'=>0,'master_company_job_changed'=>false])]);
            $db->commit();return ['status'=>'complete','claim_changes'=>$changedClaims,'link_changes'=>$changedLinks,
                'business_master_updates'=>0,'user_overlay_updates'=>0,'notifications_sent'=>0,'outbox_state'=>'held'];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
