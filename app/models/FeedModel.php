<?php
/**
 * Cross-creator discovery feed. Read-only: pulls recently published posts from
 * every creator, joined with the author's public identity and the cover asset's
 * renditions, so the Home feed can render a card without a per-post query.
 */
class FeedModel extends Model {

    /**
     * Recent published posts across all creators, newest first.
     * Cover renditions are selected inline (cover = is_cover, else first by sort).
     * The clear renditions are only ever *signed* for entitled viewers upstream.
     */
    public function recent($limit = 30, $offset = 0){
        $limit  = max(1, min(60, (int) $limit));
        $offset = max(0, (int) $offset);
        // Cover = the post's is_cover asset, else its first ready asset by sort order.
        // Pulled as correlated scalar subqueries (the ON-clause form trips a MySQL
        // "unknown column" error on the joined alias).
        $cover = "(SELECT ma.%s FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
                     WHERE pa.post_id = p.id AND ma.deleted_at IS NULL AND ma.status = 'ready'
                     ORDER BY pa.is_cover DESC, pa.sort_order ASC LIMIT 1)";
        $sql = "SELECT p.id, p.creator_id, p.caption, p.audience, p.ppv_price_credits, p.tier_id,
                       p.published_at, p.views, p.likes, p.comments, p.comments_enabled,
                       ua.u_name,
                       cp.display_name, cp.avatar_url,
                       (SELECT COUNT(*) FROM post_assets pa WHERE pa.post_id = p.id) AS asset_count,
                       " . sprintf($cover, 'type')        . " AS cover_type,
                       " . sprintf($cover, 'thumb_key')   . " AS cover_thumb_key,
                       " . sprintf($cover, 'poster_key')  . " AS cover_poster_key,
                       " . sprintf($cover, 'blurred_key') . " AS cover_blurred_key
                FROM posts p
                JOIN user_accounts ua ON ua.user_id = p.creator_id
                LEFT JOIN creator_profiles cp ON cp.user_id = p.creator_id
                WHERE p.state = 'published'
                ORDER BY p.published_at DESC, p.id DESC
                LIMIT $offset, $limit";
        return parent::select($sql, array());
    }
}
