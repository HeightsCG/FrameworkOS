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
        $this->enforce_password_change();
    }

    /**
     * Force-password-change gate. A user flagged reset_pw=1 must set a new
     * password before anything else: page controllers redirect to the force-reset
     * page, the API returns an error. Only the force-reset page, the
     * change-password endpoint, and logout are exempt.
     */
    private function enforce_password_change(): void
    {
        if (((int) Session::get('user_id')) < 1 || (int) Session::get('reset_pw') !== 1) {
            return;
        }
        $route = $this->controller . '.' . $this->method;
        $allowed = array('account.force_reset', 'api.change_password', 'logout.index');
        if (in_array($route, $allowed, true)) {
            return;
        }
        if ($this->controller === 'api') {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(array('success' => false, 'message' => 'You must change your password before continuing.'));
            exit;
        }
        header('Location: /account/force_reset');
        exit;
    }

    /**
     * Normalizes POST input to valid UTF-8 WITHOUT changing its meaning.
     *
     * Deliberately does not HTML-encode here: encoding at the input boundary
     * corrupts round-tripped data (passwords, JSON, tokens) and provides no SQL
     * protection. Escape on OUTPUT instead (htmlspecialchars in templates) and
     * use parameterized queries for SQL. Arrays are walked recursively so nested
     * values are normalized too.
     */
    public static function clean_post_data(){
        return self::normalize_utf8($_POST ?? array());
    }

    private static function normalize_utf8($data){
        if (is_array($data)) {
            $out = array();
            foreach ($data as $key => $value) {
                $out[$key] = self::normalize_utf8($value);
            }
            return $out;
        }
        if (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        return $data;
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
