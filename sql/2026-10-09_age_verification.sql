-- 2026-10-09 Age verification, explicit content only (Didit age estimation with ID fallback behind AgeVerificationProvider).
-- One row per account. Stores status + the provider's session reference only: never images, ID documents or birth dates.
CREATE TABLE IF NOT EXISTS age_verifications (
    user_id      INT UNSIGNED NOT NULL PRIMARY KEY,
    status       ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
    provider     VARCHAR(32)  NOT NULL,
    provider_ref VARCHAR(64)  NOT NULL,
    verified_at  DATETIME     NULL,
    created_at   DATETIME     NOT NULL,
    updated_at   DATETIME     NOT NULL,
    UNIQUE KEY uq_provider_ref (provider, provider_ref)
);

-- Nobody is verified yet, and "Show adult content" must not stay on without a verification:
-- every account starts with the toggle off and re-enables it after verifying. (Daniel: check the live count first.)
UPDATE user_accounts SET adult_content_enabled = 0 WHERE adult_content_enabled = 1;
