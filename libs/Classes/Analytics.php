<?php
/**
 * First-party analytics: every rendered page and every product or money event lands in analytics_events, read by
 * /admin/analytics and the creator dashboard (SiteAnalyticsModel). Google Analytics and HeyCatch keep running beside it;
 * this is the copy we own.
 *
 *  - begin()    runs early in Bootstrap for every web request: reads or sets the visitor cookie (cls_vid, 2 years, random)
 *               and the session cookie (cls_sid, 30 minutes idle). Both are plain random ids, never derived from the IP.
 *  - pageview() runs from the <head> include every HTML page shares (libs/Layout/google_analytics.php): one row per
 *               page, with the external referrer and UTM tags on the session's landing hit, device/browser/os from the
 *               user agent, the country from an edge header when the host sends one, the signed-in account, and the
 *               creator the page belongs to (profile pages set Analytics::$creator_id). Crawlers are not recorded.
 *  - event()    is called at the business points (signup, plan paid, purchase, membership, post published, AI generation,
 *               message, cash-out, link click) with the account, creator and value; it never throws.
 *
 * Nothing here blocks a page: every write is wrapped, and an analytics failure is logged and ignored.
 */
class Analytics {

    const VISITOR_COOKIE = 'cls_vid';
    const SESSION_COOKIE = 'cls_sid';
    const SESSION_IDLE   = 1800;      // seconds without a page view that end a session
    const KEEP_DAYS      = 400;       // rows older than this are purged (one in a thousand page views runs the purge)

    /** The creator a page belongs to; the profile page sets it before the <head> include, everything else leaves 0. */
    public static $creator_id = 0;

    private static $visitor = '';
    private static $session = '';
    private static $new_visitor = false;
    private static $new_session = false;
    private static $begun = false;
    private static $recorded = false;

    /** Bot user agents (the same list the blog view counter uses): never recorded. */
    const BOT_RE = '/bot|crawl|spider|slurp|facebookexternalhit|preview|lighthouse|headless|curl|wget|python|GPTBot|ClaudeBot|Bytespider|Googlebot|bingbot|DuckDuckBot|Applebot|PetalBot|Semrush|Ahrefs|MJ12|DotBot|YandexBot|pingdom|uptime/i';

    public static function is_bot(?string $ua = null): bool {
        $ua = $ua ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return $ua === '' || preg_match(self::BOT_RE, $ua) === 1;
    }

    /** Cookies in, cookies out. Safe to call more than once; does nothing on the CLI or for crawlers. */
    public static function begin(): void {
        if (self::$begun || php_sapi_name() === 'cli') { return; }
        self::$begun = true;
        try {
            if (self::is_bot()) { return; }
            $now = time();
            $vid = (string) ($_COOKIE[self::VISITOR_COOKIE] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $vid)) { $vid = bin2hex(random_bytes(16)); self::$new_visitor = true; }
            $raw = (string) ($_COOKIE[self::SESSION_COOKIE] ?? '');
            $sid = ''; $seen = 0;
            if (preg_match('/^([a-f0-9]{32})\.(\d{9,11})$/', $raw, $m)) { $sid = $m[1]; $seen = (int) $m[2]; }
            if ($sid === '' || $now - $seen > self::SESSION_IDLE) { $sid = bin2hex(random_bytes(16)); self::$new_session = true; }
            self::$visitor = $vid; self::$session = $sid;
            if (!headers_sent()) {
                $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
                $opts = array('path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax');
                setcookie(self::VISITOR_COOKIE, $vid, $opts + array('expires' => $now + 2 * 365 * 86400));
                setcookie(self::SESSION_COOKIE, $sid . '.' . $now, $opts + array('expires' => $now + self::SESSION_IDLE));
            }
        } catch (\Throwable $e) { error_log('[analytics] begin: ' . $e->getMessage()); }
    }

    /** One row for the page being rendered. Called from the shared <head> include; a second call in the same request is ignored. */
    public static function pageview(): void {
        if (self::$recorded || php_sapi_name() === 'cli') { return; }
        self::$recorded = true;
        try {
            self::begin();
            if (self::is_bot() || self::$visitor === '') { return; }
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return; }
            $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
            $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
            $row  = self::base_row('pageview', '');
            $row['path']   = mb_substr($path, 0, 255);
            $row['host']   = mb_substr($host, 0, 100);
            $row['status'] = (int) (http_response_code() ?: 200);
            // the external referrer and the UTM tags belong to the session's landing hit
            $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $ref_host = strtolower((string) (parse_url($ref, PHP_URL_HOST) ?: ''));
            if ($ref_host !== '' && $ref_host !== $host && preg_replace('/^www\./', '', $ref_host) !== preg_replace('/^www\./', '', $host)) {
                $row['referrer_host'] = mb_substr(preg_replace('/^www\./', '', $ref_host), 0, 100);
                $row['referrer']      = mb_substr($ref, 0, 255);
            }
            foreach (array('utm_source', 'utm_medium', 'utm_campaign') as $k) { $row[$k] = mb_substr(trim((string) ($_GET[$k] ?? '')), 0, $k === 'utm_campaign' ? 150 : 100); }
            $row['is_landing'] = self::$new_session ? 1 : 0;
            $row['is_new']     = self::$new_visitor ? 1 : 0;
            $row['landing']    = self::$new_session ? mb_substr($path, 0, 255) : self::session_landing();
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $row += self::ua($ua);
            $row['country'] = self::country();
            $row['creator_id'] = (int) self::$creator_id;
            (new SiteAnalyticsModel())->record($row);
            if (random_int(1, 1000) === 1) { (new SiteAnalyticsModel())->purge(self::KEEP_DAYS); }
        } catch (\Throwable $e) { error_log('[analytics] pageview: ' . $e->getMessage()); }
    }

    /**
     * A product or money event. $opts: user_id (default: the signed-in account), creator_id, value_cents, meta (short text),
     * path. From a web request it carries the visitor and session, so a signup or a sale ties back to the landing page and source.
     */
    public static function event(string $name, array $opts = array()): void {
        try {
            if (php_sapi_name() !== 'cli') { self::begin(); }
            $row = self::base_row('event', $name);
            if (isset($opts['user_id'])) { $row['user_id'] = (int) $opts['user_id']; }
            $row['creator_id']  = (int) ($opts['creator_id'] ?? 0);
            $row['value_cents'] = (int) ($opts['value_cents'] ?? 0);
            $row['meta']        = mb_substr((string) ($opts['meta'] ?? ''), 0, 255);
            $row['path']        = mb_substr((string) ($opts['path'] ?? (php_sapi_name() === 'cli' ? '' : (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''))), 0, 255);
            $row['host']        = php_sapi_name() === 'cli' ? '' : mb_substr(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), 0, 100);
            if (php_sapi_name() !== 'cli') { $row += self::ua((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')); $row['country'] = self::country(); }
            (new SiteAnalyticsModel())->record($row);
        } catch (\Throwable $e) { error_log('[analytics] event ' . $name . ': ' . $e->getMessage()); }
    }

    private static function base_row(string $kind, string $name): array {
        $uid = 0;
        try { if (class_exists('Session') && php_sapi_name() !== 'cli') { $uid = (int) Session::get('user_id'); } } catch (\Throwable $e) {}
        return array('ts' => gmdate('Y-m-d H:i:s'), 'kind' => $kind, 'name' => mb_substr($name, 0, 40), 'visitor' => self::$visitor, 'session' => self::$session, 'user_id' => $uid);
    }

    /** The landing path of the current session (the session's first row), '' when unknown. */
    private static function session_landing(): string {
        if (self::$session === '') { return ''; }
        try { return (new SiteAnalyticsModel())->session_landing(self::$session); } catch (\Throwable $e) { return ''; }
    }

    /** Device class, browser family and OS from the user agent: enough for a dashboard, never a fingerprint. */
    public static function ua(string $ua): array {
        $device = 'desktop';
        if (preg_match('/iPad|Tablet|Kindle|Silk|PlayBook/i', $ua) || (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua))) { $device = 'tablet'; }
        elseif (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|IEMobile|Opera Mini/i', $ua)) { $device = 'mobile'; }
        if (preg_match('/Edg\//', $ua)) { $browser = 'Edge'; }
        elseif (preg_match('/OPR\/|Opera/', $ua)) { $browser = 'Opera'; }
        elseif (preg_match('/SamsungBrowser/', $ua)) { $browser = 'Samsung'; }
        elseif (preg_match('/Firefox\/|FxiOS/', $ua)) { $browser = 'Firefox'; }
        elseif (preg_match('/CriOS|Chrome\//', $ua)) { $browser = 'Chrome'; }
        elseif (preg_match('/Safari\//', $ua) && preg_match('/Version\//', $ua)) { $browser = 'Safari'; }
        elseif (preg_match('/MSIE|Trident/', $ua)) { $browser = 'IE'; }
        else { $browser = $ua === '' ? '' : 'Other'; }
        if (preg_match('/iPhone|iPad|iPod/', $ua)) { $os = 'iOS'; }
        elseif (preg_match('/Android/', $ua)) { $os = 'Android'; }
        elseif (preg_match('/Windows/', $ua)) { $os = 'Windows'; }
        elseif (preg_match('/Mac OS X|Macintosh/', $ua)) { $os = 'macOS'; }
        elseif (preg_match('/CrOS/', $ua)) { $os = 'ChromeOS'; }
        elseif (preg_match('/Linux/', $ua)) { $os = 'Linux'; }
        else { $os = ''; }
        return array('device' => $device, 'browser' => $browser, 'os' => $os);
    }

    /** Two-letter country from an edge header (CloudFront, Cloudflare, or one the load balancer adds), '' when none. */
    public static function country(): string {
        foreach (array('HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_COUNTRY') as $h) {
            $v = strtoupper(trim((string) ($_SERVER[$h] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $v) && $v !== 'XX') { return $v; }
        }
        return '';
    }
}
