-- 2026-10-11: each article records the format it was written in (SeoDrafter::FORMATS), so the pipeline never publishes two in a row in the same format.
ALTER TABLE seo_articles ADD COLUMN format VARCHAR(20) NOT NULL DEFAULT '' AFTER cluster;
