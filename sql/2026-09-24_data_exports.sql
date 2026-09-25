-- Account data export ("Download Your Data", Settings -> Account).
-- One row per export request. cron/queue_worker.php (job type data_export) builds a zip of the
-- account's data (account, posts, messages, purchases, subscriptions, credits + the creator's
-- original media), stores it privately at vault/<user_id>/exports/<id>.zip and marks the row ready.
-- Downloads are presigned links; a ready export expires 7 days after it was built.
--
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-24_data_exports.sql
-- No new cron lines: the existing queue worker runs it.
-- Already applied on dev (2026-09-24). Not yet on prod.

CREATE TABLE IF NOT EXISTS data_exports (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    status        ENUM('queued','running','ready','failed') NOT NULL DEFAULT 'queued',
    s3_key        VARCHAR(255) NULL,
    bytes         BIGINT UNSIGNED NULL,
    file_count    INT UNSIGNED NULL,
    error         VARCHAR(500) NULL,
    created_at    DATETIME NOT NULL,
    completed_at  DATETIME NULL,
    expires_at    DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
