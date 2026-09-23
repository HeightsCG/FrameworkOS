-- account_addons is no longer used: extra AI influencer slots moved to billing_accounts.influencer_slots
-- (see 2026-09-23_app_billing.sql). Remove the table if an earlier version of this file created it.
DROP TABLE IF EXISTS account_addons;
