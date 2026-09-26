-- Low-severity security fixes (2026-09-26): an authenticator code works once (remember the last accepted 30-second step).
ALTER TABLE user_accounts ADD COLUMN mfa_totp_last_step BIGINT NULL DEFAULT NULL AFTER mfa_totp_secret;

-- New-post notices: one per follower per post, even if the notify job retries.
CREATE TABLE post_notify_sent (
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  sent_at DATETIME NOT NULL,
  PRIMARY KEY (post_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
