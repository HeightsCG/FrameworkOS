-- Scene rotation for scheduler automations + persisted image prompt on posts.
-- Apply by hand: dev + live.
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-15_scene_rotation.sql

ALTER TABLE scheduler_rules
  ADD COLUMN scene_poses    TEXT NULL                          AFTER topic,   -- JSON array of strings
  ADD COLUMN scene_outfits  TEXT NULL                          AFTER scene_poses,
  ADD COLUMN scene_lighting TEXT NULL                          AFTER scene_outfits,
  ADD COLUMN scene_suffix   VARCHAR(255) NOT NULL DEFAULT ''   AFTER scene_lighting,
  ADD COLUMN recent_combos  TEXT NULL                          AFTER scene_suffix;  -- JSON array of [pose,outfit,lighting] index triples, newest last, max 30

ALTER TABLE posts
  ADD COLUMN image_prompt VARCHAR(1000) NULL AFTER caption;  -- exact prompt sent to the image model by an automation

SELECT IF(COUNT(*) = 6, 'OK', 'MISSING') AS scene_rotation_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME IN ('scene_poses','scene_outfits','scene_lighting','scene_suffix','recent_combos'))
    OR (TABLE_NAME = 'posts' AND COLUMN_NAME = 'image_prompt'));
