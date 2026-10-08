-- cross-promotion swaps (/promote): creators opt in from Settings, request a swap with a creator in their niche,
-- and while it is active both profiles show the other under "Featured Creators". clicks go through /promote/click.

-- the creator's opt-in (Settings > Profile, next to the Creator Directory switch).
ALTER TABLE creator_profiles ADD COLUMN cross_promo_in tinyint(1) NOT NULL DEFAULT 0;

-- small key/value store for settings an admin changes on /admin (AdminSettingsModel).
CREATE TABLE IF NOT EXISTS app_settings (
    k varchar(64) NOT NULL,
    v text NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- one swap between two creators. active = status 'active' AND ends_at > NOW() (no cron ends it).
CREATE TABLE IF NOT EXISTS promo_swaps (
    id int unsigned NOT NULL AUTO_INCREMENT,
    requester_id int unsigned NOT NULL,
    partner_id int unsigned NOT NULL,
    days smallint unsigned NOT NULL DEFAULT 7,
    note varchar(500) NOT NULL DEFAULT '',
    status enum('pending','active','declined','ended') NOT NULL DEFAULT 'pending',
    accepted_at datetime NULL DEFAULT NULL,
    ends_at datetime NULL DEFAULT NULL,
    ended_by int unsigned NULL DEFAULT NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY (id),
    KEY idx_requester (requester_id, status),
    KEY idx_partner (partner_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- one row per click on a Featured Creators card (from the profile it was on, to the partner).
CREATE TABLE IF NOT EXISTS promo_swap_clicks (
    id int unsigned NOT NULL AUTO_INCREMENT,
    swap_id int unsigned NOT NULL,
    from_creator_id int unsigned NOT NULL,
    to_creator_id int unsigned NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id),
    KEY idx_swap (swap_id, to_creator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- one open (pending or active) swap per pair of creators, enforced by the database so two requests sent at the
-- same moment can't both land. NULL once a swap is declined or ended (NULLs don't collide).
ALTER TABLE promo_swaps
    ADD COLUMN open_pair varchar(24) GENERATED ALWAYS AS (IF(status IN ('pending', 'active'), CONCAT(LEAST(requester_id, partner_id), '-', GREATEST(requester_id, partner_id)), NULL)) STORED,
    ADD UNIQUE KEY uniq_open_pair (open_pair);

-- click dedupe: one click per swap, destination and viewer an hour (sha1 of the user id, or of ip + user agent when signed out).
ALTER TABLE promo_swap_clicks ADD COLUMN viewer_hash char(40) NULL DEFAULT NULL,
    ADD KEY idx_dedupe (swap_id, to_creator_id, viewer_hash, created_at);
