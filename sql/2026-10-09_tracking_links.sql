-- Inbound tracking links: /go/<code> (letters and digits, 6 to 32) redirects to the creator's page and sets the
-- 30-day cls_tl cookie; follows, signups, memberships and purchases made with that cookie are logged as events.
-- Codes starting with "cls" are system links (clssim<id> = clicks from the "More Creators Like This" block).
CREATE TABLE IF NOT EXISTS tracking_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    creator_id INT UNSIGNED NOT NULL,
    code VARCHAR(32) NOT NULL,
    label VARCHAR(120) NOT NULL DEFAULT '',
    target_path VARCHAR(200) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tracking_code (code),
    KEY idx_creator (creator_id, deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS tracking_link_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    link_id INT UNSIGNED NOT NULL,
    creator_id INT UNSIGNED NOT NULL,
    kind ENUM('click', 'follow', 'signup', 'subscription', 'ppv', 'purchase') NOT NULL,
    user_id INT UNSIGNED NULL,
    amount_credits INT NOT NULL DEFAULT 0,
    ref_table VARCHAR(40) NULL,
    ref_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_link_kind (link_id, kind, created_at),
    KEY idx_creator_created (creator_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
