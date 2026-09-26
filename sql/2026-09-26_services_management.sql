-- Services management (2026-09-26): what the creator earned per booking (for the Earned figure and refund clawbacks).
ALTER TABLE service_purchases ADD COLUMN net_credits INT NOT NULL DEFAULT 0 AFTER price_credits;
