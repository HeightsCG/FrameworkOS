-- One open membership checkout per fan per creator: starting a new one expires the previous Stripe session,
-- so two tabs can't both pay for a membership with the same creator.
CREATE TABLE IF NOT EXISTS membership_checkouts (
  subscriber_id     INT UNSIGNED NOT NULL,
  creator_id        INT UNSIGNED NOT NULL,
  stripe_session_id VARCHAR(255) NOT NULL,
  created_at        DATETIME NOT NULL,
  PRIMARY KEY (subscriber_id, creator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
