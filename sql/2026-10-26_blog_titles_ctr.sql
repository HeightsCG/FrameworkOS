-- 2026-10-26: new titles and meta descriptions for four blog posts that rank near page one with few clicks
-- (Search Console, last 3 months). Titles are also the H1. No schema change.
-- Apply by hand on prod (already applied on dev).
UPDATE seo_articles SET
  title = 'How many images to train a LoRA character? 20 to 40',
  meta_description = 'Twenty to forty unique images is the working range for a character LoRA. The count rules, shot mix, cull, captions and retrain tests as a checklist.'
WHERE slug = 'lora-training-dataset-tips';

UPDATE seo_articles SET
  title = 'How to train a LoRA character that keeps the same face',
  meta_description = 'Train a character LoRA in four stages: the photo set, captions, a short run with saved checkpoints, and the tests that prove the face holds.'
WHERE slug = 'how-to-train-a-lora-character';

UPDATE seo_articles SET
  title = 'How to make money with an AI influencer without OnlyFans',
  meta_description = 'Seven reasons an AI influencer earns nothing outside subscription apps, from an empty price list to a drifting face, with the fix for each one.'
WHERE slug = 'how-to-make-money-with-an-ai-influencer';

UPDATE seo_articles SET
  title = 'Leaving Fanvue? 3 alternatives compared on fees and payouts',
  meta_description = 'Three ways to leave Fanvue compared: fees, what you can sell, AI character limits, storage, payouts and export, with a side-by-side table.'
WHERE slug = 'fanvue-alternative';
