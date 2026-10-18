-- 2026-10-18 audit, money area (docs/audit-2026-10-18.md: Money + Low Events / Billing and webhooks / recordings).
-- What it does:
--   1. billing_accounts.billing_anchor_day: the account's billing day of month, so Jan 31 renews Feb 28, then Mar 31
--      (BillingService::add_period). Backfilled from current_period_end.
--   2. service_purchases: a fan may book a service again once the previous booking is delivered or refunded.
--      UNIQUE(service_id, buyer_id) is replaced by UNIQUE(service_id, buyer_id, open_key), where open_key is a
--      generated column (1 while the booking is paid and not delivered, else NULL), so two OPEN bookings of the same
--      service are still impossible.
--   3. Clean-up of existing rows: bundle items whose file was deleted, in bundles nobody bought; recordings (and their
--      videos) of events that were already deleted.
--
-- Apply by hand (run before deploy):
--   mysql -h <host> --protocol=TCP -u <user> -p <db> < sql/2026-10-18_audit_money.sql
-- Already applied on dev.
--
-- Prod safety: the new unique key cannot fail on existing rows, since the old UNIQUE(service_id, buyer_id) already
-- allows one row per pair. Pre-check (must return no rows):
--   SELECT service_id, buyer_id, COUNT(*) FROM service_purchases GROUP BY service_id, buyer_id HAVING COUNT(*) > 1;

-- 1. billing anchor day
ALTER TABLE billing_accounts ADD COLUMN billing_anchor_day TINYINT UNSIGNED NULL DEFAULT NULL AFTER next_charge_at;
UPDATE billing_accounts SET billing_anchor_day = DAY(current_period_end) WHERE current_period_end IS NOT NULL AND billing_anchor_day IS NULL;

-- 2. repeat service bookings, one open booking at a time
ALTER TABLE service_purchases
  ADD COLUMN open_key TINYINT AS (IF(status = 'paid' AND delivered_at IS NULL, 1, NULL)) STORED,
  ADD UNIQUE KEY uq_service_buyer_open (service_id, buyer_id, open_key),
  DROP INDEX uq_service_buyer;

-- 3a. deleted files leave bundles that have no sales (sold bundles keep them: buyers keep what they bought)
DELETE bi FROM bundle_items bi
  JOIN media_assets ma ON ma.id = bi.asset_id
 WHERE ma.deleted_at IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM bundle_unlocks bu WHERE bu.bundle_id = bi.bundle_id);

-- 3b. recordings of events that no longer exist stop counting toward storage
UPDATE media_assets ma
  JOIN live_recordings r ON r.asset_id = ma.id
  LEFT JOIN events e ON e.id = r.event_id
   SET ma.deleted_at = UTC_TIMESTAMP(), ma.updated_at = UTC_TIMESTAMP()
 WHERE e.id IS NULL AND ma.deleted_at IS NULL;
UPDATE live_recordings r
  LEFT JOIN events e ON e.id = r.event_id
   SET r.status = 'deleted'
 WHERE e.id IS NULL AND r.status <> 'deleted';
