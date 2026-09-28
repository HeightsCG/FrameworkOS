-- What the buyer's card actually paid for a wallet top-up (credits + processing fee), so admin "Money In" is exact.
-- Filled by TopUps::fulfill from the PaymentIntent. Rows without it fall back to credits * 10.
ALTER TABLE credit_transactions ADD COLUMN paid_cents INT NULL DEFAULT NULL AFTER stripe_payment_intent_id;
