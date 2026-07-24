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

        // Unique visitors = distinct viewer fingerprints across this creator's posts.
        $uv = parent::select(
            "SELECT COUNT(DISTINCT pv.viewer_key) AS n
             FROM post_views pv JOIN posts p ON p.id = pv.post_id
             WHERE p.creator_id = :c",
            array('c' => $c));

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
            'unique_visitors' => (int) (is_array($uv) && count($uv) ? $uv[0]['n'] : 0),
        );
    }

    /**
     * Net revenue split by offering (PRD §41.1 "revenue by offering"), from the credit
     * ledger's creator-earning rows. Every earning is already net of the platform fee.
     * 1 credit = 10 cents ($1 = 10 credits). Pass $start_utc to scope to a date range.
     */
    public function revenue_breakdown($creator_id, $start_utc = null){
        $params = array('c' => (int) $creator_id);
        $where  = "user_id = :c AND type IN ('ppv_earning','bundle_earning','event_earning','service_earning')";
        if ($start_utc !== null) { $where .= " AND created_at >= :start"; $params['start'] = $start_utc; }
        $rows = parent::select(
            "SELECT type, COALESCE(SUM(credits),0) AS credits FROM credit_transactions WHERE $where GROUP BY type",
            $params);
        $m = array('ppv_earning' => 0, 'bundle_earning' => 0, 'event_earning' => 0, 'service_earning' => 0);
        foreach ((array) $rows as $r) { if (isset($m[$r['type']])) { $m[$r['type']] = (int) $r['credits']; } }
        $ppv = $m['ppv_earning'] * 10; $bundle = $m['bundle_earning'] * 10;
        $event = $m['event_earning'] * 10; $service = $m['service_earning'] * 10;
        return array(
            'ppv_cents'     => $ppv,
            'bundle_cents'  => $bundle,
            'event_cents'   => $event,
            'service_cents' => $service,
            'total_cents'   => $ppv + $bundle + $event + $service,
        );
    }

    /**
     * Daily view counts over the last $days days, zero-filled — for the trend chart.
     * Views are stored UTC; both the day buckets AND the axis are computed in the
     * creator's timezone, so an evening view doesn't spill onto "tomorrow" (UTC date).
     */
    public function views_series($creator_id, $days = 30, $tz = 'UTC'){
        list($start, $start_utc, $off, $days) = $this->day_window($days, $tz);
        $rows = parent::select(
            "SELECT DATE(CONVERT_TZ(pv.created_at, '+00:00', :off)) AS d, COUNT(*) AS n
             FROM post_views pv JOIN posts p ON p.id = pv.post_id
             WHERE p.creator_id = :c AND pv.created_at >= :start
             GROUP BY d",
            array('c' => (int) $creator_id, 'off' => $off, 'start' => $start_utc));
        return $this->fill_days($rows, $start, $days);
    }

    /** Daily net revenue in CENTS over the window (revenue by date range), zero-filled. */
    public function revenue_series($creator_id, $days = 30, $tz = 'UTC'){
        list($start, $start_utc, $off, $days) = $this->day_window($days, $tz);
        $rows = parent::select(
            "SELECT DATE(CONVERT_TZ(created_at, '+00:00', :off)) AS d, COALESCE(SUM(credits),0) * 10 AS n
             FROM credit_transactions
             WHERE user_id = :c AND created_at >= :start
               AND type IN ('ppv_earning','bundle_earning','event_earning','service_earning')
             GROUP BY d",
            array('c' => (int) $creator_id, 'off' => $off, 'start' => $start_utc));
        return $this->fill_days($rows, $start, $days);
    }

    /** Daily NEW followers over the window (follower growth), zero-filled. */
    public function follower_series($creator_id, $days = 30, $tz = 'UTC'){
        list($start, $start_utc, $off, $days) = $this->day_window($days, $tz);
        $rows = parent::select(
            "SELECT DATE(CONVERT_TZ(created_at, '+00:00', :off)) AS d, COUNT(*) AS n
             FROM follows WHERE creator_id = :c AND created_at >= :start GROUP BY d",
            array('c' => (int) $creator_id, 'off' => $off, 'start' => $start_utc));
        return $this->fill_days($rows, $start, $days);
    }

    /**
     * Shared window for the daily trend charts. Returns [start (DateTime, creator-local
     * midnight), start_utc (string), numeric tz offset (e.g. -04:00), clamped days].
     * MySQL has no named zones loaded, so buckets use the current numeric offset.
     */
    private function day_window($days, $tz){
        $days = max(1, min(120, (int) $days));
        try { $zone = new DateTimeZone($tz); } catch (Exception $e) { $zone = new DateTimeZone('UTC'); }
        $now_local = new DateTime('now', $zone);
        $start     = new DateTime($now_local->format('Y-m-d') . ' 00:00:00', $zone);
        $start->modify('-' . ($days - 1) . ' days');
        $start_utc = (clone $start)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return array($start, $start_utc, $now_local->format('P'), $days);
    }

    /** Shared: fold (d => n) rows into an ordered, zero-filled [{date, value}] series. */
    private function fill_days($rows, DateTime $start, $days){
        $by = array();
        foreach ((array) $rows as $r) { $by[(string) $r['d']] = (int) $r['n']; }
        $out = array();
        $cur = clone $start;
        for ($i = 0; $i < $days; $i++) {
            $d = $cur->format('Y-m-d');
            $out[] = array('date' => $d, 'value' => (int) ($by[$d] ?? 0));
            $cur->modify('+1 day');
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
