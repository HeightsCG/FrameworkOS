-- Creator verification by ID check (2026-10-21). A request is now a Didit session (ID document + live selfie + face
-- match, the "Free KYC" workflow): Didit's decision sets the badge, no admin approval. full_name now holds the name read
-- from the ID. Manual requests still open (a typed name, never checked) are closed, and the creator is told to verify
-- with an ID check from Settings.

ALTER TABLE verifications
    ADD COLUMN provider     VARCHAR(20)  NULL DEFAULT NULL AFTER status,
    ADD COLUMN provider_ref VARCHAR(64)  NULL DEFAULT NULL AFTER provider,
    ADD COLUMN session_url  VARCHAR(255) NULL DEFAULT NULL AFTER provider_ref,
    ADD COLUMN decided_at   DATETIME     NULL DEFAULT NULL AFTER reviewed_at,
    ADD KEY idx_ref (provider, provider_ref);

INSERT INTO notifications (user_id, category, icon, title, body, link, is_read, created_at)
SELECT DISTINCT user_id, 'system', 'fa-id-card', 'Verify with an ID check',
       'Verification now uses a quick ID check: a photo of your ID and a selfie. Start it from Settings to get your verified badge.',
       '/account/settings?section=creator', 0, UTC_TIMESTAMP()
  FROM verifications WHERE status = 'pending' AND provider_ref IS NULL;

UPDATE verifications SET status = 'replaced', review_note = 'Replaced by the ID check', reviewed_at = UTC_TIMESTAMP()
 WHERE status = 'pending' AND provider_ref IS NULL;
