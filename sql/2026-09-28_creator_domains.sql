-- Creator custom domains (PRD §39, 2026-09-28): a Studio creator points their own domain (lexivaughn.com)
-- at the Caddy gateway and it serves their profile. Pattern ported from VIP's location_domains.
--   creator_domains: one row per hostname (apex + www are two rows; one is primary, the other 301s to it).
--   domain_handoffs: one-time tokens that carry a signed-in session from the platform to a custom domain
--                    (the session cookie can't cross domains). Only the token's hash is stored.
--
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-28_creator_domains.sql
-- Also needs in app/config/app.ini [global]:
--   domain_gateway        = "gateway.creatorlinkstudio.com"  ; what creators CNAME/ALIAS to (CLS's own Caddy gateway)
--   domain_gateway_secret = "<openssl rand -hex 32>"          ; optional: then only requests carrying it as X-CLS-Gateway count
-- Gateway setup: docs/custom-domains.md
-- Daily DNS re-check (add to crontab / launchd):
--   17 4 * * * APPLICATION_ENV=production php cron/domains.php
-- Applied on dev (2026-09-28). NOT yet on prod.

CREATE TABLE IF NOT EXISTS creator_domains (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL,
    hostname           VARCHAR(253) NOT NULL,            -- lowercased, no port/scheme/trailing dot
    -- Unique only among live rows: NULL once soft-deleted (NULLs don't collide), so a removed domain can be re-added.
    hostname_active    VARCHAR(253) GENERATED ALWAYS AS (IF(deleted = 0, hostname, NULL)) STORED,
    host_type          ENUM('subdomain','apex') NOT NULL DEFAULT 'subdomain',
    verification_token VARCHAR(64)  NOT NULL,
    status             ENUM('pending','verified','active','failed','disabled') NOT NULL DEFAULT 'pending',
    is_primary         TINYINT(1)   NOT NULL DEFAULT 0,
    verified_at        DATETIME NULL,
    last_checked_at    DATETIME NULL,
    last_error         VARCHAR(255) NULL,
    date_created       DATETIME NOT NULL,
    date_updated       DATETIME NOT NULL,
    deleted            TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hostname_active (hostname_active),
    KEY idx_host_lookup (hostname, status, deleted),
    KEY idx_user (user_id, deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS domain_handoffs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash  CHAR(64)     NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    hostname    VARCHAR(253) NOT NULL,                   -- the only host this token may be redeemed on
    path        VARCHAR(512) NOT NULL DEFAULT '/',       -- where to land after sign-in
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     NULL,
    date_created DATETIME    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
