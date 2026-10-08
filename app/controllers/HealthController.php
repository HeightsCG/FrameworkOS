<?php
/**
 * GET /health for an uptime monitor: {"ok":true,"db":true,"queue_lag_s":N,"time":"<utc iso>"}, 503 with ok:false
 * when the database does not answer. Public, cheap and side-effect free: it does not extend Controller (no presence
 * touch, no session checks), and the session Bootstrap opened is thrown away unsaved.
 */
class HealthController {

    public function indexAction(){
        if (!isset($_COOKIE[session_name()]) && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();   // a monitor has no cookie: drop the empty session file and its cookie
            header_remove('Set-Cookie');
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();   // a signed-in browser: leave its session exactly as it was
        }

        $db = false; $lag = 0;
        try {
            $m   = new HealthModel();
            $db  = $m->db_ok();
            $lag = $m->queue_lag_s();
        } catch (\Throwable $e) {
            error_log('[health] ' . $e->getMessage());
        }

        http_response_code($db ? 200 : 503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo json_encode(array('ok' => $db, 'db' => $db, 'queue_lag_s' => $lag, 'time' => gmdate('Y-m-d\TH:i:s\Z')));
    }
}
