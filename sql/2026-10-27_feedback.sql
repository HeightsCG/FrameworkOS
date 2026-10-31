-- Feedback (2026-10-27): the "Feedback" link in the app top bar opens a modal on every signed-in page.
-- Each note is stored here with the page it was sent from; staff read them on /admin/feedback (New / Reviewed).
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-27_feedback.sql
-- RUN THIS BEFORE DEPLOYING the feedback code. Already applied on dev.

CREATE TABLE IF NOT EXISTS feedback (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    kind         VARCHAR(20)  NOT NULL DEFAULT 'idea',
    message      TEXT         NOT NULL,
    page_url     VARCHAR(500) NOT NULL DEFAULT '',
    page_title   VARCHAR(190) NOT NULL DEFAULT '',
    user_agent   VARCHAR(255) NOT NULL DEFAULT '',
    viewport     VARCHAR(20)  NOT NULL DEFAULT '',
    status       VARCHAR(20)  NOT NULL DEFAULT 'new',
    reviewed_by  INT UNSIGNED NULL,
    reviewed_at  DATETIME     NULL,
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_feedback_status (status, created_at),
    KEY idx_feedback_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
