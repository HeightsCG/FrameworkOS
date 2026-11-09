-- NOT TO RUN YET. Drops tables of removed selling features after the retention period.
-- Apply by hand: mysql -h <host> --protocol=TCP -u <user> -p contentos < sql/2026-11-09_drop_selling.sql
-- Code stopped reading/writing these tables on 2026-11-09 (docs/remove-selling-2026-11-09.txt).
-- Run cron/retire_selling_once.php first (reports unreleased earnings / balances) and keep the
-- data for the retention period before dropping. Each agent appends its own section below.

-- ---- B. Events, Services, CLS Video ----
DROP TABLE IF EXISTS event_reminder_sends;
DROP TABLE IF EXISTS event_messages;
DROP TABLE IF EXISTS replay_unlocks;
DROP TABLE IF EXISTS event_registrations;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS service_purchases;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS live_tips;
DROP TABLE IF EXISTS live_waiting;
DROP TABLE IF EXISTS live_rooms;
DROP TABLE IF EXISTS live_recordings;
-- media_assets rows with source = 'recording' (CLS Video recordings) stay in the library as ordinary media.

-- ---- C: fan money (fan wallet credits, creator earnings + payouts + Stripe Connect, fan refunds) ----
-- chargebacks STAYS: RefundsModel::record_chargeback still logs plan (billing_charges) disputes there.
-- Read the money-in-flight report (fan/creator credit_balance, pending payouts) before running this.
DROP TABLE IF EXISTS credit_transactions;
DROP TABLE IF EXISTS payouts;
DROP TABLE IF EXISTS refunds;
ALTER TABLE user_accounts
    DROP COLUMN credit_balance,
    DROP COLUMN autoreplenish_enabled,
    DROP COLUMN autoreplenish_threshold,
    DROP COLUMN autoreplenish_amount_cents,
    DROP COLUMN autoreplenish_pm_id,
    DROP COLUMN autoreplenish_last_attempt_at,
    DROP COLUMN stripe_connect_account_id;   -- only after agent D's retirement script has canceled the Connect memberships
ALTER TABLE billing_accounts DROP COLUMN fee_percent_override;

-- ---- D: content selling (post paywall, fan memberships, bundles, fan promo codes, paid messages, Fanvue) ----
-- Run cron/retire_selling_once.php --apply first (cancels Connect memberships, archives paid posts).
-- posts.audience STAYS for now: after the script every live post is 'free'; the enum can be narrowed later.
DROP TABLE IF EXISTS ppv_unlocks;
DROP TABLE IF EXISTS bundle_unlocks;
DROP TABLE IF EXISTS bundle_items;
DROP TABLE IF EXISTS content_bundles;
DROP TABLE IF EXISTS post_tiers;
DROP TABLE IF EXISTS membership_checkouts;
DROP TABLE IF EXISTS creator_subscriptions;
DROP TABLE IF EXISTS creator_plans;
DROP TABLE IF EXISTS creator_promo_codes;
DROP TABLE IF EXISTS message_unlocks;
DROP TABLE IF EXISTS user_fanvue_accounts;
ALTER TABLE posts
    DROP COLUMN ppv_price_credits,
    DROP COLUMN tier_id,
    DROP COLUMN fanvue_post_uuid;
ALTER TABLE scheduler_rules DROP COLUMN tier_id;   -- scheduler_rules.audience stays like posts.audience
ALTER TABLE messages DROP COLUMN price_credits;
ALTER TABLE auto_messages DROP COLUMN price_credits;
ALTER TABLE broadcasts DROP COLUMN price_credits;
ALTER TABLE launch_campaigns DROP COLUMN promo_code_id;
ALTER TABLE inbox_settings
    DROP COLUMN fanvue_enabled,
    DROP COLUMN upsell_enabled;

-- E: adult content (adult toggle, Didit age verification, adult starter scenes, is_adult flags) ----
-- Run cron/retire_selling_once.php --apply first: step e turns media approved as adult back to 'flagged',
-- archives live/scheduled posts with adult media, deletes adult scene templates and turns the toggle off.
-- media_assets.moderation_status STAYS (the content check still uses it; 'flagged' = held, 'blocked' = never).
-- Didit IDENTITY verification (verifications table, creator badge) STAYS.
DROP TABLE IF EXISTS age_verifications;
ALTER TABLE user_accounts DROP COLUMN adult_content_enabled;
ALTER TABLE scene_templates DROP COLUMN is_adult;
ALTER TABLE media_assets DROP COLUMN is_adult;
