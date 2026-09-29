-- Service bookings pay the creator when they mark the booking delivered, not at purchase (a refund before that
-- takes nothing back from them). delivered_at = when they marked it; earning_released_at = when their share was
-- added to their wallet. Every paid booking that exists already was paid at purchase, so it is marked released now.
ALTER TABLE service_purchases
    ADD COLUMN delivered_at        DATETIME NULL DEFAULT NULL,
    ADD COLUMN earning_released_at DATETIME NULL DEFAULT NULL;
UPDATE service_purchases SET earning_released_at = created_at WHERE price_credits > 0 AND earning_released_at IS NULL;
