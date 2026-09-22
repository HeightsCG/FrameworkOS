-- Inbox: delete a conversation (for yourself) and delete a message you sent (for both people).
--   conversations.creator_cleared_id / user_cleared_id  highest message id that side deleted; that side's thread shows only newer messages
--   messages.deleted_at                                  set when the sender deletes it; hidden from both sides and from AI reply context
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-22_inbox_delete.sql
-- Applied on dev 2026-09-22. Prod: pending.

ALTER TABLE conversations
  ADD COLUMN creator_cleared_id INT NOT NULL DEFAULT 0 AFTER user_deleted,
  ADD COLUMN user_cleared_id    INT NOT NULL DEFAULT 0 AFTER creator_cleared_id;

ALTER TABLE messages
  ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER created_at;
