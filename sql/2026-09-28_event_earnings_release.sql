-- Event ticket earnings go to the creator after the event, not at purchase (a refund before the event then takes
-- nothing back from the creator). earning_released_at = when the creator's share was added to their wallet.
-- Every paid registration that exists already was credited at purchase, so it is marked released now.
ALTER TABLE event_registrations ADD COLUMN earning_released_at DATETIME NULL DEFAULT NULL;
UPDATE event_registrations SET earning_released_at = created_at WHERE price_credits > 0 AND earning_released_at IS NULL;
