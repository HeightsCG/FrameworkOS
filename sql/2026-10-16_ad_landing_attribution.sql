-- Paid-ad attribution on the new account (the /start TikTok landing page, 2026-10-16).
-- The ad touch that led to the signup: the UTM tags and TikTok click id of the ad landing (cls_ad cookie, set by
-- /start), kept apart from the first-touch acq_* columns. ad_content = the ad (utm_content), ad_term = the ad group.
-- signup_founding = the signup came with the founding trial offer, so the 14-day trial still applies when the
-- verification link is opened in a different browser than the ad's in-app browser (ApiAuthController::verify_email).
-- Written by UsersModel::record_ad_touch / record_signup_params.
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-16_ad_landing_attribution.sql
-- RUN BEFORE DEPLOYING (the register code writes these columns). Already applied on dev.
ALTER TABLE user_accounts
    ADD COLUMN ad_source   VARCHAR(100) NULL DEFAULT NULL AFTER acq_first_seen,
    ADD COLUMN ad_medium   VARCHAR(100) NULL DEFAULT NULL AFTER ad_source,
    ADD COLUMN ad_campaign VARCHAR(150) NULL DEFAULT NULL AFTER ad_medium,
    ADD COLUMN ad_content  VARCHAR(150) NULL DEFAULT NULL AFTER ad_campaign,
    ADD COLUMN ad_term     VARCHAR(150) NULL DEFAULT NULL AFTER ad_content,
    ADD COLUMN ad_ttclid   VARCHAR(500) NULL DEFAULT NULL AFTER ad_term,
    ADD COLUMN ad_landing  VARCHAR(255) NULL DEFAULT NULL AFTER ad_ttclid,
    ADD COLUMN ad_seen     DATETIME     NULL DEFAULT NULL AFTER ad_landing,
    ADD COLUMN signup_founding TINYINT(1) NOT NULL DEFAULT 0 AFTER signup_role;
