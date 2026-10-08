-- Affiliate program (/affiliates): approved affiliates earn Affiliates::RATE_PERCENT of every paid Creator or Studio
-- plan charge from the accounts they referred (cls_aff cookie or an approved affiliate's creator ref at signup).
-- Commissions are written by BillingService::apply() and reversed by a dispute on that billing charge.
CREATE TABLE IF NOT EXISTS affiliates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    code VARCHAR(24) NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'disabled') NOT NULL DEFAULT 'pending',
    website VARCHAR(255) NOT NULL DEFAULT '',
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    approved_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_affiliate_user (user_id),
    UNIQUE KEY uq_affiliate_code (code),
    KEY idx_affiliate_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS affiliate_clicks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    affiliate_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    viewer_hash CHAR(40) NOT NULL,
    landing VARCHAR(200) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_aff_click (affiliate_id, viewer_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- one commission per billing charge; a dispute after payout adds a negative adjustment row for the same charge
-- (invoice_cents negative), which the unique key keeps to one as well.
CREATE TABLE IF NOT EXISTS affiliate_commissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    affiliate_id INT UNSIGNED NOT NULL,
    referred_user_id INT UNSIGNED NOT NULL,
    charge_id INT UNSIGNED NOT NULL,
    invoice_cents INT NOT NULL,
    commission_cents INT NOT NULL,
    status ENUM('pending', 'earned', 'reversed', 'paid') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    earned_at DATETIME NULL,
    reversed_at DATETIME NULL,
    paid_at DATETIME NULL,
    payout_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aff_charge (charge_id, invoice_cents),
    KEY idx_aff_comm (affiliate_id, status, created_at),
    KEY idx_aff_payout (payout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS affiliate_payouts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    affiliate_id INT UNSIGNED NOT NULL,
    amount_cents INT NOT NULL,
    status ENUM('requested', 'paid', 'rejected') NOT NULL DEFAULT 'requested',
    requested_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    note VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_aff_payouts (affiliate_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE user_accounts ADD COLUMN affiliate_id INT UNSIGNED NULL, ADD KEY idx_user_affiliate (affiliate_id);
