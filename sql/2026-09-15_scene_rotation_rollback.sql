-- Rollback of 2026-09-15_scene_rotation.sql (feature removed the same day).
-- Apply by hand: dev + live.
ALTER TABLE scheduler_rules
  DROP COLUMN scene_poses,
  DROP COLUMN scene_outfits,
  DROP COLUMN scene_lighting,
  DROP COLUMN scene_suffix,
  DROP COLUMN recent_combos;
ALTER TABLE posts DROP COLUMN image_prompt;
SELECT IF(COUNT(*) = 0, 'OK', 'STILL PRESENT') AS scene_rotation_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'scheduler_rules' AND COLUMN_NAME IN ('scene_poses','scene_outfits','scene_lighting','scene_suffix','recent_combos'))
    OR (TABLE_NAME = 'posts' AND COLUMN_NAME = 'image_prompt'));
