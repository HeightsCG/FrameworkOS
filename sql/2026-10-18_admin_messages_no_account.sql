-- Admin messages without a system account (Daniel, 2026-10-18). "Creator Link Studio" in the Inbox is now participant 0
-- (PlatformAccount::ID), not a user row: team conversations, their messages and announcements move from the account made by
-- sql/2026-10-18_platform_account.sql to 0, then that account is deleted. Which admin sent what stays in the admin audit log.
-- Safe to run when the account was never created (every statement then changes nothing).
--
-- Apply by hand, RIGHT AFTER deploying the code (the old code needs the account; the new code ignores it):
--   mysql -h <host> -u <user> -p <db> < sql/2026-10-18_admin_messages_no_account.sql
-- Already applied on dev.
-- The user_accounts.is_platform column is left in place, unused.

SET @team_acct = (SELECT user_id FROM user_accounts WHERE is_platform = 1 ORDER BY user_id LIMIT 1);

-- The team was always the creator side; the user side never held it, but cover it anyway.
UPDATE conversations SET creator_id = 0 WHERE @team_acct IS NOT NULL AND creator_id = @team_acct;
UPDATE conversations SET user_id    = 0 WHERE @team_acct IS NOT NULL AND user_id = @team_acct;
UPDATE conversations SET last_sender_id = 0 WHERE @team_acct IS NOT NULL AND last_sender_id = @team_acct;
UPDATE messages      SET sender_id  = 0 WHERE @team_acct IS NOT NULL AND sender_id = @team_acct;
UPDATE broadcasts    SET creator_id = 0 WHERE @team_acct IS NOT NULL AND creator_id = @team_acct;
-- A user who blocked the team account keeps blocking the team.
UPDATE user_blocks   SET blocked_user_id = 0 WHERE @team_acct IS NOT NULL AND blocked_user_id = @team_acct;
DELETE FROM user_blocks WHERE @team_acct IS NOT NULL AND user_id = @team_acct;

DELETE FROM user_accounts WHERE @team_acct IS NOT NULL AND user_id = @team_acct AND is_platform = 1;
