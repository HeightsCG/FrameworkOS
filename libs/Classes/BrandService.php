<?php
/**
 * Brand Identity generation. Fetches a creator's website, extracts readable
 * text, and asks Claude to distill a brand kit (name, tagline, description,
 * voice, colour palette, keywords). Storage is the caller's concern — this
 * only generates. Anthropic key from app.ini.
 */
class BrandService {

    const MODEL = 'claude-sonnet-5';
    const API   = 'https://api.anthropic.com/v1/messages';

    /** Generate brand details from a public URL. Returns ['ok'=>bool, 'data'|'error']. */
    public static function generate_from_url($url){
        $clean = self::safe_url((string) $url);
        if ($clean === null) {
            return array('ok' => false, 'error' => 'Enter a valid website URL (http:// or https://).');
        }
        $html = self::fetch($clean);
        if ($html === null) {
            return array('ok' => false, 'error' => "Couldn't reach that URL. Check it and try again.");
        }
        $text = self::readable_text($html);
        if ($text === '') {
            return array('ok' => false, 'error' => 'That page had no readable content to analyze.');
        }
        return self::ask_claude($clean, $text);
    }

    /** Validate + normalize a URL, blocking internal/private hosts (SSRF guard). */
    private static function safe_url($url){
        $url = trim($url);
        if ($url === '') { return null; }
        if (!preg_match('#^https?://#i', $url)) { $url = 'https://' . $url; }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)) {
            return null;
        }
        $host = $parts['host'];
        if (preg_match('/^(localhost|.*\.local|.*\.internal)$/i', $host)) { return null; }
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP)
            && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null; // resolves to a private/reserved address
        }
        return $url;
    }

    private static function fetch($url){
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_USERAGENT       => 'CreatorLinkStudio-BrandBot/1.0',
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ));
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($raw === false || $code >= 400) ? null : $raw;
    }

    /** Strip HTML down to a compact text block (title, meta, og, body) for the model. */
    private static function readable_text($html){
        $out = array();
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $out[] = 'TITLE: ' . trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
        }
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
            $out[] = 'META: ' . trim($m[1]);
        }
        if (preg_match_all('/<meta[^>]+property=["\']og:[^"\']+["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
            foreach ($m[1] as $c) { $out[] = 'OG: ' . trim($c); }
        }
        $body = preg_replace('#<(script|style|noscript|svg|head)[^>]*>.*?</\1>#is', ' ', $html);
        $body = strip_tags($body);
        $body = html_entity_decode($body, ENT_QUOTES);
        $body = preg_replace('/\s+/', ' ', $body);
        $out[] = 'CONTENT: ' . trim($body);
        return mb_substr(implode("\n", $out), 0, 8000);
    }

    private static function ask_claude($url, $text){
        $key = (string) Main::config('global', 'anthropic_api_key');
        if ($key === '') { $key = (string) Main::config('global', 'claude_api_key'); }
        if ($key === '') { return array('ok' => false, 'error' => 'Brand generation is not configured.'); }

        $prompt = "You are a brand strategist. Based ONLY on the website content below, distill a concise brand identity for this creator/business.\n"
            . "Respond with STRICT JSON only — no markdown, no prose — matching exactly this shape:\n"
            . '{"brand_name":"","tagline":"","description":"","voice":"","colors":["#RRGGBB"],"keywords":[""]}' . "\n\n"
            . "Field rules:\n"
            . "- brand_name: the brand/creator name.\n"
            . "- tagline: a punchy 3-8 word slogan.\n"
            . "- description: 1-2 sentence positioning statement.\n"
            . "- voice: one sentence describing the tone of voice.\n"
            . "- colors: 3-5 hex codes that fit the brand (pick tasteful ones if the site doesn't state them).\n"
            . "- keywords: 4-8 short vibe/topic words.\n\n"
            . "WEBSITE (" . $url . "):\n" . $text;

        $body = array(
            'model'      => self::MODEL,
            'max_tokens' => 1024,
            'messages'   => array(array('role' => 'user', 'content' => $prompt)),
        );
        $ch = curl_init(self::API);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => array(
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ),
            CURLOPT_POSTFIELDS => json_encode($body),
        ));
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) { error_log('[brand] transport: ' . $err); return array('ok' => false, 'error' => 'Generation failed. Try again.'); }
        if ($code >= 400)   { error_log('[brand] http ' . $code . ': ' . $raw); return array('ok' => false, 'error' => 'Generation failed. Try again.'); }

        $decoded = json_decode($raw, true);
        $content = $decoded['content'][0]['text'] ?? '';
        $data    = self::parse_json($content);
        if (!$data) { error_log('[brand] unparseable: ' . $content); return array('ok' => false, 'error' => "Couldn't read the generated brand. Try again."); }
        return array('ok' => true, 'data' => self::normalize($data));
    }

    private static function parse_json($s){
        $s = trim((string) $s);
        if (preg_match('/\{.*\}/s', $s, $m)) { $s = $m[0]; }
        $d = json_decode($s, true);
        return is_array($d) ? $d : null;
    }

    /** Coerce the model output into safe, bounded fields. */
    private static function normalize($d){
        $hex = array();
        foreach ((array) ($d['colors'] ?? array()) as $c) {
            $c = trim((string) $c);
            if (preg_match('/^#?[0-9a-f]{6}$/i', $c)) { $hex[] = '#' . strtolower(ltrim($c, '#')); }
        }
        $kw = array();
        foreach ((array) ($d['keywords'] ?? array()) as $k) {
            $k = trim((string) $k);
            if ($k !== '') { $kw[] = mb_substr($k, 0, 40); }
        }
        return array(
            'brand_name'  => mb_substr(trim((string) ($d['brand_name'] ?? '')), 0, 190),
            'tagline'     => mb_substr(trim((string) ($d['tagline'] ?? '')), 0, 255),
            'description' => trim((string) ($d['description'] ?? '')),
            'voice'       => trim((string) ($d['voice'] ?? '')),
            'colors'      => array_slice($hex, 0, 6),
            'keywords'    => array_slice($kw, 0, 10),
        );
    }
}
