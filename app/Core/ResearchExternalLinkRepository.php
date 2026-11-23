<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Private d1.0.2 ingestion; explicit company ID binding is required before public buttons. */
final class ResearchExternalLinkRepository {
    private const ID='/\A[a-f0-9]{32}\z/D';
    public static function validatedBindings(array $plan,array $document):array {
        if(array_diff(array_keys($document),['companies','jobs'])!==[] || !is_array($document['companies']??null)||!is_array($document['jobs']??null))
            throw new \DomainException('Mapping document must contain companies and jobs');
        if(array_is_list($document['companies'])&&$document['companies']!==[] || array_is_list($document['jobs'])&&$document['jobs']!==[])
            throw new \DomainException('Mappings must be named objects');
        $allNames=[];$allIds=[];$linkToName=[];
        foreach($plan['items'] as $row){$allNames[$row['source_company']]=$row['classification'];$allIds[$row['id']]=$row;$linkToName[$row['id']]=$row['source_company'];}
        $companies=[];$jobs=[];
        foreach($document['companies'] as $name=>$binding){
            if(!isset($allNames[$name])||!is_array($binding)||array_is_list($binding)||array_diff(array_keys($binding),['company_id','expected_revision','publish'])!==[])
                throw new \DomainException('Unknown company mapping or unexpected field');
            $id=$binding['company_id']??null;$revision=$binding['expected_revision']??null;$publish=$binding['publish']??null;
            if(!is_string($id)||preg_match(self::ID,$id)!==1||!is_int($revision)||$revision<1||$revision>2147483646||!is_bool($publish))
                throw new \DomainException('Company mapping requires ID, revision and explicit boolean publish');
            if($publish && preg_match('/除外|保留/u',$allNames[$name]))throw new \DomainException('Excluded/held research company cannot be published by link import');
            $companies[$name]=['company_id'=>$id,'expected_revision'=>$revision,'publish'=>$publish];
        }
        foreach($document['jobs'] as $linkId=>$jobId){
            if(!isset($allIds[$linkId])||!is_string($jobId)||preg_match(self::ID,$jobId)!==1
               ||!in_array($allIds[$linkId]['kind'],['job','entry'],true) || !isset($companies[$linkToName[$linkId]]))
                throw new \DomainException('Job mapping requires explicit known link and company');
            $jobs[$linkId]=$jobId;
        }
        return ['companies'=>$companies,'jobs'=>$jobs];
    }
    public static function ingest(PDO $db,array $plan,array $bindings,array $actor,int $authVersion):array {
        if(($plan['version']??null)!==ResearchExternalLinks::VERSION||($plan['url_count']??null)!==141
           ||($plan['source_catalog_sha256']??null)!==ResearchExternalLinks::CATALOG_SHA256)
            throw new \DomainException('Pinned source release is required');
        $mapping=self::validatedBindings($plan,$bindings);
        $uid=$actor['id']??null;$role=$actor['role']??null;
        if(!is_int($uid)||$uid<1||!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)||$authVersion<0||$db->inTransaction())
            throw new \DomainException('Authorised non-nested administrator operation required');
        $db->beginTransaction();
        try {
            $u=$db->prepare('SELECT u.status,u.auth_version,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $u->execute([$uid]);$current=$u->fetch(PDO::FETCH_ASSOC);
            if(!is_array($current)||($current['status']??null)!=='active'||($current['role']??null)!==$role||(int)($current['auth_version']??-1)!==$authVersion)
                throw new \DomainException('Administrator has been revoked or changed');
            $validCompanies=[];
            $c=$db->prepare('SELECT id,visibility,revision FROM companies WHERE id=? FOR UPDATE');
            foreach($mapping['companies'] as $name=>$m){
                $c->execute([$m['company_id']]);$company=$c->fetch(PDO::FETCH_ASSOC);
                if(!is_array($company)||(int)($company['revision']??0)!==$m['expected_revision'])
                    throw new \DomainException('Missing or stale site company');
                if($m['publish'] && $company['visibility']!=='published')throw new \DomainException('Only published companies can expose links');
                $validCompanies[$name]=$m['company_id'];
            }
            $jobRevisions=[];
            $q=$db->prepare('SELECT id,company_id,archived_at,revision FROM jobs WHERE id=? FOR UPDATE');
            foreach($mapping['jobs'] as $linkId=>$jobId){
                $name=null;foreach($plan['items'] as $row)if($row['id']===$linkId){$name=$row['source_company'];break;}
                $q->execute([$jobId]);$job=$q->fetch(PDO::FETCH_ASSOC);
                if(!is_array($job)||$job['company_id']!==$validCompanies[$name]||$job['archived_at']!==null||(int)($job['revision']??0)<1)throw new \DomainException('Job belongs to a different company, has bad revision, or is archived');
                $jobRevisions[$linkId]=(int)$job['revision'];
            }
            $select=$db->prepare('SELECT id,source_version,url,link_kind,source_company,source_catalog_sha256,company_id,job_id,visibility FROM research_external_links WHERE id=? FOR UPDATE');
            $insert=$db->prepare('INSERT INTO research_external_links(id,source_version,source_company,source_classification,link_kind,url,source_status,source_checked_on,source_notes,source_catalog_sha256,company_id,job_id,visibility) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $guardRead=$db->prepare('SELECT company_revision,job_revision FROM research_link_revision_guards WHERE link_id=? FOR UPDATE');
            $guardWrite=$db->prepare('INSERT INTO research_link_revision_guards(link_id,company_revision,job_revision) VALUES (?,?,?)');
            $mapped=0;$published=0;$new=0;
            foreach($plan['items'] as $row){
                $m=$mapping['companies'][$row['source_company']]??null;
                $cid=$m['company_id']??null;$jid=$mapping['jobs'][$row['id']]??null;
                // A job/entry link requires an explicit job binding; a company-only approval must not expose it.
                $companyScoped=in_array($row['kind'],['official','recruit'],true);
                $visibility=$cid!==null&&($m['publish']??false)&&($companyScoped||$jid!==null)?'published':'private';
                $select->execute([$row['id']]);$old=$select->fetch(PDO::FETCH_ASSOC);
                if($old!==false){
                    foreach(['id'=>$row['id'],'source_version'=>$plan['version'],'url'=>$row['url'],'link_kind'=>$row['kind'],
                        'source_company'=>$row['source_company'],'source_catalog_sha256'=>$plan['source_catalog_sha256']] as $key=>$expected)
                        if($old[$key]!==$expected)throw new \DomainException('Existing source link differs from pinned data');
                    if($m===null){ // Re-running a catalog import never removes a previous explicit binding.
                        $cid=$old['company_id'];$jid=$old['job_id'];$visibility=$old['visibility'];
                    }elseif($old['company_id']===$cid && $old['job_id']===$jid && $old['visibility']===$visibility){
                        // exact retry; no changes needed
                    }elseif($old['company_id']===null && $old['job_id']===null && $old['visibility']==='private'){
                        $update=$db->prepare("UPDATE research_external_links SET company_id=?,job_id=?,visibility=? WHERE id=? AND company_id IS NULL AND job_id IS NULL AND visibility='private'");
                        $update->execute([$cid,$jid,$visibility,$row['id']]);
                        if($update->rowCount()!==1)throw new \DomainException('Concurrent link mapping update');
                    }elseif($old['company_id']===$cid && $old['job_id']===$jid && $old['visibility']==='private' && $visibility==='published'){
                        $update=$db->prepare("UPDATE research_external_links SET visibility='published' WHERE id=? AND company_id=? AND visibility='private'");
                        $update->execute([$row['id'],$cid]);
                        if($update->rowCount()!==1)throw new \DomainException('Concurrent publication update');
                    }else throw new \DomainException('Conflicting link mapping; use a separate reviewed unpublish/remapping workflow');
                }else{
                    $insert->execute([$row['id'],$plan['version'],$row['source_company'],$row['classification'],$row['kind'],$row['url'],
                        $row['source_status'],$row['source_checked_on'],$row['source_notes'],$plan['source_catalog_sha256'],$cid,$jid,$visibility]);
                    $new++;
                }
                if($cid!==null && $m!==null){
                    $guardRead->execute([$row['id']]);$guard=$guardRead->fetch(PDO::FETCH_ASSOC);
                    $expectedJob=$jid===null?null:($jobRevisions[$row['id']]??null);
                    if($jid!==null&&$expectedJob===null)throw new \DomainException('Missing explicit job revision');
                    if(is_array($guard)){
                        if((int)$guard['company_revision']!==$m['expected_revision'] || ($guard['job_revision']===null?null:(int)$guard['job_revision'])!==$expectedJob)
                            throw new \DomainException('Existing external link revision guard differs; separate reapproval required');
                    }else $guardWrite->execute([$row['id'],$m['expected_revision'],$expectedJob]);
                }
                if($cid!==null)$mapped++;if($visibility==='published')$published++;
            }
            $summary=['source_version'=>$plan['version'],'catalog_sha256'=>$plan['source_catalog_sha256'],
                'links_total'=>141,'inserted'=>$new,'mapped'=>$mapped,'published'=>$published];
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([
                $uid,'research.links.staged','research_external_links','d1.0.2',json_encode($summary,JSON_THROW_ON_ERROR)]);
            $db->commit();return $summary;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** The public company visibility is checked at read time to prevent link leaks after revocation. */
    public static function forCompany(PDO $db,string $companyId):array {
        BusinessData::id($companyId);
        $q=$db->prepare("SELECT l.url,l.link_kind,l.visibility,l.job_id FROM research_external_links l JOIN companies c ON c.id=l.company_id JOIN research_link_revision_guards g ON g.link_id=l.id AND g.company_revision=c.revision WHERE l.company_id=? AND l.visibility='published' AND c.visibility='published' AND ((l.job_id IS NULL AND g.job_revision IS NULL AND l.link_kind IN ('official','recruit')) OR EXISTS(SELECT 1 FROM jobs j WHERE j.id=l.job_id AND j.company_id=c.id AND j.archived_at IS NULL AND j.revision=g.job_revision)) ORDER BY FIELD(l.link_kind,'official','recruit','entry','job'),l.id LIMIT 160");
        $q->execute([$companyId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function forJob(PDO $db,string $companyId,string $jobId):array {
        BusinessData::id($companyId);BusinessData::id($jobId);
        $q=$db->prepare("SELECT l.url,l.link_kind,l.visibility FROM research_external_links l JOIN companies c ON c.id=l.company_id JOIN research_link_revision_guards g ON g.link_id=l.id AND g.company_revision=c.revision JOIN jobs j ON j.company_id=c.id AND j.id=? AND j.archived_at IS NULL WHERE l.company_id=? AND l.visibility='published' AND c.visibility='published' AND ((l.job_id=? AND g.job_revision=j.revision) OR (l.job_id IS NULL AND g.job_revision IS NULL AND l.link_kind IN ('official','recruit'))) ORDER BY FIELD(l.link_kind,'entry','job','recruit','official'),l.id LIMIT 100");
        $q->execute([$jobId,$companyId,$jobId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
