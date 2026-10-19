-- TikTok CompleteRegistration for creators only (2026-10-19). Non-creator signups send a server-only FanSignup instead.
-- tt_fan_sent_at: set the one time a new non-creator account's FanSignup is sent (UsersModel::claim_tt_fan_signup).
ALTER TABLE user_accounts
    ADD COLUMN tt_fan_sent_at DATETIME NULL DEFAULT NULL AFTER tt_reg_sent_at;

-- Accounts created before this change never fire anything later: a fan from 2026-10-16 to today who becomes a creator,
-- or an invite not yet accepted, would otherwise still have an unsent CompleteRegistration or FanSignup.
UPDATE user_accounts
   SET tt_reg_sent_at = COALESCE(tt_reg_sent_at, UTC_TIMESTAMP()),
       tt_fan_sent_at = UTC_TIMESTAMP()
 WHERE tt_reg_event_id IS NOT NULL;
