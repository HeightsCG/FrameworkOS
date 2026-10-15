-- Eromify connection back (Settings > Integrations) + Eromify characters as an automation image source.
-- Reverses sql/2026-09-18_remove_eromify.sql. Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-10-15_eromify_restore.sql

CREATE TABLE IF NOT EXISTS user_eromify_accounts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  api_key       TEXT NULL,                                  -- encrypted (same scheme as Fanvue tokens)
  status        VARCHAR(16) NOT NULL DEFAULT 'connected',   -- connected | disconnected
  plan          VARCHAR(32) NULL,
  credits       INT NULL,
  last_error    VARCHAR(500) NULL,
  connected_at  DATETIME NULL,
  created_at    DATETIME NULL,
  updated_at    DATETIME NULL,
  UNIQUE KEY uq_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE scheduler_rules
  ADD COLUMN character_id   VARCHAR(64)  NULL AFTER influencer_model_key,
  ADD COLUMN character_name VARCHAR(190) NULL AFTER character_id;
