-- Influencers: gender (woman | man), chosen when the influencer is created and editable in its settings.
-- Every image/video prompt names the matching subject ("photo of a man ...", "The woman turns ..."), instead of assuming every influencer is a woman.
-- Existing rows: 'man' when their face description / prompt defaults describe a man, otherwise 'woman' (they were all created under the old woman-only prompts).
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-22_influencer_gender.sql
-- Applied on dev 2026-09-22. Prod: pending.

ALTER TABLE influencers
  ADD COLUMN gender ENUM('woman','man') NOT NULL DEFAULT 'woman' AFTER name_lc;

UPDATE influencers SET gender = 'man'
 WHERE CONCAT_WS(' ', source_description, prompt_defaults, steer_text) REGEXP '\\b(man|men|guy|male|he|his)\\b'
   AND CONCAT_WS(' ', source_description, prompt_defaults, steer_text) NOT REGEXP '\\b(woman|women|girl|female|she|her)\\b';
