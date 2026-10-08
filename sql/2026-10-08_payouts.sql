-- creator bank payouts from the connected account, kept in sync by the Connect webhook (/webhook/stripe_connect).
CREATE TABLE IF NOT EXISTS payouts (
    id int unsigned NOT NULL AUTO_INCREMENT,
    creator_id int unsigned NOT NULL,
    stripe_payout_id varchar(64) NOT NULL,
    amount_cents int NOT NULL DEFAULT 0,
    currency varchar(3) NOT NULL DEFAULT 'usd',
    status varchar(20) NOT NULL DEFAULT 'pending',
    arrival_date datetime NULL DEFAULT NULL,
    failure_code varchar(64) NULL DEFAULT NULL,
    failure_message varchar(255) NULL DEFAULT NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_stripe_payout (stripe_payout_id),
    KEY idx_creator (creator_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- set once when the "Bank payout failed" notice is sent, so it goes out exactly once per payout.
ALTER TABLE payouts ADD COLUMN failure_notified_at datetime NULL DEFAULT NULL AFTER failure_message;
