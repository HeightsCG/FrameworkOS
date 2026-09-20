<?php
/**
 * fal.ai adapter (queue API). Submit = POST https://queue.fal.run/{endpoint}; the response
 * carries status_url / response_url / cancel_url which we persist and use verbatim. Status
 * is IN_QUEUE | IN_PROGRESS | COMPLETED; a failed request surfaces as a non-2xx on the
 * result fetch with a `detail` body (string or a list of validation errors).
 *
 * Endpoint-specific input bodies are built here so the rest of the feature only speaks
 * the generic request shape documented on InfluencerProvider. Nothing is added to the
 * prompt: what arrives in $req['prompt'] is what the model receives.
 */
class FalProvider implements InfluencerProvider {

    const QUEUE = 'https://queue.fal.run/';

    public static function key(): string { return 'fal'; }

    public static function capabilities(): array {
        return array(
            'ops'    => array('reference' => true, 'training_set' => true, 'image' => true, 'video' => true, 'enhance' => true, 'training' => true),
            'levels' => array('safe', 'spicy'),
        );
    }

    private static function api_key(): string {
        $cfg = Main::get_config();
        return trim((string) ($cfg['global']['fal_api_key'] ?? ''));
    }

    /* ---- submit ---- */

    public static function generate_image(array $req): array {
        return self::submit((string) ($req['endpoint'] ?? ''), self::build_image_input($req));
    }

    public static function generate_video(array $req): array {
        return self::submit((string) ($req['endpoint'] ?? ''), self::build_video_input($req));
    }

    public static function train_model(array $req): array {
        return self::submit((string) ($req['endpoint'] ?? ''), self::build_training_input($req));
    }

    /**
     * Map the generic request onto the endpoint's schema. Every fal image endpoint accepts
     * `prompt` and `seed`; the rest differs per family and is keyed off the endpoint id.
     */
    private static function build_image_input(array $req): array {
        $ep    = (string) ($req['endpoint'] ?? '');
        $in    = (array) ($req['params'] ?? array());
        $in['prompt'] = (string) ($req['prompt'] ?? '');
        if (!empty($req['seed'])) { $in['seed'] = (int) $req['seed']; }
        $n = max(1, min(4, (int) ($req['num_images'] ?? 1)));
        // fal's post-render safety checker does not error: it hands back a BLACK image for
        // anything it flags (tease/lingerie included). It is always off.
        $spicy = true;

        if (strpos($ep, 'nano-banana') !== false) {
            // Reference edit: prompt + image_urls; aspect ratio string; output png.
            $in['image_urls']    = array_values((array) ($req['image_urls'] ?? array()));
            $in['num_images']    = $n;
            $in['aspect_ratio']  = (string) ($req['aspect_ratio'] ?? '1:1');
            $in['output_format'] = 'png';
            return $in;
        }
        if (strpos($ep, 'clarity-upscaler') !== false) {
            $in['image_url'] = (string) ($req['image_url'] ?? '');
            if (trim((string) ($req['negative_prompt'] ?? '')) !== '') { $in['negative_prompt'] = (string) $req['negative_prompt']; }
            if ($in['prompt'] === '') { unset($in['prompt']); }
            $in['enable_safety_checker'] = !$spicy;
            return $in;
        }
        // Flux family (flux-lora, flux/schnell, flux-pro/v1.1): image_size preset + num_images.
        $in['image_size'] = self::flux_size((string) ($req['image_size'] ?? 'square'));
        $in['num_images'] = $n;
        if (!empty($req['loras'])) {
            $in['loras'] = array();
            foreach ((array) $req['loras'] as $l) {
                $in['loras'][] = array('path' => (string) $l['url'], 'scale' => (float) ($l['scale'] ?? 1.0));
            }
        }
        if (strpos($ep, 'flux-pro') !== false) {
            $in['output_format']    = 'jpeg';
            $in['safety_tolerance'] = $spicy ? '6' : '2';
        } else {
            $in['output_format']         = 'png';
            $in['enable_safety_checker'] = !$spicy;
        }
        return $in;
    }

    private static function build_video_input(array $req): array {
        $ep = (string) ($req['endpoint'] ?? '');
        $in = (array) ($req['params'] ?? array());
        $in['prompt']   = (string) ($req['prompt'] ?? '');
        $in['duration'] = (string) ($req['duration'] ?? '5');
        if (strpos($ep, 'kling-video') !== false) {
            $in['start_image_url'] = (string) ($req['image_url'] ?? '');
            if (trim((string) ($req['negative_prompt'] ?? '')) !== '') { $in['negative_prompt'] = (string) $req['negative_prompt']; }
        } else {
            $in['image_url'] = (string) ($req['image_url'] ?? '');
        }
        if (!empty($req['seed']) && strpos($ep, 'hailuo') === false) { $in['seed'] = (int) $req['seed']; }
        return $in;
    }

    private static function build_training_input(array $req): array {
        $in = (array) ($req['params'] ?? array());
        $in['images_data_url'] = (string) ($req['images_data_url'] ?? '');
        $in['trigger_word']    = (string) ($req['trigger_word'] ?? '');
        $in['steps']           = max(100, min(10000, (int) ($req['steps'] ?? 1000)));
        $in['create_masks']    = true;
        $in['is_style']        = false;
        return $in;
    }

    /** square|portrait|landscape -> fal image_size preset. */
    public static function flux_size($size): string {
        switch ((string) $size) {
            case 'portrait':  return 'portrait_4_3';
            case 'landscape': return 'landscape_4_3';
            default:          return 'square_hd';
        }
    }

    private static function submit($endpoint, array $input): array {
        $endpoint = trim($endpoint, "/ \t");
        if ($endpoint === '') { return self::fail('No endpoint configured for this model', 'validation', false); }
        if (self::api_key() === '') { return self::fail('fal.ai is not configured (fal_api_key)', 'auth', false); }
        $r = self::request('POST', self::QUEUE . $endpoint, $input, 30);
        if (!$r['ok']) { return self::map_error($r); }
        $j = (array) $r['json'];
        $id = (string) ($j['request_id'] ?? '');
        if ($id === '') { return self::fail('fal.ai did not return a request id', 'provider', true, $r['code']); }
        return array('ok' => true, 'error' => '', 'error_code' => '', 'retryable' => false, 'http_code' => $r['code'],
            'handle' => array(
                'provider'        => 'fal',
                'provider_job_id' => $id,
                'status_url'      => (string) ($j['status_url'] ?? (self::QUEUE . $endpoint . '/requests/' . $id . '/status')),
                'response_url'    => (string) ($j['response_url'] ?? (self::QUEUE . $endpoint . '/requests/' . $id)),
                'cancel_url'      => (string) ($j['cancel_url'] ?? (self::QUEUE . $endpoint . '/requests/' . $id . '/cancel')),
            ));
    }

    /* ---- status / result / cancel ---- */

    public static function get_job_status(array $handle): array {
        $url = (string) ($handle['status_url'] ?? '');
        if ($url === '') { return array('ok' => false, 'state' => 'failed', 'error' => 'Missing status url', 'error_code' => 'provider', 'retryable' => false, 'raw' => null); }
        $r = self::request('GET', $url, null, 15);
        if (!$r['ok']) {
            $m = self::map_error($r);
            // A transport blip while polling is not a job failure; the caller counts these.
            return array('ok' => false, 'state' => ($m['retryable'] ? 'running' : 'failed'), 'error' => $m['error'],
                'error_code' => $m['error_code'], 'retryable' => $m['retryable'], 'raw' => $r['json']);
        }
        $j = (array) $r['json'];
        $s = strtoupper((string) ($j['status'] ?? ''));
        $state = ($s === 'COMPLETED') ? 'completed' : (($s === 'IN_PROGRESS') ? 'running' : 'queued');
        return array('ok' => true, 'state' => $state, 'error' => '', 'error_code' => '', 'retryable' => false, 'raw' => $j,
            'queue_position' => isset($j['queue_position']) ? (int) $j['queue_position'] : null);
    }

    public static function fetch_result(array $handle): array {
        $url = (string) ($handle['response_url'] ?? '');
        if ($url === '') { return self::fail('Missing response url', 'provider', false); }
        $r = self::request('GET', $url, null, 60);
        if (!$r['ok']) { return self::map_error($r); }
        $j = (array) $r['json'];
        $outputs = array();
        foreach (array_values((array) ($j['images'] ?? array())) as $k => $img) {
            if (empty($img['url'])) { continue; }
            $outputs[] = array('kind' => 'image', 'url' => (string) $img['url'], 'content_type' => (string) ($img['content_type'] ?? ''),
                'width' => (int) ($img['width'] ?? 0), 'height' => (int) ($img['height'] ?? 0), 'file_size' => (int) ($img['file_size'] ?? 0),
                'seed' => isset($j['seed']) ? (int) $j['seed'] : null,
                'nsfw' => !empty($j['has_nsfw_concepts'][$k]));   // fal hands back a black frame when this is set
        }
        if (!empty($j['image']['url'])) {   // single-image endpoints (clarity-upscaler)
            $outputs[] = array('kind' => 'image', 'url' => (string) $j['image']['url'], 'content_type' => (string) ($j['image']['content_type'] ?? ''),
                'width' => (int) ($j['image']['width'] ?? 0), 'height' => (int) ($j['image']['height'] ?? 0), 'file_size' => 0,
                'seed' => isset($j['seed']) ? (int) $j['seed'] : null);
        }
        if (!empty($j['video']['url'])) {
            $outputs[] = array('kind' => 'video', 'url' => (string) $j['video']['url'], 'content_type' => (string) ($j['video']['content_type'] ?? 'video/mp4'),
                'width' => 0, 'height' => 0, 'file_size' => (int) ($j['video']['file_size'] ?? 0), 'seed' => null);
        }
        if (!empty($j['diffusers_lora_file']['url'])) {
            $outputs[] = array('kind' => 'lora', 'url' => (string) $j['diffusers_lora_file']['url'],
                'content_type' => (string) ($j['diffusers_lora_file']['content_type'] ?? 'application/octet-stream'),
                'width' => 0, 'height' => 0, 'file_size' => (int) ($j['diffusers_lora_file']['file_size'] ?? 0), 'seed' => null,
                'config_url' => (string) ($j['config_file']['url'] ?? ''));
        }
        if (empty($outputs)) { return self::fail('fal.ai returned no output', 'provider', true, $r['code']); }
        return array('ok' => true, 'outputs' => $outputs, 'seed' => isset($j['seed']) ? (int) $j['seed'] : null,
            'raw' => $j, 'error' => '', 'error_code' => '', 'retryable' => false);
    }

    public static function cancel(array $handle): bool {
        $url = (string) ($handle['cancel_url'] ?? '');
        if ($url === '') { return false; }
        $r = self::request('PUT', $url, null, 10);
        return $r['ok'] || $r['code'] === 202;
    }

    /** Only download outputs from fal's own hosts. */
    public static function output_url_allowed($url): bool {
        $h = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if ($h === '' || strpos((string) $url, 'https://') !== 0) { return false; }
        return $h === 'fal.media' || substr($h, -10) === '.fal.media' || substr($h, -8) === '.fal.run';
    }

    /* ---- transport ---- */

    /** ['ok', 'code', 'json', 'raw', 'error'] — ok means 2xx with a decodable body. */
    private static function request($method, $url, $body, $timeout): array {
        $ch = curl_init($url);
        $headers = array('Authorization: Key ' . self::api_key(), 'Accept: application/json');
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(5, (int) $timeout),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_CUSTOMREQUEST  => $method,
        );
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            error_log('[fal] ' . $method . ' ' . $url . ' transport: ' . $err);
            return array('ok' => false, 'code' => 0, 'json' => null, 'raw' => '', 'error' => 'Could not reach fal.ai: ' . $err);
        }
        $json = json_decode((string) $raw, true);
        if ($code >= 200 && $code < 300 && is_array($json)) {
            return array('ok' => true, 'code' => $code, 'json' => $json, 'raw' => (string) $raw, 'error' => '');
        }
        if ($code >= 400) { error_log('[fal] ' . $method . ' ' . $url . ' http ' . $code . ': ' . substr((string) $raw, 0, 400)); }
        return array('ok' => false, 'code' => $code, 'json' => is_array($json) ? $json : null, 'raw' => (string) $raw,
            'error' => ($code >= 200 && $code < 300) ? 'fal.ai returned a non-JSON body' : ('fal.ai HTTP ' . $code));
    }

    /**
     * Turn a failed transport result into the provider error contract. The provider's own
     * text is preserved verbatim (trimmed to 2000 chars) so a failed job shows the real reason.
     */
    private static function map_error(array $r): array {
        $code = (int) $r['code'];
        $detail = '';
        if (is_array($r['json'])) {
            $d = $r['json']['detail'] ?? ($r['json']['error'] ?? ($r['json']['message'] ?? ''));
            if (is_array($d)) {
                $parts = array();
                foreach ($d as $e) {
                    if (is_array($e)) { $parts[] = trim(implode('.', (array) ($e['loc'] ?? array())) . ': ' . (string) ($e['msg'] ?? json_encode($e))); }
                    else { $parts[] = (string) $e; }
                }
                $detail = implode('; ', $parts);
            } else {
                $detail = (string) $d;
            }
            if (!empty($r['json']['error_type'])) { $detail = '[' . $r['json']['error_type'] . '] ' . $detail; }
        }
        if ($detail === '') { $detail = (string) $r['error']; }
        $msg = 'fal.ai' . ($code > 0 ? ' HTTP ' . $code : '') . ': ' . $detail;
        $lc = strtolower($detail . ' ' . (string) $r['raw']);

        if ($code === 401 || $code === 403) { return self::fail($msg, 'auth', false, $code); }
        if ($code === 422 || $code === 400) {
            $policy = (strpos($lc, 'content_policy') !== false || strpos($lc, 'nsfw') !== false || strpos($lc, 'safety') !== false || strpos($lc, 'policy') !== false);
            return self::fail($msg, $policy ? 'content_policy' : 'validation', false, $code);
        }
        if ($code === 404) { return self::fail($msg, 'validation', false, $code); }
        // 0 (transport), 429, 5xx: try the next provider.
        return self::fail($msg, 'provider', true, $code);
    }

    private static function fail($error, $code, $retryable, $http = 0): array {
        return array('ok' => false, 'error' => mb_substr((string) $error, 0, 2000), 'error_code' => (string) $code,
            'retryable' => (bool) $retryable, 'http_code' => (int) $http, 'handle' => null, 'outputs' => array());
    }
}
