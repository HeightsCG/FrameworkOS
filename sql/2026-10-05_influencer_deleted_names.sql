-- RUN THIS SQL BEFORE DEPLOYING
-- A deleted influencer kept its name reserved (the unique key on creator + name ignored deletion), so the same
-- name could not be used again. Deleted rows give their name back; new deletes do this in InfluencersModel::soft_delete.
UPDATE influencers SET name_lc = CONCAT(LEFT(name_lc, 100), '#', id)
 WHERE deleted_at IS NOT NULL AND name_lc NOT LIKE CONCAT('%#', id);
