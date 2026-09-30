-- CLS Video recordings belong to their event, not the Content Studio Library (2026-09-29, Daniel).
-- media_assets.source = 'recording' marks a call recording: it stays out of the Library, the post editor's media
-- picker, message/broadcast attachments and bundles, and is played/downloaded/deleted from the event's page.
-- Recordings still count toward the plan's storage. The UPDATE marks recordings made before this change.
-- Apply by hand:  mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-29_recordings_in_events.sql
-- RUN BEFORE DEPLOYING the matching code. Already applied on dev.
ALTER TABLE media_assets ADD COLUMN source VARCHAR(20) NULL DEFAULT NULL;
UPDATE media_assets ma JOIN live_recordings lr ON lr.asset_id = ma.id SET ma.source = 'recording';
