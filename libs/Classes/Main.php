<?php
class Main {

    /**
     * Scheme for absolute URLs (emails, MCP connector URL, redirects). In production
     * TLS is terminated by a proxy, so PHP itself sees a plain-http request — the
     * forwarded-protocol headers are what say the visitor is on https. `force_https = 1`
     * in app.ini (the environment section, or [global]) pins it regardless of headers.
     */
    public static function site_protocol(): string
    {
        $cfg = self::get_config();
        $env = self::get_environment();
        if (!empty($cfg[$env]['force_https']) || !empty($cfg['global']['force_https'])) {
            return 'https://';
        }
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https://';
        }
        if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return 'https://';
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return 'https://';
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return 'https://';
        }
        if (strpos((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false) {
            return 'https://';
        }
        return 'http://';
    }

    public static function controller_name(): string
    {
        $url = self::get_url();
        if (isset($url[0][0]) && $url[0][0] === '@') { return 'ProfileController'; }   // /@handle is a profile, never the controller its handle spells (@admin, @events…)
        if (isset($url[0]) && $url[0] !== '') {
            $segment = preg_replace('/[^A-Za-z0-9_]/', '', $url[0]);
            if ($segment !== '') {
                return ucfirst($segment).'Controller';
            }
        }
        return 'IndexController';
    }

    public static function method_name(): string
    {
        $url = self::get_url();
        if (isset($url[1]) && $url[1] !== '') {
            $segment = preg_replace('/[^A-Za-z0-9_]/', '', $url[1]);
            if ($segment !== '') {
                return strtolower($segment).'Action';
            }
        }
        return 'indexAction';
    }
    
    public static function get_config(): array
    {
        $config_file = self::config_path().'/app.ini';
        if (is_file($config_file)) {
            $parsed = parse_ini_file($config_file, true);
            return is_array($parsed) ? $parsed : array();
        }
        return array();
    }

    public static function config(string $section, string $key)
    {
        $config = self::get_config();
        return $config[$section][$key];
    }

    public static function site_name(): string
    {
        return self::config('global', 'site_name');
    }

    /** Public-facing brand domain for creator profile URLs (distinct from the infra host). */
    public static function public_domain(): string
    {
        return self::config('global', 'public_domain');
    }

    /** Platform's percentage cut of paid creator subscriptions (Stripe application fee). */
    public static function platform_fee_percent(): float
    {
        $config = self::get_config();
        return (float) ($config['global']['platform_fee_percent'] ?? 10);
    }

    /** Processing (merchant service) fee % added on top of a credit purchase. */
    public static function credit_fee_percent(): float
    {
        $config = self::get_config();
        return (float) ($config['global']['credit_fee_percent'] ?? 5);
    }

    public static function get_url(): array
    {
        $url = array();
        if (isset($_GET['url'])) {  
            $raw = trim((string) $_GET['url']);
            $raw = trim($raw, '/');
            if ($raw !== '') {
                $url = explode('/', $raw);
            }
        }
        return $url;
    }
    
    public static function app_path(): string
    {
        $current_path = dirname(__DIR__);
        return dirname($current_path);
    }

    public static function vendor_path(): string
    {
        return self::app_path() . '/vendor';
    }

    public static function config_path(): string
    {
        return self::app_path().'/app/config';
    }
    
    public static function lib_path(): string
    {
        return dirname(__DIR__);
    }
    
    public static function get_param($id){
        $value = 0;
        $url = self::get_url();
        if (in_array($id, $url)) {
            $key = array_search($id, $url) + 1;
            if (key_exists($key,$url)) {
                $value = $url[$key];
            }
        }
        return $value;
    }
    
    public static function get_environment(): string
    {
        $env = getenv('APPLICATION_ENV');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $env = (string) self::config('global', 'env', '');
        return ($env !== '') ? $env : 'production';
    }
    
    public static function do_logout(): void
    {
        Session::destroy();
    }

    public static function get_base_domain(): string
    {
        $cfg = self::get_config();
        $env = self::get_environment();
        // The Host header is client-controlled and these URLs go into emails (password reset,
        // verify, invites). Only use it when it is one of OUR configured hosts or a subdomain.
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($host !== '') {
            foreach (array($cfg[$env]['canonical_host'] ?? '', $cfg[$env]['domain'] ?? '', $cfg['global']['public_domain'] ?? '') as $ok) {
                $ok = strtolower(trim((string) $ok));
                if ($ok !== '' && ($host === $ok || substr($host, -strlen('.' . $ok)) === '.' . $ok)) {
                    return self::site_protocol() . (string) $_SERVER['HTTP_HOST'];
                }
            }
        }
        // No request (cron, queue worker) or an unknown Host: this environment's domain, else the public brand domain.
        $host = (string) ($cfg[$env]['domain'] ?? '');
        if ($host === '') { $host = (string) ($cfg['global']['public_domain'] ?? ''); }
        return (($env === 'development') ? self::site_protocol() : 'https://') . $host;
    }

}
