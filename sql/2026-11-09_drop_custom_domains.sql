-- Custom domains removed 2026-11-09 (owner decision): drops the tables behind them.
--   creator_domains: one row per creator hostname (lexivaughn.com, www.lexivaughn.com).
--   domain_handoffs: one-time sign-in tokens that carried a session to a creator's domain.
-- ORDER: deploy the code that removes custom domains, run cron/retire_domains_once.php --apply
-- (marks every domain removed and notifies its owner), THEN this file.
-- Safe to re-run: DROP TABLE IF EXISTS. No other table has a custom-domain column (checked on dev
-- information_schema 2026-11-09), so there is no column drop.
-- Apply by hand: mysql -h <host> --protocol=TCP -u <user> -p contentos < sql/2026-11-09_drop_custom_domains.sql

DROP TABLE IF EXISTS domain_handoffs;
DROP TABLE IF EXISTS creator_domains;
