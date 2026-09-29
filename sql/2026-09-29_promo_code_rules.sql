-- Creator promo codes get Stripe's other rules: an amount off (instead of a percent), a minimum order, and
-- first-purchase-only (the fan has never bought anything from this creator). Amounts are wallet credits ($1 = 10).
-- percent_off stays for percent codes; an amount-off code has percent_off = 0.
ALTER TABLE creator_promo_codes
    ADD COLUMN amount_off_credits  INT NULL DEFAULT NULL AFTER percent_off,
    ADD COLUMN min_order_credits   INT NULL DEFAULT NULL AFTER amount_off_credits,
    ADD COLUMN first_purchase_only TINYINT(1) NOT NULL DEFAULT 0 AFTER min_order_credits;
