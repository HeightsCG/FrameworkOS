<?php
class Main {

    public static function site_protocol(): string
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (!empty($_SERVER['SERVER_PORT'])) && $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    }

    public static function controller_name(): string
    {
        $url = self::get_url();
        if (isset($url[0]) && $url[0] !== '') {
            // Sanitize to a bare class-name segment (no path/namespace tricks).
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
        // Don't leak the config path to the client — log it server-side.
        error_log('Config file not found: '.$config_file);
        return array();
    }

    /**
     * Read a single config value: Main::config('global', 'site_name').
     * Returns $default when the section/key is absent. Use this instead of
     * hard-coding values that belong in app.ini.
     */
    public static function config(string $section, string $key, $default = null)
    {
        $config = self::get_config();
        return $config[$section][$key] ?? $default;
    }

    /** Application display name, sourced from app.ini ([global] site_name). */
    public static function site_name(): string
    {
        return (string) self::config('global', 'site_name', '');
    }

    public static function get_base_domain(): string
    {
        $host = isset($_SERVER['HTTP_HOST'])
            ? strtolower(preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']))
            : '';
        if ($host !== '' && self::is_allowed_host($host)) {
            return self::site_protocol() . $host;
        }
        // Host header missing or untrusted — fall back to the configured hostname.
        return self::get_public_hostname_for_env(self::get_environment());
    }

    /**
     * Host-header injection guard: only trust the request Host when it matches
     * the configured domain (or a subdomain of it). Anything else is rejected so
     * an attacker-controlled Host cannot poison generated links (e.g. the URLs
     * embedded in password-reset emails).
     */
    public static function is_allowed_host(string $host): bool
    {
        $config = self::get_config();
        $env    = self::get_environment();
        $domain = isset($config[$env]['domain']) ? strtolower(trim((string) $config[$env]['domain'])) : '';
        if ($domain === '') {
            return false;
        }
        return $host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain;
    }

    public static function get_public_hostname_for_env(string $env): string
    {
        $config = self::get_config();
        return self::site_protocol() . 'app.' . trim((string) $config[$env]['domain']);
    }

    public static function get_url(): array
    {
        $url = array();
        if (isset($_GET['url'])) {
            // Normalize: drop surrounding whitespace and leading/trailing slashes
            // so segment 0 is always the controller regardless of whether the
            // rewrite preserved a leading slash.
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
        // Server env var wins (deploy override), then app.ini [global] env, then
        // the safe (least-verbose) default so a misconfig never lands in debug.
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
    
}
