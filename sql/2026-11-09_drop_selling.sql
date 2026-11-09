-- NOT TO RUN YET. Drops tables of removed selling features after the retention period.
-- Safe to re-run: tables use DROP TABLE IF EXISTS and every column drop checks information_schema first
-- (a column that is not there, e.g. one prod never had, is skipped instead of stopping the file).
-- ORDER: deploy the code, run cron/retire_selling_once.php --apply, THEN this file.
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
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `user_accounts` DROP COLUMN `credit_balance`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_accounts' AND column_name = 'credit_balance');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `user_accounts` DROP COLUMN `autoreplenish_enabled`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_accounts' AND column_name = 'autoreplenish_enabled');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `user_accounts` DROP COLUMN `autoreplenish_threshold`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_accounts' AND column_name = 'autoreplenish_threshold');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `user_accounts` DROP COLUMN `autoreplenish_amount_cents`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_accounts' AND column_name = 'autoreplenish_amount_cents');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `user_accounts` DROP COLUMN `autoreplenish_pm_id`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_accounts' AND column_name = 'autoreplenish_pm_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `user_accounts` DROP COLUMN `autoreplenish_last_attempt_at`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_accounts' AND column_name = 'autoreplenish_last_attempt_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `user_accounts` DROP COLUMN `stripe_connect_account_id`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_accounts' AND column_name = 'stripe_connect_account_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `billing_accounts` DROP COLUMN `fee_percent_override`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'billing_accounts' AND column_name = 'fee_percent_override');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
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
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `posts` DROP COLUMN `ppv_price_credits`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'posts' AND column_name = 'ppv_price_credits');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `posts` DROP COLUMN `tier_id`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'posts' AND column_name = 'tier_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `posts` DROP COLUMN `fanvue_post_uuid`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'posts' AND column_name = 'fanvue_post_uuid');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `scheduler_rules` DROP COLUMN `tier_id`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'scheduler_rules' AND column_name = 'tier_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `messages` DROP COLUMN `price_credits`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'price_credits');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `auto_messages` DROP COLUMN `price_credits`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'auto_messages' AND column_name = 'price_credits');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `broadcasts` DROP COLUMN `price_credits`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'broadcasts' AND column_name = 'price_credits');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `launch_campaigns` DROP COLUMN `promo_code_id`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'launch_campaigns' AND column_name = 'promo_code_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `inbox_settings` DROP COLUMN `fanvue_enabled`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inbox_settings' AND column_name = 'fanvue_enabled');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `inbox_settings` DROP COLUMN `upsell_enabled`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inbox_settings' AND column_name = 'upsell_enabled');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
-- Run cron/retire_selling_once.php --apply first: step e turns media approved as adult back to 'flagged',
-- archives live/scheduled posts with adult media, deletes adult scene templates and turns the toggle off.
-- media_assets.moderation_status STAYS (the content check still uses it; 'flagged' = held, 'blocked' = never).
-- Didit IDENTITY verification (verifications table, creator badge) STAYS.
DROP TABLE IF EXISTS age_verifications;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `user_accounts` DROP COLUMN `adult_content_enabled`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_accounts' AND column_name = 'adult_content_enabled');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `scene_templates` DROP COLUMN `is_adult`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'scene_templates' AND column_name = 'is_adult');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `media_assets` DROP COLUMN `is_adult`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'media_assets' AND column_name = 'is_adult');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
