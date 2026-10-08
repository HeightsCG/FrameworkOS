-- public, cacheable webp renditions for SFW media in free published posts, and webp blog covers (PublicThumbService).
ALTER TABLE media_assets
  ADD COLUMN public_thumb_key varchar(512) DEFAULT NULL AFTER blurred_key,
  ADD COLUMN public_display_key varchar(512) DEFAULT NULL AFTER public_thumb_key,
  ADD COLUMN public_width int unsigned DEFAULT NULL AFTER public_display_key,
  ADD COLUMN public_height int unsigned DEFAULT NULL AFTER public_width;

ALTER TABLE seo_articles
  ADD COLUMN cover_webp_url varchar(500) DEFAULT NULL AFTER cover_image_url;

-- webp copies of profile photos and covers (PublicThumbService::profile_image): cover 1600 (+ 800 sibling), avatar 320.
ALTER TABLE creator_profiles
  ADD COLUMN avatar_webp_url varchar(500) DEFAULT NULL AFTER avatar_url,
  ADD COLUMN cover_webp_url varchar(500) DEFAULT NULL AFTER cover_url;

-- why an asset's public rendition could not be built; the daily catch-up skips these rows.
ALTER TABLE media_assets
  ADD COLUMN public_error varchar(255) DEFAULT NULL AFTER public_height;
