<?php
declare(strict_types=1);
namespace KMM\Core;
/** v40: technical publication transaction ledger, NOT a mail delivery queue. */
final class ResearchPublicationSchema {
    public static function statements():array { return [
        "CREATE TABLE IF NOT EXISTS research_publication_batches (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,event_key VARCHAR(100) NOT NULL UNIQUE,payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,source_manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,link_catalog_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,actor_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,claim_count SMALLINT UNSIGNED NOT NULL,link_count SMALLINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,CONSTRAINT fk_research_pub_actor FOREIGN KEY(actor_id) REFERENCES users(id),INDEX idx_research_pub_status(status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS research_publication_outbox (event_key VARCHAR(100) PRIMARY KEY,batch_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,event_type VARCHAR(60) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'held',payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,CONSTRAINT fk_research_pub_outbox_batch FOREIGN KEY(batch_id) REFERENCES research_publication_batches(id),INDEX idx_research_pub_outbox_status(status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ]; }
}
