-- TikTok pixel CompleteRegistration, once per account (2026-10-16).
-- tt_reg_event_id: set when an account is created (email signup, Google signup, team invite). The same id goes to the
--   browser pixel and to the server Events API copy (TikTokEventJob), so TikTok counts the signup once.
-- tt_reg_sent_at: set the one time the browser is handed the event. Never handed out again, so a refresh, a later
--   sign-in or a second tab fires nothing. Accounts created before this change have no id and never fire.
-- Written by UsersModel::start_tt_registration / claim_tt_registration.
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-16_tiktok_registration_flag.sql
-- RUN BEFORE DEPLOYING (signup writes these columns). Already applied on dev.
ALTER TABLE user_accounts
    ADD COLUMN tt_reg_event_id VARCHAR(64) NULL DEFAULT NULL AFTER signup_founding,
    ADD COLUMN tt_reg_sent_at  DATETIME    NULL DEFAULT NULL AFTER tt_reg_event_id;
