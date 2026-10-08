<?php
/**
 * Settings an admin changes on /admin, kept in app_settings (k => v). Callers pass their own default,
 * so a key nobody has saved yet reads as that default.
 */
class AdminSettingsModel extends Model {

    private static $cache = array();

    public function __construct(){
        parent::__construct();
    }

    /** The saved value for $key, or $default when it was never saved (cached per request). */
    public function get($key, $default = ''){
        $key = (string) $key;
        if (!array_key_exists($key, self::$cache)) {
            $rows = parent::select("SELECT v FROM app_settings WHERE k = :k", array('k' => $key));
            self::$cache[$key] = (is_array($rows) && count($rows) === 1) ? (string) $rows[0]['v'] : null;
        }
        return self::$cache[$key] === null ? (string) $default : self::$cache[$key];
    }

    public function set($key, $value){
        self::$cache[(string) $key] = (string) $value;
        return parent::sql(
            "INSERT INTO app_settings (k, v, updated_at) VALUES (:k, :v, :t)
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)",
            array(':k' => (string) $key, ':v' => (string) $value, ':t' => date('Y-m-d H:i:s')));
    }
}
