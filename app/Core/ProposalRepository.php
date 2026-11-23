<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Submission + review. No member can write live company data. Approval uses one outer transaction. */
final class ProposalRepository {
    public static function submit(PDO $db,int $userId,array $input):string {
        if($userId<1)throw new \InvalidArgumentException('Sign-in required');
        $type=ProposalData::kind($input['record_type']??null);
        $operation=ProposalData::action($input['operation']??null);
        $reason=ProposalData::reason($input['reason']??null);
        $evidence=ProposalData::evidence($input['evidence_url']??'');
        $payload=ProposalData::fields($type,$operation,$input);
        if($payload!==null)self::requirePublicSources($db,$payload);
        $recordId=null;$companyId=null;$baseRevision=null;
        $parentTypes=['brands','jobs','company_locations'];
        if($operation==='create' && in_array($type,$parentTypes,true)){
            $raw=$input['company_id']??null;
            if(!is_string($raw))throw new \InvalidArgumentException('A company is required');
            $companyId=BusinessData::id($raw);
            if(!BusinessRepository::read($db,'companies',$companyId,true))throw new \InvalidArgumentException('Published company required');
        }
        if($operation!=='create'){
            $raw=$input['record_id']??null;
            if(!is_string($raw))throw new \InvalidArgumentException('Record ID required');
            $recordId=BusinessData::id($raw);
            $old=BusinessRepository::read($db,$type,$recordId,true);
            if(!$old)throw new \InvalidArgumentException('Public record not found');
            $companyId=$type==='companies'?$recordId:(string)$old['company_id'];
            if(!BusinessRepository::read($db,'companies',$companyId,true))throw new \InvalidArgumentException('Company is not published');
            if(($old['archived_at']??null)!==null || ($type==='company_locations' && $old['verification_state']==='private'))throw new \InvalidArgumentException('Private record cannot be proposed');
            $baseRevision=(int)$old['revision'];
        }
        $q=$db->prepare("SELECT COUNT(*) FROM edit_proposals WHERE author_id=? AND status='pending'");
        $q->execute([$userId]);
        if((int)$q->fetchColumn()>=25)throw new \RuntimeException('Pending proposal limit reached');
        $id=BusinessData::id();
        $q=$db->prepare("INSERT INTO edit_proposals (id,author_id,record_type,operation,record_id,company_id,base_revision,proposed_json,reason,evidence_url,status) VALUES (?,?,?,?,?,?,?,?,?,?,'pending')");
        $q->execute([$id,$userId,$type,$operation,$recordId,$companyId,$baseRevision,$payload===null?null:json_encode($payload,JSON_THROW_ON_ERROR),$reason,$evidence]);
        return $id;
    }
    public static function listMine(PDO $db,int $userId,int $page=1):array {
        $offset=self::offset($page);
        $q=$db->prepare('SELECT id,record_type,operation,status,reason,created_at,reviewed_at,review_reason FROM edit_proposals WHERE author_id=? ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET '.$offset);
        $q->execute([$userId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function listReview(PDO $db,int $page=1):array {
        $offset=(max(1,$page)-1)*20;
        $q=$db->query("SELECT id,record_type,operation,status,created_at,reason FROM edit_proposals WHERE status='pending' ORDER BY created_at ASC,id ASC LIMIT 20 OFFSET ".$offset);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function readMine(PDO $db,string $id,int $userId):?array {
        BusinessData::id($id);
        $q=$db->prepare('SELECT * FROM edit_proposals WHERE id=? AND author_id=? LIMIT 1');$q->execute([$id,$userId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public static function readReview(PDO $db,string $id):?array {
        BusinessData::id($id);
        $q=$db->prepare('SELECT * FROM edit_proposals WHERE id=? LIMIT 1');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public static function withdraw(PDO $db,string $id,int $actorId):void {
        BusinessData::id($id);
        $q=$db->prepare("UPDATE edit_proposals SET status='withdrawn',updated_at=UTC_TIMESTAMP() WHERE id=? AND author_id=? AND status='pending'");$q->execute([$id,$actorId]);
        if($q->rowCount()!==1)throw new \RuntimeException('Pending proposal unavailable');
    }
    public static function decide(PDO $db,string $id,int $reviewerId,string $role,string $decision,string $note):void {
        BusinessData::id($id);
        if(!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true))throw new \DomainException('Reviewer permission denied');
        if(!in_array($decision,ProposalData::DECISIONS,true))throw new \InvalidArgumentException('Invalid review choice');
        if($reviewerId<1)throw new \InvalidArgumentException('Invalid reviewer');
        $note=BusinessData::text($note,1500,true);
        $db->beginTransaction();
        try{
            // Review privileges must be revalidated against the live user/role
            // inside the same transaction. A stale session role is not authority.
            $q=$db->prepare('SELECT u.status,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $q->execute([$reviewerId]);$reviewer=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($reviewer)||($reviewer['status']??null)!=='active'
               ||($reviewer['role']??null)!==$role)
                throw new \DomainException('Reviewer is no longer an active administrator');
            $q=$db->prepare('SELECT * FROM edit_proposals WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['status']!=='pending')throw new \RuntimeException('Proposal has already been reviewed or withdrawn');
            if((int)$row['author_id']===$reviewerId)throw new \DomainException('You cannot review your own proposal');
            $acceptedId=null;
            if($decision==='approve'){
                $type=ProposalData::kind((string)$row['record_type']);
                $action=ProposalData::action((string)$row['operation']);
                $payload=$row['proposed_json']===null?[]:json_decode((string)$row['proposed_json'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($payload))throw new \RuntimeException('Invalid proposal payload');
                self::requirePublicSources($db,$payload);
                if($action==='create'){
                    if($type!=='companies' && !BusinessRepository::read($db,'companies',(string)$row['company_id'],true))throw new \RuntimeException('Parent company is no longer published');
                    // Re-evaluate all fields and provenance against the live database.
                    if($type==='companies')$payload['visibility']='draft';
                    $acceptedId=BusinessRepository::create($db,$type,$payload,$reviewerId,$row['company_id']);
                }else{
                    $target=(string)$row['record_id'];
                    // The target may have become archived/private since submission.
                    // The normal public-read gate includes a published parent company,
                    // public provenance for locations, and non-archived child records.
                    $current=BusinessRepository::read($db,$type,$target,true);
                    if(!$current || (int)$current['revision']!==(int)$row['base_revision'])
                        throw new \RuntimeException('Proposal target is no longer public or was changed; re-review required');
                    if($action==='archive')BusinessRepository::archive($db,$type,$target,$reviewerId,(int)$row['base_revision']);
                    else{
                        // User may only suggest public data fields. Preserving restricted flags is mandatory.
                        if($type==='companies')$payload['visibility']=$current['visibility'];
                        if($type==='company_locations' && $current['verification_state']==='verified'){
                            // If location/evidence changes, remove its verified pin until an administrator re-verifies it.
                            if(($payload['address']??null)!==$current['address'] || ($payload['source_id']??null)!==$current['source_id']){
                                $payload['verification_state']='candidate';
                                $payload['latitude']=null;$payload['longitude']=null;$payload['verified_on']=null;
                            }else{
                                $payload['verification_state']='verified';
                                $payload['latitude']=$current['latitude'];$payload['longitude']=$current['longitude'];
                                $payload['verified_on']=$current['verified_on'];
                            }
                        }
                        // A confirmed job may be corrected to listed, unverified or closed; never silently reconfirm it.
                        BusinessRepository::update($db,$type,$target,$payload,$reviewerId,(int)$row['base_revision']);
                    }
                    $acceptedId=$target;
                }
            }
            $newStatus=match($decision){'approve'=>'approved','reject'=>'rejected','needs_revision'=>'needs_revision'};
            $u=$db->prepare('UPDATE edit_proposals SET status=?,reviewer_id=?,review_reason=?,accepted_record_id=?,reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status=\'pending\'');
            $u->execute([$newStatus,$reviewerId,$note,$acceptedId,$id]);
            if($u->rowCount()!==1)throw new \RuntimeException('Concurrent proposal review');
            $a=$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)');
            $a->execute([$reviewerId,'proposal.'.$newStatus,'edit_proposal',$id,json_encode(['record_type'=>$row['record_type'],'operation'=>$row['operation'],'record_id'=>$acceptedId],JSON_THROW_ON_ERROR)]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function requirePublicSources(PDO $db,array $payload):void {
        foreach(['source_id','company_source_id'] as $field){
            if(!isset($payload[$field]))continue;
            $source=BusinessData::id((string)$payload[$field]);
            $q=$db->prepare("SELECT 1 FROM sources WHERE id=? AND archived_at IS NULL AND publication_state='public' LIMIT 1");
            $q->execute([$source]);
            if(!$q->fetchColumn())throw new \InvalidArgumentException('An active public citation is required');
        }
    }
    public static function publishedChanges(PDO $db,int $page=1,?string $companyId=null):array {
        $offset=self::offset($page);
        $where=" WHERE c.visibility='published'";$params=[];
        if($companyId!==null){$companyId=BusinessData::id($companyId);$where.=' AND e.company_id=?';$params[]=$companyId;}
        $sql='SELECT e.id,e.company_id,c.display_name,e.record_type,e.operation,e.summary,e.source_kind,e.created_at FROM public_change_events e JOIN companies c ON c.id=e.company_id'.$where.' ORDER BY e.created_at DESC,e.id DESC LIMIT 25 OFFSET '.$offset;
        $q=$db->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function offset(int $page):int {if($page<1||$page>1000)throw new \InvalidArgumentException('Invalid page');return ($page-1)*25;}
}
