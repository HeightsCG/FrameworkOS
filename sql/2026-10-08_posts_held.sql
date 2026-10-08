-- Build before you pay: a due scheduled paid post of a creator without a paid plan goes back to drafts with this reason.
ALTER TABLE posts ADD COLUMN held_reason VARCHAR(64) NULL DEFAULT NULL AFTER media_missing;
