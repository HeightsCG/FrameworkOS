-- Posts: "Creator Link Studio" on/off destination + multi-tier subscriber posts.
--   posts.on_cls = 0 → socials-only post: never shown on Discover, the creator's profile, search,
--   or new-post notifications (the Studio still shows it to its owner).
--   post_tiers   → a subscribers post can target several membership tiers; any active plan in the
--   set unlocks it. posts.tier_id stays as the legacy single-tier fallback.
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-21_post_cls_toggle_and_tiers.sql
-- Already applied on dev and prod. The ALTER is not re-runnable (it errors with "Duplicate column"
-- on a database that already has on_cls); the rest is safe to re-run.

ALTER TABLE posts ADD COLUMN on_cls TINYINT(1) NOT NULL DEFAULT 1 AFTER audience;

CREATE TABLE IF NOT EXISTS post_tiers (
  post_id BIGINT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, plan_id),
  KEY idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: a legacy single-tier post unlocks for that tier and every higher-priced tier of the same creator.
INSERT IGNORE INTO post_tiers (post_id, plan_id)
SELECT p.id, cp.id
FROM posts p
JOIN creator_plans t  ON t.id = p.tier_id
JOIN creator_plans cp ON cp.user_id = p.creator_id AND cp.price_cents >= t.price_cents
WHERE p.audience = 'subscribers' AND p.tier_id IS NOT NULL AND p.tier_id > 0;
