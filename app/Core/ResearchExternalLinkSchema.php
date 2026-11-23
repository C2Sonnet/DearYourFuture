<?php
declare(strict_types=1);
namespace KMM\Core;
/** All incoming links remain private unless operator maps to a published company/job. */
final class ResearchExternalLinkSchema {
    public static function statements():array {return [
        "CREATE TABLE IF NOT EXISTS research_external_links (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,source_version VARCHAR(30) NOT NULL,source_company VARCHAR(240) NOT NULL,source_classification VARCHAR(80) NOT NULL,link_kind VARCHAR(20) NOT NULL,url VARCHAR(2000) NOT NULL,source_status VARCHAR(140) NOT NULL,source_checked_on DATE NOT NULL,source_notes TEXT NOT NULL,source_catalog_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,company_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,visibility VARCHAR(20) NOT NULL DEFAULT 'private',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_research_link_company(company_id,visibility,link_kind),INDEX idx_research_link_job(job_id,visibility,link_kind),INDEX idx_research_link_release(source_version,visibility),CONSTRAINT fk_research_link_company FOREIGN KEY(company_id) REFERENCES companies(id),CONSTRAINT fk_research_link_job FOREIGN KEY(job_id) REFERENCES jobs(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];}
}
