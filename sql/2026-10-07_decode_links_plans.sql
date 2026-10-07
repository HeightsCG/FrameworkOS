-- 2026-10-07: creator links and membership plans were stored HTML-encoded (clean_post_data),
-- so /go/<id> redirected to "...&amp;..." and names showed &#039;. The API now stores them plain;
-- this decodes the rows saved before the fix. Safe to re-run.
UPDATE creator_links SET
  url   = REPLACE(REPLACE(REPLACE(url,   '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
  title = REPLACE(REPLACE(REPLACE(title, '&quot;', '"'), '&#039;', ''''), '&amp;', '&')
WHERE url LIKE '%&amp;%' OR url LIKE '%&#039;%' OR url LIKE '%&quot;%'
   OR title LIKE '%&amp;%' OR title LIKE '%&#039;%' OR title LIKE '%&quot;%';

UPDATE creator_plans SET
  name        = REPLACE(REPLACE(REPLACE(name,        '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
  description = REPLACE(REPLACE(REPLACE(description, '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
  perks       = REPLACE(REPLACE(REPLACE(perks,       '&quot;', '"'), '&#039;', ''''), '&amp;', '&')
WHERE name LIKE '%&amp;%' OR name LIKE '%&#039;%' OR name LIKE '%&quot;%'
   OR description LIKE '%&amp;%' OR description LIKE '%&#039;%' OR description LIKE '%&quot;%'
   OR perks LIKE '%&amp;%' OR perks LIKE '%&#039;%' OR perks LIKE '%&quot;%';
