-- Audit 2026-10-18, platform fixes (team roles, deleted accounts, AI jobs, inbox, domains).
--
-- 1. Free the unique handle and Google link of accounts that were ALREADY soft-deleted (new deletes do this in code:
--    UsersModel::release_identifiers). The old handle is archived in username_history, not reserved.
-- 2. Release custom domains of already-deleted accounts.
-- 3. Indexes for the queue-worker sweep and the jobs prune.
--
-- Apply by hand (run before deploy):
--   mysql -h <host> -u <user> -p <db> < sql/2026-10-18_audit_platform.sql
-- Already applied on dev. Steps 1-2 are safe to run twice; step 3 errors on an index that already exists (skip that line).
--
-- Pre-check (how many rows each step touches):
--   SELECT COUNT(*) FROM user_accounts WHERE deleted = 1 AND (u_name NOT LIKE 'deleted\_%' OR google_sub IS NOT NULL);
--   SELECT COUNT(*) FROM creator_domains d JOIN user_accounts u ON u.user_id = d.user_id WHERE u.deleted = 1 AND d.deleted = 0;
-- deleted_<user_id> cannot clash with a live handle: user ids are unique and the app now refuses handles starting with "deleted_".
--   SELECT COUNT(*) FROM user_accounts WHERE u_name LIKE 'deleted\_%' AND deleted = 0;   -- must be 0

INSERT INTO username_history (user_id, u_name, changed_at, reserved_until)
SELECT u.user_id, u.u_name, UTC_TIMESTAMP(), UTC_TIMESTAMP()
  FROM user_accounts u
 WHERE u.deleted = 1 AND u.u_name <> '' AND u.u_name NOT LIKE 'deleted\_%';

UPDATE user_accounts
   SET u_name = CONCAT('deleted_', user_id), google_sub = NULL
 WHERE deleted = 1 AND (u_name NOT LIKE 'deleted\_%' OR google_sub IS NOT NULL);

UPDATE creator_domains d
  JOIN user_accounts u ON u.user_id = d.user_id
   SET d.deleted = 1, d.is_primary = 0, d.date_updated = UTC_TIMESTAMP()
 WHERE u.deleted = 1 AND d.deleted = 0;

-- 3. Indexes for the queue-worker sweep and the jobs prune (cron/queue_worker.php runs them every minute).
--    MySQL 8 has no CREATE INDEX IF NOT EXISTS: skip a line whose index already shows in SHOW INDEX FROM <table>.
CREATE INDEX idx_ij_status_updated ON influencer_jobs (status, updated_at);
CREATE INDEX idx_jobs_prune ON jobs (state, updated_at);
CREATE INDEX idx_ma_status_created ON media_assets (status, created_at);
