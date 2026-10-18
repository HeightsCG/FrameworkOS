-- Per-account plan price lock (2026-10-18). Billing used to charge every account the plan's CURRENT list price on each
-- renewal, so changing a price would have changed it for existing subscribers too. Now each account stores the price it
-- started its plan at (billing_accounts.plan_price_cents, set when a plan starts or changes, cleared on moving to Free),
-- and renewals bill that. The Founding Creator offer ($49 until the end date set on /admin, then $99 for NEW signups)
-- relies on it: everyone who started at $49 keeps $49 while subscribed.
--
-- The backfill below locks every account on a paid plan today at today's price (Creator $49, Pro $149, Studio $199),
-- including founding trials. It MUST run before the offer's end date, and before deploying the code.
--
-- Apply by hand (run before deploy):
--   mysql -h <host> -u <user> -p <db> < sql/2026-10-18_plan_price_lock.sql
-- Already applied on dev.

ALTER TABLE billing_accounts ADD COLUMN plan_price_cents INT UNSIGNED NULL DEFAULT NULL AFTER plan_key;

UPDATE billing_accounts SET plan_price_cents = CASE plan_key WHEN 'creator' THEN 4900 WHEN 'pro' THEN 14900 WHEN 'studio' THEN 19900 END
 WHERE plan_price_cents IS NULL AND plan_key IN ('creator', 'pro', 'studio');
