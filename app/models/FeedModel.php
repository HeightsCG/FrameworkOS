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
        $params = array();
        $where  = self::visible_where((int) $viewer_id, $params);
        // Step 1: the page of ids only. Near the top, idx_posts_feed walks posts newest first and stops at the limit (no temp
        // table or filesort); the optimizer otherwise starts from user_accounts and sorts every published post. Deep pages are
        // left to the optimizer. An optimizer hint, not FORCE INDEX: without the index it is only a warning.
        $hint = $offset < 300 ? '/*+ INDEX(p idx_posts_feed) */ ' : '';
        $ids = parent::select(
            "SELECT {$hint}p.id FROM posts p JOIN user_accounts ua ON ua.user_id = p.creator_id
             WHERE $where
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT $offset, $limit", $params);
        $ids = array_map(function ($r) { return (int) $r['id']; }, (array) $ids);
        if (!$ids) { return array(); }
        // Step 2: author, counts and cover for just those posts.
        // Cover = the post's is_cover asset, else its first ready asset by sort order.
        // Pulled as correlated scalar subqueries (the ON-clause form trips a MySQL
        // "unknown column" error on the joined alias).
        $cover = "(SELECT ma.%s FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
                     WHERE pa.post_id = p.id AND ma.deleted_at IS NULL AND ma.status = 'ready'
                     ORDER BY pa.is_cover DESC, pa.sort_order ASC LIMIT 1)";
        $rows = parent::select(
            "SELECT p.id, p.creator_id, p.caption, p.audience, p.ppv_price_credits, p.tier_id,
                    p.published_at, p.views, p.likes, p.comments, p.comments_enabled,
                    ua.u_name,
                    cp.display_name, cp.avatar_url,
                    (SELECT COUNT(*) FROM post_assets pa WHERE pa.post_id = p.id) AS asset_count,
                    " . sprintf($cover, 'type')        . " AS cover_type,
                    " . sprintf($cover, 'thumb_key')   . " AS cover_thumb_key,
                    " . sprintf($cover, 'poster_key')  . " AS cover_poster_key,
                    " . sprintf($cover, 'blurred_key') . " AS cover_blurred_key,
                    " . sprintf($cover, 'moderation_status') . " AS cover_moderation_status,
                    " . sprintf($cover, 'is_adult') . " AS cover_is_adult,
                    " . sprintf($cover, 'public_display_key') . " AS cover_public_display_key
             FROM posts p
             JOIN user_accounts ua ON ua.user_id = p.creator_id
             LEFT JOIN creator_profiles cp ON cp.user_id = p.creator_id
             WHERE p.id IN (" . implode(',', $ids) . ")");
        $by_id = array();
        foreach ((array) $rows as $r) { $by_id[(int) $r['id']] = $r; }
        $out = array();
        foreach ($ids as $id) { if (isset($by_id[$id])) { $out[] = $by_id[$id]; } }   // keep the page order
        return $out;
    }

    /**
     * Which posts a viewer may get in the feed (posts p JOIN user_accounts ua). Shared by recent() and count_since(), so the
     * "N new posts" count never counts a post the feed would not show.
     */
    private static function visible_where(int $viewer_id, array &$params): string {
        $sql = "p.state = 'published' AND p.on_cls = 1
                AND ua.deleted = 0 AND (ua.user_status IS NULL OR ua.user_status <> 'Disabled') AND " . Plan::paid_sql('ua');
        if ($viewer_id > 0) {
            // Blocks hide a creator's posts from the blocked viewer, and the viewer's own blocks hide creators they blocked.
            $sql .= ' AND ' . BlocksModel::exclude_sql('p.creator_id', 'bv1', 'bv2');
            $params['bv1'] = $viewer_id; $params['bv2'] = $viewer_id;
            // demo accounts stay out of everyone else's feed; the demo user still sees their own posts.
            $sql .= ' AND (ua.is_demo = 0 OR ua.user_id = :demo_viewer)';
            $params['demo_viewer'] = $viewer_id;
        } else {
            $sql .= ' AND ua.is_demo = 0';
        }
        // Subscriber posts appear only for a viewer with an active subscription to that creator (2026-10-13): nobody else gets
        // even a locked tile. Free and pay-per-view posts are for everyone. Done here, not in PHP, so paging stays exact.
        return $sql . self::subscriber_sql($viewer_id, $params);
    }

    /** The audience clause for the feed: free and pay-per-view for everyone, subscriber posts only with an active subscription to the creator. */
    private static function subscriber_sql(int $viewer_id, array &$params): string {
        if ($viewer_id <= 0) { return " AND p.audience IN ('free', 'ppv')"; }
        $params['sub_viewer'] = $viewer_id;
        return " AND (p.audience IN ('free', 'ppv') OR EXISTS (SELECT 1 FROM creator_subscriptions cs WHERE cs.subscriber_id = :sub_viewer AND cs.creator_id = p.creator_id AND cs.status = 'active'))";
    }

    /**
     * How many published posts exist newer than a watermark id — powers the Home
     * feed's "N new posts" alert. Ids are auto-increment so id > watermark is a
     * cheap monotonic proxy for "published since you last loaded the top". Capped
     * so the count query stays bounded (the UI only needs "9+").
     * Same rules as recent() plus the moderation gate the feed applies after it (PostsModel::moderation_map):
     * a post with blocked or unscanned media is never counted, an adult one only when $show_adult.
     */
    public function count_since($since_id, $cap = 50, $viewer_id = 0, $show_adult = false){
        $since_id = (int) $since_id;
        if ($since_id <= 0) { return 0; }
        $cap = max(1, min(200, (int) $cap));
        $params = array('since' => $since_id);
        $where  = self::visible_where((int) $viewer_id, $params);
        $adult  = $show_adult ? '' : " OR ma.moderation_status = 'flagged' OR (ma.moderation_status = 'approved' AND ma.is_adult = 1)";
        $sql = "SELECT COUNT(*) AS c FROM (
                    SELECT p.id FROM posts p JOIN user_accounts ua ON ua.user_id = p.creator_id
                    WHERE $where AND p.id > :since
                      AND NOT EXISTS (SELECT 1 FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
                                      WHERE pa.post_id = p.id AND ma.deleted_at IS NULL AND ma.type IN ('image', 'video')
                                        AND (ma.moderation_status NOT IN ('approved', 'flagged', 'n_a')$adult))
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
    /** public_latest() with at most one post per creator (the newest), so a single handle never repeats on the home page (D3.1, 2026-10-12). */
    public function public_latest_distinct($limit = 5){
        $limit = max(1, min(12, (int) $limit)); $out = array(); $seen = array();
        foreach ((array) $this->public_latest(12) as $row) {   // newest 12 public posts, then the first per creator
            $k = strtolower((string) $row['u_name']); if (isset($seen[$k])) { continue; }
            $seen[$k] = true; $out[] = $row; if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    public function public_latest($limit = 6){
        $limit = max(1, min(12, (int) $limit));
        // the cover's public webp copies (PublicThumbService) ride along; every asset here is approved and not adult.
        $cover = "(SELECT ma.%s FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
                    WHERE pa.post_id = p.id AND ma.deleted_at IS NULL AND ma.status = 'ready'
                    ORDER BY pa.is_cover DESC, pa.sort_order ASC LIMIT 1)";
        $sql = "SELECT p.id, p.caption, p.published_at, ua.u_name, cp.display_name,
                       " . sprintf($cover, 'thumb_key') . " AS cover_thumb_key,
                       " . sprintf($cover, 'public_thumb_key') . " AS public_thumb_key,
                       " . sprintf($cover, 'public_display_key') . " AS public_display_key,
                       " . sprintf($cover, 'public_width') . " AS public_width,
                       " . sprintf($cover, 'public_height') . " AS public_height
                FROM posts p
                JOIN user_accounts ua ON ua.user_id = p.creator_id
                LEFT JOIN creator_profiles cp ON cp.user_id = p.creator_id
                WHERE p.state = 'published' AND p.on_cls = 1 AND p.audience = 'free'
                  AND ua.deleted = 0 AND (ua.user_status IS NULL OR ua.user_status <> 'Disabled') AND ua.is_demo = 0 AND " . Plan::paid_sql('ua') . "
                  AND cp.directory_listed = 1   -- only creators who chose to be listed in the public directory appear on the home page (Daniel 2026-10-12)
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
