-- CLS Video: an optional password the creator sets for an event's call. When set, everyone but the host types it to
-- join. Stored as the creator typed it so they can see and share it. NULL = no password.
ALTER TABLE events ADD COLUMN call_password VARCHAR(64) NULL DEFAULT NULL;
