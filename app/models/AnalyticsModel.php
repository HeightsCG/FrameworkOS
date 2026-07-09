<?php
/**
 * Creator analytics — read-only aggregations over the data the platform already
 * tracks (posts, ppv_unlocks, follows, subscriptions, post_views) for the creator
 * dashboard. All money is reported in cents; PPV earnings come from posts.earnings_cents.
 */
class AnalyticsModel extends Model {

    /** Headline KPIs for a creator. */
    public function overview($creator_id){
        $c = (int) $creator_id;
        $p = parent::select(
            "SELECT
                COUNT(*)                               AS published_posts,
                COALESCE(SUM(earnings_cents),0)        AS ppv_cents,
                COALESCE(SUM(views),0)                 AS views,
                COALESCE(SUM(likes),0)                 AS likes,
                COALESCE(SUM(comments),0)              AS comments
             FROM posts WHERE creator_id = :c AND state = 'published'",
            array('c' => $c));
        $p = (is_array($p) && count($p)) ? $p[0] : array();

        $subs = parent::select(
            "SELECT COUNT(*) AS n, COALESCE(SUM(price_cents),0) AS mrr_cents
             FROM creator_subscriptions WHERE creator_id = :c AND status = 'active'",
            array('c' => $c));
        $subs = (is_array($subs) && count($subs)) ? $subs[0] : array('n' => 0, 'mrr_cents' => 0);

        $followers = parent::select("SELECT COUNT(*) AS n FROM follows WHERE creator_id = :c", array('c' => $c));
        $unlocks   = parent::select("SELECT COUNT(*) AS n FROM ppv_unlocks WHERE creator_id = :c", array('c' => $c));

        return array(
            'published_posts' => (int) ($p['published_posts'] ?? 0),
            'ppv_cents'       => (int) ($p['ppv_cents'] ?? 0),
            'views'           => (int) ($p['views'] ?? 0),
            'likes'           => (int) ($p['likes'] ?? 0),
            'comments'        => (int) ($p['comments'] ?? 0),
            'subscribers'     => (int) ($subs['n'] ?? 0),
            'mrr_cents'       => (int) ($subs['mrr_cents'] ?? 0),
            'followers'       => (int) (is_array($followers) && count($followers) ? $followers[0]['n'] : 0),
            'unlocks'         => (int) (is_array($unlocks) && count($unlocks) ? $unlocks[0]['n'] : 0),
        );
    }

    /** Daily view counts over the last $days days, zero-filled — for the trend chart. */
    public function views_series($creator_id, $days = 30){
        $days = max(1, min(120, (int) $days));
        $rows = parent::select(
            "SELECT DATE(pv.created_at) AS d, COUNT(*) AS n
             FROM post_views pv JOIN posts p ON p.id = pv.post_id
             WHERE p.creator_id = :c AND pv.created_at >= DATE_SUB(UTC_DATE(), INTERVAL :n DAY)
             GROUP BY DATE(pv.created_at)",
            array('c' => (int) $creator_id, 'n' => $days - 1));
        $by = array();
        foreach ((array) $rows as $r) { $by[(string) $r['d']] = (int) $r['n']; }

        $out = array();
        $ts  = strtotime(gmdate('Y-m-d') . ' -' . ($days - 1) . ' days');
        for ($i = 0; $i < $days; $i++) {
            $d = gmdate('Y-m-d', $ts + $i * 86400);
            $out[] = array('date' => $d, 'value' => (int) ($by[$d] ?? 0));
        }
        return $out;
    }

    /** Top published posts by views, with engagement + PPV earnings. */
    public function top_posts($creator_id, $limit = 5){
        $limit = max(1, min(20, (int) $limit));
        return parent::select(
            "SELECT id, caption, audience, views, likes, comments, earnings_cents, published_at
             FROM posts
             WHERE creator_id = :c AND state = 'published'
             ORDER BY views DESC, earnings_cents DESC
             LIMIT $limit",
            array('c' => (int) $creator_id));
    }

    /** Recent PPV unlocks (earning events) for the activity feed. */
    public function recent_unlocks($creator_id, $limit = 8){
        $limit = max(1, min(50, (int) $limit));
        return parent::select(
            "SELECT u.price_credits, u.created_at, p.caption
             FROM ppv_unlocks u JOIN posts p ON p.id = u.post_id
             WHERE u.creator_id = :c
             ORDER BY u.id DESC LIMIT $limit",
            array('c' => (int) $creator_id));
    }
}
