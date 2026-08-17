<?php
class Bootstrap
{

    public function __construct()
    {
        $this->start_app();
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

        // Public creator profiles live at /@handle. The "@" namespaces them away
        // from real app routes, so intercept before normal controller resolution.
        $url = Main::get_url();
        if (isset($url[0][0]) && $url[0][0] === '@') {
            (new ProfileController())->viewAction();
            return;
        }

        // Outbound link click-through: /go/<id> records the click then redirects.
        if (isset($url[0]) && $url[0] === 'go') {
            (new GoController())->indexAction();
            return;
        }

        // Remote MCP connector: /mcp and /mcp/<token> both dispatch to the same
        // handler (the trailing segment is an auth-token fallback, not a method).
        if (isset($url[0]) && $url[0] === 'mcp') {
            (new McpController())->indexAction();
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
