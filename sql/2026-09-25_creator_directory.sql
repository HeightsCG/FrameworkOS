-- Creator directory (/creators, /creators/<category>): opt-in, safe-for-work only.
-- directory_listed: the creator's switch in Settings -> Creator Profile. directory_category: the one category they pick.
-- directory_media_ok: SHA1 of "avatar_url|cover_url" that last passed the adult-image check. The directory only shows a
-- creator while this matches their current images, so a new photo drops them out until it passes (re-checked by the
-- directory_recheck job queued on every photo change). Eligibility also needs >=1 published, non-adult post.
--
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-25_creator_directory.sql
-- Already applied on dev (2026-09-25). On prod (confirmed 2026-09-26).

ALTER TABLE creator_profiles
    ADD COLUMN directory_listed     TINYINT(1)  NOT NULL DEFAULT 0,
    ADD COLUMN directory_category   VARCHAR(30) NULL,
    ADD COLUMN directory_media_ok   CHAR(40)    NULL,
    ADD COLUMN directory_checked_at DATETIME    NULL,
    ADD KEY idx_directory (directory_listed, directory_category);
