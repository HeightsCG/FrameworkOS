-- Influencer body settings: height, build and bust. They are added to every image prompt for the influencer,
-- to her full-body reference images and to the full-body shots of her training set.
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-05_influencer_body.sql
--
-- RUN BEFORE DEPLOYING the code that reads these columns (influencer create, settings, image generation).
-- Safe while the old code is live (new columns default to '').
-- Applied on dev: 2026-10-05. NOT yet on prod.

ALTER TABLE influencers
    ADD COLUMN body_height VARCHAR(16) NOT NULL DEFAULT '' AFTER gender,   -- '' | short | average | tall
    ADD COLUMN body_build  VARCHAR(16) NOT NULL DEFAULT '' AFTER body_height,   -- '' | thin | average | athletic | muscular | curvy
    ADD COLUMN body_bust   VARCHAR(16) NOT NULL DEFAULT '' AFTER body_build;    -- '' | small | medium | large (women)
