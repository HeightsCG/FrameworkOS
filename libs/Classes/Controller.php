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
            $post = [];
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

    /**
     * Client IP. REMOTE_ADDR is authoritative unless it belongs to a trusted proxy
     * ([global] trusted_proxies in app.ini: comma-separated IPs / CIDRs; when the key is
     * absent, loopback + RFC1918 ranges). Only then are proxy headers consulted, and
     * X-Forwarded-For is walked right-to-left skipping trusted hops, so a client can't
     * prepend a fake address to pick its own IP.
     */
    public function get_ip_address(): string
    {
        $remote  = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $trusted = self::trusted_proxies();
        if ($remote === '' || !self::ip_in_list($remote, $trusted)) {
            return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '';
        }
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $header) {
            $v = trim((string) ($_SERVER[$header] ?? ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP) !== false) {
                return $v;
            }
        }
        $hops = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($hops as $hop) {
            if ($hop === '' || filter_var($hop, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!self::ip_in_list($hop, $trusted)) {
                return $hop;
            }
        }
        return $remote;
    }

    private static function trusted_proxies(): array
    {
        $config = Main::get_config();
        $raw = $config['global']['trusted_proxies'] ?? null;
        if ($raw === null) {
            return ['127.0.0.0/8', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
        }
        $list = [];
        foreach (explode(',', (string) $raw) as $item) {
            $item = trim($item);
            if ($item !== '') { $list[] = $item; }
        }
        return $list;
    }

    /** Exact IP or CIDR match, IPv4 and IPv6 (binary compare via inet_pton). */
    private static function ip_in_list(string $ip, array $list): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) { return false; }
        foreach ($list as $entry) {
            if (strpos($entry, '/') === false) {
                $eb = @inet_pton($entry);
                if ($eb !== false && $eb === $bin) { return true; }
                continue;
            }
            [$net, $bits] = explode('/', $entry, 2);
            $nb   = @inet_pton($net);
            $bits = (int) $bits;
            if ($nb === false || strlen($nb) !== strlen($bin)) { continue; }
            $bytes = intdiv($bits, 8);
            $rem   = $bits % 8;
            if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) { continue; }
            if ($rem === 0) { return true; }
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($nb[$bytes]) & $mask)) { return true; }
        }
        return false;
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

    /**
     * JSON responders for the API controllers. Always HTTP 200 by default and NO Content-Type
     * header: the pages JSON.parse() the text themselves, and a JSON content type would make
     * jQuery pre-parse it and break them. Both exit.
     */
    protected function jsonSuccess(array $data = [], int $httpCode = 200): void {
        http_response_code($httpCode);
        echo json_encode(array_merge(['success' => true], $data));
        exit;
    }

    protected function jsonError(string $message, array $extra = [], int $httpCode = 200): void {
        http_response_code($httpCode);
        echo json_encode(array_merge(['success' => false, 'message' => $message], $extra));
        exit;
    }
}
