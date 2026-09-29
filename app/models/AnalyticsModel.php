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
            "SELECT COUNT(*) AS n, COALESCE(SUM(" . CreatorSubscriptionsModel::MONTHLY_CENTS_SQL . "),0) AS mrr_cents
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
     * The creator's revenue rows in the credit ledger: every sale's earning (already net of the platform fee) and every
     * refund's clawback (a negative refund_reversal). Revenue = their sum, so refunds come off what was earned.
     */
    const REVENUE_TYPES = "('ppv_earning','bundle_earning','message_earning','event_earning','service_earning','tip_earning','refund_reversal')";

    /**
     * Which offering a revenue row belongs to. A clawback carries no reference, but its description is written by the
     * refund code and names the item ("Refund reversal: event ticket"), so it comes off the right offering.
     */
    const REVENUE_OFFERING = "CASE type
            WHEN 'ppv_earning' THEN 'ppv' WHEN 'bundle_earning' THEN 'bundle' WHEN 'message_earning' THEN 'message'
            WHEN 'event_earning' THEN 'event' WHEN 'service_earning' THEN 'service' WHEN 'tip_earning' THEN 'tip'
            ELSE CASE WHEN description LIKE '%pay-per-view%' THEN 'ppv' WHEN description LIKE '%bundle%' THEN 'bundle'
                      WHEN description LIKE '%message%' THEN 'message' WHEN description LIKE '%event%' THEN 'event'
                      WHEN description LIKE '%service%' THEN 'service' ELSE 'other' END END";

    /**
     * Net revenue split by offering (PRD §41.1 "revenue by offering"): earnings net of the platform fee, minus refunds.
     * 1 credit = 10 cents ($1 = 10 credits). Pass $start_utc to scope to a date range.
     */
    public function revenue_breakdown($creator_id, $start_utc = null){
        $params = array('c' => (int) $creator_id);
        $where  = "user_id = :c AND type IN " . self::REVENUE_TYPES;
        if ($start_utc !== null) { $where .= " AND created_at >= :start"; $params['start'] = $start_utc; }
        $rows = parent::select(
            "SELECT " . self::REVENUE_OFFERING . " AS k, COALESCE(SUM(credits),0) AS credits FROM credit_transactions WHERE $where GROUP BY k",
            $params);
        $m = array('ppv' => 0, 'bundle' => 0, 'message' => 0, 'event' => 0, 'service' => 0, 'tip' => 0, 'other' => 0);
        foreach ((array) $rows as $r) { if (isset($m[$r['k']])) { $m[$r['k']] = (int) $r['credits'] * 10; } }
        return array(
            'ppv_cents'     => $m['ppv'],
            'bundle_cents'  => $m['bundle'],
            'message_cents' => $m['message'],
            'event_cents'   => $m['event'],
            'service_cents' => $m['service'],
            'tip_cents'     => $m['tip'],   // tips sent in CLS Video calls
            'total_cents'   => array_sum($m),
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
               AND type IN " . self::REVENUE_TYPES . "
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

    /** Profile-view metrics within the window: total visits and unique visitors. */
    public function profile_view_stats($creator_id, $days, $tz){
        list(, $start_utc) = $this->day_window($days, $tz);
        $c = (int) $creator_id;
        $r = parent::select(
            "SELECT COUNT(*) AS views, COUNT(DISTINCT viewer_key) AS uniq
             FROM profile_views WHERE creator_id = :c AND created_at >= :s",
            array('c' => $c, 's' => $start_utc));
        $row = (is_array($r) && count($r)) ? $r[0] : array();
        return array('views' => (int) ($row['views'] ?? 0), 'unique' => (int) ($row['uniq'] ?? 0));
    }

    /** Outbound link-click metrics within the window: total clicks and top links. */
    public function link_click_stats($creator_id, $days, $tz){
        list(, $start_utc) = $this->day_window($days, $tz);
        $c = (int) $creator_id;
        $total = parent::select(
            "SELECT COUNT(*) AS n FROM link_clicks WHERE creator_id = :c AND created_at >= :s",
            array('c' => $c, 's' => $start_utc));
        $top = parent::select(
            "SELECT lc.link_id, COUNT(*) AS clicks, cl.title, cl.url
             FROM link_clicks lc JOIN creator_links cl ON cl.id = lc.link_id
             WHERE lc.creator_id = :c AND lc.created_at >= :s
             GROUP BY lc.link_id, cl.title, cl.url
             ORDER BY clicks DESC LIMIT 6",
            array('c' => $c, 's' => $start_utc));
        return array(
            'total' => (int) (is_array($total) && count($total) ? $total[0]['n'] : 0),
            'top'   => (array) $top,
        );
    }

    /**
     * When the audience is active: a 7×24 grid (day-of-week × hour) of view counts in
     * the creator's timezone — the "best time to post" heatmap. Row 0 = Sunday.
     */
    public function activity_heatmap($creator_id, $tz){
        list(, , $off) = $this->day_window(1, $tz);
        $rows = parent::select(
            "SELECT DAYOFWEEK(CONVERT_TZ(pv.created_at, '+00:00', :off1)) AS dow,
                    HOUR(CONVERT_TZ(pv.created_at, '+00:00', :off2)) AS hr, COUNT(*) AS n
             FROM post_views pv JOIN posts p ON p.id = pv.post_id
             WHERE p.creator_id = :c
             GROUP BY dow, hr",
            array('c' => (int) $creator_id, 'off1' => $off, 'off2' => $off));
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $max = 0;
        foreach ((array) $rows as $r) {
            $d = (int) $r['dow'] - 1; $h = (int) $r['hr']; $n = (int) $r['n'];
            if ($d < 0 || $d > 6 || $h < 0 || $h > 23) { continue; }
            $grid[$d][$h] = $n;
            if ($n > $max) { $max = $n; }
        }
        return array('grid' => $grid, 'max' => $max);
    }

    /** Content mix: posts / views / revenue split by audience type (free / subscribers / ppv). */
    public function content_mix($creator_id){
        $rows = parent::select(
            "SELECT audience, COUNT(*) AS posts, COALESCE(SUM(views),0) AS views, COALESCE(SUM(earnings_cents),0) AS earnings
             FROM posts WHERE creator_id = :c AND state = 'published' GROUP BY audience",
            array('c' => (int) $creator_id));
        $mix = array();
        foreach ((array) $rows as $r) {
            $mix[(string) $r['audience']] = array(
                'posts'    => (int) $r['posts'],
                'views'    => (int) $r['views'],
                'earnings' => (int) $r['earnings'],
            );
        }
        return $mix;
    }

    /** Full credit-ledger rows for the CSV export (newest first). */
    public function export_rows($creator_id){
        return (array) parent::select(
            "SELECT created_at, type, credits, balance_after, description
             FROM credit_transactions WHERE user_id = :c ORDER BY id DESC",
            array('c' => (int) $creator_id));
    }

    /** The UTC start of a range window (for scoping breakdowns to the selected range). */
    public function range_start_utc($days, $tz){
        list(, $start_utc) = $this->day_window($days, $tz);
        return $start_utc;
    }

    /**
     * Current vs. previous period totals for the headline metrics, with % deltas
     * (PRD §41 "conversion rates / trends"). A null delta means "no prior baseline"
     * (previous period was zero) — the view shows it as "new" rather than a bogus %.
     */
    public function compare_periods($creator_id, $days, $tz){
        list($start, $start_utc, , $days) = $this->day_window($days, $tz);
        $utc = new DateTimeZone('UTC');
        $now_utc    = (new DateTime('now', $utc))->format('Y-m-d H:i:s');
        $prev_start = (clone $start)->modify('-' . $days . ' days');
        $prev_utc   = (clone $prev_start)->setTimezone($utc)->format('Y-m-d H:i:s');

        $cur  = $this->window_totals($creator_id, $start_utc, $now_utc);
        $prev = $this->window_totals($creator_id, $prev_utc, $start_utc);
        $delta = array();
        foreach ($cur as $k => $v) {
            $p = (int) $prev[$k];
            $delta[$k] = $p > 0 ? (int) round(($v - $p) / $p * 100) : ($v > 0 ? null : 0);
        }
        return array('current' => $cur, 'previous' => $prev, 'delta' => $delta, 'start_utc' => $start_utc);
    }

    /** Revenue (cents) / views / new followers / new subscribers within [start, end). */
    private function window_totals($creator_id, $start_utc, $end_utc){
        $c = (int) $creator_id;
        $p = array('c' => $c, 's' => $start_utc, 'e' => $end_utc);
        $rev = parent::select(
            "SELECT COALESCE(SUM(credits),0) * 10 AS n FROM credit_transactions
             WHERE user_id = :c AND type IN " . self::REVENUE_TYPES . "
               AND created_at >= :s AND created_at < :e", $p);
        $views = parent::select(
            "SELECT COUNT(*) AS n FROM post_views pv JOIN posts po ON po.id = pv.post_id
             WHERE po.creator_id = :c AND pv.created_at >= :s AND pv.created_at < :e", $p);
        $fol = parent::select(
            "SELECT COUNT(*) AS n FROM follows WHERE creator_id = :c AND created_at >= :s AND created_at < :e", $p);
        $sub = parent::select(
            "SELECT COUNT(*) AS n FROM creator_subscriptions WHERE creator_id = :c AND created_at >= :s AND created_at < :e", $p);
        $posts = parent::select(
            "SELECT COUNT(*) AS n FROM posts WHERE creator_id = :c AND state = 'published' AND published_at >= :s AND published_at < :e", $p);
        $likes = parent::select(
            "SELECT COUNT(*) AS n FROM post_likes pl JOIN posts po ON po.id = pl.post_id
             WHERE po.creator_id = :c AND pl.created_at >= :s AND pl.created_at < :e", $p);
        $comments = parent::select(
            "SELECT COUNT(*) AS n FROM post_comments pc JOIN posts po ON po.id = pc.post_id
             WHERE po.creator_id = :c AND pc.deleted_at IS NULL AND pc.created_at >= :s AND pc.created_at < :e", $p);
        $unlocks = parent::select(
            "SELECT COUNT(*) AS n FROM ppv_unlocks WHERE creator_id = :c AND created_at >= :s AND created_at < :e", $p);
        $ppv_views = parent::select(
            "SELECT COUNT(*) AS n FROM post_views pv JOIN posts po ON po.id = pv.post_id
             WHERE po.creator_id = :c AND po.audience = 'ppv' AND pv.created_at >= :s AND pv.created_at < :e", $p);
        $g = function ($r) { return (int) (is_array($r) && count($r) ? $r[0]['n'] : 0); };
        $v = $g($views); $l = $g($likes); $cm = $g($comments); $u = $g($unlocks); $pvv = $g($ppv_views);
        return array(
            'revenue_cents' => $g($rev), 'views' => $v, 'followers' => $g($fol), 'subscribers' => $g($sub),
            'posts' => $g($posts), 'likes' => $l, 'comments' => $cm, 'unlocks' => $u, 'ppv_views' => $pvv,
            // Rates (percent, 1 dp): interactions per view, and PPV unlocks per PPV view.
            'engagement'  => $v > 0 ? round(($l + $cm) / $v * 100, 1) : 0,
            'unlock_rate' => $pvv > 0 ? round($u / $pvv * 100, 1) : 0,
        );
    }

    /** Font Awesome brand icon per Post for Me platform key (Fanvue is not a brand icon). */
    public static function platform_icon($platform){
        $m = array('x' => 'fa-brands fa-x-twitter', 'facebook' => 'fa-brands fa-facebook', 'instagram' => 'fa-brands fa-instagram',
                   'tiktok' => 'fa-brands fa-tiktok', 'tiktok_business' => 'fa-brands fa-tiktok', 'youtube' => 'fa-brands fa-youtube',
                   'pinterest' => 'fa-brands fa-pinterest', 'linkedin' => 'fa-brands fa-linkedin', 'threads' => 'fa-brands fa-threads',
                   'bluesky' => 'fa-brands fa-bluesky', 'fanvue' => 'fa-solid fa-bolt');
        return $m[strtolower((string) $platform)] ?? 'fa-solid fa-share-nodes';
    }

    /** Post for Me job statuses folded into three buckets for display. */
    public static function share_bucket($status){
        $s = strtolower((string) $status);
        if (in_array($s, array('processed', 'published', 'completed', 'success', 'posted'), true)) { return 'delivered'; }
        if (in_array($s, array('failed', 'error', 'errored', 'rejected'), true)) { return 'failed'; }
        return 'pending';
    }

    /** Post for Me account id → platform, for this creator's connected accounts. */
    private function platform_map($creator_id){
        $rows = parent::select(
            "SELECT post_for_me_social_account_id AS id, platform FROM user_social_accounts WHERE user_id = :c",
            array('c' => (int) $creator_id));
        $map = array();
        foreach ((array) $rows as $r) { $map[(string) $r['id']] = (string) $r['platform']; }
        return $map;
    }

    /**
     * Every published or scheduled post with lifetime counters, views inside the window,
     * PPV unlocks, and where it was cross-posted (social platforms + Fanvue). Newest first.
     */
    public function posts_table($creator_id, $start_utc, $limit = 200){
        $c = (int) $creator_id; $limit = max(1, min(500, (int) $limit));
        $rows = (array) parent::select(
            "SELECT p.id, p.caption, p.audience, p.state, p.published_at, p.scheduled_at, p.views, p.likes, p.comments,
                    p.earnings_cents, p.ppv_price_credits, p.fanvue_post_uuid,
                    (SELECT COUNT(*) FROM post_views pv WHERE pv.post_id = p.id AND pv.created_at >= :s) AS views_period,
                    (SELECT COUNT(*) FROM ppv_unlocks u WHERE u.post_id = p.id) AS unlocks
             FROM posts p
             WHERE p.creator_id = :c AND p.state IN ('published', 'scheduled')
             ORDER BY COALESCE(p.published_at, p.scheduled_at) DESC, p.id DESC
             LIMIT $limit",
            array('c' => $c, 's' => (string) $start_utc));
        if (empty($rows)) { return array(); }

        $map    = $this->platform_map($c);
        $shares = (array) parent::select(
            "SELECT post_id, status, target_account_ids FROM social_posts WHERE user_id = :c AND post_id IS NOT NULL ORDER BY id ASC",
            array('c' => $c));
        $by_post = array();
        foreach ($shares as $sh) {
            $pid = (int) $sh['post_id'];
            if (!isset($by_post[$pid])) { $by_post[$pid] = array('platforms' => array(), 'status' => ''); }
            foreach ((array) json_decode((string) $sh['target_account_ids'], true) as $aid) {
                $pl = $map[(string) $aid] ?? 'social';
                $by_post[$pid]['platforms'][$pl] = $pl;
            }
            $by_post[$pid]['status'] = self::share_bucket($sh['status']);
        }
        // Engagement on the cross-posted copies (from each platform's feed via Post for Me),
        // folded per post and kept per platform for the breakdown.
        $social = array();
        foreach ((new SocialPostMetricsModel())->for_creator_posts($c) as $m) {
            $pid = (int) $m['post_id'];
            if (!isset($social[$pid])) { $social[$pid] = array('views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'per_platform' => array()); }
            foreach (array('views', 'likes', 'comments', 'shares') as $k) { $social[$pid][$k] += (int) $m[$k]; }
            $social[$pid]['per_platform'][(string) $m['platform']] = array(
                'views' => (int) $m['views'], 'likes' => (int) $m['likes'], 'comments' => (int) $m['comments'],
                'shares' => (int) $m['shares'], 'url' => (string) $m['platform_url'], 'fetched_at' => (string) $m['fetched_at']);
        }
        foreach ($rows as &$r) {
            $pid   = (int) $r['id'];
            $plats = isset($by_post[$pid]) ? array_values($by_post[$pid]['platforms']) : array();
            if (!empty($r['fanvue_post_uuid'])) { $plats[] = 'fanvue'; }
            $r['platforms']    = $plats;
            $r['share_status'] = isset($by_post[$pid]) ? $by_post[$pid]['status'] : (!empty($r['fanvue_post_uuid']) ? 'delivered' : '');
            $r['social']       = $social[$pid] ?? null;
            $views = (int) $r['views'];
            $r['unlock_rate']  = ($r['audience'] === 'ppv' && $views > 0) ? round((int) $r['unlocks'] / $views * 100, 1) : null;
            $r['engagement']   = $views > 0 ? round(((int) $r['likes'] + (int) $r['comments']) / $views * 100, 1) : null;
        }
        unset($r);
        return $rows;
    }

    /**
     * Cross-posting within the window: share jobs per platform, delivery buckets, distinct posts
     * shared (social + Fanvue), and the latest jobs. `metrics` adds lifetime engagement per
     * platform for everything shared through us, pulled from the platforms' feeds via Post for Me.
     */
    public function share_stats($creator_id, $start_utc, $recent = 6){
        $c = (int) $creator_id;
        $map  = $this->platform_map($c);
        $jobs = (array) parent::select(
            "SELECT sp.id, sp.post_id, sp.status, sp.target_account_ids, sp.created_at, p.caption
             FROM social_posts sp LEFT JOIN posts p ON p.id = sp.post_id
             WHERE sp.user_id = :c AND sp.created_at >= :s
             ORDER BY sp.id DESC",
            array('c' => $c, 's' => (string) $start_utc));
        $per_platform = array(); $buckets = array('delivered' => 0, 'pending' => 0, 'failed' => 0);
        $posts_shared = array(); $targets = 0; $list = array();
        foreach ($jobs as $j) {
            $plats = array();
            foreach ((array) json_decode((string) $j['target_account_ids'], true) as $aid) {
                $pl = $map[(string) $aid] ?? 'social';
                $plats[$pl] = $pl;
                $per_platform[$pl] = ($per_platform[$pl] ?? 0) + 1;
                $targets++;
            }
            $buckets[self::share_bucket($j['status'])]++;
            if ((int) $j['post_id'] > 0) { $posts_shared[(int) $j['post_id']] = true; }
            if (count($list) < $recent) {
                $list[] = array('caption' => (string) $j['caption'], 'platforms' => array_values($plats),
                                'bucket' => self::share_bucket($j['status']), 'created_at' => (string) $j['created_at']);
            }
        }
        $fv = parent::select(
            "SELECT COUNT(*) AS n FROM posts WHERE creator_id = :c AND fanvue_post_uuid IS NOT NULL
               AND COALESCE(published_at, created_at) >= :s",
            array('c' => $c, 's' => (string) $start_utc));
        $fanvue = (int) (is_array($fv) && count($fv) ? $fv[0]['n'] : 0);
        if ($fanvue > 0) { $per_platform['fanvue'] = $fanvue; $targets += $fanvue; }
        $fv_posts = (array) parent::select(
            "SELECT id FROM posts WHERE creator_id = :c AND fanvue_post_uuid IS NOT NULL AND COALESCE(published_at, created_at) >= :s",
            array('c' => $c, 's' => (string) $start_utc));
        foreach ($fv_posts as $r) { $posts_shared[(int) $r['id']] = true; }
        arsort($per_platform);
        $metrics = array();
        foreach ((new SocialPostMetricsModel())->totals_by_platform($c) as $m) {
            $metrics[(string) $m['platform']] = array(
                'posts' => (int) $m['posts'], 'views' => (int) $m['views'], 'likes' => (int) $m['likes'],
                'comments' => (int) $m['comments'], 'shares' => (int) $m['shares'], 'fetched_at' => (string) $m['fetched_at']);
        }
        return array(
            'jobs'         => count($jobs) + $fanvue,
            'targets'      => $targets,
            'posts_shared' => count($posts_shared),
            'per_platform' => $per_platform,
            'buckets'      => $buckets,
            'recent'       => $list,
            'metrics'      => $metrics,
        );
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

    /** Top published posts, ranked by earnings first then views (top-earning content). */
    public function top_posts($creator_id, $limit = 5){
        $limit = max(1, min(20, (int) $limit));
        return parent::select(
            "SELECT id, caption, audience, views, likes, comments, earnings_cents, published_at
             FROM posts
             WHERE creator_id = :c AND state = 'published'
             ORDER BY earnings_cents DESC, views DESC
             LIMIT $limit",
            array('c' => (int) $creator_id));
    }

    /**
     * Unified recent sales across every paid offering (PPV, bundles, events, services).
     * Amounts are the gross price the buyer paid, in credits. COLLATE reconciles the
     * differing text collations across the joined title columns.
     */
    /**
     * What fans paid the creator since $since_utc, in CENTS, before the platform take rate:
     * pay-per-view, bundles, paid messages, events and services (credits, $1 = 10), plus one
     * month of every paid membership that is active now (weekly/yearly prices scaled to a month).
     */
    public function gross_sales_cents($creator_id, $since_utc){
        $c = (int) $creator_id; $s = (string) $since_utc;
        $r = parent::select(
            "SELECT COALESCE(SUM(credits), 0) AS n FROM (
                SELECT pu.price_credits AS credits FROM ppv_unlocks pu WHERE pu.creator_id = :c1 AND pu.created_at >= :s1
                UNION ALL SELECT bu.price_credits FROM bundle_unlocks bu WHERE bu.creator_id = :c2 AND bu.created_at >= :s2
                UNION ALL SELECT mu.price_credits FROM message_unlocks mu WHERE mu.creator_id = :c3 AND mu.created_at >= :s3
                UNION ALL SELECT er.price_credits FROM event_registrations er JOIN events e ON e.id = er.event_id WHERE e.creator_id = :c4 AND er.status <> 'refunded' AND er.created_at >= :s4
                UNION ALL SELECT sp.price_credits FROM service_purchases sp JOIN services sv ON sv.id = sp.service_id WHERE sv.creator_id = :c5 AND sp.status = 'paid' AND sp.created_at >= :s5
             ) x",
            array('c1' => $c, 's1' => $s, 'c2' => $c, 's2' => $s, 'c3' => $c, 's3' => $s, 'c4' => $c, 's4' => $s, 'c5' => $c, 's5' => $s));
        $cents = (int) ((is_array($r) && count($r)) ? $r[0]['n'] : 0) * 10;
        $m = parent::select(
            "SELECT COALESCE(SUM(CASE billing_interval WHEN 'week' THEN price_cents * 52 / 12 WHEN 'year' THEN price_cents / 12 ELSE price_cents END), 0) AS n
             FROM creator_subscriptions WHERE creator_id = :c AND status = 'active' AND is_free = 0",
            array('c' => $c));
        return $cents + (int) round((float) ((is_array($m) && count($m)) ? $m[0]['n'] : 0));
    }

    public function recent_sales($creator_id, $limit = 8){
        $limit = max(1, min(50, (int) $limit));
        $c = (int) $creator_id;
        return (array) parent::select(
            "SELECT x.kind, x.credits, x.created_at, x.item FROM (
                SELECT 'ppv' AS kind, pu.price_credits AS credits, pu.created_at AS created_at, p.caption COLLATE utf8mb4_unicode_ci AS item
                  FROM ppv_unlocks pu JOIN posts p ON p.id = pu.post_id
                  WHERE pu.creator_id = :c1 AND pu.price_credits > 0
                UNION ALL
                SELECT 'bundle', bu.price_credits, bu.created_at, b.name COLLATE utf8mb4_unicode_ci
                  FROM bundle_unlocks bu JOIN content_bundles b ON b.id = bu.bundle_id
                  WHERE bu.creator_id = :c2 AND bu.price_credits > 0
                UNION ALL
                SELECT 'event', er.price_credits, er.created_at, e.title COLLATE utf8mb4_unicode_ci
                  FROM event_registrations er JOIN events e ON e.id = er.event_id
                  WHERE e.creator_id = :c3 AND er.status <> 'refunded' AND er.price_credits > 0
                UNION ALL
                SELECT 'service', sp.price_credits, sp.created_at, s.name COLLATE utf8mb4_unicode_ci
                  FROM service_purchases sp JOIN services s ON s.id = sp.service_id
                  WHERE s.creator_id = :c4 AND sp.status = 'paid' AND sp.price_credits > 0
                UNION ALL
                SELECT 'tip', lt.credits, lt.created_at, CONCAT('Tip in ', COALESCE(e.title, s2.name, 'a call')) COLLATE utf8mb4_unicode_ci
                  FROM live_tips lt
                  LEFT JOIN events e ON lt.kind = 'event' AND e.id = lt.ref_id
                  LEFT JOIN service_purchases sp2 ON lt.kind = 'booking' AND sp2.id = lt.ref_id
                  LEFT JOIN services s2 ON s2.id = sp2.service_id
                  WHERE lt.creator_id = :c5
             ) x ORDER BY x.created_at DESC LIMIT $limit",
            array('c1' => $c, 'c2' => $c, 'c3' => $c, 'c4' => $c, 'c5' => $c));
    }

    /** Paying-customer metrics across all offerings: distinct buyers, repeat rate, avg spend. */
    public function customer_stats($creator_id){
        $c = (int) $creator_id;
        $rows = (new FanSpendModel())->by_fan($c);   // the one definition of what a fan has spent (now includes paid messages)
        $customers = 0; $repeat = 0; $credits = 0;
        foreach ((array) $rows as $r) {
            $customers++;
            if ((int) $r['purchases'] > 1) { $repeat++; }
            $credits += (int) $r['credits'];
        }
        return array(
            'customers'   => $customers,
            'repeat'      => $repeat,
            'repeat_pct'  => $customers > 0 ? (int) round($repeat / $customers * 100) : 0,
            'arpu_cents'  => $customers > 0 ? (int) round($credits * 10 / $customers) : 0,
            'gross_cents' => $credits * 10,
        );
    }

    /** New vs. churned subscribers within the window, and the net change. */
    public function subscriber_movement($creator_id, $days, $tz){
        list(, $start_utc) = $this->day_window($days, $tz);
        $c = (int) $creator_id;
        $new = parent::select(
            "SELECT COUNT(*) AS n FROM creator_subscriptions WHERE creator_id = :c AND created_at >= :s",
            array('c' => $c, 's' => $start_utc));
        $churn = parent::select(
            "SELECT COUNT(*) AS n FROM creator_subscriptions WHERE creator_id = :c AND canceled_at IS NOT NULL AND canceled_at >= :s",
            array('c' => $c, 's' => $start_utc));
        $g = function ($r) { return (int) (is_array($r) && count($r) ? $r[0]['n'] : 0); };
        $n = $g($new); $ch = $g($churn);
        return array('new' => $n, 'churned' => $ch, 'net' => $n - $ch);
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
