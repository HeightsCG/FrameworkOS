-- RUN THIS SQL BEFORE DEPLOYING
-- Influencers still being set up were saved with the old default face model (flux_pro_11), which renders
-- airbrushed skin. Move them to the photo-real one so their next face reference is made with it.
-- Finished influencers (those with a trained model) are left alone; every pre-training status is covered
-- (draft, awaiting_reference, and a failed first training that still has no model). Idempotent.
UPDATE influencers SET reference_model_key = 'flux_ultra_raw'
 WHERE reference_model_key = 'flux_pro_11'
   AND status IN ('draft', 'awaiting_reference', 'failed')
   AND active_model_id IS NULL;
