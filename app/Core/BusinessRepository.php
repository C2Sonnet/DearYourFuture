<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** MySQL-first, prepared SQL and revision checked writes. Tables/field names are never read from HTTP. */
final class BusinessRepository {
    private const PARENTS=['brands'=>'company_id','company_locations'=>'company_id','jobs'=>'company_id'];
    private const ARCHIVE=['companies'=>'visibility','brands'=>'archived_at','company_locations'=>'archived_at','jobs'=>'archived_at','sources'=>'archived_at'];

    public static function read(PDO $db,string $type,string $id,bool $publicOnly=false): ?array {
        self::type($type);BusinessData::id($id);
        $sql='SELECT * FROM '.$type.' WHERE id=?';
        if($publicOnly)$sql.=self::publicReadClause($type);
        $q=$db->prepare($sql.' LIMIT 1');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }
    /** The same public permission boundary applies to detail reads as to list views. */
    public static function publicReadClause(string $type): string {
        self::type($type);
        $parentPublic="co.visibility='published' AND NOT EXISTS (SELECT 1 FROM seed_entity_states r6p WHERE r6p.record_type='companies' AND r6p.record_id=co.id AND r6p.seed_version='research-d1.0.4-R06')";
        $jobPublic="NOT EXISTS (SELECT 1 FROM seed_entity_states r6j WHERE r6j.record_type='jobs' AND r6j.record_id=jo.id AND r6j.seed_version='research-d1.0.4-R06')";
        if($type==='companies')return " AND visibility='published' AND NOT EXISTS (SELECT 1 FROM seed_entity_states r6 WHERE r6.record_type='companies' AND r6.record_id=companies.id AND r6.seed_version='research-d1.0.4-R06')";
        if($type==='sources')return " AND archived_at IS NULL AND publication_state='public' AND (EXISTS (SELECT 1 FROM company_sources cs JOIN companies co ON co.id=cs.company_id WHERE cs.source_id=sources.id AND $parentPublic) OR EXISTS (SELECT 1 FROM jobs jo JOIN companies co ON co.id=jo.company_id WHERE jo.source_id=sources.id AND jo.archived_at IS NULL AND $parentPublic AND $jobPublic))";
        $sql=' AND archived_at IS NULL';
        $sql.=" AND EXISTS (SELECT 1 FROM companies co WHERE co.id=$type.company_id AND $parentPublic)";
        if($type==='jobs')$sql.=" AND NOT EXISTS (SELECT 1 FROM seed_entity_states r6 WHERE r6.record_type='jobs' AND r6.record_id=jobs.id AND r6.seed_version='research-d1.0.4-R06')";
        if($type==='company_locations')$sql.=" AND verification_state<>'private' AND (source_id IS NULL OR EXISTS (SELECT 1 FROM sources sx WHERE sx.id=company_locations.source_id AND sx.archived_at IS NULL AND sx.publication_state='public'))";
        return $sql;
    }
    public static function list(PDO $db,string $type,bool $publicOnly=true,int $limit=25,int $offset=0,?string $companyId=null): array {
        self::type($type);
        if($limit<1||$limit>100||$offset<0)throw new \InvalidArgumentException('Invalid pagination');
        $params=[];$conditions=[];
        if($publicOnly)$conditions[]=substr(self::publicReadClause($type),5);
        if($companyId!==null){
            if(!isset(self::PARENTS[$type]))throw new \InvalidArgumentException('Unsupported company scope');
            $conditions[]='company_id=?';$params[]=BusinessData::id($companyId);
        }
        $order=match($type){'companies'=>'display_name','jobs'=>'created_at DESC','sources'=>'observed_on DESC','company_locations'=>'kind, name','brands'=>'name'};
        $sql='SELECT * FROM '.$type.($conditions?' WHERE '.implode(' AND ',$conditions):'').' ORDER BY '.$order.' LIMIT '.$limit.' OFFSET '.$offset;
        $q=$db->prepare($sql);$q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function create(PDO $db,string $type,array $input,int $actorId,?string $companyId=null): string {
        self::type($type);
        $fields=BusinessData::fields($type,$input);
        BusinessData::requireProvenance($type,$fields);
        if(isset(self::PARENTS[$type])){
            $id=BusinessData::id((string)$companyId);
            if(self::read($db,'companies',$id)===null)throw new \InvalidArgumentException('Company not found');
            $fields['company_id']=$id;
        }
        $sourceForCompany=null;
        if($type==='companies'){
            $sourceForCompany=self::sourceId($input['company_source_id']??'');
            if($fields['visibility']==='published' && $sourceForCompany===null)throw new \InvalidArgumentException('Publication requires a cited company source');
        }
        $id=BusinessData::id();
        $ownTransaction=!$db->inTransaction();
        if($ownTransaction)$db->beginTransaction();
        try {
            if($type==='companies')$fields['created_by']=$actorId;
            $keys=array_keys($fields);
            $stmt=$db->prepare('INSERT INTO '.$type.' (id,'.implode(',',$keys).') VALUES (?,'.implode(',',array_fill(0,count($keys),'?')).')');
            $stmt->execute(array_merge([$id],array_values($fields)));
            if($type==='companies'&&$sourceForCompany!==null)self::attachCompanySource($db,$id,$sourceForCompany);
            if($type==='companies'&&$fields['visibility']==='published'&&!self::hasPublicSource($db,$id))throw new \InvalidArgumentException('Published company must have a public authoritative source');
            if($type==='jobs'&&$fields['recruitment_state']==='confirmed_open'&&!self::isPublicRecruitmentSource($db,$fields['source_id']))throw new \InvalidArgumentException('Confirmed recruitment requires public official or job-board evidence');
            self::event($db,$actorId,$type,$id,'create',null,$fields);
            if($ownTransaction)$db->commit();return $id;
        }catch(\Throwable $e){if($ownTransaction && $db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function update(PDO $db,string $type,string $id,array $input,int $actorId,int $expectedRevision): bool {
        self::type($type);BusinessData::id($id);
        if($expectedRevision<1)throw new \InvalidArgumentException('Invalid record revision');
        $fields=BusinessData::fields($type,$input);
        BusinessData::requireProvenance($type,$fields);
        $newSource=($type==='companies')?self::sourceId($input['company_source_id']??''):null;
        $ownTransaction=!$db->inTransaction();
        if($ownTransaction)$db->beginTransaction();
        try {
            $old=self::readForUpdate($db,$type,$id);
            if($old===null)throw new \RuntimeException('Record not found');
            if((int)$old['revision']!==$expectedRevision)throw new \RuntimeException('Stale revision - reload before editing');
            if($type==='company_locations' && ($old['address']!==$fields['address'] || $old['kind']!==$fields['kind'])){
                $fields=SeedEntityData::invalidateLocation($old,$fields);
                BusinessData::requireProvenance($type,$fields);
            }
            if($type==='companies'&&$fields['visibility']==='published'){
                $q=$db->prepare("SELECT COUNT(*) FROM company_sources cs JOIN sources s ON s.id=cs.source_id WHERE cs.company_id=? AND s.archived_at IS NULL AND s.publication_state='public' AND s.source_type IN ('company_official','recruitment_official','job_board','ir','public_agency')");$q->execute([$id]);
                if((int)$q->fetchColumn()===0 && $newSource===null)throw new \InvalidArgumentException('Publication requires a cited company source');
            }
            $sets=array_map(static fn($x)=>$x.'=?',array_keys($fields));
            $stmt=$db->prepare('UPDATE '.$type.' SET '.implode(',',$sets).',revision=revision+1 WHERE id=? AND revision=?');
            $stmt->execute(array_merge(array_values($fields),[$id,$expectedRevision]));
            if($stmt->rowCount()!==1)throw new \RuntimeException('Concurrent update detected');
            if($newSource!==null)self::attachCompanySource($db,$id,$newSource);
            if($type==='companies'&&$fields['visibility']==='published'&&!self::hasPublicSource($db,$id))throw new \InvalidArgumentException('Published company must have a public authoritative source');
            if($type==='jobs'&&$fields['recruitment_state']==='confirmed_open'&&!self::isPublicRecruitmentSource($db,$fields['source_id']))throw new \InvalidArgumentException('Confirmed recruitment requires public official or job-board evidence');
            if($type==='sources')self::verifySourceDependencies($db,$id);
            SeedRepository::captureOverlay($db,$type,$id,$old,$fields,$actorId);
            SeedEntityRepository::captureOverlay($db,$type,$id,$old,$fields,$actorId);
            self::event($db,$actorId,$type,$id,'update',$old,$fields);
            if($ownTransaction)$db->commit();return true;
        }catch(\Throwable $e){if($ownTransaction && $db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function archive(PDO $db,string $type,string $id,int $actorId,int $expectedRevision): void {
        self::type($type);BusinessData::id($id);
        if($expectedRevision<1)throw new \InvalidArgumentException('Invalid revision');
        $ownTransaction=!$db->inTransaction();
        if($ownTransaction)$db->beginTransaction();
        try{
            $old=self::readForUpdate($db,$type,$id);
            if($old===null||(int)$old['revision']!==$expectedRevision)throw new \RuntimeException('Stale or unknown record');
            if($type==='companies')$stmt=$db->prepare("UPDATE companies SET visibility='archived',revision=revision+1 WHERE id=? AND revision=?");
            else $stmt=$db->prepare('UPDATE '.$type.' SET archived_at=UTC_TIMESTAMP(),revision=revision+1 WHERE id=? AND revision=?');
            $stmt->execute([$id,$expectedRevision]);
            if($stmt->rowCount()!==1)throw new \RuntimeException('Concurrent update detected');
            if($type==='sources')self::verifySourceDependencies($db,$id);
            self::event($db,$actorId,$type,$id,'archive',$old,['archived'=>true]);
            if($ownTransaction)$db->commit();
        }catch(\Throwable $e){if($ownTransaction && $db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function attachCompanySource(PDO $db,string $companyId,string $sourceId): void {
        BusinessData::id($companyId);BusinessData::id($sourceId);
        $q=$db->prepare('SELECT 1 FROM sources WHERE id=? AND archived_at IS NULL');$q->execute([$sourceId]);
        if(!$q->fetchColumn())throw new \InvalidArgumentException('Active source required');
        $db->prepare('INSERT IGNORE INTO company_sources(company_id,source_id) VALUES (?,?)')->execute([$companyId,$sourceId]);
    }
    public static function sourcesForCompany(PDO $db,string $companyId): array {
        BusinessData::id($companyId);
        $q=$db->prepare("SELECT s.* FROM sources s JOIN company_sources cs ON cs.source_id=s.id WHERE cs.company_id=? AND s.archived_at IS NULL AND s.publication_state='public' ORDER BY s.observed_on DESC");
        $q->execute([$companyId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function jobsForCompany(PDO $db,string $companyId,bool $publicOnly=true): array {
        return self::list($db,'jobs',$publicOnly,100,0,$companyId);
    }
    public static function locationsForJob(PDO $db,string $jobId,bool $publicOnly=false): array {
        BusinessData::id($jobId);
        $sql='SELECT l.*,jl.assignment_state FROM company_locations l JOIN job_locations jl ON jl.location_id=l.id WHERE jl.job_id=? AND l.archived_at IS NULL';
        if($publicOnly)$sql.=" AND l.verification_state<>'private' AND (l.source_id IS NULL OR EXISTS (SELECT 1 FROM sources sx WHERE sx.id=l.source_id AND sx.archived_at IS NULL AND sx.publication_state='public'))";
        $q=$db->prepare($sql.' ORDER BY l.name');
        $q->execute([$jobId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function assignJobLocation(PDO $db,string $jobId,string $locationId,string $state,int $actorId,bool $remove=false): void {
        BusinessData::id($jobId);BusinessData::id($locationId);
        $state=BusinessData::select($state,['candidate','confirmed']);
        $ownTransaction=!$db->inTransaction();
        if($ownTransaction)$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT j.company_id AS jc,l.company_id AS lc,l.verification_state AS vs,j.archived_at AS ja,l.archived_at AS la FROM jobs j JOIN company_locations l ON l.id=? WHERE j.id=? FOR UPDATE');
            $q->execute([$locationId,$jobId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['jc']!==$row['lc']||$row['ja']!==null||$row['la']!==null)throw new \InvalidArgumentException('Workplace must belong to the same active company');
            if(!$remove && $state==='confirmed' && $row['vs']!=='verified')throw new \InvalidArgumentException('Confirmed assignment requires verified location');
            if($remove)$db->prepare('DELETE FROM job_locations WHERE job_id=? AND location_id=?')->execute([$jobId,$locationId]);
            else $db->prepare('INSERT INTO job_locations(job_id,location_id,assignment_state) VALUES (?,?,?) ON DUPLICATE KEY UPDATE assignment_state=VALUES(assignment_state)')->execute([$jobId,$locationId,$state]);
            self::event($db,$actorId,'job_locations',$jobId,$remove?'unlink':'link',null,['location_id'=>$locationId,'state'=>$state]);
            if($ownTransaction)$db->commit();
        }catch(\Throwable $e){if($ownTransaction && $db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Disallow silently revoking evidence still required by published companies/jobs. */
    private static function verifySourceDependencies(PDO $db,string $sourceId):void {
        $q=$db->prepare("SELECT cs.company_id FROM company_sources cs JOIN companies c ON c.id=cs.company_id WHERE cs.source_id=? AND c.visibility='published'");
        $q->execute([$sourceId]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $companyId){
            if(!self::hasPublicSource($db,(string)$companyId))throw new \InvalidArgumentException('Cannot revoke the last published company citation');
        }
        $q=$db->prepare("SELECT COUNT(*) FROM jobs j JOIN companies c ON c.id=j.company_id WHERE j.source_id=? AND j.recruitment_state='confirmed_open' AND j.archived_at IS NULL AND c.visibility='published'");
        $q->execute([$sourceId]);
        if((int)$q->fetchColumn()>0 && !self::isPublicRecruitmentSource($db,$sourceId))throw new \InvalidArgumentException('Cannot revoke confirmed recruitment evidence');
    }
    private static function isPublicRecruitmentSource(PDO $db,?string $sourceId):bool {
        if($sourceId===null)return false;
        $q=$db->prepare("SELECT 1 FROM sources WHERE id=? AND archived_at IS NULL AND publication_state='public' AND source_type IN ('recruitment_official','company_official','job_board') LIMIT 1");
        $q->execute([$sourceId]);return (bool)$q->fetchColumn();
    }
    private static function hasPublicSource(PDO $db,string $companyId):bool {
        $q=$db->prepare("SELECT 1 FROM company_sources cs JOIN sources s ON s.id=cs.source_id WHERE cs.company_id=? AND s.archived_at IS NULL AND s.publication_state='public' AND s.source_type IN ('company_official','recruitment_official','job_board','ir','public_agency') LIMIT 1");
        $q->execute([$companyId]);return (bool)$q->fetchColumn();
    }
    private static function readForUpdate(PDO $db,string $type,string $id): ?array {
        $q=$db->prepare('SELECT * FROM '.$type.' WHERE id=? FOR UPDATE');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private static function sourceId(mixed $raw): ?string {
        if($raw===null||$raw==='')return null;
        if(!is_string($raw))throw new \InvalidArgumentException('Invalid source ID');
        return BusinessData::id($raw);
    }
    private static function event(PDO $db,int $actorId,string $type,string $id,string $op,?array $before,array $after): void {
        $stmt=$db->prepare('INSERT INTO business_change_events(actor_id,record_type,record_id,operation,before_json,after_json) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$actorId,$type,$id,$op,$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);
        self::publicEvent($db,$type,$id,$op);
    }
    /** Do not copy raw before/after values or user identity into the public change feed. */
    private static function publicEvent(PDO $db,string $type,string $id,string $operation):void {
        if(!in_array($type,ProposalData::TYPES,true) || !in_array($operation,ProposalData::ACTIONS,true))return;
        $current=self::read($db,$type,$id);
        if(!$current)return;
        $companyId=$type==='companies'?$id:(string)$current['company_id'];
        $company=$type==='companies'?$current:self::read($db,'companies',$companyId);
        if(!ProposalData::visibleTarget($type,$current,$company))return;
        if($type==='company_locations'){
            if($current['source_id']===null || $current['verified_on']===null)return;
            $q=$db->prepare("SELECT 1 FROM sources WHERE id=? AND archived_at IS NULL AND publication_state='public'");
            $q->execute([$current['source_id']]);if(!$q->fetchColumn())return;
        }
        $stmt=$db->prepare('INSERT INTO public_change_events(company_id,record_type,operation,summary,source_kind) VALUES (?,?,?,?,?)');
        $stmt->execute([$companyId,$type,$operation,ProposalData::publicSummary($type,$operation),'operator']);
    }
    private static function type(string $type): void {
        if(!in_array($type,BusinessData::KINDS,true))throw new \InvalidArgumentException('Unsupported table');
    }
}
