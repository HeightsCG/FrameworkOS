-- 2026-10-11: a merged article is archived and points at its survivor; /blog/<old> answers 301 to /blog/<survivor> (BlogController).
ALTER TABLE seo_articles ADD COLUMN redirect_to VARCHAR(160) NULL DEFAULT NULL AFTER status;
