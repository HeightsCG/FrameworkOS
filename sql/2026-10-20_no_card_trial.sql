-- No-card trial (2026-10-20): the founding trial starts without a card. trial_credits_held = the plan's AI credits held back
-- until a card is added (NULL = not a no-card trial). Day 14 with no card: the account moves to Free.
ALTER TABLE billing_accounts ADD COLUMN trial_credits_held INT DEFAULT NULL AFTER fee_percent_override;

-- Fix: a $0 charge (a trial start) left its checkout attempt at card_entered. Mark those succeeded from their charge.
UPDATE checkout_attempts a JOIN billing_charges c ON c.id = a.charge_id
SET a.status = 'succeeded', a.finished_at = c.updated_at, a.failure_reason = NULL
WHERE a.status = 'card_entered' AND c.status = 'succeeded';
