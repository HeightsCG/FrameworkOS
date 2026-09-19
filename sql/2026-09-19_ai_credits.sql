-- AI credits: one balance per account, granted monthly by the platform plan and
-- topped up by purchase ($1 = 1 AI credit). Separate from the fan-facing wallet
-- (credit_balance / credit_transactions, $1 = 10 credits).
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-19_ai_credits.sql

ALTER TABLE user_accounts
  ADD COLUMN ai_credit_balance      INT NOT NULL DEFAULT 0 AFTER credit_balance,
  ADD COLUMN ai_credit_grant_period CHAR(10) DEFAULT NULL AFTER ai_credit_balance,
  ADD COLUMN ai_credit_grant_amount INT NOT NULL DEFAULT 0 AFTER ai_credit_grant_period;   -- what this period's grant added so far (topped up when the plan amount rises)

CREATE TABLE IF NOT EXISTS ai_credit_transactions (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                  INT UNSIGNED NOT NULL,
  type                     ENUM('plan_grant','purchase','spend','refund','adjust') NOT NULL,
  credits                  INT NOT NULL,
  balance_after            INT NOT NULL,
  job_id                   BIGINT UNSIGNED DEFAULT NULL,
  stripe_payment_intent_id VARCHAR(64) DEFAULT NULL,
  description              VARCHAR(255) NOT NULL DEFAULT '',
  created_at               DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_credit_pi (stripe_payment_intent_id),
  KEY idx_ai_credit_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Backfill (only matters if grants already ran before the column existed): what each
-- account's current-period grant has added so far.
UPDATE user_accounts u
   SET ai_credit_grant_amount = (SELECT COALESCE(SUM(t.credits), 0) FROM ai_credit_transactions t WHERE t.user_id = u.user_id AND t.type = 'plan_grant')
 WHERE ai_credit_grant_period IS NOT NULL;

-- What a job was charged, so a failure/cancel refunds exactly that.
ALTER TABLE influencer_jobs
  ADD COLUMN credits_charged SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER cost_usd;

SELECT IF(COUNT(*) = 1, 'OK', 'MISSING') AS ai_credit_transactions_table
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_credit_transactions';
