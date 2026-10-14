-- Automation runs record what was sent to the models (AutoPostService / AutomationPlan, 2026-10-14):
-- the base image prompt, the on-screen hook line burned on in code, the motion action, and the models used.
ALTER TABLE scheduler_runs
  ADD COLUMN image_prompt  TEXT NULL AFTER message,
  ADD COLUMN hook_line     VARCHAR(255) NULL AFTER image_prompt,
  ADD COLUMN motion_action TEXT NULL AFTER hook_line,
  ADD COLUMN image_model   VARCHAR(64) NULL AFTER motion_action,
  ADD COLUMN video_model   VARCHAR(64) NULL AFTER image_model;
