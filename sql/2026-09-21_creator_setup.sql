-- Creator onboarding: one row per owner creator holding the setup checklist state behind the
-- floating "Set up your studio" widget and /setup.
--   steps_json   {"profile":"2026-09-21 14:02:11", ...} completed-at per step (sticky)
--   skipped_json steps the creator chose to skip
--   dismissed_at widget hidden by the creator (after a confirm); completed_at all steps done + confirmed
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-21_creator_setup.sql
-- Already applied on dev and prod (prod got the table first, then skipped_json via
--   ALTER TABLE creator_setup ADD COLUMN skipped_json TEXT NULL AFTER steps_json;).

CREATE TABLE IF NOT EXISTS creator_setup (
  user_id      INT UNSIGNED NOT NULL,
  steps_json   TEXT NULL,
  skipped_json TEXT NULL,
  checked_at   DATETIME NULL,
  dismissed_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at   DATETIME NOT NULL,
  updated_at   DATETIME NOT NULL,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
