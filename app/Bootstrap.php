<?php
class Bootstrap
{

    public function __construct()
    {
        $this->start_app();
    }

    /**
     * 301 to the canonical https://www host when the request came in on another one.
     * Skipped on CLI, on dev/local hosts, and for /api and /mcp (machine callers follow their own URL).
     */
    private function canonical_host(array $config, string $env): void
    {
        if (php_sapi_name() === 'cli') { return; }
        $canonical = trim((string) ($config[$env]['canonical_host'] ?? ''));
        if ($canonical === '') { return; }   // unset (dev) = leave every host alone

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') { return; }
        // force_https counts as proof: behind a proxy that strips the protocol headers, redirecting
        // an already-https request to itself would loop forever.
        $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || !empty($config[$env]['force_https']) || !empty($config['global']['force_https']);
        if ($host === strtolower($canonical) && $https) { return; }

        $first = strtolower((string) (Main::get_url()[0] ?? ''));
        if ($first === 'api' || $first === 'mcp') { return; }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: https://' . $canonical . $uri, true, 301);
        exit;
    }

    public function start_app()
    {
        // Store everything in UTC; display layers convert to the viewer's timezone.
        // (The CLI scheduler already runs in UTC, and compute_next_run/gmdate are UTC,
        // so this aligns the web context with the rest of the app.)
        date_default_timezone_set('UTC');
        $env = Main::get_environment();
        $config = Main::get_config();
        $domain = $config[$env]['domain'];
        // Only flag the session cookie "secure" on real HTTPS. Over plain HTTP a
        // secure cookie is never stored by the browser, so the session never
        // persists and logins don't stick. Stays locked down in production (HTTPS).
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.cookie_domain', '.' . $domain);
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure',   $isHttps ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        Session::init();

        // One canonical host: everything answers on https://www.<domain>. Anything else 301s there once,
        // so links, sitemap URLs and Search Console all agree. Done here rather than in .htaccess:
        // no server config to get wrong, and it is testable on dev (where it stays off).
        $this->canonical_host($config, $env);

        // Public creator profiles live at /@handle. The "@" namespaces them away
        // from real app routes, so intercept before normal controller resolution.
        $url = Main::get_url();
        if (isset($url[0][0]) && $url[0][0] === '@') {
            (new ProfileController())->viewAction();
            return;
        }

        // Crawler endpoints: generated so absolute URLs match the served host.
        $crawl = array('robots.txt' => 'robotsAction', 'sitemap.xml' => 'sitemapAction', 'llms.txt' => 'llmsAction', 'llms-full.txt' => 'llmsFullAction');
        if (isset($url[0]) && isset($crawl[$url[0]])) {
            (new SeoController())->{$crawl[$url[0]]}();
            return;
        }

        // Public product pages (URLs don't fit /controller/action): see PagesController::ROUTES.
        if (isset($url[0]) && isset(PagesController::ROUTES[$url[0]])) {
            $method = PagesController::ROUTES[$url[0]] . 'Action';
            (new PagesController())->$method();
            return;
        }

        // Public blog (SEO content engine): /blog, /blog/<slug>, /blog/feed.xml.
        if (isset($url[0]) && $url[0] === 'blog') {
            (new BlogController())->dispatch($url);
            return;
        }

        // Outbound link click-through: /go/<id> records the click then redirects.
        if (isset($url[0]) && $url[0] === 'go') {
            header('X-Robots-Tag: noindex');
            (new GoController())->indexAction();
            return;
        }

        // Picture in a "new post" email: /mail-image/<post_id> (public; decides what may be shown on every open).
        if (isset($url[0]) && $url[0] === 'mail-image') {
            header('X-Robots-Tag: noindex');
            (new MailImageController())->indexAction();
            return;
        }

        // Remote MCP connector: /mcp and /mcp/<token> both dispatch to the same
        // handler (the trailing segment is an auth-token fallback, not a method).
        if (isset($url[0]) && $url[0] === 'mcp') {
            header('X-Robots-Tag: noindex');
            (new McpController())->indexAction();
            return;
        }

        // JSON API: /api/<action>. The action name picks the Api*Controller (see ApiRoutes);
        // URLs and action names are unchanged from the old monolithic ApiController.
        if (isset($url[0]) && $url[0] === 'api') {
            header('X-Robots-Tag: noindex');
            $action = isset($url[1]) ? strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $url[1])) : '';
            $class  = ($action !== '') ? ApiRoutes::controller_for($action) : null;
            $method = $action . 'Action';
            if ($class !== null && class_exists($class) && method_exists($class, $method)) {
                (new $class())->$method();
            } else {
                Errors::page_not_found();
            }
            return;
        }

        $c = Main::controller_name();
        $m = Main::method_name();
        if (class_exists($c)) {
            $co = new $c();
            if (method_exists($c, $m)) {
                $co->$m();
            } else {
                Errors::page_not_found();
            }
        } else {
            Errors::page_not_found();
        }
    }
}
