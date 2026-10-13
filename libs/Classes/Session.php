<?php
class Session {

    public function __construct(){
        if(session_id() == '' || !isset($_SESSION)) {
            session_start();
	    }
    }

    public static function init(){
        session_start();
    }

    /**
     * Cache-Control for a public page: shared caches may keep it for a signed-out visitor who got no cookie in this response;
     * anyone with a session, or a response that sets a cookie, stays private. Safe to call again later in the request.
     */
    public static function cache_header(int $seconds = 300): void {
        if (headers_sent()) { return; }
        $cookie = false; foreach (headers_list() as $h) { if (stripos($h, 'Set-Cookie:') === 0) { $cookie = true; break; } }
        header('Cache-Control: ' . ((session_status() !== PHP_SESSION_ACTIVE && !$cookie) ? 'public' : 'private') . ', max-age=' . $seconds);
    }

    public static function set($key, $value){
        $_SESSION[$key] = $value;
    }

    public static function get($key){
        return $_SESSION[$key] ?? null;
    }

    public static function has($key){
        return isset($_SESSION[$key]);
    }

    public static function destroy(){
        if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = array(); return; }   // nothing was started (a public page read without a session)
        session_unset();
        session_destroy();
    }

    public static function destroyValue($value){
        unset($_SESSION[$value]);
    }

}
