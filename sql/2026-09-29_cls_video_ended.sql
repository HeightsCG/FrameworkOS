-- CLS Video: a call the host ended stays closed (2026-09-29). live_rooms.ended_at is set by End Call for Everyone;
-- attendees can't join again until the host rejoins, which clears it (like starting a Zoom meeting again).
-- Apply by hand:  mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-29_cls_video_ended.sql
-- RUN BEFORE DEPLOYING the matching code. Already applied on dev.
ALTER TABLE live_rooms ADD COLUMN ended_at DATETIME NULL DEFAULT NULL;
