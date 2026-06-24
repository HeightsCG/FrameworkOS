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
        return $_SESSION[$key] ?? null;
    }

    public static function has($key){
        return isset($_SESSION[$key]);
    }

    public static function destroy(){
        session_unset();
        session_destroy();
    }

    public static function destroyValue($value){
        unset($_SESSION[$value]);
    }

}
