-- Creator-owned scene templates (Content Studio, Scenes): a creator adds, edits, turns off and
-- deletes their OWN scenes beside the platform ones. creator_id NULL = platform scene (admin-managed).
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-06_creator_scenes.sql
--
-- Applied on dev: 2026-10-06.

ALTER TABLE scene_templates
    ADD COLUMN creator_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER id,
    ADD KEY idx_st_creator (creator_id);

-- Starter platform scenes (the same three as dev; the Rooftop Cafe thumbnail already exists in the bucket).
-- Skipped where a platform scene with that title already exists.
INSERT INTO scene_templates (creator_id, title, category, base_prompt, thumb_key, is_adult, default_aspect, is_active, sort_order, created_by, created_at, updated_at)
SELECT NULL, 'Rooftop Cafe', 'Lifestyle', 'photo of a {subject} at a rooftop cafe at golden hour, iced coffee in hand, city skyline behind, looking at the camera, film grain', 'scenes/7/thumb_b5910bfa.jpg', 0, '4:5', 1, 0, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()
 WHERE NOT EXISTS (SELECT 1 FROM scene_templates WHERE creator_id IS NULL AND title = 'Rooftop Cafe' AND deleted_at IS NULL);
INSERT INTO scene_templates (creator_id, title, category, base_prompt, thumb_key, is_adult, default_aspect, is_active, sort_order, created_by, created_at, updated_at)
SELECT NULL, 'Gym Mirror', 'Fitness', 'gym mirror photo of a {subject} in athletic wear, water bottle in hand, bright gym lighting', NULL, 0, '9:16', 1, 1, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()
 WHERE NOT EXISTS (SELECT 1 FROM scene_templates WHERE creator_id IS NULL AND title = 'Gym Mirror' AND deleted_at IS NULL);
INSERT INTO scene_templates (creator_id, title, category, base_prompt, thumb_key, is_adult, default_aspect, is_active, sort_order, created_by, created_at, updated_at)
SELECT NULL, 'Lingerie Bedroom', 'Boudoir', 'photo of a {subject} in lace lingerie on a bed, soft window light', NULL, 1, '3:4', 1, 2, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()
 WHERE NOT EXISTS (SELECT 1 FROM scene_templates WHERE creator_id IS NULL AND title = 'Lingerie Bedroom' AND deleted_at IS NULL);
