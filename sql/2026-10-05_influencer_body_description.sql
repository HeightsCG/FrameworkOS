-- Influencer body: free text instead of the three fixed choices (height / build / bust), so a creator can
-- describe her exactly ("tall, long legs, narrow waist, toned arms"). The text is added to every image prompt,
-- to her full-body reference and to the full-body shots of her training set.
-- Run sql/2026-10-05_influencer_body.sql first (this copies any choices made there into the new text).
--
-- Apply by hand:
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-10-05_influencer_body_description.sql
--
-- RUN BEFORE DEPLOYING the code that reads body_description (influencer create, settings, image generation).
-- Safe while the old code is live (the new column defaults to ''; the old columns are left in place).
-- Applied on dev: 2026-10-05. NOT yet on prod.

ALTER TABLE influencers
    ADD COLUMN body_description VARCHAR(600) NOT NULL DEFAULT '' AFTER gender;

-- Carry over anything chosen in the three earlier columns.
UPDATE influencers
   SET body_description = TRIM(BOTH ', ' FROM CONCAT_WS(', ',
           NULLIF(CASE body_height WHEN 'short' THEN 'short' WHEN 'average' THEN 'average height' WHEN 'tall' THEN 'tall' ELSE '' END, ''),
           NULLIF(CASE body_build WHEN 'thin' THEN 'thin, slender build' WHEN 'average' THEN 'average build' WHEN 'athletic' THEN 'athletic, toned build' WHEN 'muscular' THEN 'muscular build' WHEN 'curvy' THEN 'curvy build' ELSE '' END, ''),
           NULLIF(CASE WHEN gender = 'woman' THEN CASE body_bust WHEN 'small' THEN 'small bust' WHEN 'medium' THEN 'medium bust' WHEN 'large' THEN 'large bust' ELSE '' END ELSE '' END, '')))
 WHERE body_description = '' AND (body_height <> '' OR body_build <> '' OR body_bust <> '');

-- body_height, body_build and body_bust are no longer read. Drop them once this code is live everywhere:
--   ALTER TABLE influencers DROP COLUMN body_height, DROP COLUMN body_build, DROP COLUMN body_bust;
