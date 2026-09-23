-- App-managed platform billing (replaces Stripe Subscriptions for creator plans).
-- Stripe only stores the card and processes each charge; the app owns plans, add-ons and schedules.

-- One row per account that has (or had) a paid plan or a recurring credit pack.
CREATE TABLE IF NOT EXISTS billing_accounts (
    user_id                   INT NOT NULL,
    plan_key                  VARCHAR(20) NOT NULL DEFAULT 'free',
    status                    VARCHAR(16) NOT NULL DEFAULT 'free',     -- active | past_due | canceled | free
    current_period_start      DATETIME NULL DEFAULT NULL,              -- UTC
    current_period_end        DATETIME NULL DEFAULT NULL,
    next_charge_at            DATETIME NULL DEFAULT NULL,
    cancel_at_period_end      TINYINT(1) NOT NULL DEFAULT 0,
    pending_plan_key          VARCHAR(20) NULL DEFAULT NULL,           -- downgrade that applies at next_charge_at
    influencer_slots          INT NOT NULL DEFAULT 0,
    influencer_slots_next     INT NULL DEFAULT NULL,                   -- slot removal that applies at next_charge_at
    pack_dollars              INT NULL DEFAULT NULL,                   -- recurring AI credit pack (PlanTiers::AI_PACKS)
    pack_price_cents          INT NULL DEFAULT NULL,                   -- pack price when it was added
    pack_started_at           DATETIME NULL DEFAULT NULL,
    pack_dollars_next         INT NULL DEFAULT NULL,                   -- pack change at next_charge_at (0 = remove)
    stripe_payment_method_id  VARCHAR(64) NULL DEFAULT NULL,
    card_brand                VARCHAR(20) NULL DEFAULT NULL,
    card_last4                VARCHAR(4) NULL DEFAULT NULL,
    card_exp                  VARCHAR(7) NULL DEFAULT NULL,
    past_due_since            DATETIME NULL DEFAULT NULL,
    retry_count               INT NOT NULL DEFAULT 0,
    next_retry_at             DATETIME NULL DEFAULT NULL,
    last_charge_id            INT NULL DEFAULT NULL,
    last_charge_status        VARCHAR(20) NULL DEFAULT NULL,
    migrated_subscription_id  VARCHAR(64) NULL DEFAULT NULL,           -- the Stripe subscription it replaced
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_due (status, next_charge_at),
    KEY idx_retry (status, next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every charge attempt, with its line items and what it applies to the account once it succeeds.
CREATE TABLE IF NOT EXISTS billing_charges (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id                   INT NOT NULL,
    kind                      VARCHAR(20) NOT NULL,                    -- subscribe | renewal | upgrade | slots | pack
    amount_cents              INT NOT NULL,
    line_items                TEXT NOT NULL,                           -- JSON [{label, amount_cents}]
    effects                   TEXT NOT NULL,                           -- JSON applied on success
    period_start              DATETIME NULL DEFAULT NULL,
    period_end                DATETIME NULL DEFAULT NULL,
    stripe_payment_intent_id  VARCHAR(64) NULL DEFAULT NULL,
    idempotency_key           VARCHAR(120) NOT NULL,
    status                    VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending | succeeded | failed | requires_action
    failure_reason            VARCHAR(255) NULL DEFAULT NULL,
    attempt                   INT NOT NULL DEFAULT 1,
    applied                   TINYINT(1) NOT NULL DEFAULT 0,           -- effects + credits applied (exactly once)
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idem (idempotency_key),
    UNIQUE KEY uq_pi (stripe_payment_intent_id),
    KEY idx_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI credit buckets inside ai_credit_balance: included plan credits (reset each period, spent first)
-- and recurring-pack credits. The rest of the balance is bought or starter credits.
ALTER TABLE user_accounts
    ADD COLUMN ai_credits_plan INT NOT NULL DEFAULT 0 AFTER ai_credit_balance,
    ADD COLUMN ai_credits_pack INT NOT NULL DEFAULT 0 AFTER ai_credits_plan;
