-- AI influencer pipeline, stage 1 (foundation): asset lineage + provenance, audio assets,
-- the new generation job types, influencer persona fields, angle references, and the AI credit
-- ledger types the code already writes.
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-05_ai_pipeline_foundation.sql
--
-- RUN BEFORE DEPLOYING the stage 1 code: it reads and writes these columns.
-- Safe to run while the old code is live (new columns are nullable or defaulted, enums only grow).
-- Aspect ratios need no data change: scheduler_rules.size keeps square/portrait/landscape rows,
-- which the code reads as 1:1 / 3:4 / 4:3 from now on.
--
-- Applied on dev: 2026-10-05. NOT yet on prod.

-- ---------------------------------------------------------------------------------------------
-- media_assets: where every file came from
-- ---------------------------------------------------------------------------------------------
ALTER TABLE media_assets
    MODIFY COLUMN type ENUM('image','video','gif','audio') NOT NULL,
    ADD COLUMN provenance ENUM('uploaded','generated','edited') NOT NULL DEFAULT 'uploaded' AFTER source,
    ADD COLUMN parent_asset_id BIGINT UNSIGNED NULL AFTER provenance,      -- the asset this one is a new version of
    ADD COLUMN source_asset_id BIGINT UNSIGNED NULL AFTER parent_asset_id, -- the input it was made from (still, source video)
    ADD COLUMN gen_model_key VARCHAR(64) NULL AFTER source_asset_id,
    ADD COLUMN gen_prompt TEXT NULL AFTER gen_model_key,
    ADD COLUMN gen_influencer_id BIGINT UNSIGNED NULL AFTER gen_prompt,
    ADD COLUMN gen_job_id BIGINT UNSIGNED NULL AFTER gen_influencer_id,
    ADD KEY idx_ma_parent (parent_asset_id),
    ADD KEY idx_ma_provenance (creator_id, provenance);

-- Backfill: everything an influencer job produced.
UPDATE media_assets a
  JOIN influencer_images ii ON ii.asset_id = a.id
  LEFT JOIN influencer_jobs j ON j.id = ii.job_id
   SET a.provenance        = IF(ii.role = 'enhanced', 'edited', 'generated'),
       a.gen_influencer_id = ii.influencer_id,
       a.gen_job_id        = ii.job_id,
       a.gen_model_key     = j.model_key,
       a.gen_prompt        = j.prompt,
       a.source_asset_id   = j.input_asset_id,
       a.parent_asset_id   = IF(ii.role = 'enhanced', j.input_asset_id, NULL)
 WHERE ii.role IN ('reference','training','generated','video','enhanced');

-- Backfill: brand images and automation renders (named by the code that made them).
UPDATE media_assets
   SET provenance = 'generated'
 WHERE provenance = 'uploaded' AND (filename LIKE 'Generated%' OR filename LIKE 'Scheduled%');

-- ---------------------------------------------------------------------------------------------
-- influencer_jobs: new run types; a plain Library edit has no influencer
-- ---------------------------------------------------------------------------------------------
ALTER TABLE influencer_jobs
    MODIFY COLUMN influencer_id BIGINT UNSIGNED NULL,
    MODIFY COLUMN type ENUM('reference','training_set','training','image','video','enhance',
                            'replicate','edit','angle','carousel','motion','talking','replace','scene','speech') NOT NULL,
    MODIFY COLUMN credits_charged INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN attested_at DATETIME NULL AFTER credits_charged,   -- rights attestation for a source video (replace)
    ADD COLUMN source_hash CHAR(64) NULL AFTER attested_at;        -- SHA-256 of that source file

-- ---------------------------------------------------------------------------------------------
-- influencers: persona used by every AI writer
-- ---------------------------------------------------------------------------------------------
ALTER TABLE influencers
    ADD COLUMN persona_description TEXT NULL AFTER negative_prompt,
    ADD COLUMN persona_personality VARCHAR(1000) NULL AFTER persona_description,
    ADD COLUMN persona_speaking VARCHAR(1000) NULL AFTER persona_personality,
    ADD COLUMN persona_niche VARCHAR(255) NULL AFTER persona_speaking,
    ADD COLUMN persona_vulnerability VARCHAR(1000) NULL AFTER persona_niche;

-- ---------------------------------------------------------------------------------------------
-- influencer_images: angle references and audio
-- ---------------------------------------------------------------------------------------------
ALTER TABLE influencer_images
    MODIFY COLUMN role ENUM('upload','face','reference','training','generated','video','enhanced','angle','audio') NOT NULL,
    ADD COLUMN angle VARCHAR(24) NULL AFTER role,                  -- front_close | left_profile | right_profile | back | full_front | full_back
    ADD COLUMN approved TINYINT(1) NOT NULL DEFAULT 0 AFTER angle;

-- ---------------------------------------------------------------------------------------------
-- ai_credit_transactions: types the code already writes but the enum rejected (stored as '')
-- ---------------------------------------------------------------------------------------------
ALTER TABLE ai_credit_transactions
    MODIFY COLUMN type ENUM('plan_grant','purchase','spend','refund','adjust',
                            'plan_expire','pack_expire','pack_grant','admin_adjust') NOT NULL;

UPDATE ai_credit_transactions SET type = 'plan_expire'  WHERE type = '' AND description = 'Unused plan credits expired';
UPDATE ai_credit_transactions SET type = 'pack_expire'  WHERE type = '' AND description = 'Unused pack credits expired';
UPDATE ai_credit_transactions SET type = 'admin_adjust' WHERE type = '' AND description LIKE 'Adjustment by support%';
UPDATE ai_credit_transactions SET type = 'pack_grant'   WHERE type = '' AND credits > 0;
UPDATE ai_credit_transactions SET type = 'admin_adjust' WHERE type = '';
