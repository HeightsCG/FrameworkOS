-- "Why are you leaving?" (a required reason + an optional note) in the Delete Account and Stop Being a Creator dialogs: the answer stays on the
-- account row (deletion is soft) and shows on Admin > Users > the user. left_kind: 'account' (deleted) or 'creator'.
ALTER TABLE user_accounts
  ADD COLUMN left_reason VARCHAR(32) NULL DEFAULT NULL,
  ADD COLUMN left_note VARCHAR(500) NULL DEFAULT NULL,
  ADD COLUMN left_kind VARCHAR(16) NULL DEFAULT NULL,
  ADD COLUMN left_at DATETIME NULL DEFAULT NULL;
