-- Free lead tools (/tools/fan-questions, /tools/ai-influencer-persona) and the in-app Ideas page (/ideas).
-- leads: one row per public tool run (consent is the required "I agree to receive emails" box).
-- tool_runs: a creator's Ideas runs, with the result the page shows and the history table lists. Times are UTC.

CREATE TABLE IF NOT EXISTS leads (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source      VARCHAR(40)  NOT NULL,
    first_name  VARCHAR(100) NOT NULL DEFAULT '',
    email       VARCHAR(190) NOT NULL,
    niche       VARCHAR(190) NOT NULL DEFAULT '',
    extra       JSON NULL,
    consent     TINYINT(1)   NOT NULL DEFAULT 0,
    ip          VARCHAR(45)  NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_leads_source (source, created_at),
    KEY idx_leads_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tool_runs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    source      VARCHAR(40)  NOT NULL,
    niche       VARCHAR(190) NOT NULL DEFAULT '',
    status      ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    result      JSON NULL,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_tool_runs_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- a lead's result is emailed once: the job sets emailed_at before sending, so a retry never sends twice
ALTER TABLE leads ADD COLUMN emailed_at DATETIME NULL AFTER consent;
