-- First-party analytics (2026-10-11): one row per page view and per product/money event, written by libs/Classes/Analytics.php.
-- Read by SiteAnalyticsModel for /admin/analytics and the creator dashboard. No IP addresses: the visitor id is a random
-- first-party cookie, the country comes from an edge header when present. Rows older than 400 days are purged on the fly.
CREATE TABLE IF NOT EXISTS analytics_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts            DATETIME NOT NULL,
  kind          VARCHAR(10) NOT NULL,                  -- 'pageview' | 'event'
  name          VARCHAR(40) NOT NULL DEFAULT '',       -- event name (signup, plan_paid, purchase, ...); '' for a page view
  path          VARCHAR(255) NOT NULL DEFAULT '',      -- request path without the query string
  host          VARCHAR(100) NOT NULL DEFAULT '',
  status        SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  referrer_host VARCHAR(100) NOT NULL DEFAULT '',      -- external referrer only (same-host navigation is blank)
  referrer      VARCHAR(255) NOT NULL DEFAULT '',
  utm_source    VARCHAR(100) NOT NULL DEFAULT '',
  utm_medium    VARCHAR(100) NOT NULL DEFAULT '',
  utm_campaign  VARCHAR(150) NOT NULL DEFAULT '',
  landing       VARCHAR(255) NOT NULL DEFAULT '',      -- the session's first path (every row of the session)
  is_landing    TINYINT(1) NOT NULL DEFAULT 0,         -- the first page view of a session
  is_new        TINYINT(1) NOT NULL DEFAULT 0,         -- the visitor's first ever page view
  device        VARCHAR(10) NOT NULL DEFAULT '',       -- desktop | mobile | tablet
  browser       VARCHAR(20) NOT NULL DEFAULT '',
  os            VARCHAR(20) NOT NULL DEFAULT '',
  country       CHAR(2) NOT NULL DEFAULT '',
  visitor       CHAR(32) NOT NULL DEFAULT '',
  session       CHAR(32) NOT NULL DEFAULT '',
  user_id       INT UNSIGNED NOT NULL DEFAULT 0,       -- signed-in account, 0 for anonymous
  creator_id    INT UNSIGNED NOT NULL DEFAULT 0,       -- the creator a page or event belongs to (their /@handle, their sale)
  value_cents   INT NOT NULL DEFAULT 0,
  meta          VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY ts (ts),
  KEY kind_name_ts (kind, name, ts),
  KEY creator_ts (creator_id, ts),
  KEY visitor_ts (visitor, ts),
  KEY session_ts (session, ts),
  KEY path_ts (path(80), ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
