<?php
declare(strict_types=1);
namespace KMM\Core;
/** Round39: additive, non-public by default. No researcher record or Overlay mutation. */
final class ResearchSupplementSchema {
    public static function statements(): array {return [
        "CREATE TABLE IF NOT EXISTS research_supplement_claims (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,research_version VARCHAR(30) NOT NULL,review_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,source_record_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,field_key VARCHAR(40) NOT NULL,target_scope VARCHAR(20) NOT NULL,field_label VARCHAR(100) NOT NULL,claim_text TEXT NOT NULL,source_url VARCHAR(2000) NOT NULL,source_checked_on DATE NOT NULL,research_status VARCHAR(32) NOT NULL,company_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,company_revision INT UNSIGNED NULL,job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,job_revision INT UNSIGNED NULL,publication_state VARCHAR(20) NOT NULL DEFAULT 'private',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_supp_company(company_id,publication_state,field_key),INDEX idx_supp_job(job_id,publication_state,field_key),INDEX idx_supp_source(research_version,review_id),CONSTRAINT fk_supp_company FOREIGN KEY(company_id) REFERENCES companies(id),CONSTRAINT fk_supp_job FOREIGN KEY(job_id) REFERENCES jobs(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];}
}
