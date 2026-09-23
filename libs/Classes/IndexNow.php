<?php
/**
 * IndexNow ping (Bing, Yandex, Seznam, Naver). Tells search engines a URL changed instead of
 * waiting for a crawl. The key is the Bing Webmaster verification key, served as a plain-text
 * file at /<key>.txt (public/<key>.txt) as the protocol requires.
 *
 * Never throws and never blocks a publish: failures are logged and ignored.
 */
class IndexNow {

    const ENDPOINT = 'https://api.indexnow.org/IndexNow';
    const KEY      = '078E9E11E118AECF36633845ECD77926';

    /** Off on dev/localhost: search engines can't fetch those URLs and would reject the key. */
    public static function enabled(): bool {
        $host = parse_url(SeoMeta::base(), PHP_URL_HOST) ?: '';
        if ($host === '' || stripos($host, 'localhost') !== false || substr($host, -4) === '.cvk' || substr($host, -6) === '.local') { return false; }
        return is_file(Main::app_path() . '/public/' . self::KEY . '.txt');
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

        $body = json_encode(array(
            'host'        => $host,
            'key'         => self::KEY,
            'keyLocation' => $base . '/' . self::KEY . '.txt',
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
