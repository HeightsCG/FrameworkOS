-- CLS Video tips and pinned offers (2026-09-29).
-- live_tips: every tip sent in a call (fans pay in wallet credits; the creator gets their share at once, less the
--   platform fee, as credit_transactions 'live_tip' / 'tip_earning'). kind + ref_id: 'event' + event id, or
--   'booking' + service purchase id. Tips are final, like other content sales.
-- live_rooms.pinned: the offer the host pinned in the call (JSON: type, id, title, price, cta, url).
-- Apply by hand:  mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-29_cls_video_tips.sql
-- RUN BEFORE DEPLOYING the matching code (after 2026-09-29_cls_video_controls.sql). Already applied on dev.
CREATE TABLE IF NOT EXISTS live_tips (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room         VARCHAR(40)  NOT NULL,
    kind         VARCHAR(10)  NOT NULL,
    ref_id       INT UNSIGNED NOT NULL,
    creator_id   INT UNSIGNED NOT NULL,
    fan_id       INT UNSIGNED NOT NULL,
    credits      INT UNSIGNED NOT NULL,
    net_credits  INT UNSIGNED NOT NULL,
    created_at   DATETIME     NOT NULL,
    KEY ix_room (room),
    KEY ix_creator (creator_id, created_at),
    KEY ix_fan (fan_id, creator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE live_rooms ADD COLUMN pinned TEXT NULL;
