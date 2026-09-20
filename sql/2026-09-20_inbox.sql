-- Inbox: media and paid unlocks in direct messages, welcome/trigger messages,
-- richer broadcasts. Replaces the Fanvue inbox automation (its tables stay, unused).
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-20_inbox.sql

ALTER TABLE messages
  ADD COLUMN price_credits INT UNSIGNED NOT NULL DEFAULT 0 AFTER body,
  ADD COLUMN media_count   TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER price_credits,
  ADD COLUMN trigger_key   VARCHAR(32) DEFAULT NULL AFTER media_count;

CREATE TABLE IF NOT EXISTS message_assets (
  message_id INT NOT NULL,
  asset_id   INT NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (message_id, asset_id),
  KEY idx_ma_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- A row per (fan, message) the fan has paid for; the unique key is the double-charge mutex.
CREATE TABLE IF NOT EXISTS message_unlocks (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id    INT NOT NULL,
  creator_id    INT NOT NULL,
  fan_id        INT NOT NULL,
  price_credits INT NOT NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_message_fan (message_id, fan_id),
  KEY idx_mu_fan (fan_id, created_at),
  KEY idx_mu_creator (creator_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Welcome / trigger messages a creator sends automatically on CLS events.
CREATE TABLE IF NOT EXISTS auto_messages (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  creator_id    INT NOT NULL,
  trigger_key   VARCHAR(32) NOT NULL,
  enabled       TINYINT(1) NOT NULL DEFAULT 1,
  text          TEXT NOT NULL,
  price_credits INT UNSIGNED NOT NULL DEFAULT 0,
  asset_ids     JSON DEFAULT NULL,
  updated_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_creator_trigger (creator_id, trigger_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One automatic message per fan per trigger (purchases append the purchase ref so they can repeat).
CREATE TABLE IF NOT EXISTS auto_message_sends (
  creator_id  INT NOT NULL,
  fan_id      INT NOT NULL,
  trigger_ref VARCHAR(64) NOT NULL,
  sent_at     DATETIME NOT NULL,
  PRIMARY KEY (creator_id, fan_id, trigger_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE broadcasts
  ADD COLUMN price_credits INT UNSIGNED NOT NULL DEFAULT 0 AFTER body,
  ADD COLUMN asset_ids     JSON DEFAULT NULL AFTER price_credits,
  ADD COLUMN segments      VARCHAR(255) DEFAULT NULL AFTER audience;

ALTER TABLE scheduler_rules MODIFY message_targets TEXT;

SELECT IF(COUNT(*) = 2, 'OK', 'MISSING') AS inbox_tables
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('message_unlocks', 'auto_messages');
