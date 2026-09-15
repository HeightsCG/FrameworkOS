-- Background job queue used by DatabaseJobQueue / cron/queue_worker.php
-- (scheduler_run_now and media_generate dispatch here instead of blocking the request).
-- Apply by hand: dev already has this table; run on live before deploying the API split.
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-15_jobs.sql

CREATE TABLE IF NOT EXISTS jobs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          VARCHAR(64) NOT NULL,
  payload_json  JSON DEFAULT NULL,
  dedupe_key    VARCHAR(191) DEFAULT NULL,
  state         ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  attempts      INT NOT NULL DEFAULT 0,
  max_attempts  INT NOT NULL DEFAULT 5,
  run_after     DATETIME NOT NULL,
  locked_at     DATETIME DEFAULT NULL,
  locked_by     VARCHAR(64) DEFAULT NULL,
  last_error    TEXT,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dedupe (dedupe_key),
  KEY idx_jobs_claim (state, run_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SELECT IF(COUNT(*) = 1, 'OK', 'MISSING') AS jobs_table
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs';
