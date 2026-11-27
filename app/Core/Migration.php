<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Safe-to-repeat CREATE statements; MySQL DDL is not transactional. */
final class Migration {
    public const SCHEMA=41;
    public static function statements(): array {return [
        "CREATE TABLE IF NOT EXISTS installation_state (id TINYINT UNSIGNED PRIMARY KEY,install_id CHAR(32) NOT NULL,status VARCHAR(32) NOT NULL,schema_version INT UNSIGNED NOT NULL,release_version VARCHAR(30) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS schema_migrations (version INT UNSIGNED PRIMARY KEY,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(120) PRIMARY KEY,value_json LONGTEXT NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS users (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,handle VARCHAR(40) NOT NULL UNIQUE,email VARCHAR(254) NOT NULL UNIQUE,display_name VARCHAR(120) NOT NULL,password_hash VARCHAR(255) NOT NULL,auth_version INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS roles (role_code VARCHAR(32) PRIMARY KEY,display_name VARCHAR(80) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_roles (user_id BIGINT UNSIGNED PRIMARY KEY,role_code VARCHAR(32) NOT NULL,CONSTRAINT fk_ur_user FOREIGN KEY(user_id) REFERENCES users(id),CONSTRAINT fk_ur_role FOREIGN KEY(role_code) REFERENCES roles(role_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS audit_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,actor_id BIGINT UNSIGNED NULL,event_type VARCHAR(100) NOT NULL,target_type VARCHAR(60) NOT NULL,target_key VARCHAR(120) NOT NULL,details_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_time(created_at),INDEX idx_actor(actor_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS auth_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,purpose VARCHAR(40) NOT NULL,token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,payload_json LONGTEXT NULL,expires_at DATETIME NOT NULL,consumed_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_tokens_user(user_id,purpose,consumed_at),CONSTRAINT fk_token_user FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_profiles (user_id BIGINT UNSIGNED PRIMARY KEY,avatar_file VARCHAR(90) NULL,guide_complete TINYINT(1) NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,CONSTRAINT fk_profile_user FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mail_delivery_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,event_type VARCHAR(40) NOT NULL,status VARCHAR(30) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_mail_user(user_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];}
    public static function apply(PDO $db): void {if(FreshInstallPreflight::countDatabaseObjects($db)!==0)throw new \RuntimeException('Schema creation requires empty database');foreach(array_merge(self::statements(),BusinessSchema::statements(),LibrarySchema::statements(),ApplicationSchema::statements(),ProposalSchema::statements(),MessageSchema::statements(),SeedSchema::statements(),SeedEntitySchema::statements(),MessageSenderSchema::statements(),WorkspaceSchema::statements(),EnrichmentSchema::statements(),ResearchIdentityReviewSchema::statements(),ResearchImportSchema::statements(),ResearchExternalLinkSchema::statements(),ResearchSupplementSchema::statements(),ResearchPublicationSchema::statements(),ResearchLinkRevisionSchema::statements()) as $sql)$db->exec($sql);}
    public static function seed(PDO $db,string $id,array $admin,array $settings): void {
        $createdAvatar=null;
        $commitAttempted=false;
        $db->beginTransaction();
        try {
            // Database DDL is intentionally finished before this transaction.
            $n=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $installation=(int)$db->query('SELECT COUNT(*) FROM installation_state')->fetchColumn();
            if($n!==0||$installation!==0)throw new \RuntimeException('Existing database contents require manual recovery');
            $db->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute([self::SCHEMA]);
            foreach(['USER'=>'一般ユーザー','ADMIN'=>'管理者','FOUNDER'=>'創始者','DEVELOPER'=>'開発者'] as $role=>$name) {
                $db->prepare('INSERT INTO roles(role_code,display_name) VALUES (?,?)')->execute([$role,$name]);
            }
            $db->prepare('INSERT INTO users(handle,email,display_name,password_hash) VALUES (?,?,?,?)')->execute([$admin['handle'],$admin['email'],$admin['name'],$admin['hash']]);
            $userId=(int)$db->lastInsertId();
            $createdAvatar=InitialAvatar::create((string)$admin['handle'],(string)$admin['name']);
            $db->prepare('INSERT INTO user_profiles(user_id,avatar_file) VALUES (?,?)')->execute([$userId,$createdAvatar]);
            $db->prepare('INSERT INTO user_roles(user_id,role_code) VALUES (?,?)')->execute([$userId,'DEVELOPER']);
            // Preserve the historical table DDL (default 0) while ensuring the
            // newly created developer explicitly opts into email notifications.
            $db->prepare('INSERT INTO message_user_settings(user_id,allow_direct,public_profile,notify_email) VALUES (?,1,0,1)')->execute([$userId]);
            foreach($settings as $key=>$value){$db->prepare('INSERT INTO system_settings(setting_key,value_json) VALUES (?,?)')->execute([$key,json_encode($value,JSON_THROW_ON_ERROR)]);}
            // Private company drafts and research snapshots are part of the same
            // initial data transaction; never publish them automatically.
            InitialCompanySql::apply($db,$userId);
            // Source-pinned R06 companies/jobs are added atomically after the
            // preserved Seed10/legacy research corpus, before installation ready.
            InitialResearchSql::apply($db,$userId);
            // Fresh installations register the pinned current d2.0.0 records as well.
            // Existing R06/user-owned records are never overwritten.
            ResearchR07CanonicalSync::sync($db);
            WeeklyReport::initialize($db,new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')));
            WelcomeMessage::enqueue($db,$userId,(string)$admin['name']);
            $db->prepare('INSERT INTO installation_state(id,install_id,status,schema_version,release_version) VALUES (1,?,?,?,?)')->execute([$id,'installed',self::SCHEMA,trim((string)file_get_contents(SiteState::root().'/VERSION'))]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$userId,'installation.completed','installation','1','{}']);
            $commitAttempted=true;
            if(!$db->commit())throw new \RuntimeException('Developer creation commit failed');
        } catch(\Throwable $e) {
            if($db->inTransaction())try{$db->rollBack();}catch(\Throwable){}
            if(!$commitAttempted && is_string($createdAvatar))InitialAvatar::discard($createdAvatar);
            throw $e;
        }
    }
}
