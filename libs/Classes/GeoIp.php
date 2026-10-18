<?php
/**
 * Country from the visitor's IP, resolved in PHP from the free DB-IP "IP to Country Lite" database (MMDB format,
 * https://db-ip.com, CC BY 4.0: the privacy policy carries the attribution line) in data/, read with maxmind-db/reader.
 * Only the two-letter country code is ever stored; the IP is used for the lookup and dropped. The file is refreshed
 * monthly by cron/geoip_refresh.php (no account, no key). Every failure (no file, unreadable file, private IP, no
 * match) answers '' and the dashboards show "Unknown".
 */
class GeoIp {

    const DB_FILE = 'data/dbip-country-lite.mmdb';

    private static $reader = null;
    private static $tried = false;

    public static function db_path(): string { return rtrim(Main::app_path(), '/') . '/' . self::DB_FILE; }

    /** The visitor's public IP (Controller::request_ip: REMOTE_ADDR, or behind a trusted proxy the right-most untrusted X-Forwarded-For hop), else ''. */
    public static function client_ip(): string {
        $ip = Controller::request_ip();   // the right-most untrusted X-Forwarded-For hop, as the rate limits use
        return self::is_public($ip) ? $ip : '';
    }

    public static function is_public(string $ip): bool {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** ISO 3166-1 alpha-2 code for an IP (default: the current visitor), '' when unknown. Never throws. */
    public static function country(?string $ip = null): string {
        try {
            $ip = $ip === null ? self::client_ip() : trim($ip);
            if (!self::is_public($ip)) { return ''; }
            $r = self::reader();
            if ($r === null) { return ''; }
            $rec = $r->get($ip);
            $code = strtoupper((string) ($rec['country']['iso_code'] ?? ($rec['registered_country']['iso_code'] ?? '')));
            return preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** One reader per process; null when the database file is missing or unreadable (logged once). */
    private static function reader() {
        if (self::$tried) { return self::$reader; }
        self::$tried = true;
        $path = self::db_path();
        if (!is_file($path) || !class_exists('\\MaxMind\\Db\\Reader')) { return null; }
        try { self::$reader = new \MaxMind\Db\Reader($path); }
        catch (\Throwable $e) { error_log('[geoip] cannot open ' . $path . ': ' . $e->getMessage()); self::$reader = null; }
        return self::$reader;
    }

    /** True when the database file is present (the admin view says so when it is not). */
    public static function available(): bool { return is_file(self::db_path()); }
}
