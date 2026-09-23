-- Article pipeline upgrades (growth): topic clusters, author, and the fields the duplicate guard needs.
--   seo_articles.cluster   topic cluster slug, e.g. 'ai-influencer-monetization' (see SeoDrafter::CLUSTERS)
--   seo_articles.author    byline shown on the article and in its Article JSON-LD
--   seo_keywords.cluster   the cluster a queued keyword belongs to; the drafted article inherits it
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-23_article_growth.sql
-- Applied on dev 2026-09-23. Prod: pending.

ALTER TABLE seo_articles
  ADD COLUMN cluster VARCHAR(40) NOT NULL DEFAULT '' AFTER target_keyword,
  ADD COLUMN author  VARCHAR(80) NOT NULL DEFAULT '' AFTER cluster,
  ADD KEY idx_articles_cluster (cluster, status);

ALTER TABLE seo_keywords
  ADD COLUMN cluster VARCHAR(40) NOT NULL DEFAULT '' AFTER keyword;

-- Backfill: give the keywords that already exist their cluster, then copy each article's cluster from its keyword.
UPDATE seo_keywords SET cluster = 'platform-comparisons' WHERE cluster = '' AND keyword IN
  ('creator monetization platform','onlyfans alternative','fanvue alternative','link in bio for creators',
   'best link in bio for creators','cross-post to social media from one place','online creator platform');
UPDATE seo_keywords SET cluster = 'creator-payouts' WHERE cluster = '' AND keyword IN ('creator payouts stripe');
UPDATE seo_keywords SET cluster = 'ai-influencer-monetization' WHERE cluster = '';

UPDATE seo_articles a JOIN seo_keywords k ON k.article_id = a.id
   SET a.cluster = k.cluster WHERE a.cluster = '' AND k.cluster <> '';
UPDATE seo_articles SET cluster = 'ai-influencer-monetization' WHERE cluster = '';
UPDATE seo_articles SET author = 'Creator Link Studio' WHERE author = '';
