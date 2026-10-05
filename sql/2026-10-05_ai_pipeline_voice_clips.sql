-- AI influencer pipeline, stage 4 (voice + clip editor): an influencer's saved voices and clip edit projects.
-- Run sql/2026-10-05_ai_pipeline_foundation.sql first (it adds the 'audio' asset type these features store).
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-05_ai_pipeline_voice_clips.sql
--
-- RUN BEFORE DEPLOYING the stage 4 code (Influencers, Voice; Generate Videos, Talking; Content Studio, New Edit).
-- Also needed in app.ini [global] on the live server: elevenlabs_api_key (with permission to create AND delete voices).
-- Applied on dev: 2026-10-05. NOT yet on prod.

-- Voices designed for an influencer (ElevenLabs Voice Design). Several can be saved; one is active.
CREATE TABLE IF NOT EXISTS influencer_voices (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    creator_id    INT UNSIGNED NOT NULL,
    influencer_id BIGINT UNSIGNED NOT NULL,
    name          VARCHAR(120) NOT NULL,
    provider      VARCHAR(24) NOT NULL DEFAULT 'elevenlabs',
    voice_id      VARCHAR(64) NOT NULL,          -- the provider's id for the saved voice
    prompt        TEXT NULL,                     -- the description it was designed from
    settings_json JSON NULL,                     -- builder fields (age and vibe, keyword, accent, tone) and preview text
    is_active     TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    deleted_at    DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_iv_influencer (creator_id, influencer_id, deleted_at),
    KEY idx_iv_voice (voice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Clip edit projects (Content Studio, New Edit): a saved timeline that can be reopened and exported again.
CREATE TABLE IF NOT EXISTS edit_projects (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    creator_id      INT UNSIGNED NOT NULL,
    name            VARCHAR(160) NOT NULL DEFAULT '',
    aspect          VARCHAR(8) NOT NULL DEFAULT '9:16',   -- 9:16 | 3:4
    timeline_json   JSON NULL,                             -- clips, text overlays, image overlays, audio track
    status          ENUM('draft','rendering','done','failed') NOT NULL DEFAULT 'draft',
    render_token    VARCHAR(32) NULL,                      -- the export in flight (a newer export supersedes an older one)
    result_asset_id BIGINT UNSIGNED NULL,
    error           VARCHAR(500) NULL,
    rendered_at     DATETIME NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    deleted_at      DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_ep_creator (creator_id, deleted_at, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
