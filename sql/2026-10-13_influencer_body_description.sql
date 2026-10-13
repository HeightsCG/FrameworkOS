-- The body description was capped at 600 characters, which cut a careful description mid-word. It now holds 2,000
-- (InfluencerService::BODY_MAX), so the column becomes TEXT like the face description.
ALTER TABLE influencers MODIFY body_description TEXT NULL;
