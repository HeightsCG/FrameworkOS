-- Failed/cancelled influencer generations left their placeholder library tile spinning on "Processing..." forever.
-- Code now removes the tile when a job fails; this clears the ones already stuck. Safe to re-run.
UPDATE media_assets a
JOIN influencer_jobs j ON j.result_asset_id = a.id AND j.creator_id = a.creator_id
SET a.deleted_at = NOW(), a.updated_at = NOW()
WHERE j.status IN ('failed', 'cancelled')
  AND a.status <> 'ready'
  AND a.deleted_at IS NULL;
