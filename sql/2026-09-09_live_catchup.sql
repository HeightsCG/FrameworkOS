-- Live catch-up for the 2026-09-08/09 builds (inbox automation, scheduled messages,
-- Eromify characters). Safe to run repeatedly: every step checks information_schema
-- first, so nothing errors on "duplicate" and nothing is re-created.

CREATE TABLE IF NOT EXISTS inbox_settings (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  creator_id      INT NOT NULL,
  fanvue_enabled  TINYINT NOT NULL DEFAULT 0,
  cls_enabled     TINYINT NOT NULL DEFAULT 0,
  mode            VARCHAR(10) NOT NULL DEFAULT 'approve',
  quiet_start     TIME NULL,
  quiet_end       TIME NULL,
  quiet_action    VARCHAR(5) NOT NULL DEFAULT 'hold',
  max_consecutive TINYINT NOT NULL DEFAULT 3,
  persona         TEXT NULL,
  avoid_topics    TEXT NULL,
  upsell_enabled  TINYINT NOT NULL DEFAULT 0,
  disclose_ai     TINYINT NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  UNIQUE KEY uniq_creator (creator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inbox_events (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  provider     VARCHAR(10) NOT NULL,
  event_id     VARCHAR(191) NOT NULL,
  creator_id   INT NOT NULL,
  event_type   VARCHAR(64) NOT NULL,
  payload      MEDIUMTEXT NOT NULL,
  status       VARCHAR(12) NOT NULL DEFAULT 'pending',
  attempts     TINYINT NOT NULL DEFAULT 0,
  result       VARCHAR(255) NULL,
  received_at  DATETIME NOT NULL,
  processed_at DATETIME NULL,
  UNIQUE KEY uniq_event (provider, event_id),
  KEY idx_status_received (status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inbox_replies (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  creator_id          INT NOT NULL,
  channel             VARCHAR(10) NOT NULL,
  peer_key            VARCHAR(64) NOT NULL,
  peer_name           VARCHAR(255) NULL,
  event_id            INT NULL,
  inbound_text        TEXT NULL,
  draft_text          TEXT NULL,
  final_text          TEXT NULL,
  status              VARCHAR(16) NOT NULL,
  reason              VARCHAR(64) NULL,
  provider_message_id VARCHAR(64) NULL,
  decided_by          INT NULL,
  error               VARCHAR(255) NULL,
  created_at          DATETIME NOT NULL,
  sent_at             DATETIME NULL,
  KEY idx_creator_status (creator_id, status, created_at),
  KEY idx_peer (creator_id, channel, peer_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_eromify_accounts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  api_key       TEXT NULL,
  status        VARCHAR(16) NOT NULL DEFAULT 'connected',
  plan          VARCHAR(32) NULL,
  credits       INT NULL,
  last_error    VARCHAR(500) NULL,
  connected_at  DATETIME NULL,
  created_at    DATETIME NULL,
  updated_at    DATETIME NULL,
  UNIQUE KEY uq_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Columns and index: added only when absent.
DROP PROCEDURE IF EXISTS cls_catchup_20260909;
DELIMITER //
CREATE PROCEDURE cls_catchup_20260909()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_fanvue_accounts' AND INDEX_NAME = 'idx_fanvue_uuid') THEN
    ALTER TABLE user_fanvue_accounts ADD KEY idx_fanvue_uuid (fanvue_user_uuid);
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME = 'kind') THEN
    ALTER TABLE scheduler_rules ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'post' AFTER creator_id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME = 'message_text') THEN
    ALTER TABLE scheduler_rules ADD COLUMN message_text TEXT NULL AFTER topic;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME = 'message_targets') THEN
    ALTER TABLE scheduler_rules ADD COLUMN message_targets VARCHAR(255) NULL AFTER message_text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME = 'message_ai') THEN
    ALTER TABLE scheduler_rules ADD COLUMN message_ai TINYINT NOT NULL DEFAULT 0 AFTER message_targets;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME = 'image_source') THEN
    ALTER TABLE scheduler_rules ADD COLUMN image_source VARCHAR(12) NOT NULL DEFAULT 'brand' AFTER size;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME = 'character_id') THEN
    ALTER TABLE scheduler_rules ADD COLUMN character_id VARCHAR(64) NULL AFTER image_source;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME = 'character_name') THEN
    ALTER TABLE scheduler_rules ADD COLUMN character_name VARCHAR(190) NULL AFTER character_id;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'fanvue_post_uuid') THEN
    ALTER TABLE posts ADD COLUMN fanvue_post_uuid VARCHAR(36) NULL AFTER media_missing;
  END IF;
END //
DELIMITER ;
CALL cls_catchup_20260909();
DROP PROCEDURE IF EXISTS cls_catchup_20260909;

-- Result: one row per item, all should say OK.
SELECT 'scheduler_rules.kind'           AS item, IF(COUNT(*) = 1, 'OK', 'MISSING') AS state FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME = 'kind'
UNION ALL SELECT 'scheduler_rules.image_source', IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME = 'image_source'
UNION ALL SELECT 'user_eromify_accounts',        IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.TABLES  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_eromify_accounts'
UNION ALL SELECT 'inbox_replies',                IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.TABLES  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inbox_replies'
UNION ALL SELECT 'posts.fanvue_post_uuid',       IF(COUNT(*) = 1, 'OK', 'MISSING') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'fanvue_post_uuid';
