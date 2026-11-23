-- Change25 Batch37 / Research Batch22: OPTIONAL, manually reviewed R07 Batch22 source snapshot tables.
-- Apply ONLY to an isolated, backed-up staging database whose installation_state
-- reports Schema41 and whose R06 source mapping has been verified.
-- No ALTER/DROP/UPDATE/DELETE to canonical tables or user-owned records.
-- No new foreign keys reference canonical companies/jobs: existing delete/archive
-- behavior must not be changed by this optional research snapshot feature.
-- MySQL DDL implicitly commits: do not mistake this file for a rollbackable transaction.
-- The three tables contain research source observations, NOT live job eligibility.
CREATE TABLE IF NOT EXISTS research_r07b22_snapshot_batches (
  source_release VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  company_count INT UNSIGNED NOT NULL,
  job_count INT UNSIGNED NOT NULL,
  mapped_company_count INT UNSIGNED NOT NULL,
  mapped_job_count INT UNSIGNED NOT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_r07b22_batch_sha (source_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_r07b22_company_snapshots (
  source_company_id CHAR(7) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  source_release VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mapped_company_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  payload_json LONGTEXT NOT NULL,
  payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_r07b22_company_mapping (mapped_company_id),
  CONSTRAINT fk_r07b22_co_batch FOREIGN KEY (source_release) REFERENCES research_r07b22_snapshot_batches(source_release)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_r07b22_job_snapshots (
  source_job_id CHAR(8) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  source_company_id CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_release VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mapped_job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  payload_json LONGTEXT NOT NULL,
  payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_r07b22_job_company (source_company_id),
  INDEX idx_r07b22_job_mapping (mapped_job_id),
  CONSTRAINT fk_r07b22_job_company_snapshot FOREIGN KEY (source_company_id) REFERENCES research_r07b22_company_snapshots(source_company_id),
  CONSTRAINT fk_r07b22_job_batch FOREIGN KEY (source_release) REFERENCES research_r07b22_snapshot_batches(source_release)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
