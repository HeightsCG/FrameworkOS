<?php
class Controller {

    public $protected;
    public $view;
    public $env;
    public $controller;
    public $method;
    public $post;

    public function __construct() {
        $this->env = $this->get_environment();
        $this->controller = preg_replace('/controller/', '', strtolower(Main::controller_name()));
        $this->method = preg_replace('/action/', '', strtolower(Main::method_name()));
        $this->view = new View($this->protected);
        $this->post = self::clean_post_data();
        $this->touch_presence();
    }

    /**
     * Keep a logged-in user's presence ("online") fresh on any page load or AJAX call,
     * so browsing anywhere — not just the Studio heartbeat — counts as being active.
     * Throttled to once / 45s via a session timestamp so it never adds a read query.
     */
    private function touch_presence(){
        $uid = (int) Session::get('user_id');
        if ($uid <= 0) { return; }
        $now  = time();
        $last = (int) Session::get('presence_touch_at');
        if ($now - $last >= 45) {
            (new UsersModel())->touch_last_active($uid);
            Session::set('presence_touch_at', $now);
        }
    }

    public static function clean_post_data(){
        if (isset($_POST)) {
            $post = array();
            foreach ($_POST as $key => $value) {
                if (is_array($value)) {
                    $post[$key] = $value;
                } else {
                    $data = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                    $post[$key] = htmlentities($data, ENT_QUOTES, 'UTF-8');
                }
            }
            return $post;
        }
    }

    public static function get_environment(): string
    {
        return getenv('APPLICATION_ENV');
    }

    public function get_ip_address(): string
    {
        $candidates = array(
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_CLIENT_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        );
        foreach ($candidates as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            if (strpos($raw, ',') !== false) {
                $raw = trim(explode(',', $raw, 2)[0]);
            }
            if (filter_var($raw, FILTER_VALIDATE_IP) !== false) {
                return $raw;
            }
        }
        return '';
    }

    public function get_user_agent(): string
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    }

    public function get_host_from_ip(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $host = @gethostbyaddr($ip);
        if ($host === false || $host === '' || $host === $ip) {
            return $ip;
        }
        return (string) $host;
    }

}
