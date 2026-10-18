-- Audit 2026-10-18, performance under ad traffic: indexes for the signed-in home feed, the block filter,
-- the directory "trending" sort and its 7-day follower count. Index-only: no data changes, no UNIQUE keys.
--   posts        idx_posts_feed (state, on_cls, published_at, id)   home feed page of ids, newest first, no filesort
--   user_blocks  idx_blocked (blocked_user_id, user_id)              "who blocked this account" side of the block filter
--   post_views   idx_pv_created (created_at, post_id)               trending: one range scan over the last 7 days of views
--   follows      idx_follows_creator_created (creator_id, created_at) trending: new followers in the last 7 days
-- Each statement checks information_schema first, so the file can be run more than once.
-- Apply by hand (run before deploy):
--   mysql -h <host> -u <user> -p <db> < sql/2026-10-18_audit_performance.sql
-- Already applied on dev.

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD INDEX idx_posts_feed (state, on_cls, published_at, id)', 'SELECT 1')
          FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'posts' AND index_name = 'idx_posts_feed');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE user_blocks ADD INDEX idx_blocked (blocked_user_id, user_id)', 'SELECT 1')
          FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'user_blocks' AND index_name = 'idx_blocked');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE post_views ADD INDEX idx_pv_created (created_at, post_id)', 'SELECT 1')
          FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'post_views' AND index_name = 'idx_pv_created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE follows ADD INDEX idx_follows_creator_created (creator_id, created_at)', 'SELECT 1')
          FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'follows' AND index_name = 'idx_follows_creator_created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
