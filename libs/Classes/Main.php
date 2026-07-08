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
        $host = $_SERVER['HTTP_HOST'] ?? self::config('development', 'domain');
        return self::site_protocol() . $host;
    }

}
