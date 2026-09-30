-- CLS Video call recording (2026-09-29). The host records an event's call; LiveKit's recorder (Egress, on the video
-- server) uploads an MP4 to s3://content-os-bucket/recordings/, and the queue job recording_watch turns it into a
-- Library video for the creator (LiveRecording).
-- status: recording (running) -> stopping (host pressed Stop, or the plan's time limit) -> processing (making the
--   Library video) -> ready (asset_id set) | failed (error says why; the file stays in recordings/).
-- Apply by hand:  mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-29_cls_video_recording.sql
-- RUN BEFORE DEPLOYING the matching code. Already applied on dev.
CREATE TABLE IF NOT EXISTS live_recordings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room          VARCHAR(40)  NOT NULL,
    event_id      INT UNSIGNED NOT NULL,
    creator_id    INT UNSIGNED NOT NULL,
    started_by    INT UNSIGNED NOT NULL,
    egress_id     VARCHAR(64)  NOT NULL,
    s3_key        VARCHAR(255) NOT NULL,
    status        VARCHAR(12)  NOT NULL DEFAULT 'recording',
    limit_minutes INT UNSIGNED NOT NULL,
    asset_id      INT UNSIGNED NULL DEFAULT NULL,
    duration_sec  INT UNSIGNED NOT NULL DEFAULT 0,
    bytes         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    error         VARCHAR(255) NULL DEFAULT NULL,
    started_at    DATETIME     NOT NULL,
    ended_at      DATETIME     NULL DEFAULT NULL,
    KEY ix_room_status (room, status),
    KEY ix_event (event_id),
    KEY ix_creator (creator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
