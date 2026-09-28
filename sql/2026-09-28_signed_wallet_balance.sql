-- A refund's clawback is owed in full, so a creator's wallet can go below zero (a debt that future earnings repay).
-- Unsigned columns can't hold that: non-strict MySQL silently clamps it to 0 (the debt vanishes) and strict mode
-- rejects the update (nothing is clawed back at all). Signed INT keeps every existing value as is.
ALTER TABLE user_accounts       MODIFY credit_balance INT NOT NULL DEFAULT 0;
ALTER TABLE credit_transactions MODIFY balance_after  INT NOT NULL;
