<?php
/**
 * Per-post engagement pulled from each connected social account's feed via Post for Me.
 * One row per (social account, platform post). Counters are lifetime totals as of fetched_at;
 * the normalized columns (views/likes/comments/shares/saves/reach) are derived from the
 * platform-shaped metrics object, which is kept verbatim in metrics_json.
 */
class SocialPostMetricsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /**
     * Upsert one feed item for a creator. $item is a Post for Me feed row; $post_id is our
     * posts.id when the item's social_post_id maps to one of our social_posts rows.
     */
    public function upsert_feed_item($user_id, $platform, $item, $post_id = null){
        $now   = date('Y-m-d H:i:s');
        $norm  = self::normalize($platform, isset($item['metrics']) && is_array($item['metrics']) ? $item['metrics'] : array());
        $acct  = (string) ($item['social_account_id'] ?? '');
        $ppid  = (string) ($item['platform_post_id'] ?? '');
        if ($acct === '' || $ppid === '') { return false; }

        $posted = null;
        if (!empty($item['posted_at'])) {
            $ts = strtotime((string) $item['posted_at']);
            if ($ts) { $posted = gmdate('Y-m-d H:i:s', $ts); }
        }

        return parent::sql(
            "INSERT INTO social_post_metrics
                (user_id, social_account_id, platform, pfm_post_id, post_id, platform_post_id, platform_url, caption, posted_at,
                 views, likes, comments, shares, saves, reach, metrics_json, fetched_at, created_at, updated_at)
             VALUES
                (:user_id, :acct, :platform, :pfm_post_id, :post_id, :ppid, :url, :caption, :posted_at,
                 :views, :likes, :comments, :shares, :saves, :reach, :json, :fetched, :created, :updated)
             ON DUPLICATE KEY UPDATE
                pfm_post_id  = COALESCE(VALUES(pfm_post_id), pfm_post_id),
                post_id      = COALESCE(VALUES(post_id), post_id),
                platform_url = VALUES(platform_url),
                caption      = VALUES(caption),
                posted_at    = COALESCE(VALUES(posted_at), posted_at),
                views = VALUES(views), likes = VALUES(likes), comments = VALUES(comments),
                shares = VALUES(shares), saves = VALUES(saves), reach = VALUES(reach),
                metrics_json = VALUES(metrics_json), fetched_at = VALUES(fetched_at), updated_at = VALUES(updated_at)",
            array(
                ':user_id'     => (int) $user_id,
                ':acct'        => $acct,
                ':platform'    => (string) $platform,
                ':pfm_post_id' => !empty($item['social_post_id']) ? (string) $item['social_post_id'] : null,
                ':post_id'     => $post_id !== null ? (int) $post_id : null,
                ':ppid'        => $ppid,
                ':url'         => isset($item['platform_url']) ? mb_substr((string) $item['platform_url'], 0, 512) : null,
                ':caption'     => isset($item['caption']) ? (string) $item['caption'] : null,
                ':posted_at'   => $posted,
                ':views'       => $norm['views'],
                ':likes'       => $norm['likes'],
                ':comments'    => $norm['comments'],
                ':shares'      => $norm['shares'],
                ':saves'       => $norm['saves'],
                ':reach'       => $norm['reach'],
                ':json'        => json_encode($item['metrics'] ?? new stdClass()),
                ':fetched'     => $now,
                ':created'     => $now,
                ':updated'     => $now,
            )
        );
    }

    /**
     * Fold a platform-shaped metrics object into six common counters. Each platform names
     * things differently (likes / like_count / likeCount / reactions_total / public_metrics…);
     * unknown shapes just yield zeros and the raw object is still stored.
     */
    public static function normalize($platform, array $m){
        // X nests its counters; Pinterest wraps lifetime metrics in a sub-object.
        if (isset($m['public_metrics']) && is_array($m['public_metrics'])) {
            $pm = $m['public_metrics'];
            $m  = array_merge($m, $pm, array('shares' => (int) ($pm['retweet_count'] ?? 0) + (int) ($pm['quote_count'] ?? 0)));
        }
        if (isset($m['lifetime_metrics']) && is_array($m['lifetime_metrics'])) {
            $m = array_merge($m, array_change_key_case($m['lifetime_metrics'], CASE_LOWER));
        }
        $pick = function (array $keys) use ($m) {
            foreach ($keys as $k) {
                if (isset($m[$k]) && is_numeric($m[$k])) { return (int) $m[$k]; }
            }
            return 0;
        };
        $shares = $pick(array('shares', 'share_count', 'shareCount', 'reposts'));
        if ($shares === 0) { $shares = $pick(array('repostCount')) + $pick(array('quoteCount', 'quotes')); }
        return array(
            'views'    => $pick(array('views', 'view_count', 'video_views', 'media_views', 'impression_count', 'impressionCount', 'videoView', 'impression')),
            'likes'    => $pick(array('likes', 'like_count', 'likeCount', 'reactions_total', 'reaction')),
            'comments' => $pick(array('comments', 'comment_count', 'commentCount', 'replies', 'reply_count', 'replyCount', 'comment')),
            'shares'   => $shares,
            'saves'    => $pick(array('saved', 'saves', 'favorites', 'bookmark_count', 'save')),
            'reach'    => $pick(array('reach')),
        );
    }

    /** Map Post for Me post ids → our posts.id for one creator (only shares tied to a studio post). */
    public function pfm_to_post_map($user_id){
        $rows = parent::select(
            "SELECT post_for_me_post_id, post_id FROM social_posts WHERE user_id = :u AND post_id IS NOT NULL AND post_for_me_post_id IS NOT NULL",
            array('u' => (int) $user_id));
        $map = array();
        foreach ((array) $rows as $r) { $map[(string) $r['post_for_me_post_id']] = (int) $r['post_id']; }
        return $map;
    }

    /** Per-platform rows for every studio post of a creator that has metrics. */
    public function for_creator_posts($user_id){
        return (array) parent::select(
            "SELECT post_id, platform, platform_url, views, likes, comments, shares, saves, reach, fetched_at
             FROM social_post_metrics WHERE user_id = :u AND post_id IS NOT NULL ORDER BY post_id, platform",
            array('u' => (int) $user_id));
    }

    /** Per-platform rows for one studio post. */
    public function for_post($user_id, $post_id){
        return (array) parent::select(
            "SELECT platform, platform_url, posted_at, views, likes, comments, shares, saves, reach, fetched_at
             FROM social_post_metrics WHERE user_id = :u AND post_id = :p ORDER BY platform",
            array('u' => (int) $user_id, 'p' => (int) $post_id));
    }

    /** Lifetime totals per platform across everything the creator posted through us. */
    public function totals_by_platform($user_id){
        return (array) parent::select(
            "SELECT platform, COUNT(*) AS posts, SUM(views) AS views, SUM(likes) AS likes, SUM(comments) AS comments,
                    SUM(shares) AS shares, SUM(saves) AS saves, SUM(reach) AS reach, MAX(fetched_at) AS fetched_at
             FROM social_post_metrics WHERE user_id = :u AND post_id IS NOT NULL GROUP BY platform ORDER BY views DESC",
            array('u' => (int) $user_id));
    }

    /** Most recent feed items (studio posts or not), newest first — for the MCP tool. */
    public function recent_for_user($user_id, $limit = 25){
        $limit = max(1, min(100, (int) $limit));
        return (array) parent::select(
            "SELECT post_id, pfm_post_id, platform, platform_url, caption, posted_at, views, likes, comments, shares, saves, reach, fetched_at
             FROM social_post_metrics WHERE user_id = :u ORDER BY COALESCE(posted_at, updated_at) DESC LIMIT $limit",
            array('u' => (int) $user_id));
    }
}
