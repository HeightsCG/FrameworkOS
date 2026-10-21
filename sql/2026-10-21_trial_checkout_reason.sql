-- Founding trial checkouts need no card since the no-card trial (2026-10-20): ones closed without starting the trial said
-- "Closed checkout without entering a card". Same wording as CheckoutAttemptsModel::expire() now uses for them.
UPDATE checkout_attempts SET failure_reason = 'Closed checkout without starting the trial'
 WHERE status = 'abandoned' AND founding = 1 AND amount_cents = 0
   AND failure_reason = 'Closed checkout without entering a card' AND opened_at >= '2026-10-21';
