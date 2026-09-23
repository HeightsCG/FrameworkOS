-- AI credits move from $1 = 1 credit to $1 = 10 credits (same as the fan wallet), so credits don't read as dollars.
-- Every AI credit amount is multiplied by 10: balances, plan/pack buckets, the grant marker, the ledger and
-- what running influencer jobs were charged (refunds must return the new amount). Run ONCE.
UPDATE user_accounts SET ai_credit_balance = ai_credit_balance * 10, ai_credits_plan = ai_credits_plan * 10,
    ai_credits_pack = ai_credits_pack * 10, ai_credit_grant_amount = ai_credit_grant_amount * 10;
UPDATE ai_credit_transactions SET credits = credits * 10, balance_after = balance_after * 10;
UPDATE influencer_jobs SET credits_charged = credits_charged * 10;
