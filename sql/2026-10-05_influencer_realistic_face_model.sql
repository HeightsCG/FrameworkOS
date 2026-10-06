-- RUN THIS SQL BEFORE DEPLOYING
-- Influencers still being set up were saved with the old default face model (flux_pro_11), which renders
-- airbrushed skin. Move them to the photo-real one so their next face reference is made with it.
-- Finished influencers are left alone.
UPDATE influencers SET reference_model_key = 'flux_ultra_raw'
 WHERE reference_model_key = 'flux_pro_11' AND status = 'draft';
