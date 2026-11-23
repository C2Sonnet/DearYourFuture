<?php
declare(strict_types=1);
namespace KMM\Core;
/** R41 additive revision fence. Missing guard means NOT public, even for old links. */
final class ResearchLinkRevisionSchema {
    public static function statements():array {return [
        "CREATE TABLE IF NOT EXISTS research_link_revision_guards (link_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,company_revision INT UNSIGNED NOT NULL,job_revision INT UNSIGNED NULL,recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,CONSTRAINT fk_research_link_guard_link FOREIGN KEY(link_id) REFERENCES research_external_links(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];}
}
