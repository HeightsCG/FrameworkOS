-- A file removed from the library while a post still uses it is hidden from the library, not deleted:
-- the post keeps its image. Set by MediaAssetsModel::remove_from_library(); cleared to a real delete
-- (deleted_at) when the last post using it is deleted or drops it.
ALTER TABLE media_assets ADD COLUMN library_hidden_at DATETIME NULL DEFAULT NULL AFTER deleted_at;
