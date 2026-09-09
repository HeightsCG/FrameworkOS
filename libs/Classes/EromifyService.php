<?php
/**
 * Eromify / Creator Studio client. Eromify exposes its studio as an MCP server
 * (https://api.eromify.com/mcp, JSON-RPC over HTTP, bearer API key), so this is a
 * minimal MCP client: one tools/call per request, parsing either a JSON body or an
 * SSE "data:" frame. Used to render a creator's saved AI character into scenes for
 * post automations. Each creator supplies their own key (Settings > Integrations).
 */
class EromifyService {

    const ENDPOINT = 'https://api.eromify.com/mcp';
    const MODEL    = 'nano-banana-pro';   // best character consistency per Eromify

    /** Map the automation's image shape to Eromify's aspect ratios. */
    public static function aspect_for_size($size): string {
        switch ((string) $size) {
            case 'portrait':  return '3:4';
            case 'landscape': return '4:3';
            default:          return '1:1';
        }
    }

    /**
     * Low-level tools/call. Returns ['ok'=>bool, 'text'=>string, 'data'=>array|null, 'error'=>string].
     * 'data' is structuredContent when the server provides it, else the first text block
     * parsed as JSON when possible.
     */
    public static function call($api_key, $tool, array $args, $timeout = 60): array {
        $api_key = trim((string) $api_key);
        if ($api_key === '') { return self::fail('Eromify is not connected'); }
        $body = json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => array('name' => (string) $tool, 'arguments' => empty($args) ? new stdClass() : $args)));
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => max(10, (int) $timeout),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
                'Accept: application/json, text/event-stream',
            ),
        ));
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            error_log('[eromify] ' . $tool . ' transport: ' . $err);
            return self::fail('Could not reach Eromify');
        }
        $msg = self::decode((string) $raw);
        if ($code === 401 || $code === 403) {
            return self::fail('Eromify rejected the API key');
        }
        if ($code >= 400 || !is_array($msg)) {
            error_log('[eromify] ' . $tool . ' http ' . $code . ': ' . substr((string) $raw, 0, 300));
            return self::fail('Eromify request failed (HTTP ' . $code . ')');
        }
        if (isset($msg['error'])) {
            return self::fail('Eromify: ' . (string) ($msg['error']['message'] ?? 'error'));
        }
        $result = (array) ($msg['result'] ?? array());
        $text = '';
        foreach ((array) ($result['content'] ?? array()) as $c) {
            if (($c['type'] ?? '') === 'text') { $text = (string) $c['text']; break; }
        }
        if (!empty($result['isError'])) {
            return self::fail('Eromify: ' . mb_substr(trim($text) !== '' ? $text : 'tool error', 0, 300));
        }
        $data = isset($result['structuredContent']) && is_array($result['structuredContent']) ? $result['structuredContent'] : null;
        if ($data === null && $text !== '') {
            $parsed = json_decode($text, true);
            if (is_array($parsed)) { $data = $parsed; }
        }
        return array('ok' => true, 'text' => $text, 'data' => $data, 'error' => '');
    }

    /** Validate a key: credits + plan, or an error. */
    public static function verify($api_key): array {
        $r = self::call($api_key, 'studio_get_credits', array(), 20);
        if (!$r['ok']) { return $r; }
        $d = (array) ($r['data'] ?? array());
        return array('ok' => true, 'credits' => (int) ($d['credits'] ?? 0), 'plan' => (string) ($d['subscription_plan'] ?? ''), 'error' => '');
    }

    /** The creator's characters: [['id','name','handle','reference_image'], ...] or ['error'=>...]. */
    public static function list_characters($api_key): array {
        $r = self::call($api_key, 'studio_list_influencers', array('limit' => 100), 30);
        if (!$r['ok']) { return array('error' => $r['error']); }
        $out = array();
        foreach ((array) (($r['data'] ?? array())['influencers'] ?? array()) as $c) {
            if (isset($c['safe_to_generate']) && !$c['safe_to_generate']) { continue; }
            $out[] = array(
                'id'              => (string) ($c['id'] ?? ''),
                'name'            => (string) ($c['name'] ?? ''),
                'handle'          => ltrim((string) ($c['description'] ?? ''), '@'),
                'reference_image' => (string) ($c['reference_image'] ?? ''),
            );
        }
        return array('characters' => $out);
    }

    /**
     * Render a character into a scene. Synchronous on Eromify's side (~30-60 s).
     * @return array ['ok'=>bool, 'url'=>string, 'credits_remaining'=>int|null, 'error'=>string]
     */
    public static function generate_character_image($api_key, $influencer_id, $scene, $size = 'square'): array {
        $scene = trim((string) $scene);
        if ((string) $influencer_id === '' || $scene === '') { return self::fail('Character and scene are required'); }
        $r = self::call($api_key, 'studio_generate_image_with_character', array(
            'influencer_id' => (string) $influencer_id,
            'prompt'        => mb_substr($scene, 0, 2000),
            'model'         => self::MODEL,
            'aspect_ratio'  => self::aspect_for_size($size),
            'num_images'    => 1,
        ), 180);
        if (!$r['ok']) { return $r; }
        $d   = (array) ($r['data'] ?? array());
        $url = (string) ($d['imageUrl'] ?? (($d['images'][0]['url'] ?? '')));
        if (($d['status'] ?? 'completed') !== 'completed' || $url === '') {
            return self::fail('Eromify did not return an image' . (!empty($d['status']) ? ' (status ' . $d['status'] . ')' : ''));
        }
        return array('ok' => true, 'url' => $url, 'credits_remaining' => isset($d['creditsRemaining']) ? (int) $d['creditsRemaining'] : null, 'error' => '');
    }

    /** Parse a JSON body or the first SSE "data:" frame. */
    private static function decode($raw){
        $raw = trim($raw);
        if ($raw === '') { return null; }
        if ($raw[0] === '{') { return json_decode($raw, true); }
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (strpos($line, 'data:') === 0) {
                $j = json_decode(trim(substr($line, 5)), true);
                if (is_array($j)) { return $j; }
            }
        }
        return null;
    }

    private static function fail($error): array {
        return array('ok' => false, 'text' => '', 'data' => null, 'error' => (string) $error);
    }

}
