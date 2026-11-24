<?php
declare(strict_types=1);
namespace KMM\Core;

/** New-installation schema v6: separate review inbox, public-safe events, no implicit upgrade. */
final class ProposalSchema {
    public static function statements(): array { return [
        "CREATE TABLE IF NOT EXISTS edit_proposals (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,author_id BIGINT UNSIGNED NOT NULL,record_type VARCHAR(40) NOT NULL,operation VARCHAR(20) NOT NULL,record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,company_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,base_revision INT UNSIGNED NULL,proposed_json LONGTEXT NULL,reason TEXT NOT NULL,evidence_url VARCHAR(2000) NULL,status VARCHAR(24) NOT NULL DEFAULT 'pending',reviewer_id BIGINT UNSIGNED NULL,review_reason TEXT NULL,accepted_record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,reviewed_at DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_proposal_author(author_id,created_at),INDEX idx_proposal_review(status,created_at),INDEX idx_proposal_target(record_type,record_id),CONSTRAINT fk_proposal_author FOREIGN KEY(author_id) REFERENCES users(id),CONSTRAINT fk_proposal_reviewer FOREIGN KEY(reviewer_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS public_change_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,record_type VARCHAR(40) NOT NULL,operation VARCHAR(20) NOT NULL,summary VARCHAR(240) NOT NULL,source_kind VARCHAR(30) NOT NULL DEFAULT 'operator',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_public_events(created_at,id),INDEX idx_public_company(company_id,created_at),CONSTRAINT fk_public_company FOREIGN KEY(company_id) REFERENCES companies(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ]; }
}
