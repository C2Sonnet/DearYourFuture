<?php
declare(strict_types=1);
namespace KMM\Core;
/** Schema31: private operator workflow only. No company/brand/job/location publication. */
final class ResearchIdentityReviewSchema {
    public static function statements():array { return [
        "CREATE TABLE IF NOT EXISTS research_identity_review_states (research_version VARCHAR(20) NOT NULL,review_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,source_record_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,evidence_upload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,state VARCHAR(32) NOT NULL,reason_code VARCHAR(40) NULL,revision INT UNSIGNED NOT NULL DEFAULT 1,last_actor_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(research_version,review_id),INDEX idx_research_review_state(research_version,state),CONSTRAINT fk_research_review_actor FOREIGN KEY(last_actor_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];}
}
