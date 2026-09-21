-- SEO content engine (sub-project B): keyword queue, blog articles, article view counts.
-- Three new tables; touches no existing table. Run on prod BEFORE deploying the code
-- (Admin > Content and /blog read these tables).
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-21_seo_content_engine.sql
-- Then add the cron line (first run seeds the 15 starter keywords and drafts one article):
--   0 9 * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_draft.php >> /var/www/creatorlinkstudio.com/www/cron/seo_draft.log 2>&1
-- Already applied on dev.
CREATE TABLE IF NOT EXISTS seo_keywords (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  keyword     VARCHAR(160) NOT NULL,
  volume      INT UNSIGNED NULL,
  difficulty  ENUM('easy','doable','hard') NOT NULL DEFAULT 'doable',
  priority    INT NOT NULL DEFAULT 100,
  status      ENUM('queued','drafting','drafted','published','skipped') NOT NULL DEFAULT 'queued',
  article_id  INT UNSIGNED NULL,
  last_error  VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_keyword (keyword),
  KEY idx_status_priority (status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_articles (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug               VARCHAR(160) NOT NULL,
  title              VARCHAR(200) NOT NULL,
  meta_description   VARCHAR(200) NOT NULL DEFAULT '',
  excerpt            TEXT NULL,
  body_md            MEDIUMTEXT NOT NULL,
  body_html          MEDIUMTEXT NOT NULL,
  target_keyword     VARCHAR(160) NOT NULL DEFAULT '',
  secondary_keywords JSON NULL,
  faq                JSON NULL,
  cover_image_url    VARCHAR(500) NULL,
  reading_minutes    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  status             ENUM('draft','review','published','archived') NOT NULL DEFAULT 'review',
  rewrite_note       TEXT NULL,
  model              VARCHAR(80) NULL,
  prompt_version     VARCHAR(20) NULL,
  views              INT UNSIGNED NOT NULL DEFAULT 0,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  published_at       DATETIME NULL,
  published_by       INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_status_published (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_page_views (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id  INT UNSIGNED NOT NULL,
  viewer_key  VARCHAR(64) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_view (article_id, viewer_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
