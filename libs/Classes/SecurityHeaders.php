<?php
/**
 * Security response headers, sent from PHP on every web response (Bootstrap::start_app, before any output), so no
 * server config changes: HSTS (only when the request reached the load balancer over https, X-Forwarded-Proto),
 * X-Content-Type-Options, Referrer-Policy, X-Frame-Options (only when nothing else sends one) and a REPORT-ONLY
 * Content-Security-Policy listing what the site loads today. Never enforcing, never a redirect.
 *
 * Off switch: app.ini `security_headers = off` (environment section or [global]). Skipped on the CLI (cron) and on the
 * webhook endpoints, which answer other servers, not browsers.
 */
class SecurityHeaders {

    const HSTS = 'max-age=31536000; includeSubDomains';

    /** Report-only policy: the hosts the pages load scripts, styles, fonts, images, media, frames and connections from (2026-10-11 inventory). */
    const CSP = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://kit.fontawesome.com https://ka-f.fontawesome.com https://js.stripe.com https://www.googletagmanager.com https://www.google-analytics.com https://www.clarity.ms https://esm.sh https://accounts.google.com https://apis.google.com https://www.google.com https://www.gstatic.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://kit.fontawesome.com https://ka-f.fontawesome.com https://accounts.google.com; font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://ka-f.fontawesome.com; img-src 'self' data: blob: https:; media-src 'self' blob: https:; connect-src 'self' https: wss:; frame-src 'self' https://js.stripe.com https://hooks.stripe.com https://checkout.stripe.com https://accounts.google.com https://www.youtube-nocookie.com https://verification.didit.me https://www.google.com; object-src 'none'; base-uri 'self'; form-action 'self' https://checkout.stripe.com https://accounts.google.com";

    /** Paths that answer machines, not browsers: no headers there. */
    const SKIP_PREFIXES = array('/webhook');

    private static $sent = false;

    public static function enabled(): bool {
        $cfg = Main::get_config(); $env = Main::get_environment();
        $v = $cfg[$env]['security_headers'] ?? ($cfg['global']['security_headers'] ?? 'on');
        return !in_array(strtolower(trim((string) $v)), array('off', '0', 'false', 'no'), true);
    }

    public static function send(): void {
        if (self::$sent || php_sapi_name() === 'cli' || headers_sent()) { return; }
        self::$sent = true;
        try {
            if (!self::enabled()) { return; }
            $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
            foreach (self::SKIP_PREFIXES as $p) { if (strpos($path, $p) === 0) { return; } }
            if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') { header('Strict-Transport-Security: ' . self::HSTS); }
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            // X-Frame-Options already comes from the web server on this stack (.htaccess "Header always append" on dev and prod); a
            // PHP copy on top produced two headers. PHP sends it only when app.ini says the server no longer does: security_headers_xfo = on.
            if (self::xfo_from_php() && !self::already('X-Frame-Options')) { header('X-Frame-Options: SAMEORIGIN'); }
            header('Content-Security-Policy-Report-Only: ' . self::CSP);
        } catch (\Throwable $e) { error_log('[security_headers] ' . $e->getMessage()); }
    }

    private static function xfo_from_php(): bool {
        $cfg = Main::get_config(); $env = Main::get_environment();
        $v = $cfg[$env]['security_headers_xfo'] ?? ($cfg['global']['security_headers_xfo'] ?? 'off');
        return in_array(strtolower(trim((string) $v)), array('on', '1', 'true', 'yes'), true);
    }

    /** Is a header with this name already queued by PHP (another layer, a controller)? */
    private static function already(string $name): bool {
        foreach (headers_list() as $h) { if (stripos($h, $name . ':') === 0) { return true; } }
        return false;
    }
}
