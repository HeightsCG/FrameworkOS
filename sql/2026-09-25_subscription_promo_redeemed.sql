-- Once-only marker for the checkout success page: the discount code is counted (and the
-- "you're subscribed" notices sent) the first time a membership is recorded, not on every reload
-- of the ?sub=success URL.
--
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-25_subscription_promo_redeemed.sql
-- Already applied on dev (2026-09-25). On prod (confirmed 2026-09-26).

ALTER TABLE creator_subscriptions ADD COLUMN checkout_recorded TINYINT(1) NOT NULL DEFAULT 0;
