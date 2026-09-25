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
    public function recent($limit = 30, $offset = 0, $viewer_id = 0){
        $limit  = max(1, min(60, (int) $limit));
        $offset = max(0, (int) $offset);
        $viewer_id = (int) $viewer_id;
        // Blocks hide a creator's posts from the blocked viewer, and the viewer's own blocks hide creators they blocked.
        $block_sql = $viewer_id > 0 ? ' AND ' . BlocksModel::exclude_sql('p.creator_id', 'bv1', 'bv2') : '';
        $params    = $viewer_id > 0 ? array('bv1' => $viewer_id, 'bv2' => $viewer_id) : array();
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
                WHERE p.state = 'published' AND p.on_cls = 1
                  AND ua.deleted = 0 AND (ua.user_status IS NULL OR ua.user_status <> 'Disabled')$block_sql
                ORDER BY p.published_at DESC, p.id DESC
                LIMIT $offset, $limit";
        return parent::select($sql, $params);
    }

    /**
     * How many published posts exist newer than a watermark id — powers the Home
     * feed's "N new posts" alert. Ids are auto-increment so id > watermark is a
     * cheap monotonic proxy for "published since you last loaded the top". Capped
     * so the count query stays bounded (the UI only needs "9+").
     */
    public function count_since($since_id, $cap = 50, $viewer_id = 0){
        $since_id = (int) $since_id;
        if ($since_id <= 0) { return 0; }
        $cap = max(1, min(200, (int) $cap));
        $viewer_id = (int) $viewer_id;
        $block_sql = $viewer_id > 0 ? ' AND ' . BlocksModel::exclude_sql('p.creator_id', 'bv1', 'bv2') : '';
        $params    = array('since' => $since_id);
        if ($viewer_id > 0) { $params['bv1'] = $viewer_id; $params['bv2'] = $viewer_id; }
        $sql = "SELECT COUNT(*) AS c FROM (
                    SELECT p.id FROM posts p
                    WHERE p.state = 'published' AND p.on_cls = 1 AND p.id > :since$block_sql
                    LIMIT $cap
                ) t";
        $rows = parent::select($sql, $params);
        return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
    }

    /**
     * Latest posts for the public marketing pages (logged-out visitors). Strictly safe:
     * free audience, published to the site, creator active, and EVERY asset is an image
     * the moderator approved as not adult (videos are not scanned, so posts with video are
     * left out). Returns the cover thumbnail key; the caller signs it.
     */
    public function public_latest($limit = 6){
        $limit = max(1, min(12, (int) $limit));
        $sql = "SELECT p.id, p.caption, p.published_at, ua.u_name, cp.display_name,
                       (SELECT ma.thumb_key FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
                         WHERE pa.post_id = p.id AND ma.deleted_at IS NULL AND ma.status = 'ready'
                         ORDER BY pa.is_cover DESC, pa.sort_order ASC LIMIT 1) AS cover_thumb_key
                FROM posts p
                JOIN user_accounts ua ON ua.user_id = p.creator_id
                LEFT JOIN creator_profiles cp ON cp.user_id = p.creator_id
                WHERE p.state = 'published' AND p.on_cls = 1 AND p.audience = 'free'
                  AND ua.deleted = 0 AND (ua.user_status IS NULL OR ua.user_status <> 'Disabled')
                  AND EXISTS (SELECT 1 FROM post_assets pa WHERE pa.post_id = p.id)
                  AND NOT EXISTS (SELECT 1 FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
                                   WHERE pa.post_id = p.id AND ma.deleted_at IS NULL
                                     AND (ma.type <> 'image' OR ma.status <> 'ready' OR ma.moderation_status <> 'approved' OR ma.is_adult = 1))
                ORDER BY p.published_at DESC, p.id DESC
                LIMIT $limit";
        $rows = parent::select($sql);
        return is_array($rows) ? $rows : array();
    }
}
