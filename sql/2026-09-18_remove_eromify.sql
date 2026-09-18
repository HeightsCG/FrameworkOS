-- Eromify integration removed (native AI influencers replace it). Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-18_remove_eromify.sql

UPDATE scheduler_rules SET image_source = 'brand' WHERE image_source = 'character';
ALTER TABLE scheduler_rules DROP COLUMN character_id, DROP COLUMN character_name;
DROP TABLE IF EXISTS user_eromify_accounts;

SELECT IF(COUNT(*) = 0, 'OK', 'STILL THERE') AS eromify_table
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_eromify_accounts';
