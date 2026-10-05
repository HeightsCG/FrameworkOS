-- AI influencer pipeline, stage 2 (images): carousel sets and the admin-managed scene template library.
-- Run sql/2026-10-05_ai_pipeline_foundation.sql first.
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-05_ai_pipeline_images.sql
--
-- RUN BEFORE DEPLOYING the stage 2 code (Studio Scenes tab, /admin Scenes tab, Generate Carousel).
-- Applied on dev: 2026-10-05. NOT yet on prod.

-- One row per carousel a creator generates; its slots are influencer_jobs rows of type 'carousel' sharing group_key.
CREATE TABLE IF NOT EXISTS carousel_sets (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    creator_id    INT UNSIGNED NOT NULL,
    influencer_id BIGINT UNSIGNED NOT NULL,
    group_key     VARCHAR(64) NOT NULL,
    seed_asset_id BIGINT UNSIGNED NULL,          -- the seed scene as an image, or
    seed_text     TEXT NULL,                     -- as text
    focus         VARCHAR(16) NOT NULL,          -- angles | expressions | poses | details | without_her
    aspect        VARCHAR(8) NOT NULL DEFAULT '3:4',
    model_key     VARCHAR(64) NOT NULL,
    slot_count    TINYINT UNSIGNED NOT NULL,
    constants     TEXT NULL,                     -- what Claude held constant (outfit, location, props, pets)
    created_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_carousel_group (group_key),
    KEY idx_carousel_creator (creator_id, influencer_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Scene templates: managed in /admin, picked by creators in Studio, Scenes.
CREATE TABLE IF NOT EXISTS scene_templates (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title          VARCHAR(120) NOT NULL,
    category       VARCHAR(60) NOT NULL DEFAULT '',
    base_prompt    TEXT NOT NULL,                -- {subject} becomes "woman" / "man"
    thumb_key      VARCHAR(512) NULL,            -- private S3 key of the thumbnail
    is_adult       TINYINT(1) NOT NULL DEFAULT 0,
    default_aspect VARCHAR(8) NOT NULL DEFAULT '3:4',
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order     INT NOT NULL DEFAULT 0,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL,
    updated_at     DATETIME NOT NULL,
    deleted_at     DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_scene_list (deleted_at, is_active, category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- A creator's thumbs up / down on one variant a template produced.
CREATE TABLE IF NOT EXISTS scene_template_votes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    creator_id  INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    asset_id    BIGINT UNSIGNED NOT NULL,
    job_id      BIGINT UNSIGNED NULL,
    vote        TINYINT NOT NULL,               -- 1 up, -1 down
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_scene_vote (creator_id, asset_id),
    KEY idx_scene_vote_tpl (template_id, vote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
