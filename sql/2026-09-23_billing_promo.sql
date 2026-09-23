-- Promo codes on app-managed plan billing: the discount the account's plan still gets.
--   promo_percent / promo_amount_cents  one of the two is set (Stripe coupon percent_off / amount_off)
--   promo_periods_left                  plan charges left with the discount; NULL = forever
ALTER TABLE billing_accounts
    ADD COLUMN promo_code         VARCHAR(64)  NULL DEFAULT NULL AFTER pack_dollars_next,
    ADD COLUMN promo_percent      DECIMAL(5,2) NULL DEFAULT NULL AFTER promo_code,
    ADD COLUMN promo_amount_cents INT          NULL DEFAULT NULL AFTER promo_percent,
    ADD COLUMN promo_periods_left INT          NULL DEFAULT NULL AFTER promo_amount_cents;
