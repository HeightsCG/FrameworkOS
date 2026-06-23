<?php
class Bootstrap
{

    public function __construct()
    {
        $this->start_app();
    }

    public function start_app()
    {
        date_default_timezone_set(Main::config('global', 'timezone', 'UTC'));
        $env = Main::get_environment();
        error_reporting(E_ALL);
        ini_set('log_errors', '1');
        ini_set('display_errors', $env === 'development' ? '1' : '0');
        if ($env !== 'development') {
            set_exception_handler(function (\Throwable $e) {
                error_log('[uncaught] ' . $e);
                if (!headers_sent()) {
                    http_response_code(500);
                }
                echo 'Internal Server Error';
            });
        }
        $config = Main::get_config();
        $domain = $config[$env]['domain'];
        $reqHost = isset($_SERVER['HTTP_HOST'])
            ? strtolower(preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']))
            : '';
        if ($reqHost !== '' && ($reqHost === $domain || substr($reqHost, -strlen('.' . $domain)) === '.' . $domain)) {
            ini_set('session.cookie_domain', $reqHost);
        } else {
            ini_set('session.cookie_domain', '.' . $domain);
        }
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure',   '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        Session::init();
        $c = Main::controller_name();
        $m = Main::method_name();
        if (!class_exists($c)) {
            Errors::page_not_found();
            return;
        }
        $co = new $c();
        $action = strtolower(preg_replace('/action$/i', '', $m));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $csrfExempt = (isset($co->csrfExempt) && is_array($co->csrfExempt)) ? $co->csrfExempt : array();
            if (!in_array($action, $csrfExempt, true) && !CSRF::validate()) {
                Errors::access_denied();
                return;
            }
        }

        if (method_exists($c, $m)) {
            $co->$m();
        } else {
            Errors::page_not_found();
        }
    }
}
