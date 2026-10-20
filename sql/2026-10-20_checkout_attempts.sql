-- Checkout attempts (2026-10-20): one row per plan / add-on checkout a user opens, and what happened to it.
-- Status: opened -> card_entered -> succeeded | failed | abandoned. Written by CheckoutAttempts (libs/Classes).

CREATE TABLE IF NOT EXISTS checkout_attempts (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                  INT NOT NULL,
  kind                     VARCHAR(16) NOT NULL,              -- plan | pack | slots
  item                     VARCHAR(32) NOT NULL DEFAULT '',   -- plan key, pack dollars, or slot count
  mode                     VARCHAR(16) NOT NULL DEFAULT '',   -- subscribe | upgrade | pack | slots
  founding                 TINYINT(1) NOT NULL DEFAULT 0,
  amount_cents             INT NOT NULL DEFAULT 0,            -- due today
  recurring_cents          INT NOT NULL DEFAULT 0,
  status                   VARCHAR(16) NOT NULL DEFAULT 'opened',
  failure_code             VARCHAR(64) DEFAULT NULL,          -- Stripe decline_code (or error code)
  failure_reason           VARCHAR(255) DEFAULT NULL,         -- plain language, e.g. "Card declined: insufficient funds"
  declines                 INT NOT NULL DEFAULT 0,            -- declines before the outcome (a retry with another card keeps the row)
  charge_id                INT UNSIGNED DEFAULT NULL,         -- billing_charges.id
  stripe_setup_intent_id   VARCHAR(64) DEFAULT NULL,
  stripe_payment_intent_id VARCHAR(64) DEFAULT NULL,
  opened_at                DATETIME NOT NULL,
  card_entered_at          DATETIME DEFAULT NULL,
  finished_at              DATETIME DEFAULT NULL,
  backfilled               TINYINT(1) NOT NULL DEFAULT 0,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id, opened_at),
  KEY idx_status (status, opened_at),
  KEY idx_opened (opened_at),
  KEY idx_charge (charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Backfill: every plan checkout opened before this table existed (Analytics 'checkout_started', since 2026-10-18),
-- linked to its user. Downgrades are left out (nothing is paid). Safe to run twice.
INSERT INTO checkout_attempts (user_id, kind, item, mode, founding, amount_cents, recurring_cents, status, opened_at, backfilled)
SELECT ae.user_id, 'plan', SUBSTRING_INDEX(SUBSTRING_INDEX(ae.meta, ' ', 2), ' ', -1), SUBSTRING_INDEX(ae.meta, ' ', 1),
       ae.meta LIKE '% founding', 0, COALESCE(ae.value_cents, 0), 'opened', ae.ts, 1
FROM analytics_events ae
WHERE ae.kind = 'event' AND ae.name = 'checkout_started' AND ae.user_id > 0 AND ae.meta NOT LIKE 'downgrade%'
  AND NOT EXISTS (SELECT 1 FROM checkout_attempts ca WHERE ca.user_id = ae.user_id AND ca.opened_at = ae.ts AND ca.backfilled = 1);

-- What happened next: the first plan charge after the checkout opened (within a day).
UPDATE checkout_attempts ca
JOIN billing_charges ch ON ch.id = (SELECT c2.id FROM billing_charges c2 WHERE c2.user_id = ca.user_id AND c2.kind IN ('subscribe', 'upgrade')
                                    AND c2.created_at >= ca.opened_at AND c2.created_at < ca.opened_at + INTERVAL 1 DAY ORDER BY c2.id LIMIT 1)
SET ca.charge_id = ch.id, ca.stripe_payment_intent_id = ch.stripe_payment_intent_id, ca.amount_cents = ch.amount_cents,
    ca.card_entered_at = ch.created_at, ca.finished_at = ch.updated_at,
    ca.status = CASE WHEN ch.status = 'succeeded' THEN 'succeeded' WHEN ch.status = 'failed' THEN 'failed' ELSE 'card_entered' END,
    ca.failure_reason = CASE WHEN ch.status = 'failed' THEN ch.failure_reason END
WHERE ca.backfilled = 1 AND ca.charge_id IS NULL;

-- Never paid and over an hour old: abandoned.
UPDATE checkout_attempts SET status = 'abandoned', failure_reason = 'Closed checkout without entering a card', finished_at = opened_at + INTERVAL 1 HOUR
WHERE backfilled = 1 AND status = 'opened' AND opened_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR;
