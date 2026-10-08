-- signup params carried from ?plan= / ?role= / ?ref= (cls_signup cookie or the register post) onto the new account.
ALTER TABLE user_accounts
    ADD COLUMN signup_plan varchar(20) NULL DEFAULT NULL,
    ADD COLUMN signup_role varchar(10) NULL DEFAULT NULL,
    ADD COLUMN referred_by_creator_id int unsigned NULL DEFAULT NULL;
