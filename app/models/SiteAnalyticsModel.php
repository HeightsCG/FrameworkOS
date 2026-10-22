<?php
/**
 * analytics_events: the first-party analytics rows written by Analytics (page views and product/money events) and the
 * queries behind /admin/analytics (site-wide) and the creator dashboard (one creator's page). Every method takes a day
 * count and reads UTC; the admin views label the period. Demo accounts' events are kept (they are real page loads).
 */
class SiteAnalyticsModel extends Model {

    public function __construct(){ parent::__construct(); }

    public function record(array $row){
        $cols = array('ts', 'kind', 'name', 'path', 'host', 'status', 'referrer_host', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'landing', 'is_landing', 'is_new',
                      'device', 'browser', 'os', 'country', 'visitor', 'session', 'user_id', 'creator_id', 'value_cents', 'meta');
        $data = array();
        foreach ($cols as $c) { if (array_key_exists($c, $row)) { $data[$c] = $row[$c]; } }
        return (int) parent::insert('analytics_events', $data);
    }

    public function session_landing($session){
        $r = parent::select("SELECT landing FROM analytics_events WHERE session = :s AND kind = 'pageview' ORDER BY id ASC LIMIT 1", array('s' => (string) $session));
        return (string) ($r[0]['landing'] ?? '');
    }

    public function purge($keep_days){
        return parent::sql("DELETE FROM analytics_events WHERE ts < UTC_TIMESTAMP() - INTERVAL :d DAY LIMIT 2000", array('d' => (int) $keep_days));
    }

    /** "ts >= start of the window", with the previous window of equal length for deltas. */
    private function window($days, $previous = false){
        $days = max(1, min(366, (int) $days));
        return $previous
            ? "ts >= UTC_DATE() - INTERVAL " . (2 * $days - 1) . " DAY AND ts < UTC_DATE() - INTERVAL " . ($days - 1) . " DAY"
            : "ts >= UTC_DATE() - INTERVAL " . ($days - 1) . " DAY";
    }

    /** Headline numbers for the window and the window before it: visitors, sessions, page views, signups, paid plans, purchases. */
    public function overview($days, $creator_id = 0){
        $out = array();
        foreach (array('now' => false, 'before' => true) as $k => $prev) {
            $w = $this->window($days, $prev); $c = $creator_id > 0 ? ' AND creator_id = ' . (int) $creator_id : '';
            $r = parent::select(
                "SELECT COUNT(DISTINCT CASE WHEN kind = 'pageview' THEN visitor END) AS visitors,
                        COUNT(DISTINCT CASE WHEN kind = 'pageview' THEN session END) AS sessions,
                        SUM(kind = 'pageview') AS pageviews,
                        SUM(kind = 'pageview' AND is_new = 1) AS new_visitors,
                        SUM(kind = 'event' AND name = 'signup') AS signups,
                        SUM(kind = 'event' AND name = 'plan_paid' AND COALESCE(value_cents, 0) = 0) AS trials,
                        SUM(kind = 'event' AND name = 'plan_paid' AND COALESCE(value_cents, 0) = 0) AS trials,
                    SUM(kind = 'event' AND name = 'plan_paid' AND value_cents > 0) AS plans_paid,
                        SUM(kind = 'event' AND name IN ('purchase', 'membership_paid')) AS sales,
                        COALESCE(SUM(CASE WHEN kind = 'event' AND name IN ('purchase', 'membership_paid', 'plan_paid', 'credits_bought') THEN value_cents END), 0) AS value_cents
                 FROM analytics_events WHERE $w$c");
            $out[$k] = array_map('intval', (array) ($r[0] ?? array()));
        }
        return $out;
    }

    /** Per day, oldest first, every day present: visitors, sessions, page views, signups, free trials started, paid plans, sales. */
    public function daily($days, $creator_id = 0){
        $days = max(1, min(366, (int) $days)); $c = $creator_id > 0 ? ' AND creator_id = ' . (int) $creator_id : '';
        $rows = parent::select(
            "SELECT DATE(ts) AS day,
                    COUNT(DISTINCT CASE WHEN kind = 'pageview' THEN visitor END) AS visitors,
                    COUNT(DISTINCT CASE WHEN kind = 'pageview' THEN session END) AS sessions,
                    SUM(kind = 'pageview') AS pageviews,
                    SUM(kind = 'event' AND name = 'signup') AS signups,
                    SUM(kind = 'event' AND name = 'plan_paid' AND COALESCE(value_cents, 0) = 0) AS trials,
                    SUM(kind = 'event' AND name = 'plan_paid' AND value_cents > 0) AS plans_paid,
                    SUM(kind = 'event' AND name IN ('purchase', 'membership_paid')) AS sales
             FROM analytics_events WHERE " . $this->window($days) . "$c GROUP BY DATE(ts)");
        $by = array(); foreach ($rows as $r) { $day = (string) $r['day']; $by[$day] = array_map('intval', $r); $by[$day]['day'] = $day; }
        $out = array();
        for ($i = $days - 1; $i >= 0; $i--) { $d = gmdate('Y-m-d', strtotime("-$i day")); $out[] = $by[$d] ?? array('day' => $d, 'visitors' => 0, 'sessions' => 0, 'pageviews' => 0, 'signups' => 0, 'trials' => 0, 'plans_paid' => 0, 'sales' => 0); }
        return $out;
    }

    public function top_pages($days, $limit = 100, $creator_id = 0){
        $c = $creator_id > 0 ? ' AND creator_id = ' . (int) $creator_id : '';
        return parent::select(
            "SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors, SUM(is_landing = 1) AS entrances, SUM(status >= 400) AS errors
             FROM analytics_events WHERE kind = 'pageview' AND " . $this->window($days) . "$c GROUP BY path ORDER BY views DESC LIMIT " . max(1, min(500, (int) $limit)));
    }

    /**
     * Where sessions come from: the landing hit's UTM source/medium, else the external referrer host, else direct.
     * Signups and paid plans are counted per source through the session that produced them.
     */
    public function sources($days, $limit = 100, $creator_id = 0){
        $c = $creator_id > 0 ? ' AND l.creator_id = ' . (int) $creator_id : '';
        return parent::select(
            "SELECT COALESCE(NULLIF(l.utm_source, ''), NULLIF(l.referrer_host, ''), 'direct') AS source,
                    CASE WHEN l.utm_source <> '' THEN l.utm_medium WHEN l.referrer_host <> '' THEN 'referral' ELSE '' END AS medium,
                    COUNT(*) AS sessions, COUNT(DISTINCT l.visitor) AS visitors,
                    COUNT(DISTINCT s.session) AS signups, COUNT(DISTINCT p.session) AS plans_paid, COUNT(DISTINCT t.session) AS trials
             FROM analytics_events l
             LEFT JOIN analytics_events s ON s.session = l.session AND s.kind = 'event' AND s.name = 'signup'
             LEFT JOIN analytics_events t ON t.session = l.session AND t.kind = 'event' AND t.name = 'plan_paid' AND COALESCE(t.value_cents, 0) = 0
             LEFT JOIN analytics_events p ON p.session = l.session AND p.kind = 'event' AND p.name = 'plan_paid' AND p.value_cents > 0
             WHERE l.kind = 'pageview' AND l.is_landing = 1 AND l." . $this->window($days) . "$c
             GROUP BY source, medium ORDER BY sessions DESC LIMIT " . max(1, min(500, (int) $limit)));
    }

    public function campaigns($days, $limit = 100){
        return parent::select(
            "SELECT l.utm_campaign AS campaign, l.utm_source AS source, l.utm_medium AS medium, COUNT(*) AS sessions,
                    COUNT(DISTINCT s.session) AS signups, COUNT(DISTINCT p.session) AS plans_paid, COUNT(DISTINCT t.session) AS trials
             FROM analytics_events l
             LEFT JOIN analytics_events s ON s.session = l.session AND s.kind = 'event' AND s.name = 'signup'
             LEFT JOIN analytics_events t ON t.session = l.session AND t.kind = 'event' AND t.name = 'plan_paid' AND COALESCE(t.value_cents, 0) = 0
             LEFT JOIN analytics_events p ON p.session = l.session AND p.kind = 'event' AND p.name = 'plan_paid' AND p.value_cents > 0
             WHERE l.kind = 'pageview' AND l.is_landing = 1 AND l.utm_campaign <> '' AND l." . $this->window($days) . "
             GROUP BY l.utm_campaign, l.utm_source, l.utm_medium ORDER BY sessions DESC LIMIT " . max(1, min(500, (int) $limit)));
    }

    public function landing_pages($days, $limit = 100, $creator_id = 0){
        $c = $creator_id > 0 ? ' AND l.creator_id = ' . (int) $creator_id : '';
        return parent::select(
            "SELECT l.path, COUNT(*) AS sessions, COUNT(DISTINCT l.visitor) AS visitors, COUNT(DISTINCT s.session) AS signups, COUNT(DISTINCT p.session) AS plans_paid, COUNT(DISTINCT t.session) AS trials
             FROM analytics_events l
             LEFT JOIN analytics_events s ON s.session = l.session AND s.kind = 'event' AND s.name = 'signup'
             LEFT JOIN analytics_events t ON t.session = l.session AND t.kind = 'event' AND t.name = 'plan_paid' AND COALESCE(t.value_cents, 0) = 0
             LEFT JOIN analytics_events p ON p.session = l.session AND p.kind = 'event' AND p.name = 'plan_paid' AND p.value_cents > 0
             WHERE l.kind = 'pageview' AND l.is_landing = 1 AND l." . $this->window($days) . "$c
             GROUP BY l.path ORDER BY sessions DESC LIMIT " . max(1, min(500, (int) $limit)));
    }

    /** Sessions by one column (device, browser, os, country) for the window. */
    public function breakdown($days, $col, $creator_id = 0){
        if (!in_array($col, array('device', 'browser', 'os', 'country'), true)) { return array(); }
        $c = $creator_id > 0 ? ' AND creator_id = ' . (int) $creator_id : '';
        return parent::select(
            "SELECT $col AS label, COUNT(DISTINCT session) AS sessions, COUNT(DISTINCT visitor) AS visitors, COUNT(*) AS pageviews
             FROM analytics_events WHERE kind = 'pageview' AND " . $this->window($days) . "$c GROUP BY $col ORDER BY sessions DESC LIMIT 50");
    }

    public function referrers($days, $limit = 100, $creator_id = 0){
        $c = $creator_id > 0 ? ' AND creator_id = ' . (int) $creator_id : '';
        return parent::select(
            "SELECT referrer_host AS host, COUNT(*) AS sessions, COUNT(DISTINCT visitor) AS visitors
             FROM analytics_events WHERE kind = 'pageview' AND is_landing = 1 AND referrer_host <> '' AND " . $this->window($days) . "$c
             GROUP BY referrer_host ORDER BY sessions DESC LIMIT " . max(1, min(500, (int) $limit)));
    }

    /** Product and money events by name: count, accounts, value, and the same for the previous window. */
    /**
     * A plan_paid event worth $0 is a free trial starting (it stays plan_paid for TikTok's StartTrial), not a paid plan:
     * "Paid plans" counts real payments only (value_cents > 0), and the event list shows trial starts as their own row.
     */
    const EVENT_NAME = "CASE WHEN name = 'plan_paid' AND COALESCE(value_cents, 0) = 0 THEN 'trial_started' ELSE name END";

    public function events_summary($days, $creator_id = 0){
        $out = array();
        foreach (array('now' => false, 'before' => true) as $k => $prev) {
            $c = $creator_id > 0 ? ' AND creator_id = ' . (int) $creator_id : '';
            $rows = parent::select(
                "SELECT " . self::EVENT_NAME . " AS name, COUNT(*) AS n, COUNT(DISTINCT user_id) AS accounts, COALESCE(SUM(value_cents), 0) AS value_cents
                 FROM analytics_events WHERE kind = 'event' AND " . $this->window($days, $prev) . "$c GROUP BY 1 ORDER BY n DESC");
            foreach ($rows as $r) { $out[$r['name']][$k] = array('n' => (int) $r['n'], 'accounts' => (int) $r['accounts'], 'value_cents' => (int) $r['value_cents']); }
        }
        foreach ($out as $name => &$v) { $v += array('now' => array('n' => 0, 'accounts' => 0, 'value_cents' => 0), 'before' => array('n' => 0, 'accounts' => 0, 'value_cents' => 0)); }
        uasort($out, function ($a, $b) { return $b['now']['n'] <=> $a['now']['n']; });
        return $out;
    }

    /** Events per day for one name (sparklines on the Product view). */
    public function events_daily($days, $creator_id = 0){
        $days = max(1, min(366, (int) $days)); $c = $creator_id > 0 ? ' AND creator_id = ' . (int) $creator_id : '';
        $rows = parent::select("SELECT " . self::EVENT_NAME . " AS name, DATE(ts) AS day, COUNT(*) AS n FROM analytics_events WHERE kind = 'event' AND " . $this->window($days) . "$c GROUP BY 1, DATE(ts)");
        $by = array(); foreach ($rows as $r) { $by[$r['name']][$r['day']] = (int) $r['n']; }
        $out = array();
        foreach ($by as $name => $d) { $s = array(); for ($i = $days - 1; $i >= 0; $i--) { $day = gmdate('Y-m-d', strtotime("-$i day")); $s[] = $d[$day] ?? 0; } $out[$name] = $s; }
        return $out;
    }

    /** The latest events, newest first, with the account's handle (the Product view's feed). */
    public function recent_events($days, $limit = 200){
        return parent::select(
            "SELECT e.ts, e.name, e.user_id, e.creator_id, e.value_cents, e.meta, e.path, u.u_name, c.u_name AS creator_handle
             FROM analytics_events e LEFT JOIN user_accounts u ON u.user_id = e.user_id LEFT JOIN user_accounts c ON c.user_id = e.creator_id
             WHERE e.kind = 'event' AND e." . $this->window($days) . " ORDER BY e.id DESC LIMIT " . max(1, min(1000, (int) $limit)));
    }

    /** Creator pages by traffic: views, visitors, the events their pages produced (signups, memberships, purchases). */
    public function creators_top($days, $limit = 200){
        return parent::select(
            "SELECT e.creator_id, u.u_name, u.first_name, u.last_name,
                    SUM(e.kind = 'pageview') AS views, COUNT(DISTINCT CASE WHEN e.kind = 'pageview' THEN e.visitor END) AS visitors,
                    SUM(e.kind = 'pageview' AND e.is_landing = 1) AS entrances,
                    SUM(e.kind = 'event' AND e.name IN ('membership_free', 'membership_paid')) AS memberships,
                    SUM(e.kind = 'event' AND e.name = 'purchase') AS purchases,
                    COALESCE(SUM(CASE WHEN e.kind = 'event' AND e.name IN ('purchase', 'membership_paid') THEN e.value_cents END), 0) AS value_cents
             FROM analytics_events e JOIN user_accounts u ON u.user_id = e.creator_id
             WHERE e.creator_id > 0 AND e." . $this->window($days) . "
             GROUP BY e.creator_id ORDER BY views DESC LIMIT " . max(1, min(1000, (int) $limit)));
    }

    /** One creator's page traffic for their dashboard: totals, daily series, top referrers, top pages, devices. */
    public function creator_traffic($creator_id, $days){
        $creator_id = (int) $creator_id;
        return array(
            'overview'  => $this->overview($days, $creator_id),
            'daily'     => $this->daily($days, $creator_id),
            'sources'   => $this->sources($days, 10, $creator_id),
            'pages'     => $this->top_pages($days, 10, $creator_id),
            'devices'   => $this->breakdown($days, 'device', $creator_id),
            'countries' => $this->breakdown($days, 'country', $creator_id),
        );
    }
}
