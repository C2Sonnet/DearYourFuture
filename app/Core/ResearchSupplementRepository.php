<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Only explicit, previously published mappings with current entity revisions are visible. */
final class ResearchSupplementRepository {
    public static function forCompany(PDO $db,string $companyId):array {
        BusinessData::id($companyId);
        $q=$db->prepare("SELECT s.* FROM research_supplement_claims s JOIN companies c ON c.id=s.company_id WHERE s.company_id=? AND s.job_id IS NULL AND s.target_scope='company' AND s.research_status='RESEARCH_ACCEPTED' AND s.publication_state='published' AND c.visibility='published' AND c.revision=s.company_revision ORDER BY s.field_key,s.id LIMIT 80");
        $q->execute([$companyId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function forJob(PDO $db,string $companyId,string $jobId):array {
        BusinessData::id($companyId);BusinessData::id($jobId);
        $q=$db->prepare("SELECT s.* FROM research_supplement_claims s JOIN companies c ON c.id=s.company_id JOIN jobs j ON j.id=s.job_id AND j.company_id=c.id WHERE s.company_id=? AND s.job_id=? AND s.target_scope='job' AND s.research_status='RESEARCH_ACCEPTED' AND s.publication_state='published' AND c.visibility='published' AND c.revision=s.company_revision AND j.archived_at IS NULL AND j.revision=s.job_revision ORDER BY s.field_key,s.id LIMIT 80");
        $q->execute([$companyId,$jobId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
