-- AI credits: one balance per account, granted monthly by the platform plan and
-- topped up by purchase ($1 = 1 AI credit). Separate from the fan-facing wallet
-- (credit_balance / credit_transactions, $1 = 10 credits).
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-19_ai_credits.sql
-- Already applied on prod. Follow with sql/2026-09-19_ai_credits_grant_amount.sql.

ALTER TABLE user_accounts
  ADD COLUMN ai_credit_balance      INT NOT NULL DEFAULT 0 AFTER credit_balance,
  ADD COLUMN ai_credit_grant_period CHAR(10) DEFAULT NULL AFTER ai_credit_balance;

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

-- What a job was charged, so a failure/cancel refunds exactly that.
ALTER TABLE influencer_jobs
  ADD COLUMN credits_charged SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER cost_usd;

SELECT IF(COUNT(*) = 1, 'OK', 'MISSING') AS ai_credit_transactions_table
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_credit_transactions';
