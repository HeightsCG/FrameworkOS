-- Signup attribution: where each account came from (first touch).
-- libs/Layout/google_analytics.php stores the visitor's first UTM tags, gclid, referrer and landing page in a
-- 90-day first-party cookie (cls_ft); ApiAuthController::registerAction copies it onto the new account.
--
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-25_signup_attribution.sql
-- Already applied on dev (2026-09-25). On prod (confirmed 2026-09-26).

ALTER TABLE user_accounts
    ADD COLUMN acq_source   VARCHAR(100) NULL,
    ADD COLUMN acq_medium   VARCHAR(100) NULL,
    ADD COLUMN acq_campaign VARCHAR(150) NULL,
    ADD COLUMN acq_term     VARCHAR(150) NULL,
    ADD COLUMN acq_content  VARCHAR(150) NULL,
    ADD COLUMN acq_gclid    VARCHAR(255) NULL,
    ADD COLUMN acq_referrer VARCHAR(255) NULL,
    ADD COLUMN acq_landing  VARCHAR(255) NULL,
    ADD COLUMN acq_first_seen DATETIME NULL;
