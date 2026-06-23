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

    public static function set($key, $value){
        $_SESSION[$key] = $value;
    }

    public static function get($key){
        // Returns null (not 0) when unset, so callers can distinguish "missing"
        // from a legitimate 0/"0" value. Coerce explicitly where you need an int.
        return $_SESSION[$key] ?? null;
    }

    public static function has($key){
        return isset($_SESSION[$key]);
    }

    /**
     * Regenerate the session ID while preserving session data. Call on every
     * privilege change (login, logout, role change) to prevent session fixation.
     */
    public static function regenerate(){
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(){
        session_unset();
        session_destroy();
    }

    public static function destroyValue($value){
        unset($_SESSION[$value]);
    }

}
