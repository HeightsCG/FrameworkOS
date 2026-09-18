-- AI influencers: create an influencer, train a LoRA of her once (fal.ai), then generate
-- images/videos from the trained weights and post them. Tables are creator-scoped like the
-- rest of the vault. Apply by hand (no migration runner):
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-18_influencers.sql

CREATE TABLE IF NOT EXISTS influencers (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  creator_id          INT UNSIGNED NOT NULL,
  name                VARCHAR(120) NOT NULL,
  name_lc             VARCHAR(120) NOT NULL,                       -- lowercased name for the per-creator uniqueness key
  status              ENUM('draft','awaiting_reference','training','ready','failed') NOT NULL DEFAULT 'draft',
  path                ENUM('photos','reference') DEFAULT NULL,     -- photos = train from uploads; reference = text/face -> reference -> 10-image set
  input_method        ENUM('text','face_photo') DEFAULT NULL,     -- reference path only
  is_public           TINYINT(1) NOT NULL DEFAULT 0,
  source_description  TEXT,                                       -- face description (reference path, text input)
  reference_model_key VARCHAR(64) DEFAULT NULL,                    -- picker choice used ONLY for the reference image
  steer_text          VARCHAR(1000) DEFAULT NULL,                  -- optional steering for the training set
  prompt_defaults     TEXT,                                        -- prepended to every prompt (defaults to the trigger word once trained)
  negative_prompt     TEXT,
  face_asset_id       BIGINT UNSIGNED DEFAULT NULL,                -- uploaded single face photo (media_assets.id)
  reference_asset_id  BIGINT UNSIGNED DEFAULT NULL,                -- approved reference image (media_assets.id)
  training_set_group  VARCHAR(64) DEFAULT NULL,                    -- current influencer_jobs.group_key for the 10-image set
  active_model_id     BIGINT UNSIGNED DEFAULT NULL,                -- influencer_models.id currently used for generation
  pending_model_id    BIGINT UNSIGNED DEFAULT NULL,                -- influencer_models.id currently training (retrain keeps active_model_id)
  wizard_step         VARCHAR(24) NOT NULL DEFAULT 'name',         -- last step the user was on; resume rule in InfluencerService
  share_accounts      JSON DEFAULT NULL,                           -- default social account ids for her automations
  last_error          VARCHAR(2000) DEFAULT NULL,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NOT NULL,
  deleted_at          DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_infl_name (creator_id, name_lc),
  KEY idx_infl_creator (creator_id, deleted_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Media attached to an influencer, with the role it plays. Generated outputs carry the job
-- that produced them; (job_id, result_index) is unique so a repeated landing can never
-- attach a second asset to the same output slot.
CREATE TABLE IF NOT EXISTS influencer_images (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  influencer_id BIGINT UNSIGNED NOT NULL,
  creator_id    INT UNSIGNED NOT NULL,
  asset_id      BIGINT UNSIGNED NOT NULL,                          -- media_assets.id
  role          ENUM('upload','face','reference','training','generated','video','enhanced') NOT NULL,
  job_id        BIGINT UNSIGNED DEFAULT NULL,                      -- influencer_jobs.id (NULL for uploads)
  result_index  TINYINT UNSIGNED NOT NULL DEFAULT 0,               -- position within a multi-image output
  sort_order    INT NOT NULL DEFAULT 0,
  is_excluded   TINYINT(1) NOT NULL DEFAULT 0,                     -- left out of the training set by the user
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ii_job_slot (job_id, result_index),
  UNIQUE KEY uq_ii_asset (asset_id),
  KEY idx_ii_infl_role (influencer_id, role, is_excluded)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Trained weights. Many per influencer; the generated active_slot column + unique key make
-- "exactly one active per influencer" a database guarantee, not a convention.
CREATE TABLE IF NOT EXISTS influencer_models (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  influencer_id      BIGINT UNSIGNED NOT NULL,
  creator_id         INT UNSIGNED NOT NULL,
  job_id             BIGINT UNSIGNED DEFAULT NULL,                 -- the 'training' influencer_jobs row
  provider           VARCHAR(32) NOT NULL DEFAULT 'fal',
  model_key          VARCHAR(64) NOT NULL,                         -- InfluencerConfig catalog key
  base_model         VARCHAR(64) NOT NULL DEFAULT 'flux-dev',
  trigger_word       VARCHAR(48) NOT NULL,
  status             ENUM('training','ready','failed','archived') NOT NULL DEFAULT 'training',
  is_active          TINYINT(1) NOT NULL DEFAULT 0,
  active_slot        BIGINT UNSIGNED GENERATED ALWAYS AS (IF(is_active = 1, influencer_id, NULL)) STORED,
  weights_key        VARCHAR(255) DEFAULT NULL,                    -- S3 vault key of the mirrored LoRA file
  weights_url        VARCHAR(1024) DEFAULT NULL,                   -- provider URL as returned (may expire)
  weights_bytes      BIGINT UNSIGNED DEFAULT NULL,
  steps              INT DEFAULT NULL,
  image_count        INT DEFAULT NULL,
  training_set_group VARCHAR(64) DEFAULT NULL,
  params_json        JSON DEFAULT NULL,
  error              VARCHAR(2000) DEFAULT NULL,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  trained_at         DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_im_active (active_slot),
  KEY idx_im_infl (influencer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Every generation is a row, including failures. Driven by InfluencerJobService::step()
-- through the shared `jobs` queue (type influencer_job, payload {job_id}).
CREATE TABLE IF NOT EXISTS influencer_jobs (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  creator_id            INT UNSIGNED NOT NULL,
  influencer_id         BIGINT UNSIGNED NOT NULL,
  type                  ENUM('reference','training_set','training','image','video','enhance') NOT NULL,
  status                ENUM('queued','submitting','running','landing','done','failed','cancelled') NOT NULL DEFAULT 'queued',
  wait_reason           VARCHAR(24) DEFAULT NULL,                  -- cap_influencer | cap_account while waiting for a slot
  origin                ENUM('wizard','studio','scheduler') NOT NULL DEFAULT 'studio',
  rule_id               INT UNSIGNED DEFAULT NULL,                 -- scheduler_rules.id when origin = scheduler
  group_key             VARCHAR(64) DEFAULT NULL,                  -- training-set group
  group_index           TINYINT UNSIGNED DEFAULT NULL,             -- 1..N within the group
  superseded_by         BIGINT UNSIGNED DEFAULT NULL,              -- regenerated slot points at the newer row
  model_key             VARCHAR(64) NOT NULL,                      -- InfluencerConfig catalog key
  provider              VARCHAR(32) DEFAULT NULL,                  -- provider that served (or is serving) the job
  endpoint              VARCHAR(128) DEFAULT NULL,
  provider_index        TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- position in the ordered fallback list
  attempts_json         JSON DEFAULT NULL,                         -- one entry per provider attempt
  prompt                TEXT,
  negative_prompt       TEXT,
  seed                  BIGINT UNSIGNED DEFAULT NULL,
  params_json           JSON DEFAULT NULL,
  input_asset_id        BIGINT UNSIGNED DEFAULT NULL,              -- still for video, source for enhance, face/reference for edits
  model_id              BIGINT UNSIGNED DEFAULT NULL,              -- influencer_models.id used (image/video)
  provider_job_id       VARCHAR(128) DEFAULT NULL,
  provider_status_url   VARCHAR(512) DEFAULT NULL,
  provider_response_url VARCHAR(512) DEFAULT NULL,
  provider_cancel_url   VARCHAR(512) DEFAULT NULL,
  poll_count            INT NOT NULL DEFAULT 0,
  dispatch_seq          INT NOT NULL DEFAULT 0,                    -- bumped per queue dispatch -> unique dedupe keys
  deadline_at           DATETIME DEFAULT NULL,                     -- submitted_at + ceiling(type)
  result_asset_id       BIGINT UNSIGNED DEFAULT NULL,              -- first landed asset (single-writer slot)
  result_model_id       BIGINT UNSIGNED DEFAULT NULL,              -- training jobs
  result_json           JSON DEFAULT NULL,                         -- provider output summary: urls, seed(s), asset ids
  error                 VARCHAR(2000) DEFAULT NULL,
  error_code            VARCHAR(32) DEFAULT NULL,                  -- content_policy|validation|auth|provider|timeout|landing|cancelled
  cost_usd              DECIMAL(9,4) DEFAULT NULL,
  submitted_at          DATETIME DEFAULT NULL,
  landed_at             DATETIME DEFAULT NULL,
  finished_at           DATETIME DEFAULT NULL,
  created_at            DATETIME NOT NULL,
  updated_at            DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ij_provider_job (provider, provider_job_id),
  KEY idx_ij_infl_status (influencer_id, status),
  KEY idx_ij_creator_status (creator_id, status, created_at),
  KEY idx_ij_group (group_key, group_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Automations can point at an influencer (image_source = 'influencer'); the Eromify
-- character columns are untouched.
ALTER TABLE scheduler_rules
  ADD COLUMN influencer_id        BIGINT UNSIGNED DEFAULT NULL AFTER character_name,
  ADD COLUMN influencer_model_key VARCHAR(64) DEFAULT NULL AFTER influencer_id;

SELECT IF(COUNT(*) = 4, 'OK', 'MISSING') AS influencer_tables
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('influencers','influencer_images','influencer_models','influencer_jobs');
