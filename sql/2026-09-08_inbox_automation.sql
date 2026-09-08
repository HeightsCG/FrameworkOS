-- Inbox automation (AI replies to fan DMs, approval queue). Apply by hand: dev + live.

CREATE TABLE IF NOT EXISTS inbox_settings (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  creator_id      INT NOT NULL,
  fanvue_enabled  TINYINT NOT NULL DEFAULT 0,
  cls_enabled     TINYINT NOT NULL DEFAULT 0,
  mode            VARCHAR(10) NOT NULL DEFAULT 'approve',   -- auto | approve
  quiet_start     TIME NULL,
  quiet_end       TIME NULL,
  quiet_action    VARCHAR(5) NOT NULL DEFAULT 'hold',       -- hold | skip
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
  provider     VARCHAR(10) NOT NULL,                        -- fanvue | cls
  event_id     VARCHAR(191) NOT NULL,
  creator_id   INT NOT NULL,
  event_type   VARCHAR(64) NOT NULL,
  payload      MEDIUMTEXT NOT NULL,
  status       VARCHAR(12) NOT NULL DEFAULT 'pending',      -- pending | processing | done | skipped | failed
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
  channel             VARCHAR(10) NOT NULL,                 -- fanvue | cls
  peer_key            VARCHAR(64) NOT NULL,                 -- fanvue fan uuid | cls conversation id
  peer_name           VARCHAR(255) NULL,
  event_id            INT NULL,
  inbound_text        TEXT NULL,
  draft_text          TEXT NULL,
  final_text          TEXT NULL,
  status              VARCHAR(16) NOT NULL,                 -- pending_approval | sending | sent | dismissed | failed | skipped
  reason              VARCHAR(64) NULL,
  provider_message_id VARCHAR(64) NULL,
  decided_by          INT NULL,
  error               VARCHAR(255) NULL,
  created_at          DATETIME NOT NULL,
  sent_at             DATETIME NULL,
  KEY idx_creator_status (creator_id, status, created_at),
  KEY idx_peer (creator_id, channel, peer_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE user_fanvue_accounts ADD KEY idx_fanvue_uuid (fanvue_user_uuid);

-- Phase 3: scheduled mass messages reuse the post scheduler's cadence machinery.
ALTER TABLE scheduler_rules
  ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'post' AFTER creator_id,   -- post | message
  ADD COLUMN message_text TEXT NULL AFTER topic,
  ADD COLUMN message_targets VARCHAR(255) NULL AFTER message_text,         -- JSON {"fanvue":["subscribers"],"cls":"subscribers"}
  ADD COLUMN message_ai TINYINT NOT NULL DEFAULT 0 AFTER message_targets;  -- 1 = generate from topic
