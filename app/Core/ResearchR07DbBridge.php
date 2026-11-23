<?php
declare(strict_types=1);
namespace KMM\Core;

use PDO;

/**
 * Optional R07 Batch22 sidecar: snapshot import without editing R06 canonical tables.
 * All SQL mutation is contained in three research_r07b22_* tables and requires an
 * explicit isolated staging invocation. Import and DB verification are external.
 */
final class ResearchR07DbBridge {
    public const RELEASE = 'd1.0.4-R07-BATCH22';
    public const SOURCE_SHA256 = '3bb3be0ff16513f0fd707b4296de1ba9da83e1d9416cb2479c6c99ecf5aa9146';
    private const TABLES = ['research_r07b22_snapshot_batches','research_r07b22_company_snapshots','research_r07b22_job_snapshots'];
    private const COUNTS = ['companies'=>108,'jobs'=>137];

    private static function legacyData():array {
        $path=SiteState::root().'/resources/research/r07-batch22/COMPANY_JOB_RELATIONS_d1.0.4_R07_BATCH22_20261122.json';
        if(!is_file($path)||!hash_equals(self::SOURCE_SHA256,(string)hash_file('sha256',$path)))
            throw new \RuntimeException('Legacy R07 Batch22 source checksum mismatch');
        return json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    }
    /** The compiled PHP binary can render a static plan without DB credentials. */
    public static function sourcePlan():array {
        // Historic Batch22 sidecar is available for old audit records only.
        $data=self::legacyData();$companies=[];$jobs=[];
        foreach($data['companies'] as $row){
            if(!is_array($row) || !ResearchR07::isCompanyId($row['company_id']??''))throw new \RuntimeException('Invalid company source ID');
            $id=$row['company_id'];if(isset($companies[$id]))throw new \RuntimeException('Duplicate source company ID');
            $companies[$id]=true;
        }
        foreach($data['jobs'] as $row){
            if(!is_array($row)||!ResearchR07::isJobId($row['job_id']??''))throw new \RuntimeException('Invalid job source ID');
            $id=$row['job_id'];if(isset($jobs[$id]))throw new \RuntimeException('Duplicate source job ID');
            if(!isset($companies[$row['company_id']??'']))throw new \RuntimeException('Orphan source job');
            $jobs[$id]=true;
        }
        if(count($companies)!==108||count($jobs)!==137)throw new \RuntimeException('Research count mismatch');
        return ['source_release'=>self::RELEASE,'source_sha256'=>self::SOURCE_SHA256,
            'companies'=>count($companies),'jobs'=>count($jobs),
            'canonical_schema'=>Migration::SCHEMA,
            'sidecar_tables'=>self::TABLES,'write_scope'=>'research_r07b22_* ONLY',
            'status'=>'SOURCE_PIN_VERIFIED; DATABASE_NOT_TESTED'];
    }

    /** Does not expose credential strings or raw private record values. */
    public static function schemaState(PDO $db):array {
        $sql="SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('research_r07b22_snapshot_batches','research_r07b22_company_snapshots','research_r07b22_job_snapshots')";
        $tables=[];foreach($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){$tables[$r['TABLE_NAME']]=$r['ENGINE'];}
        $ready=count($tables)===3;
        foreach($tables as $engine)if(strcasecmp((string)$engine,'InnoDB')!==0)$ready=false;
        if($ready){
            $expected=['research_r07b22_snapshot_batches'=>['source_release','source_sha256','company_count','job_count','mapped_company_count','mapped_job_count'],
                'research_r07b22_company_snapshots'=>['source_company_id','source_release','mapped_company_id','payload_json','payload_sha256'],
                'research_r07b22_job_snapshots'=>['source_job_id','source_company_id','source_release','mapped_job_id','payload_json','payload_sha256']];
            $cols=[];
            foreach($db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('research_r07b22_snapshot_batches','research_r07b22_company_snapshots','research_r07b22_job_snapshots')")->fetchAll(PDO::FETCH_ASSOC) as $row)$cols[$row['TABLE_NAME']][$row['COLUMN_NAME']]=true;
            foreach($expected as $table=>$keys)foreach($keys as $key)if(!isset($cols[$table][$key]))$ready=false;
        }
        return ['present_tables'=>count($tables),'expected_tables'=>3,'schema_ready'=>$ready];
    }

    private static function installState(PDO $db):array {
        $row=$db->query('SELECT status,schema_version FROM installation_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)||$row['status']!=='installed'||(int)$row['schema_version']!==Migration::SCHEMA)
            throw new \RuntimeException('Installation not complete or canonical schema differs');
        return ['installed'=>true,'schema'=>(int)$row['schema_version']];
    }

    /**
     * R06's exact source ID ledger is the only authority for mapping; never match
     * on company name, URL, job title or brand. CO-0050 alias remains independent.
     */
    public static function crosswalk(PDO $db):array {
        $lookup=['companies'=>[],'jobs'=>[]];
        $q=$db->prepare("SELECT record_type,record_id,base_json FROM seed_entity_states WHERE seed_version='research-d1.0.4-R06' AND record_type IN ('companies','jobs') LIMIT 600");
        $q->execute();
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $item){
            $kind=$item['record_type']??'';$uuid=$item['record_id']??'';
            if(!isset($lookup[$kind]) || !is_string($uuid) || !preg_match('/\A[a-f0-9]{32}\z/D',$uuid))continue;
            $base=json_decode((string)($item['base_json']??''),true);
            if(!is_array($base))continue;
            $field=$kind==='companies'?'source_company_id':'source_job_id';$id=$base[$field]??null;
            if(!is_string($id)||($kind==='companies'?!ResearchR07::isCompanyId($id):!ResearchR07::isJobId($id)))continue;
            if(isset($lookup[$kind][$id])&&$lookup[$kind][$id]!==$uuid)throw new \RuntimeException('Conflicting R06 source ID mapping');
            $lookup[$kind][$id]=$uuid;
            if($kind==='companies')foreach(($base['source_company_aliases']??[]) as $alias){
                if(is_string($alias)&&ResearchR07::isCompanyId($alias)){
                    if(isset($lookup['companies'][$alias])&&$lookup['companies'][$alias]!==$uuid)throw new \RuntimeException('Conflicting R06 company alias');
                    $lookup['companies'][$alias]=$uuid;
                }
            }
        }
        if(count(array_unique(array_values($lookup['companies'])))!==103 || count($lookup['jobs'])!==128)
            throw new \RuntimeException('R06 source mapping count does not match the installed 103/128 baseline');
        // Every UUID must still exist. In particular, never point to a user-owned
        // record that is not in the original R06 source states.
        foreach(['companies','jobs'] as $kind){
            $ids=array_values(array_unique(array_values($lookup[$kind])));
            if($ids===[])throw new \RuntimeException('Missing R06 crosswalk');
            $stmt=$db->prepare("SELECT id FROM `$kind` WHERE id=?");
            foreach($ids as $uuid){$stmt->execute([$uuid]);if($stmt->fetchColumn()!==$uuid)throw new \RuntimeException('Stale R06 crosswalk');}
        }
        return $lookup;
    }

    /** Read-only preflight: neither creates sidecars nor changes any existing record. */
    public static function preflight(PDO $db):array {
        $source=self::sourcePlan();$installed=self::installState($db);$crosswalk=self::crosswalk($db);
        $state=self::schemaState($db);
        $mappedC=0;$mappedJ=0;
        foreach(array_keys(ResearchR07::companies()) as $id)if(isset($crosswalk['companies'][$id]))$mappedC++;
        foreach(array_keys(ResearchR07::jobs()) as $id)if(isset($crosswalk['jobs'][$id]))$mappedJ++;
        $linkedJobs=$db->prepare('SELECT company_id FROM jobs WHERE id=?');
        foreach(ResearchR07::jobs() as $job){
            $jid=$crosswalk['jobs'][$job['job_id']]??null;
            $parent=$crosswalk['companies'][$job['company_id']]??null;
            if($jid!==null){
                if($parent===null)throw new \RuntimeException('Mapped job lacks mapped parent');
                $linkedJobs->execute([$jid]);
                if($linkedJobs->fetchColumn()!==$parent)throw new \RuntimeException('R06 job/company linkage mismatch');
            }
        }
        $old=null;$sidecarRows=null;
        if($state['schema_ready']){
            $q=$db->prepare('SELECT source_sha256,company_count,job_count FROM research_r07b22_snapshot_batches WHERE source_release=?');
            $q->execute([self::RELEASE]);$old=$q->fetch(PDO::FETCH_ASSOC)?:null;
            $sidecarRows=[];
            foreach(self::TABLES as $table)$sidecarRows[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
        }
        return $source+$installed+$state+[
            'mapped_company_source_ids'=>$mappedC, 'unmapped_company_source_ids'=>108-$mappedC,
            'mapped_job_source_ids'=>$mappedJ,'unmapped_job_source_ids'=>137-$mappedJ,
            'existing_batch_matches_source'=>$old!==null && ($old['source_sha256']??null)===self::SOURCE_SHA256,
            'existing_batch_present'=>$old!==null,
            'sidecar_table_rows'=>$sidecarRows,
            'can_apply'=>$state['schema_ready'] && $old===null && $sidecarRows!==null && array_sum($sidecarRows)===0,
            'requires_independent_staging_and_backup'=>true,
            'tests_in_this_codebase'=>'NOT_RUN'
        ];
    }

    /** Insert-only transactional staging import; no canonical/user table is mutated. */
    public static function apply(PDO $db):array {
        // Historical source cannot be imported as the current R07 release.
        throw new \RuntimeException('Batch22 sidecar import disabled after Batch42 consolidation; existing DB unchanged');
        $pre=self::preflight($db);
        if(!$pre['can_apply'])throw new \RuntimeException('Sidecar not prepared or import already exists');
        $mapping=self::crosswalk($db);
        $lockName='kmm:r07:'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,32);
        $lock=$db->prepare('SELECT GET_LOCK(?,5)');$lock->execute([$lockName]);
        if((int)$lock->fetchColumn()!==1)throw new \RuntimeException('R07 import lock unavailable');
        try{
            // Revalidate inside the lock; CREATE TABLE statements are deliberately
            // excluded from this transaction because MySQL DDL implicitly commits.
            $db->beginTransaction();
            $q=$db->prepare('SELECT source_release FROM research_r07b22_snapshot_batches WHERE source_release=? FOR UPDATE');
            $q->execute([self::RELEASE]);if($q->fetchColumn()!==false)throw new \RuntimeException('Research batch already imported');
            $mappedC=$pre['mapped_company_source_ids'];$mappedJ=$pre['mapped_job_source_ids'];
            $db->prepare('INSERT INTO research_r07b22_snapshot_batches(source_release,source_sha256,company_count,job_count,mapped_company_count,mapped_job_count) VALUES (?,?,?,?,?,?)')
                ->execute([self::RELEASE,self::SOURCE_SHA256,108,137,$mappedC,$mappedJ]);
            $insertC=$db->prepare('INSERT INTO research_r07b22_company_snapshots(source_company_id,source_release,mapped_company_id,payload_json,payload_sha256) VALUES (?,?,?,?,?)');
            foreach(ResearchR07::companies() as $id=>$row){
                $payload=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                $insertC->execute([$id,self::RELEASE,$mapping['companies'][$id]??null,$payload,hash('sha256',$payload)]);
            }
            $insertJ=$db->prepare('INSERT INTO research_r07b22_job_snapshots(source_job_id,source_company_id,source_release,mapped_job_id,payload_json,payload_sha256) VALUES (?,?,?,?,?,?)');
            foreach(ResearchR07::jobs() as $id=>$row){
                $payload=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                $insertJ->execute([$id,$row['company_id'],self::RELEASE,$mapping['jobs'][$id]??null,$payload,hash('sha256',$payload)]);
            }
            $cc=(int)$db->query('SELECT COUNT(*) FROM research_r07b22_company_snapshots')->fetchColumn();
            $jc=(int)$db->query('SELECT COUNT(*) FROM research_r07b22_job_snapshots')->fetchColumn();
            if($cc!==108||$jc!==137)throw new \RuntimeException('Research record count mismatch after insert');
            if(!$db->commit())throw new \RuntimeException('Snapshot transaction commit failed');
            return ['committed'=>true,'companies'=>$cc,'jobs'=>$jc,'mapped_companies'=>$mappedC,'mapped_jobs'=>$mappedJ];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        finally{$r=$db->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lockName]);}
    }

    public static function imported(PDO $db):bool {
        if(!self::schemaState($db)['schema_ready'])return false;
        $q=$db->prepare('SELECT source_sha256,company_count,job_count FROM research_r07b22_snapshot_batches WHERE source_release=?');
        $q->execute([self::RELEASE]);$r=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($r)&&hash_equals(self::SOURCE_SHA256,(string)($r['source_sha256']??''))
            &&(int)$r['company_count']===108&&(int)$r['job_count']===137;
    }

    /** Verified DB copy; null means the site continues to use source-pinned files. */
    public static function record(PDO $db,string $kind,string $sourceId):?array {
        if(!in_array($kind,['companies','jobs'],true))return null;
        if(($kind==='companies'&&!ResearchR07::isCompanyId($sourceId))||($kind==='jobs'&&!ResearchR07::isJobId($sourceId)))return null;
        if(!self::imported($db))return null;
        $table=$kind==='companies'?'research_r07b22_company_snapshots':'research_r07b22_job_snapshots';
        $col=$kind==='companies'?'source_company_id':'source_job_id';
        $q=$db->prepare("SELECT payload_json,payload_sha256 FROM $table WHERE $col=? AND source_release=?");
        $q->execute([$sourceId,self::RELEASE]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($r))return null;
        if(!hash_equals((string)$r['payload_sha256'],hash('sha256',(string)$r['payload_json'])))throw new \RuntimeException('R07 DB record checksum mismatch');
        $item=json_decode((string)$r['payload_json'],true,128,JSON_THROW_ON_ERROR);
        if(!is_array($item) || ($item[$kind==='companies'?'company_id':'job_id']??null)!==$sourceId)throw new \RuntimeException('R07 DB source ID mismatch');
        return $item;
    }

    /** Full external post-import verification; reads sidecar only. */
    public static function verify(PDO $db):array {
        $source=self::sourcePlan();
        if(!self::imported($db))throw new \RuntimeException('R07 snapshot not imported');
        $count=[];
        $mapping=self::crosswalk($db);
        foreach(['companies'=>'research_r07b22_company_snapshots','jobs'=>'research_r07b22_job_snapshots'] as $kind=>$table){
            $items=[];
            foreach($db->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC) as $row){
                $id=$kind==='companies'?$row['source_company_id']:$row['source_job_id'];
                if(isset($items[$id]))throw new \RuntimeException('Duplicate DB source ID');
                $items[$id]=true;
                $mapped=$kind==='companies'?$row['mapped_company_id']:$row['mapped_job_id'];
                if($mapped!==($mapping[$kind][$id]??null))throw new \RuntimeException('R06 source crosswalk changed after import');
                $dbItem=self::record($db,$kind,$id);
                $fileItem=$kind==='companies'?ResearchR07::company($id):ResearchR07::job($id);
                if($dbItem!==$fileItem)throw new \RuntimeException('DB file record mismatch');
            }
            $count[$kind]=count($items);
            if($count[$kind]!==self::COUNTS[$kind])throw new \RuntimeException('R07 snapshot is incomplete');
        }
        return $source+['db_snapshot_verified'=>true,'verified_counts'=>$count,'user_owned_table_mutations'=>'NONE_BY_THIS_TOOL'];
    }
}
