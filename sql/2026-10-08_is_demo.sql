-- Demo accounts: hidden from the creator directory, sitemap, home feed and search; profile stays reachable but noindex.
-- Toggle from /admin user page ("Mark as Demo"). Run on prod with the deploy.
ALTER TABLE user_accounts ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin;
UPDATE user_accounts SET is_demo = 1 WHERE u_name = 'demoaccount';
