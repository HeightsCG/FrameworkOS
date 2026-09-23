<?php
/**
 * IndexNow ping (Bing, Yandex, Seznam, Naver). Tells search engines a URL changed instead of
 * waiting for a crawl.
 *
 * The key comes from app.ini [global] indexnow_key (paste the one Bing's IndexNow page generates),
 * falling back to the Bing Webmaster verification key. Whichever is used, a plain-text file holding
 * exactly that key must sit at /<key>.txt (public/<key>.txt) — that is how the protocol proves the
 * domain is yours. Bing answers 403 when the file is missing and 422 when the key does not match.
 *
 * Per indexnow.org/faq: at most 10,000 URLs per request, same host only, and never resubmit an
 * unchanged URL (it wastes crawl quota), so each URL is held for RECENT_TTL after it is sent.
 *
 * Never throws and never blocks a publish: failures are logged and ignored.
 */
class IndexNow {

    const ENDPOINT    = 'https://api.indexnow.org/IndexNow';
    const RECENT_TTL  = 21600;   // 6h: a URL sent this recently is skipped (the FAQ asks for 5 minutes minimum)
    const FALLBACK_KEY = '078E9E11E118AECF36633845ECD77926';   // the Bing site-verification key

    /** The IndexNow key: app.ini [global] indexnow_key when set, else the Bing verification key. */
    public static function key(): string {
        $cfg = Main::get_config();
        $k = trim((string) ($cfg['global']['indexnow_key'] ?? ''));
        if ($k === '' || !preg_match('/^[A-Za-z0-9-]{8,128}$/', $k)) { return self::FALLBACK_KEY; }
        return $k;
    }

    /** Off on dev/localhost: search engines can't fetch those URLs and would reject the key. */
    public static function enabled(): bool {
        $host = parse_url(SeoMeta::base(), PHP_URL_HOST) ?: '';
        if ($host === '' || stripos($host, 'localhost') !== false || substr($host, -4) === '.cvk' || substr($host, -6) === '.local') { return false; }
        return is_file(Main::app_path() . '/public/' . self::key() . '.txt');
    }

    /** Where the "already sent" timestamps live (best-effort; losing it only means one extra ping). */
    private static function state_file(): string {
        return sys_get_temp_dir() . '/cls_indexnow_sent.json';
    }

    /** Drop URLs sent within RECENT_TTL, then record what is about to go out. */
    private static function drop_recent(array $urls): array {
        $now = time(); $seen = array();
        try {
            $raw = @file_get_contents(self::state_file());
            $seen = $raw !== false ? (array) json_decode($raw, true) : array();
        } catch (\Throwable $e) { $seen = array(); }

        $out = array();
        foreach ($urls as $u) {
            $last = (int) ($seen[$u] ?? 0);
            if ($now - $last < self::RECENT_TTL) { continue; }
            $out[] = $u; $seen[$u] = $now;
        }
        foreach ($seen as $u => $t) { if ($now - (int) $t > 604800) { unset($seen[$u]); } }   // forget anything older than a week
        try { @file_put_contents(self::state_file(), json_encode($seen), LOCK_EX); } catch (\Throwable $e) {}
        return $out;
    }

    /**
     * Submit absolute URLs (max 10,000 per call). Relative paths are resolved against the site base.
     * Returns the HTTP status, or 0 when skipped/failed.
     */
    public static function ping(array $urls): int {
        $base = rtrim(SeoMeta::base(), '/');
        $host = parse_url($base, PHP_URL_HOST) ?: '';
        $list = array();
        foreach ($urls as $u) {
            $u = trim((string) $u);
            if ($u === '') { continue; }
            if (strpos($u, 'http') !== 0) { $u = $base . '/' . ltrim($u, '/'); }
            if (parse_url($u, PHP_URL_HOST) !== $host) { continue; }   // IndexNow rejects URLs from another host
            $list[] = $u;
        }
        $list = array_values(array_unique($list));
        if (empty($list) || !self::enabled()) { return 0; }

        $list = self::drop_recent($list);
        if (empty($list)) { return 0; }

        $body = json_encode(array(
            'host'        => $host,
            'key'         => self::key(),
            'keyLocation' => $base . '/' . self::key() . '.txt',
            'urlList'     => array_slice($list, 0, 10000),
        ), JSON_UNESCAPED_SLASHES);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json; charset=utf-8'),
            CURLOPT_POSTFIELDS     => $body,
        ));
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code >= 200 && $code < 300) { error_log('[indexnow] ' . count($list) . ' url(s) accepted (' . $code . ')'); }
        else { error_log('[indexnow] failed (' . $code . ') ' . ($err !== '' ? $err : substr((string) $out, 0, 200))); }
        return $code;
    }
}
