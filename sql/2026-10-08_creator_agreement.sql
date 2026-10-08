-- creator agreement: accepted at the Creator/Studio checkout, recorded with the agreement version and the request ip.
ALTER TABLE user_accounts ADD COLUMN creator_agreement_version VARCHAR(20) NULL DEFAULT NULL, ADD COLUMN creator_agreement_ip VARCHAR(45) NULL DEFAULT NULL;
