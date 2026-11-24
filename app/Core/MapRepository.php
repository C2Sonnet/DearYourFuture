<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** A map point is not public merely because it has coordinates. */
final class MapRepository {
    public const EVIDENCE_TYPES=['company_official','recruitment_official','job_board','public_agency','ir'];
    public static function validPoint(array $row):bool {
        if(($row['company_visibility']??null)!=='published'||($row['verification_state']??null)!=='verified')return false;
        if(($row['source_publication']??null)!=='public'||($row['source_archived']??null)!==null)return false;
        if(!in_array(($row['source_type']??null),self::EVIDENCE_TYPES,true)||empty($row['verified_on']))return false;
        $lat=$row['latitude']??null;$lon=$row['longitude']??null;
        if($lat===null||$lon===null||!is_numeric($lat)||!is_numeric($lon))return false;
        return is_finite((float)$lat)&&is_finite((float)$lon)&&abs((float)$lat)<=90&&abs((float)$lon)<=180;
    }
    public static function pins(PDO $db,?string $companyId=null,?string $kind=null,bool $worksitesOnly=false,?string $region=null,?string $category=null,?string $evidence=null):array {
        if($companyId!==null)BusinessData::id($companyId);
        if($kind!==null && !in_array($kind,BusinessData::LOCATION_KINDS,true))throw new \InvalidArgumentException('Invalid location filter');
        $where=["c.visibility='published'","l.archived_at IS NULL","l.verification_state='verified'",
            'l.latitude IS NOT NULL','l.longitude IS NOT NULL','l.verified_on IS NOT NULL',
            "s.archived_at IS NULL","s.publication_state='public'",
            "s.source_type IN ('company_official','recruitment_official','job_board','public_agency','ir')"];
        $params=[];
        if($companyId!==null){$where[]='l.company_id=?';$params[]=$companyId;}
        if($kind!==null){$where[]='l.kind=?';$params[]=$kind;}
        if($worksitesOnly)$where[]="EXISTS (SELECT 1 FROM job_locations jl JOIN jobs j ON j.id=jl.job_id JOIN sources js ON js.id=j.source_id WHERE jl.location_id=l.id AND jl.assignment_state='confirmed' AND j.archived_at IS NULL AND j.company_id=l.company_id AND js.publication_state='public' AND js.archived_at IS NULL)";

        if($region!==null&&$region!==''){
            if(strlen($region)>80||preg_match('/[\x00-\x1f\x7f]/',$region))throw new \InvalidArgumentException('Invalid region');
            $where[]="l.address LIKE ? ESCAPE '!'";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$region).'%';
        }
        if($category!==null&&$category!==''){
            if(!in_array($category,['pastry','bread','research','product_development','quality_control','other'],true))throw new \InvalidArgumentException('Invalid category');
            $where[]="EXISTS (SELECT 1 FROM job_locations jl JOIN jobs j ON j.id=jl.job_id JOIN sources js ON js.id=j.source_id WHERE jl.location_id=l.id AND j.archived_at IS NULL AND j.category=? AND j.company_id=l.company_id AND js.publication_state='public' AND js.archived_at IS NULL)";$params[]=$category;
        }
        if($evidence!==null&&$evidence!==''){
            if(!in_array($evidence,self::EVIDENCE_TYPES,true))throw new \InvalidArgumentException('Invalid source type');
            $where[]='s.source_type=?';$params[]=$evidence;
        }

        $sql='SELECT l.id,l.company_id,c.display_name AS company_name,c.visibility AS company_visibility,l.name,l.kind,l.address,l.latitude,l.longitude,l.verification_state,l.verified_on,s.publication_state AS source_publication,s.archived_at AS source_archived,s.source_type,s.title AS source_title,s.source_url,EXISTS(SELECT 1 FROM job_locations jl JOIN jobs j ON j.id=jl.job_id WHERE jl.location_id=l.id AND jl.assignment_state=\'confirmed\' AND j.archived_at IS NULL AND j.company_id=l.company_id AND EXISTS (SELECT 1 FROM sources js WHERE js.id=j.source_id AND js.archived_at IS NULL AND js.publication_state=\'public\')) AS is_confirmed_workplace,(SELECT j2.id FROM job_locations jl2 JOIN jobs j2 ON j2.id=jl2.job_id JOIN sources sx2 ON sx2.id=j2.source_id WHERE jl2.location_id=l.id AND jl2.assignment_state=\'confirmed\' AND j2.company_id=l.company_id AND j2.archived_at IS NULL AND sx2.publication_state=\'public\' AND sx2.archived_at IS NULL ORDER BY j2.id LIMIT 1) AS confirmed_job_id FROM company_locations l JOIN companies c ON c.id=l.company_id JOIN sources s ON s.id=l.source_id WHERE '.implode(' AND ',$where).' ORDER BY c.display_name,l.kind,l.name LIMIT 1000';
        $stmt=$db->prepare($sql);$stmt->execute($params);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),[self::class,'validPoint']));
    }
    public static function publishedCompanyCount(PDO $db):int {
        return (int)$db->query("SELECT COUNT(*) FROM companies WHERE visibility='published'")->fetchColumn();
    }
    public static function unpublishedPinCount(PDO $db):int {
        $companyIds=array_unique(array_column(self::pins($db),'company_id'));
        return max(0,self::publishedCompanyCount($db)-count($companyIds));
    }
}
