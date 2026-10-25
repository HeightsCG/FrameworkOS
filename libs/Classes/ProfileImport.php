<?php
/**
 * "Import your page": read a creator's existing link-in-bio page (Linktree, Beacons, Stan or any public page) and
 * return what it shows (name, bio, photo, links) so Settings can offer each piece to copy. Linktree pages carry
 * their data as JSON (__NEXT_DATA__); every other page is read from its meta tags and outbound links. Pages that
 * block server requests (Beacons answers 403) return ok=false and Settings offers to paste the links instead.
 * Every fetch, redirects included, goes through MediaIngestService::safe_url (SSRF guard, DNS pinned).
 */
class ProfileImport {

    const MAX_BYTES = 3145728;   // 3 MB of HTML is far more than any link page
    const MAX_LINKS = 40;
    const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /** Hosts that are the page builder itself: their links are chrome (sign up, report, privacy), never the creator's. */
    const OWN_HOSTS = array('linktr.ee', 'linktree.com', 'beacons.ai', 'beacons.page', 'stan.store', 'stanwith.me', 'campsite.bio',
        'komi.io', 'later.com', 'milkshake.app', 'bio.link', 'carrd.co', 'lnk.bio', 'snipfeed.co', 'hoo.be', 'allmylinks.com', 'solo.to',
        'cloudfront.net', 'googleapis.com', 'gstatic.com', 'google.com', 'apple.com', 'cookiebot.com', 'onetrust.com', 'w3.org', 'schema.org');

    /** Names for well-known link hosts, used when a link has no label of its own. */
    const NAMES = array('instagram.com' => 'Instagram', 'tiktok.com' => 'TikTok', 'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube',
        'x.com' => 'X', 'twitter.com' => 'X', 'facebook.com' => 'Facebook', 'threads.net' => 'Threads', 'threads.com' => 'Threads',
        'pinterest.com' => 'Pinterest', 'linkedin.com' => 'LinkedIn', 'snapchat.com' => 'Snapchat', 'twitch.tv' => 'Twitch',
        'spotify.com' => 'Spotify', 'music.apple.com' => 'Apple Music', 'soundcloud.com' => 'SoundCloud', 'bsky.app' => 'Bluesky',
        'discord.gg' => 'Discord', 'discord.com' => 'Discord', 'patreon.com' => 'Patreon', 'onlyfans.com' => 'OnlyFans',
        'fansly.com' => 'Fansly', 'fanvue.com' => 'Fanvue', 'ko-fi.com' => 'Ko-fi', 'amazon.com' => 'Amazon', 'etsy.com' => 'Etsy',
        'substack.com' => 'Substack', 'github.com' => 'GitHub', 'reddit.com' => 'Reddit', 'telegram.me' => 'Telegram', 't.me' => 'Telegram',
        'wa.me' => 'WhatsApp', 'calendly.com' => 'Calendly', 'cash.app' => 'Cash App', 'venmo.com' => 'Venmo', 'paypal.me' => 'PayPal');

    /**
     * Read a page. Returns ['ok' => true, 'source', 'url', 'name', 'bio', 'avatar', 'links' => [['title','url']]]
     * or ['ok' => false, 'error', 'blocked' => bool].
     */
    public static function read($url): array {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
        if ($url === '') { return array('ok' => false, 'blocked' => false, 'error' => 'Enter the address of your current page.'); }
        if (!preg_match('#^https?://#i', $url)) { $url = 'https://' . ltrim($url, '/'); }
        $p = parse_url($url);
        if (!$p || empty($p['host']) || strpos($p['host'], '.') === false) {
            return array('ok' => false, 'blocked' => false, 'error' => 'That address is not a web page. Use one like linktr.ee/yourname.');
        }
        $got = self::fetch($url);
        if ($got['html'] === null) {
            $blocked = in_array($got['code'], array(401, 403, 429, 503), true);
            return array('ok' => false, 'blocked' => $blocked, 'error' => $got['code'] === 404
                ? 'No page was found at that address. Check the spelling of your username.'
                : ($blocked ? 'That site does not let other services read its pages.' : 'That page could not be reached. Check the address and try again.'));
        }
        $html = $got['html'];
        $host = strtolower(preg_replace('/^www\./i', '', (string) parse_url($got['url'], PHP_URL_HOST)));

        $out = null;
        if (preg_match('#<script[^>]+id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m)) { $out = self::linktree(json_decode($m[1], true)); }
        if (!$out) { $out = self::generic($html, $host); }
        $out['source'] = (strpos($host, 'linktr') === 0) ? 'linktree' : ((strpos($host, 'beacons') === 0) ? 'beacons' : ((strpos($host, 'stan') === 0) ? 'stan' : 'page'));
        $out['url'] = $got['url'];
        $out['links'] = self::clean_links($out['links'], $host);
        if ($out['name'] === '' && $out['bio'] === '' && $out['avatar'] === '' && empty($out['links'])) {
            return array('ok' => false, 'blocked' => false, 'error' => 'That page has nothing to import. Check that it is your public page.');
        }
        return array('ok' => true) + $out;
    }

    /** Lines pasted by hand: one link per line, an optional label before the address ("Shop https://..."). */
    public static function pasted_links($text): array {
        $links = array();
        foreach (preg_split('/\r\n|\r|\n/', html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')) as $line) {
            $line = trim($line);
            if ($line === '' || !preg_match('#((?:https?://)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:/\S*)?)\s*$#i', $line, $m)) { continue; }
            $u = $m[1];
            $label = trim(rtrim(trim(substr($line, 0, -strlen($m[0]))), ':-'));
            $links[] = array('title' => $label, 'url' => preg_match('#^https?://#i', $u) ? $u : 'https://' . $u);
        }
        return self::clean_links($links, '');
    }

    /** Linktree's page JSON. Null when it is not a Linktree page. */
    private static function linktree($d) {
        $pp = is_array($d) ? ($d['props']['pageProps'] ?? null) : null;
        if (!is_array($pp) || !isset($pp['account'])) { return null; }
        $a = (array) $pp['account'];
        $name = trim((string) ($a['pageTitle'] ?? $pp['pageTitle'] ?? ''));
        $links = array();
        foreach ((array) ($pp['links'] ?? $a['links'] ?? array()) as $l) {
            if (!is_array($l) || (string) ($l['url'] ?? '') === '' || !empty($l['locked'])) { continue; }
            $links[] = array('title' => (string) ($l['title'] ?? ''), 'url' => (string) $l['url']);
        }
        foreach ((array) ($pp['socialLinks'] ?? $a['socialLinks'] ?? array()) as $s) {
            if (is_array($s) && (string) ($s['url'] ?? '') !== '') { $links[] = array('title' => '', 'url' => (string) $s['url']); }
        }
        return array('name' => ltrim($name, '@') === (string) ($a['username'] ?? '') ? '' : $name, 'bio' => trim((string) ($a['description'] ?? $pp['description'] ?? '')),
            'avatar' => (string) ($a['profilePictureUrl'] ?? ''), 'links' => $links);
    }

    /** Any other page: og/meta tags for name, bio and photo; every outbound link in the body. */
    private static function generic($html, $host): array {
        $meta = function ($names) use ($html) {
            foreach ((array) $names as $n) {
                if (preg_match('#<meta[^>]+(?:property|name)=["\']' . preg_quote($n, '#') . '["\'][^>]*content=["\']([^"\']*)#i', $html, $m)
                    || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . preg_quote($n, '#') . '["\']#i', $html, $m)) {
                    $v = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                    if ($v !== '') { return $v; }
                }
            }
            return '';
        };
        $name = $meta(array('og:title', 'twitter:title'));
        if ($name === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) { $name = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')); }
        // "Ana (@ana) | Stan" / "Ana | Beacons" -> "Ana"
        $name = trim(preg_replace('/\s*[|\-–·]\s*(stan|beacons|linktree|linksite|link in bio|instagram|tiktok)\b.*$/iu', '', $name));
        $name = trim(preg_replace('/\s*\(@[^)]*\)\s*$/', '', $name));
        $bio = $meta(array('og:description', 'description', 'twitter:description'));
        if (preg_match('/^(stan|beacons|linktree)\b/i', $bio) || strcasecmp($bio, $name) === 0) { $bio = ''; }
        $avatar = $meta(array('og:image', 'twitter:image'));
        if (preg_match('#(no_user|default|placeholder|og-image|share-image)#i', $avatar)) { $avatar = ''; }

        $links = array();
        $body = preg_replace('#<(script|style|head|noscript)[^>]*>.*?</\1>#is', ' ', $html);
        if (preg_match_all('#<a\b[^>]*href=["\'](https?://[^"\']+)["\'][^>]*>(.*?)</a>#is', $body, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $x) {
                $links[] = array('title' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($x[2]), ENT_QUOTES, 'UTF-8'))), 'url' => html_entity_decode($x[1], ENT_QUOTES, 'UTF-8'));
            }
        }
        return array('name' => mb_substr($name, 0, 190), 'bio' => mb_substr($bio, 0, 1000), 'avatar' => $avatar, 'links' => $links);
    }

    /** Valid public http(s) links, not the builder's own, one per address, each with a label. */
    private static function clean_links(array $links, $page_host): array {
        $out = array(); $seen = array();
        foreach ($links as $l) {
            $u = trim((string) ($l['url'] ?? ''));
            if (!filter_var($u, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $u)) { continue; }
            $h = strtolower(preg_replace('/^www\./i', '', (string) parse_url($u, PHP_URL_HOST)));
            if ($h === '' || ($page_host !== '' && $h === $page_host)) { continue; }
            $own = false;
            foreach (self::OWN_HOSTS as $o) { if ($h === $o || substr($h, -strlen('.' . $o)) === '.' . $o) { $own = true; break; } }
            if ($own) { continue; }
            $k = strtolower(rtrim(preg_replace('#^https?://(www\.)?#i', '', $u), '/'));
            if (isset($seen[$k])) { continue; }
            $seen[$k] = 1;
            $title = trim((string) ($l['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 120) { $title = self::name_for($h); }
            $out[] = array('title' => mb_substr($title, 0, 120), 'url' => $u);
            if (count($out) >= self::MAX_LINKS) { break; }
        }
        return $out;
    }

    /** "Instagram" for instagram.com, else the bare host. */
    private static function name_for($host): string {
        foreach (self::NAMES as $h => $n) { if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) { return $n; } }
        return $host;
    }

    /** GET with redirects followed by hand (max 4), every hop SSRF-checked and DNS-pinned, capped at MAX_BYTES. */
    private static function fetch($url): array {
        for ($hop = 0; $hop <= 4; $hop++) {
            try { $safe = MediaIngestService::safe_url($url, 'page address'); } catch (\Throwable $e) { return array('html' => null, 'code' => 0, 'url' => $url); }
            $buf = '';
            $ch = curl_init($safe['url']);
            curl_setopt_array($ch, array(
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_RESOLVE => array($safe['resolve']), CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT => self::USER_AGENT, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9'),
                CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf) { $buf .= $chunk; return (strlen($buf) > self::MAX_BYTES) ? 0 : strlen($chunk); },
            ));
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if ($code >= 300 && $code < 400 && $location !== '') { $url = $location; continue; }
            if ($code < 200 || $code >= 400 || $buf === '') { return array('html' => null, 'code' => $code, 'url' => $url); }
            return array('html' => $buf, 'code' => $code, 'url' => $url);
        }
        return array('html' => null, 'code' => 0, 'url' => $url);
    }
}
