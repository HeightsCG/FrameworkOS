-- Paid event replays (2026-09-29). A creator sells one of an event's CLS Video recordings as its replay, watched on
-- the public event page. Paid in wallet credits; the creator gets their share at once, less the platform fee
-- (credit_transactions 'replay_unlock' / 'replay_earning'). Sales are final, like other content.
-- events.replay_recording_id   the live_recordings row sold as the replay (NULL = no replay for sale)
-- events.replay_price_credits  10 to 5,000 credits
-- events.replay_free_attendees 1 = people who registered for the event watch it free
-- replay_unlocks               who bought it (UNIQUE event+fan also stops a double charge)
-- Apply by hand:  mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-29_event_replays.sql
-- RUN BEFORE DEPLOYING the matching code. Already applied on dev.
ALTER TABLE events
    ADD COLUMN replay_recording_id   INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN replay_price_credits  INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN replay_free_attendees TINYINT(1)   NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS replay_unlocks (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id      INT UNSIGNED NOT NULL,
    fan_id        INT UNSIGNED NOT NULL,
    creator_id    INT UNSIGNED NOT NULL,
    price_credits INT UNSIGNED NOT NULL,
    net_credits   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL,
    UNIQUE KEY uq_event_fan (event_id, fan_id),
    KEY ix_creator (creator_id, created_at),
    KEY ix_fan (fan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
