-- Lifecycle emails (cron/lifecycle.php, LifecycleEmails): verification reminders, trial started / trial ending,
-- abandoned plan checkout, and Postmark hard-bounce / spam-complaint marks.
--   user_accounts.verify_reminders_sent / verify_reminder_at: how many reminders went to an unverified account (max 3) and when.
--   user_accounts.email_bounced_at / email_bounce_reason / email_bounced_address: Postmark reported this address as a
--     hard bounce or spam complaint (/webhook/postmark). Only counts while user_email still equals email_bounced_address.
--   lifecycle_emails: one row per lifecycle email claimed (exactly-once guard), keyed by user + kind + ref.
--
-- Apply by hand:
--   mysql -h <host> --protocol=TCP -u <user> -p <db> < sql/2026-10-18_lifecycle_emails.sql
-- RUN BEFORE DEPLOYING (the code reads these columns at sign-in and in the billing worker).
-- Prod cron (add after deploy):
--   */15 * * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/lifecycle.php >> /tmp/cls-lifecycle.log 2>&1
-- Already applied on dev.

ALTER TABLE user_accounts
  ADD COLUMN verify_reminders_sent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN verify_reminder_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN email_bounced_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN email_bounce_reason VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN email_bounced_address VARCHAR(190) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS lifecycle_emails (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  kind VARCHAR(32) NOT NULL,
  ref VARCHAR(64) NOT NULL DEFAULT '',
  status VARCHAR(12) NOT NULL DEFAULT 'sent',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lifecycle (user_id, kind, ref),
  KEY idx_lifecycle_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
