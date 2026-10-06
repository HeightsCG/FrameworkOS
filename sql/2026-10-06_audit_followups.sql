-- Follow-ups from the 2026-10-06 audit of the AI influencer pipeline build. The 2026-10-05 files were
-- already applied (dev and prod on 2026-10-05); these two statements were NOT part of that run.
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-06_audit_followups.sql
--
-- Applied on dev: 2026-10-06. Both statements are safe on live rows.

-- 1. The posts of one launch campaign are read and deleted by campaign_id (was a full scan of posts).
ALTER TABLE posts ADD KEY idx_posts_campaign (campaign_id);

-- 2. The 2026-10-05 face-model move only covered status = 'draft'; influencers parked in the other
--    pre-training statuses were still on the airbrushed model. Finished influencers (trained model) are
--    left alone. Idempotent.
UPDATE influencers SET reference_model_key = 'flux_ultra_raw'
 WHERE reference_model_key = 'flux_pro_11'
   AND status IN ('draft', 'awaiting_reference', 'failed')
   AND active_model_id IS NULL;
