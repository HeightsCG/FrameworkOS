-- Referral reward (ReferralRewards): a creator earns AI credits when a signup from their page pays its first plan charge.
-- Two new AI credit ledger types; the reward row is keyed 'ref:<charge_id>' in stripe_payment_intent_id (unique).
ALTER TABLE ai_credit_transactions
    MODIFY COLUMN type ENUM('plan_grant','purchase','spend','refund','adjust',
                            'plan_expire','pack_expire','pack_grant','admin_adjust',
                            'referral_reward','referral_reversal') NOT NULL;
