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
