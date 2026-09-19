-- AI credits, part 2 (after 2026-09-19_ai_credits.sql, which prod already has).
-- Records how much the current period's plan grant has added, so a mid-period rise in
-- the plan's amount (upgrade, or a raised allowance) tops up only the difference.
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-19_ai_credits_grant_amount.sql

ALTER TABLE user_accounts
  ADD COLUMN ai_credit_grant_amount INT NOT NULL DEFAULT 0 AFTER ai_credit_grant_period;

-- Backfill for accounts already granted this period: what their grant added so far.
UPDATE user_accounts u
   SET ai_credit_grant_amount = (SELECT COALESCE(SUM(t.credits), 0) FROM ai_credit_transactions t
                                  WHERE t.user_id = u.user_id AND t.type = 'plan_grant')
 WHERE ai_credit_grant_period IS NOT NULL;

SELECT IF(COUNT(*) = 1, 'OK', 'MISSING') AS ai_credit_grant_amount_column
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_accounts' AND COLUMN_NAME = 'ai_credit_grant_amount';
