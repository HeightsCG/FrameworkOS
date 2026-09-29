-- CLS Video host controls (2026-09-29).
-- events: the call's starting settings, chosen in the event editor (the host can change them during the call).
--   call_waiting_room  1 = people wait until the host lets them in
--   call_screen_share  'host' = only the host shares a screen, 'everyone' = attendees with an account can too
--   call_attendees     'talk' = attendees can use their mic and camera, 'watch' = only people the host picks can
--   call_chat          1 = chat in the call is on
-- live_rooms: the settings of a call while it runs (one row per room: ev-<event id>, sv-<booking id>); made from the
--   event's settings when the first person joins, reset when the event is saved.
-- live_waiting: who is waiting to be let in, who was let in (they can rejoin without waiting, also when the call is
--   locked) and who was turned away.
-- Apply by hand:  mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-29_cls_video_controls.sql
-- RUN BEFORE DEPLOYING the matching code. Already applied on dev.
ALTER TABLE events
    ADD COLUMN call_waiting_room TINYINT(1)  NOT NULL DEFAULT 0,
    ADD COLUMN call_screen_share VARCHAR(10) NOT NULL DEFAULT 'host',
    ADD COLUMN call_attendees    VARCHAR(10) NOT NULL DEFAULT 'talk',
    ADD COLUMN call_chat         TINYINT(1)  NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS live_rooms (
    room        VARCHAR(40)  NOT NULL PRIMARY KEY,
    waiting     TINYINT(1)   NOT NULL DEFAULT 0,
    share       VARCHAR(10)  NOT NULL DEFAULT 'host',
    watch       TINYINT(1)   NOT NULL DEFAULT 0,
    chat        TINYINT(1)   NOT NULL DEFAULT 1,
    locked      TINYINT(1)   NOT NULL DEFAULT 0,
    spotlight   VARCHAR(64)  NULL DEFAULT NULL,
    speakers    TEXT         NULL,
    updated_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS live_waiting (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room         VARCHAR(40)  NOT NULL,
    identity     VARCHAR(64)  NOT NULL,
    name         VARCHAR(80)  NOT NULL DEFAULT '',
    status       VARCHAR(10)  NOT NULL DEFAULT 'waiting',
    requested_at DATETIME     NOT NULL,
    seen_at      DATETIME     NOT NULL,
    decided_at   DATETIME     NULL DEFAULT NULL,
    UNIQUE KEY uq_room_identity (room, identity),
    KEY ix_room_status (room, status, seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
