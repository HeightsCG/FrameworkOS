-- AI influencer pipeline, stage 5: caption modes, launch campaigns with one-off scheduled messages,
-- AI disclosure (cross-posts, inbox), and Stories as a cross-post placement.
-- Run sql/2026-10-05_ai_pipeline_foundation.sql first (provenance on media_assets is what "AI media" means here).
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-05_ai_pipeline_campaigns_disclosure.sql
--
-- RUN BEFORE DEPLOYING the stage 5 code: the composer, cross-posting, the inbox and cron/scheduler.php read these columns.
-- Safe while the old code is live (new columns are nullable or defaulted).
-- Applied on dev: 2026-10-05. NOT yet on prod.

-- Posts: the AI disclosure choice, which accounts get the post as a Story, and the campaign that made it.
ALTER TABLE posts
    ADD COLUMN ai_disclosure TINYINT(1) NULL AFTER comments_enabled,          -- NULL = on when the post has AI media; 0 = off; 1 = on
    ADD COLUMN story_accounts VARCHAR(1024) NOT NULL DEFAULT '' AFTER ai_disclosure,   -- comma list of social account ids that get a Story instead of a feed post
    ADD COLUMN campaign_id BIGINT UNSIGNED NULL AFTER story_accounts;

-- The line added to a cross-post's caption where the platform has no AI label of its own (NULL = the default line).
ALTER TABLE user_social_accounts
    ADD COLUMN ai_disclosure_text VARCHAR(200) NULL AFTER display_name;

-- Automations: which kind of caption to write.
ALTER TABLE scheduler_rules
    ADD COLUMN caption_mode VARCHAR(16) NOT NULL DEFAULT 'standard' AFTER caption_text;

-- Inbox automation: the disclosure sent with the first automated reply to each fan, and whose persona replies use.
ALTER TABLE inbox_settings
    ADD COLUMN ai_disclosure_text VARCHAR(300) NOT NULL DEFAULT 'Some replies here may be automated.' AFTER disclose_ai,
    ADD COLUMN persona_influencer_id BIGINT UNSIGNED NULL AFTER ai_disclosure_text;

-- One row per fan who has been sent that disclosure.
CREATE TABLE IF NOT EXISTS inbox_disclosures (
    creator_id INT UNSIGNED NOT NULL,
    fan_id     INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (creator_id, fan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Launch campaigns (Audience, Launch Campaign): the plan a set of scheduled posts and messages came from.
CREATE TABLE IF NOT EXISTS launch_campaigns (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    creator_id        INT UNSIGNED NOT NULL,
    influencer_id     BIGINT UNSIGNED NULL,
    destination       VARCHAR(16) NOT NULL DEFAULT 'cls',     -- cls | fanvue
    launch_at         DATETIME NOT NULL,                      -- UTC
    anticipation_days TINYINT UNSIGNED NOT NULL DEFAULT 0,
    promo_code_id     INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_lc_creator (creator_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- A broadcast to send once at a set time (sent by cron/scheduler.php through the normal broadcast path).
CREATE TABLE IF NOT EXISTS scheduled_broadcasts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    creator_id   INT UNSIGNED NOT NULL,
    campaign_id  BIGINT UNSIGNED NULL,
    segments     VARCHAR(120) NOT NULL DEFAULT 'followers',   -- comma list: all | followers | subscribers | expired | buyers
    body         TEXT NOT NULL,
    send_at      DATETIME NOT NULL,                           -- UTC
    status       ENUM('scheduled','sending','sent','failed','cancelled') NOT NULL DEFAULT 'scheduled',
    broadcast_id INT UNSIGNED NULL,
    recipients   INT UNSIGNED NULL,
    error        VARCHAR(300) NULL,
    created_at   DATETIME NOT NULL,
    sent_at      DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_sb_due (status, send_at),
    KEY idx_sb_creator (creator_id, send_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
