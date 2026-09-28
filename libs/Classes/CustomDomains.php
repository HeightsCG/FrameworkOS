<?php
/**
 * Creator custom domains (PRD §39): lexivaughn.com serves @lexivaughn's profile.
 * Ported from VIP's location domains. Traffic arrives through the Caddy gateway (on-demand TLS), which
 * forwards to the platform with the visitor's hostname in X-Forwarded-Host; that header is believed only
 * from a trusted proxy (Controller::trusted_proxies), so nobody can pick a tenant by spoofing it.
 *
 * DNS a creator adds (see records()):
 *   TXT   _cls-verify.<root or subdomain>  = token            ownership
 *   ALIAS lexivaughn.com                   = domain_gateway      routing, bare domain (ANAME / CNAME flattening)
 *   CNAME www.lexivaughn.com / sub.x.com   = domain_gateway      routing, subdomains
 * Like VIP, no IP is ever published: domain_gateway (gateway.creatorlinkstudio.com) CNAMEs to the Caddy gateway.
 * A bare domain is added together with its www twin; the one the creator picks is primary, the other 301s to it.
 */
class CustomDomains {

    const TXT_PREFIX = '_cls-verify.';

    /** Profile sub-pages a custom domain serves (/events/<id>, /services/<id>); everything else is the platform's. */
    const PROFILE_PATHS = array('events', 'services');

    /** Routes that work on a custom domain as-is (AJAX, link clicks, email images, crawlers, the handoff). */
    const PASSTHROUGH = array('api', 'go', 'mail-image', 'robots.txt', 'sitemap.xml', '_handoff');

    private static $current = false;   // memo: false = not resolved yet, null = not a custom domain

    /* ---- hostnames ---- */

    /** 'https://LexiVaughn.com/about/' -> 'lexivaughn.com'. */
    public static function normalize($raw): string {
        $h = strtolower(trim((string) $raw));
        $h = preg_replace('#^[a-z]+://#', '', $h);
        $h = preg_replace('#[/?\#].*$#', '', $h);
        $h = preg_replace('/:\d+$/', '', $h);
        return rtrim($h, '.');
    }

    public static function valid($h): bool {
        return strlen($h) <= 253 && (bool) preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h);
    }

    /** Two labels = a bare (apex) domain. Like VIP; a co.uk-style domain counts as a subdomain and gets a CNAME. */
    public static function root($host): string {
        $labels = explode('.', $host);
        return (count($labels) <= 2) ? $host : implode('.', array_slice($labels, -2));
    }

    public static function is_apex($host): bool {
        return self::root($host) === $host;
    }

    /** The platform's own domains and the gateway can never be claimed. */
    public static function is_reserved($host): bool {
        foreach (self::platform_hosts() as $blocked) {
            $blocked = self::root($blocked);
            if ($host === $blocked || substr($host, -strlen('.' . $blocked)) === '.' . $blocked) { return true; }
        }
        return false;
    }

    private static function platform_hosts(): array {
        $cfg = Main::get_config();
        $env = Main::get_environment();
        $out = array();
        foreach (array($cfg[$env]['canonical_host'] ?? '', $cfg[$env]['domain'] ?? '', $cfg['global']['public_domain'] ?? '', self::gateway_host()) as $h) {
            $h = strtolower(trim((string) $h));
            if ($h !== '') { $out[] = $h; }
        }
        return array_unique($out);
    }

    public static function is_platform_host($host): bool {
        foreach (self::platform_hosts() as $ok) {
            if ($host === $ok || substr($host, -strlen('.' . $ok)) === '.' . $ok) { return true; }
        }
        return false;
    }

    /** Where creators point a CNAME (config [global] domain_gateway). */
    public static function gateway_host(): string {
        $cfg = Main::get_config();
        return strtolower(trim((string) ($cfg['global']['domain_gateway'] ?? '')));
    }

    /* ---- DNS ---- */

    /** The TXT record name that proves ownership. A bare domain and its www twin share the root's. */
    public static function txt_name(array $row): string {
        $host = (string) $row['hostname'];
        $root = self::root($host);
        return self::TXT_PREFIX . (($host === $root || $host === 'www.' . $root) ? $root : $host);
    }

    /** A record name as a registrar's Host field wants it: '@', 'www', '_cls-verify'. */
    public static function relative($name, $root): string {
        if ($name === $root) { return '@'; }
        $suffix = '.' . $root;
        return (substr($name, -strlen($suffix)) === $suffix) ? substr($name, 0, -strlen($suffix)) : $name;
    }

    /** The DNS records to show for a creator's domains (deduplicated: a bare domain + www share one TXT). */
    public static function records(array $rows): array {
        $out = array();
        foreach ($rows as $row) {
            $host = (string) $row['hostname'];
            $root = self::root($host);
            $done = in_array($row['status'], array('verified', 'active'), true);
            $txt  = self::txt_name($row);
            $out[$txt] = array('type' => 'TXT', 'host' => self::relative($txt, $root), 'value' => (string) $row['verification_token'], 'done' => $done);
            if ($row['host_type'] === 'apex') {
                $out[$host] = array('type' => 'ALIAS', 'host' => '@', 'value' => self::gateway_host(), 'done' => $row['status'] === 'active');
            } else {
                $out[$host] = array('type' => 'CNAME', 'host' => self::relative($host, $root), 'value' => self::gateway_host(), 'done' => $row['status'] === 'active');
            }
        }
        return array_values($out);
    }

    /**
     * Check a domain's DNS. Ownership (TXT) is required; routing to the gateway makes it active.
     * Returns ['status' => 'failed'|'verified'|'active', 'error' => ?string].
     */
    public static function verify(array $row): array {
        $host = (string) $row['hostname'];
        $name = self::txt_name($row);
        $owned = false;
        foreach ((array) @dns_get_record($name, DNS_TXT) as $rec) {
            if (trim((string) ($rec['txt'] ?? '')) === (string) $row['verification_token']) { $owned = true; break; }
        }
        if (!$owned) { return array('status' => 'failed', 'error' => 'TXT record ' . $name . ' not found yet'); }

        $gw_host = self::gateway_host();
        foreach ((array) @dns_get_record($host, DNS_CNAME) as $rec) {
            if ($gw_host !== '' && strtolower(rtrim((string) ($rec['target'] ?? ''), '.')) === $gw_host) { return array('status' => 'active', 'error' => null); }
        }
        // A bare domain's ALIAS / flattened CNAME shows up as A records: they must be the gateway's addresses.
        $want = ($gw_host !== '') ? (array) @gethostbynamel($gw_host) : array();
        foreach ((array) @dns_get_record($host, DNS_A) as $rec) {
            if (in_array((string) ($rec['ip'] ?? ''), $want, true)) { return array('status' => 'active', 'error' => null); }
        }
        return array('status' => 'verified', 'error' => $host . ' does not point at ' . $gw_host . ' yet');
    }

    /** May this creator have a custom domain? (Studio plan.) */
    public static function allowed(array $user): bool {
        return Plan::has_feature($user, 'custom_domain');
    }

    /* ---- the current request ---- */

    /**
     * The hostname the visitor typed. Behind the gateway it's X-Forwarded-Host, believed only from a trusted proxy
     * AND, when [global] domain_gateway_secret is set, only with the X-CLS-Gateway header Caddy adds. The secret
     * matters in production: the gateway reaches the app through the load balancer, which is itself a trusted
     * proxy, so the IP check alone would also pass a forged header sent straight to the load balancer.
     */
    public static function request_host(): string {
        $host = '';
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST']) && self::gateway_signed() && $remote !== '' && Controller::ip_in_list($remote, Controller::trusted_proxies())) {
            $host = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
        } elseif (isset($_SERVER['HTTP_HOST'])) {
            $host = (string) $_SERVER['HTTP_HOST'];
        }
        return strtolower(rtrim(preg_replace('/:\d+$/', '', $host), '.'));
    }

    /** Did the request carry the gateway's shared secret? (Always true while [global] domain_gateway_secret is unset, i.e. dev.) */
    public static function gateway_signed(): bool {
        $cfg    = Main::get_config();
        $secret = trim((string) ($cfg['global']['domain_gateway_secret'] ?? ''));
        return $secret === '' || hash_equals($secret, (string) ($_SERVER['HTTP_X_CLS_GATEWAY'] ?? ''));
    }

    /**
     * The custom domain this request is on: the creator_domains row plus u_name, or null (platform host,
     * unknown host, suspended creator, or a plan without custom domains). Memoized per request.
     */
    public static function current() {
        if (self::$current !== false) { return self::$current; }
        self::$current = null;
        if (php_sapi_name() === 'cli') { return null; }
        self::$current = self::live(self::request_host());
        return self::$current;
    }

    /** A hostname's live domain row (plus u_name) when its creator is active and on a plan with custom domains, else null. */
    public static function live($host) {
        if ($host === '' || self::is_platform_host($host)) { return null; }
        $row = (new CreatorDomainsModel())->get_live_by_hostname($host);
        if (!$row) { return null; }
        $users = (new UsersModel())->get_user_by_id((int) $row['user_id']);
        return (is_array($users) && count($users) === 1 && self::allowed($users[0])) ? $row : null;
    }

    /** Is this request on $handle's own domain? */
    public static function is_home_of($handle): bool {
        $d = self::current();
        return $d !== null && strtolower((string) $d['u_name']) === strtolower((string) $handle);
    }

    /** 'https://lexivaughn.com' for the current custom domain. */
    public static function current_base(): string {
        $d = self::current();
        return $d ? 'https://' . $d['hostname'] : '';
    }

    /** Base URL of the platform itself (sign-in, settings, wallet), whichever host this request is on. */
    public static function platform_base(): string {
        return Main::get_base_domain();
    }

    /** Path prefix for a creator's profile links on this page: '' on their own domain, '/@handle' elsewhere. */
    public static function profile_path($handle): string {
        return self::is_home_of($handle) ? '' : '/@' . rawurlencode((string) $handle);
    }

    /** Absolute profile URL for links that leave the page (Stripe return, share): their domain when we're on it. */
    public static function profile_url_here($handle): string {
        return self::is_home_of($handle) ? self::current_base() . '/' : self::platform_base() . '/@' . rawurlencode((string) $handle);
    }

    /**
     * Canonical profile URL for SEO: the creator's active primary domain when they have one (and it's on their
     * plan), else the platform's /@handle.
     */
    public static function canonical_profile_url(array $user): string {
        $d = self::allowed($user) ? (new CreatorDomainsModel())->primary_for_user((int) $user['user_id']) : null;
        return $d ? 'https://' . $d['hostname'] . '/' : Main::get_base_domain() . '/@' . rawurlencode((string) $user['u_name']);
    }

    /** Sign-in link on a custom domain: the platform signs them in, then hands the session back to $path here. */
    public static function login_url($path = '/'): string {
        $d = self::current();
        if (!$d) { return '/'; }
        return self::platform_base() . '/account/domain_login?host=' . rawurlencode((string) $d['hostname']) . '&path=' . rawurlencode((string) $path);
    }

    /** URL that signs $user_id in on $host and lands on $path (one-time, 60 s). */
    public static function handoff_url($user_id, $host, $base, $path): string {
        $t = (new CreatorDomainsModel())->create_handoff((int) $user_id, $host, $path);
        return rtrim($base, '/') . '/_handoff?t=' . $t;
    }

    /** A same-site path only ('/events/5?x=1'), never '//evil.com' or 'https://…'. */
    public static function safe_path($p): string {
        $p = (string) $p;
        return ($p !== '' && $p[0] === '/' && (strlen($p) < 2 || ($p[1] !== '/' && $p[1] !== '\\'))) ? $p : '/';
    }
}
