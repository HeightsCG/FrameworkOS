-- 2026-10-18 audit, signup and auth (M5 + login_attempts).
-- 1. user_accounts: one LIVE account per email. A functional UNIQUE index that ignores empty emails (the platform
--    account has user_email = '') and soft-deleted rows (deleted = 1 keeps its email), so a double-submitted signup
--    can no longer create a second row that locks the user out. Case-insensitive (the column collation is _ai_ci).
--    Plus a plain index on user_email, so login / reset / email_exists stop full-scanning the table.
-- 2. login_attempts: index for the per-account limit (identifier, action, created_at). The per-IP index
--    idx_ip_action_time already exists. Old rows are purged by the app on write (LoginAttemptsModel::record).
--
-- PRE-CHECK on prod first. The UNIQUE index fails if this returns rows; merge or soft-delete the extras, then apply:
--   SELECT user_email, COUNT(*) AS n, GROUP_CONCAT(user_id) AS ids
--   FROM user_accounts WHERE deleted = 0 AND user_email <> ''
--   GROUP BY user_email HAVING n > 1;
--
-- Apply by hand: mysql -h <host> --protocol=TCP -u <user> -p <db> < sql/2026-10-18_audit_auth.sql
-- Run before deploy. Already applied on dev.
-- MySQL 8.0.13+ (functional key parts). ADD INDEX has no IF NOT EXISTS: a second run errors with "Duplicate key name", which is harmless.

ALTER TABLE user_accounts
    ADD UNIQUE INDEX uq_user_email_live ((CASE WHEN deleted = 0 AND user_email <> '' THEN user_email END)),
    ADD INDEX idx_user_accounts_email (user_email);

ALTER TABLE login_attempts
    ADD INDEX idx_identifier_action_time (identifier, action, created_at);
