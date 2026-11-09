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

    /** First URL segments the router answers itself (before any controller lookup) that keep their session. */
    const SESSION_ROUTES = array('api', 'mcp', 'go', 'mail-image', 'csrf-token', 'a');
    /** First URL segments that are public reading pages besides PagesController::ROUTES (the home page is ''). */
    const PUBLIC_READ = array('', 'blog', 'tools', 'robots.txt', 'sitemap.xml', 'llms.txt', 'llms-full.txt', '.well-known');

    /**
     * True for a GET or HEAD of a public reading page from a visitor with no session cookie, on the platform host. Those
     * requests run without a session (Session::get() answers null for everything, as it does for any signed-out visitor).
     */
    private static function anonymous_public_get(): bool
    {
        if (!in_array((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), array('GET', 'HEAD'), true)) { return false; }
        if (isset($_COOKIE[session_name()])) { return false; }
        $first = (string) (Main::get_url()[0] ?? '');
        if (substr($first, -3) === '.md') { return true; }   // the Markdown copy of a public page
        if (in_array($first, self::PUBLIC_READ, true) || isset(PagesController::ROUTES[$first])) { return true; }
        if ($first[0] === '@') { return true; }   // a creator's public profile, read signed out
        // a path no route answers ends in the 404 page, which needs no session either
        return !in_array($first, self::SESSION_ROUTES, true) && !class_exists(Main::controller_name());
    }

    public function start_app()
    {
        // Store everything in UTC; display layers convert to the viewer's timezone.
        // (The CLI scheduler already runs in UTC, and compute_next_run/gmdate are UTC,
        // so this aligns the web context with the rest of the app.)
        date_default_timezone_set('UTC');
        $env = Main::get_environment();
        SecurityHeaders::send();   // HSTS (https only), nosniff, Referrer-Policy, X-Frame-Options, report-only CSP; app.ini security_headers = off turns it off
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
        // A signed-out visitor reading a public page gets no session: no cookie, no no-store header, so the page is cacheable
        // (SEO audit 2026-10-12). The session starts when it is needed: any request that already carries the cookie, any POST,
        // any app route, and /csrf-token, which the sign-in dialog and the public forms call when they open.
        // The page-view beacon a cacheable page sends after load: it sets the two analytics cookies and records the view. No session.
        if (implode('/', array_slice(Main::get_url(), 0, 2)) === ltrim(Analytics::BEACON_PATH, '/')) { Analytics::beacon(); return; }
        if (self::anonymous_public_get()) {
            Analytics::defer();   // no cookie on this response: the page reports its own view after load
        } else {
            Analytics::begin();   // first-party analytics cookies (visitor + session), before any output; crawlers and the CLI are skipped
            Session::init();
        }

        // A fan session once handed to a creator's own domain (custom domains were removed 2026-11-09) is never
        // honoured: dropped before anything reads it.
        if ((string) Session::get('domain_scope') !== '') {
            Session::destroy();
            Session::init();
            session_regenerate_id(true);
        }

        // One canonical host: everything answers on https://www.<domain>. Anything else 301s there once,
        // so links, sitemap URLs and Search Console all agree. Done here rather than in .htaccess:
        // no server config to get wrong, and it is testable on dev (where it stays off).
        $this->canonical_host($config, $env);

        // The request token for a page that was served without a session (above): the dialog fetches it when it opens.
        if ((Main::get_url()[0] ?? '') === 'csrf-token') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
            echo json_encode(array('csrf_token' => CSRF::token()));
            return;
        }

        // The Markdown copy of a public page (/pricing.md, /blog/<slug>.md, /index.md) and the MCP connector's resource metadata.
        $url = Main::get_url();
        if (!empty($url) && substr((string) end($url), -3) === '.md') {
            (new SeoController())->markdownAction($url);
            return;
        }
        // Public creator profiles live at /@handle. The "@" namespaces them away
        // from real app routes, so intercept before normal controller resolution.
        $url = Main::get_url();
        if (isset($url[0][0]) && $url[0][0] === '@') {
            (new ProfileController())->viewAction();
            return;
        }

        // HeyCatch short links: a single-character path (/a-/z, /0-/9) is a channel link. The query IS the
        // attribution (the SDK reads it on landing, then tidies the URL bar), so a bare redirect to / would lose it.
        // No real route is one character long. Done here, not in server config, for the same reason as canonical_host.
        if (HeyCatch::ENABLED && count($url) === 1 && preg_match('/^[a-z0-9]$/', $url[0])) {
            header('Location: /?utm_source=heycatch&utm_campaign=' . $url[0], true, 302);
            return;
        }

        // Crawler endpoints: generated so absolute URLs match the served host.
        $crawl = array('robots.txt' => 'robotsAction', 'sitemap.xml' => 'sitemapAction', 'llms.txt' => 'llmsAction', 'llms-full.txt' => 'llmsFullAction');
        if (isset($url[0]) && isset($crawl[$url[0]])) {
            (new SeoController())->{$crawl[$url[0]]}();
            return;
        }

        if (($url[0] ?? '') === '.well-known' && ($url[1] ?? '') === 'oauth-protected-resource') {
            (new SeoController())->protectedResourceAction();
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
        // Api* controllers answer only through /api/<action> above (and its checks); /Api<Name>/<action> is not a route.
        if (stripos($c, 'api') === 0 || stripos($c, 'baseapi') === 0) { Errors::page_not_found(); return; }
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
