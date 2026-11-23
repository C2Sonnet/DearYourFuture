<?php
declare(strict_types=1);
namespace KMM\Core;
/** User-owned data: favorites, private notes, saved filters and guide steps. */
final class WorkspaceSchema {
    public static function statements():array {return [
        "CREATE TABLE IF NOT EXISTS user_favorite_companies (user_id BIGINT UNSIGNED NOT NULL,company_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,company_id),CONSTRAINT fk_favorites_user FOREIGN KEY(user_id) REFERENCES users(id),CONSTRAINT fk_favorites_company FOREIGN KEY(company_id) REFERENCES companies(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_company_notes (user_id BIGINT UNSIGNED NOT NULL,company_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,note TEXT NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(user_id,company_id),CONSTRAINT fk_notes_user FOREIGN KEY(user_id) REFERENCES users(id),CONSTRAINT fk_notes_company FOREIGN KEY(company_id) REFERENCES companies(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_saved_filters (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,label VARCHAR(80) NOT NULL,filters_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_saved_filter_user(user_id,created_at),CONSTRAINT fk_saved_filters_user FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_guide_steps (user_id BIGINT UNSIGNED NOT NULL,step_key VARCHAR(32) NOT NULL,completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,step_key),CONSTRAINT fk_guide_steps_user FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];}
}
