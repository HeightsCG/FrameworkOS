-- Founding creator offer (/founding): the first Founding::SPOTS creators on the Creator plan get the first month
-- free and the platform fee locked at the Studio rate while their Creator plan stays active or past due.
-- A claim is held at checkout (claimed), becomes active when the plan starts, lapses when the plan ends or changes.
ALTER TABLE user_accounts ADD COLUMN is_founding TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE billing_accounts ADD COLUMN fee_percent_override DECIMAL(5,2) NULL;

CREATE TABLE IF NOT EXISTS founding_claims (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    claimed_at DATETIME NOT NULL,
    activated_at DATETIME NULL,
    status ENUM('claimed', 'active', 'lapsed', 'refused') NOT NULL DEFAULT 'claimed',
    testimonial_requested_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_founding_user (user_id),
    KEY idx_founding_status (status, activated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The testimonial founding creators send from /founding/testimonial (asked by email 14 days after activation).
ALTER TABLE founding_claims
    ADD COLUMN testimonial_text VARCHAR(300) NULL,
    ADD COLUMN testimonial_consent TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN testimonial_submitted_at DATETIME NULL;
