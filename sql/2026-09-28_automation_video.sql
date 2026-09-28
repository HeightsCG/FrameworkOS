-- Automations can post a video (2026-09-28): an automation (brand photo or influencer) generates its still as before,
-- then animates it (image-to-video, MediaVideoJob) with its own motion prompt; the post publishes when the video
-- lands (AutoPostService::finish_video). scheduler_rules.last_status gains the value 'rendering'.
--
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-28_automation_video.sql
-- Applied on dev (2026-09-28). NOT yet on prod.

ALTER TABLE scheduler_rules
    ADD COLUMN media_type      VARCHAR(8)  NOT NULL DEFAULT 'image' AFTER influencer_model_key,
    ADD COLUMN video_prompt    TEXT        NULL AFTER media_type,
    ADD COLUMN video_model_key VARCHAR(64) NULL AFTER video_prompt,
    ADD COLUMN video_duration  VARCHAR(4)  NULL AFTER video_model_key;
