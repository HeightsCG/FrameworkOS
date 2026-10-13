-- "Add To Every Photo" on the training set step is a multi-line box that holds 4,000 characters (InfluencerService::CHANGE_MAX);
-- the column was VARCHAR(1000).
ALTER TABLE influencers MODIFY steer_text TEXT NULL;
