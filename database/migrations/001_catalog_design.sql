-- DESIGN ONLY. Not executed; do not apply to the job-support site's database.
-- Dedicated code publication DB, MySQL 8.4 LTS candidate, UTC.
CREATE TABLE schema_migrations (version INT UNSIGNED PRIMARY KEY, applied_at DATETIME(6) NOT NULL, checksum CHAR(64) CHARACTER SET ascii NOT NULL);
CREATE TABLE releases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tag VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  parent_id BIGINT UNSIGNED NULL,
  source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  upstream_commit VARCHAR(64) CHARACTER SET ascii NULL,
  upstream_parent VARCHAR(64) CHARACTER SET ascii NULL,
  published_at DATETIME(6) NOT NULL,
  metadata JSON NOT NULL,
  CONSTRAINT fk_parent FOREIGN KEY(parent_id) REFERENCES releases(id)
) ENGINE=InnoDB;
CREATE TABLE release_files (
  release_id BIGINT UNSIGNED NOT NULL,
  path_sha256 BINARY(32) NOT NULL,
  path TEXT NOT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  byte_size BIGINT UNSIGNED NOT NULL,
  last_changed_release_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(release_id,path_sha256),
  FOREIGN KEY(release_id) REFERENCES releases(id),
  FOREIGN KEY(last_changed_release_id) REFERENCES releases(id)
) ENGINE=InnoDB;
CREATE TABLE publication_state (
  singleton TINYINT UNSIGNED PRIMARY KEY CHECK(singleton=1),
  latest_release_id BIGINT UNSIGNED NULL,
  generation BIGINT UNSIGNED NOT NULL,
  catalog_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
  state ENUM('READY','RECOVERY_REQUIRED') NOT NULL,
  FOREIGN KEY(latest_release_id) REFERENCES releases(id)
) ENGINE=InnoDB;
CREATE TABLE publication_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  occurred_at DATETIME(6) NOT NULL,
  release_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) CHARACTER SET ascii NOT NULL,
  details JSON NOT NULL,
  FOREIGN KEY(release_id) REFERENCES releases(id)
) ENGINE=InnoDB;
