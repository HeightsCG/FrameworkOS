-- Sign in / sign up with Google (AccountController::google_start / google_callback, libs/Classes/GoogleAuth.php).
-- google_sub is Google's stable account id: the account a Google login belongs to, even if the Gmail address changes.
--
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-26_google_signin.sql
-- Also needs google_client_id + google_client_secret in app/config/app.ini; without them the Google button stays hidden.
-- Applied on dev and prod (2026-09-26); Google sign-in confirmed working on prod.

ALTER TABLE user_accounts
    ADD COLUMN google_sub VARCHAR(64) NULL DEFAULT NULL AFTER user_email,
    ADD UNIQUE KEY uq_user_google_sub (google_sub);
