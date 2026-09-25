-- Events management (/events/<id>): virtual vs in-person, and the creator's messages to attendees.
-- Registration statuses used from now on: registered (shown "Going"), canceled, refunded, removed.
--
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-25_events_management.sql
-- Already applied on dev (2026-09-25). Not yet on prod.

ALTER TABLE events ADD COLUMN format VARCHAR(16) NOT NULL DEFAULT 'virtual';

CREATE TABLE IF NOT EXISTS event_messages (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id    INT UNSIGNED NOT NULL,
    creator_id  INT UNSIGNED NOT NULL,
    body        TEXT NOT NULL,
    recipients  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    KEY idx_event (event_id)
);
