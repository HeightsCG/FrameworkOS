-- Social stats via Post for Me: per-post engagement pulled from each connected social account
-- (views, likes, comments, shares, saves, reach), matched back to our posts where possible.
-- Filled by cron/social_metrics.php (every 6 h); read by the Dashboard "All posts" table and the
-- social_metrics MCP tool.
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-20_social_post_metrics.sql
-- Cron:
--   0 */6 * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/social_metrics.php >> /var/www/creatorlinkstudio.com/www/cron/social_metrics.log 2>&1
-- Already applied on dev and prod.

CREATE TABLE IF NOT EXISTS social_post_metrics (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  social_account_id VARCHAR(64) NOT NULL,
  platform          VARCHAR(32) NOT NULL,
  pfm_post_id       VARCHAR(64) DEFAULT NULL,
  post_id           BIGINT UNSIGNED DEFAULT NULL,
  platform_post_id  VARCHAR(255) NOT NULL,
  platform_url      VARCHAR(512) DEFAULT NULL,
  caption           TEXT,
  posted_at         DATETIME DEFAULT NULL,
  views             INT UNSIGNED NOT NULL DEFAULT 0,
  likes             INT UNSIGNED NOT NULL DEFAULT 0,
  comments          INT UNSIGNED NOT NULL DEFAULT 0,
  shares            INT UNSIGNED NOT NULL DEFAULT 0,
  saves             INT UNSIGNED NOT NULL DEFAULT 0,
  reach             INT UNSIGNED NOT NULL DEFAULT 0,
  metrics_json      MEDIUMTEXT,
  fetched_at        DATETIME NOT NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_account_post (social_account_id, platform_post_id(191)),
  KEY idx_user_post (user_id, post_id),
  KEY idx_pfm_post (pfm_post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
