-- APPLIED ON PROD 2026-11-09.
-- 2026-11-09: Eromify integration removed (docs/remove-selling-2026-11-09.txt).
-- Drops the creators' Eromify API-key table and the scheduler_rules "My Character" columns.
-- ORDER: deploy the code, run cron/retire_eromify_once.php --apply (pauses rules still on the
-- 'character' source and switches them to Brand Photo), THEN this file.
-- Safe to re-run: DROP TABLE IF EXISTS, and each column drop checks information_schema first
-- (a column that is not there is skipped instead of stopping the file).
-- Apply by hand: mysql -h <host> --protocol=TCP -u <user> -p contentos < sql/2026-11-09_drop_eromify.sql

DROP TABLE IF EXISTS user_eromify_accounts;

SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `scheduler_rules` DROP COLUMN `character_id`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'scheduler_rules' AND column_name = 'character_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `scheduler_rules` DROP COLUMN `character_name`', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'scheduler_rules' AND column_name = 'character_name');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
