-- The "Creator Link Studio" team account: the sender of admin messages (/admin user page, Users > Messages) and
-- announcements (Users > Announcements). Users see it in their Inbox and can reply; admins answer from /admin/messages.
-- It can't sign in: no email, and p_word is not a password hash, so no password ever verifies.
-- Run on prod with the deploy.
ALTER TABLE user_accounts ADD COLUMN is_platform TINYINT(1) NOT NULL DEFAULT 0 AFTER is_demo;

INSERT INTO user_accounts (first_name, last_name, user_email, email_verified, u_name, p_word, user_status, role_id, is_platform, reset_pw, content_timezone)
SELECT 'Creator Link Studio', '', '', 1, 'creatorlinkstudio', '!no-sign-in', 'Active', 1, 1, 0, 'UTC' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM user_accounts WHERE is_platform = 1)
  AND NOT EXISTS (SELECT 1 FROM user_accounts WHERE u_name = 'creatorlinkstudio');

-- The handle was already taken by someone: use a second one.
INSERT INTO user_accounts (first_name, last_name, user_email, email_verified, u_name, p_word, user_status, role_id, is_platform, reset_pw, content_timezone)
SELECT 'Creator Link Studio', '', '', 1, 'creatorlinkstudioteam', '!no-sign-in', 'Active', 1, 1, 0, 'UTC' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM user_accounts WHERE is_platform = 1);
