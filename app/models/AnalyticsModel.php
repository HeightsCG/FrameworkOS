<?php
/**
 * Creator analytics — read-only aggregations over the data the platform already
 * tracks (posts, follows, post_views, cross-posts) for the creator dashboard.
 */
class AnalyticsModel extends Model {

    /** Headline KPIs for a creator. */
    public function overview($creator_id){
        $c = (int) $creator_id;
        $p = parent::select(
            "SELECT
                COUNT(*)                               AS published_posts,
                COALESCE(SUM(views),0)                 AS views,
                COALESCE(SUM(likes),0)                 AS likes,
                COALESCE(SUM(comments),0)              AS comments
             FROM posts WHERE creator_id = :c AND state = 'published'",
            array('c' => $c));
        $p = (is_array($p) && count($p)) ? $p[0] : array();

        $followers = parent::select("SELECT COUNT(*) AS n FROM follows WHERE creator_id = :c", array('c' => $c));

        // Unique visitors = distinct viewer fingerprints across this creator's posts.
        $uv = parent::select(
            "SELECT COUNT(DISTINCT pv.viewer_key) AS n
             FROM post_views pv JOIN posts p ON p.id = pv.post_id
             WHERE p.creator_id = :c",
            array('c' => $c));

        return array(
            'published_posts' => (int) ($p['published_posts'] ?? 0),
            'views'           => (int) ($p['views'] ?? 0),
            'likes'           => (int) ($p['likes'] ?? 0),
            'comments'        => (int) ($p['comments'] ?? 0),
            'followers'       => (int) (is_array($followers) && count($followers) ? $followers[0]['n'] : 0),
            'unique_visitors' => (int) (is_array($uv) && count($uv) ? $uv[0]['n'] : 0),
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

    /** Content mix: published posts and their views split by what they carry (image / video / text only, by the cover). */
    public function content_mix($creator_id){
        $rows = parent::select(
            "SELECT COALESCE((SELECT ma.type FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
                              WHERE pa.post_id = p.id ORDER BY pa.is_cover DESC, pa.asset_id ASC LIMIT 1), 'text') AS kind,
                    COUNT(*) AS posts, COALESCE(SUM(p.views),0) AS views
             FROM posts p WHERE p.creator_id = :c AND p.state = 'published' GROUP BY kind",
            array('c' => (int) $creator_id));
        $mix = array();
        foreach ((array) $rows as $r) {
            $k = in_array((string) $r['kind'], array('image', 'video'), true) ? (string) $r['kind'] : 'text';
            if (!isset($mix[$k])) { $mix[$k] = array('posts' => 0, 'views' => 0); }
            $mix[$k]['posts'] += (int) $r['posts'];
            $mix[$k]['views'] += (int) $r['views'];
        }
        return $mix;
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

    /** Views / new followers / posts / engagement within [start, end). */
    private function window_totals($creator_id, $start_utc, $end_utc){
        $c = (int) $creator_id;
        $p = array('c' => $c, 's' => $start_utc, 'e' => $end_utc);
        $views = parent::select(
            "SELECT COUNT(*) AS n FROM post_views pv JOIN posts po ON po.id = pv.post_id
             WHERE po.creator_id = :c AND pv.created_at >= :s AND pv.created_at < :e", $p);
        $fol = parent::select(
            "SELECT COUNT(*) AS n FROM follows WHERE creator_id = :c AND created_at >= :s AND created_at < :e", $p);
        $posts = parent::select(
            "SELECT COUNT(*) AS n FROM posts WHERE creator_id = :c AND state = 'published' AND published_at >= :s AND published_at < :e", $p);
        $likes = parent::select(
            "SELECT COUNT(*) AS n FROM post_likes pl JOIN posts po ON po.id = pl.post_id
             WHERE po.creator_id = :c AND pl.created_at >= :s AND pl.created_at < :e", $p);
        $comments = parent::select(
            "SELECT COUNT(*) AS n FROM post_comments pc JOIN posts po ON po.id = pc.post_id
             WHERE po.creator_id = :c AND pc.deleted_at IS NULL AND pc.created_at >= :s AND pc.created_at < :e", $p);
        $g = function ($r) { return (int) (is_array($r) && count($r) ? $r[0]['n'] : 0); };
        $v = $g($views); $l = $g($likes); $cm = $g($comments);
        return array(
            'views' => $v, 'followers' => $g($fol),
            'posts' => $g($posts), 'likes' => $l, 'comments' => $cm,
            // Rate (percent, 1 dp): interactions per view.
            'engagement'  => $v > 0 ? round(($l + $cm) / $v * 100, 1) : 0,
        );
    }

    /** Font Awesome brand icon per Post for Me platform key. */
    public static function platform_icon($platform){
        $m = array('x' => 'fa-brands fa-x-twitter', 'facebook' => 'fa-brands fa-facebook', 'instagram' => 'fa-brands fa-instagram',
                   'tiktok' => 'fa-brands fa-tiktok', 'tiktok_business' => 'fa-brands fa-tiktok', 'youtube' => 'fa-brands fa-youtube',
                   'pinterest' => 'fa-brands fa-pinterest', 'linkedin' => 'fa-brands fa-linkedin', 'threads' => 'fa-brands fa-threads',
                   'bluesky' => 'fa-brands fa-bluesky');
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
     * and where it was cross-posted (social platforms). Newest first.
     */
    public function posts_table($creator_id, $start_utc, $limit = 200){
        $c = (int) $creator_id; $limit = max(1, min(500, (int) $limit));
        $rows = (array) parent::select(
            "SELECT p.id, p.caption, p.audience, p.state, p.published_at, p.scheduled_at, p.views, p.likes, p.comments,
                    (SELECT COUNT(*) FROM post_views pv WHERE pv.post_id = p.id AND pv.created_at >= :s) AS views_period
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
            $r['platforms']    = $plats;
            $r['share_status'] = isset($by_post[$pid]) ? $by_post[$pid]['status'] : '';
            $r['social']       = $social[$pid] ?? null;
            $views = (int) $r['views'];
            $r['engagement']   = $views > 0 ? round(((int) $r['likes'] + (int) $r['comments']) / $views * 100, 1) : null;
        }
        unset($r);
        return $rows;
    }

    /**
     * Cross-posting within the window: share jobs per platform, delivery buckets, distinct posts
     * shared, and the latest jobs. `metrics` adds lifetime engagement per
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
        arsort($per_platform);
        $metrics = array();
        foreach ((new SocialPostMetricsModel())->totals_by_platform($c) as $m) {
            $metrics[(string) $m['platform']] = array(
                'posts' => (int) $m['posts'], 'views' => (int) $m['views'], 'likes' => (int) $m['likes'],
                'comments' => (int) $m['comments'], 'shares' => (int) $m['shares'], 'fetched_at' => (string) $m['fetched_at']);
        }
        return array(
            'jobs'         => count($jobs),
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

    /** Top published posts, ranked by views then likes. */
    public function top_posts($creator_id, $limit = 5){
        $limit = max(1, min(20, (int) $limit));
        return parent::select(
            "SELECT id, caption, views, likes, comments, published_at
             FROM posts
             WHERE creator_id = :c AND state = 'published'
             ORDER BY views DESC, likes DESC
             LIMIT $limit",
            array('c' => (int) $creator_id));
    }

    /** Accounts that signed up from this creator's page (?ref=), and how many of them are on a paid plan now. */
    public function referred_signups($creator_id){
        $r = parent::select(
            "SELECT COUNT(*) AS n, COALESCE(SUM(" . Plan::paid_sql('u') . "), 0) AS paid
             FROM user_accounts u WHERE u.referred_by_creator_id = :c AND u.deleted = 0 AND u.is_demo = 0",
            array('c' => (int) $creator_id));
        $r = (is_array($r) && count($r)) ? $r[0] : array();
        $c = parent::select("SELECT COALESCE(SUM(credits), 0) AS n FROM ai_credit_transactions WHERE user_id = :c AND type IN ('referral_reward', 'referral_reversal')",
            array('c' => (int) $creator_id));   // ReferralRewards, net of reversals
        return array('signups' => (int) ($r['n'] ?? 0), 'paid' => (int) ($r['paid'] ?? 0), 'credits' => (is_array($c) && count($c)) ? (int) $c[0]['n'] : 0);
    }

}
