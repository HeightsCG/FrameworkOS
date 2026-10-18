-- Admin > Growth > Ads report (AdminModel::ad_funnel, 2026-10-18): two read indexes.
--   analytics_events: landing page views by campaign over a period (covering: kind, is_landing, ts, utm_campaign, utm_source).
--   user_accounts:    signups cohorted by created_at (the Growth tab's funnel queries use it too).
-- Index adds only, no data change; MySQL 8 builds them online (ALGORITHM=INPLACE, LOCK=NONE).
-- MySQL has no CREATE INDEX IF NOT EXISTS: if one already exists the statement errors harmlessly. Check first with
--   SHOW INDEX FROM analytics_events WHERE Key_name = 'idx_ae_landing_campaign';
--   SHOW INDEX FROM user_accounts WHERE Key_name = 'idx_ua_created';
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-18_ads_report.sql
-- Run before deploy (the report works without them, only slower). Already applied on dev.
CREATE INDEX idx_ae_landing_campaign ON analytics_events (kind, is_landing, ts, utm_campaign, utm_source) ALGORITHM=INPLACE LOCK=NONE;
CREATE INDEX idx_ua_created ON user_accounts (created_at) ALGORITHM=INPLACE LOCK=NONE;
