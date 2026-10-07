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

    /** What creators see while fal refuses requests for a moment ('unavailable'): callers retry before showing it. */
    const UNAVAILABLE = 'Generation is temporarily unavailable. Please try again in a few minutes.';


    const QUEUE = 'https://queue.fal.run/';

    public static function key(): string { return 'fal'; }

    public static function capabilities(): array {
        return array(
            'ops'    => array('reference' => true, 'training_set' => true, 'image' => true, 'video' => true, 'enhance' => true, 'training' => true,
                'replicate' => true, 'edit' => true, 'angle' => true, 'motion' => true, 'replace' => true, 'scene' => true, 'talking' => true),
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

    /** Input family of a request: the catalog's `family`, else read off the endpoint id (an app.ini endpoint override). */
    private static function family(array $req): string {
        $f = (string) ($req['family'] ?? '');
        if ($f !== '') { return $f; }
        $ep = (string) ($req['endpoint'] ?? '');
        foreach (array('nano-banana' => 'nano_banana', 'clarity-upscaler' => 'clarity', 'seedream' => 'seedream', 'grok-imagine' => 'grok',
                       'v1.1-ultra' => 'flux_ultra', 'flux-pro' => 'flux_pro', 'kling-video' => 'kling_i2v', 'hailuo' => 'hailuo') as $needle => $family) {
            if (strpos($ep, $needle) !== false) { return $family; }
        }
        return 'flux';
    }

    /**
     * Map the generic request onto the endpoint's schema. Every fal image endpoint accepts
     * `prompt` and `seed`; the rest differs per family. $req['aspect_value'] is the catalog's
     * value for the chosen shape (a preset, a ratio string or width/height).
     */
    private static function build_image_input(array $req): array {
        $family = self::family($req);
        $shape  = $req['aspect_value'] ?? null;
        $in    = (array) ($req['params'] ?? array());
        $in['prompt'] = (string) ($req['prompt'] ?? '');
        if (!empty($req['seed'])) { $in['seed'] = (int) $req['seed']; }
        $n = max(1, min(4, (int) ($req['num_images'] ?? 1)));
        // fal's post-render safety checker does not error: it hands back a BLACK image for
        // anything it flags (tease/lingerie included). It is always off (flux-pro: most permissive tolerance).

        if ($family === 'nano_banana') {
            // Reference edit: prompt + image_urls; aspect ratio string; output png.
            $in['image_urls']    = array_values((array) ($req['image_urls'] ?? array()));
            $in['num_images']    = $n;
            $in['aspect_ratio']  = is_string($shape) ? $shape : (string) ($req['aspect_ratio'] ?? '1:1');   // 'auto' keeps the source image's shape
            $in['output_format'] = 'png';
            return $in;
        }
        if ($family === 'seedream') {
            // Multi-image edit: prompt + up to 10 image_urls; explicit width/height.
            $in['image_urls'] = array_values((array) ($req['image_urls'] ?? array()));
            $in['num_images'] = $n;
            if (is_array($shape)) { $in['image_size'] = array('width' => (int) $shape['width'], 'height' => (int) $shape['height']); }
            $in['enable_safety_checker'] = false;
            return $in;
        }
        if ($family === 'grok') {
            // Instruction edit: prompt + up to 3 image_urls; 'auto' keeps the first image's shape.
            $in['image_urls']   = array_slice(array_values((array) ($req['image_urls'] ?? array())), 0, 3);
            $in['num_images']   = $n;
            $in['aspect_ratio'] = is_string($shape) ? $shape : 'auto';
            $in['output_format'] = 'png';
            unset($in['seed']);   // not an input of this endpoint
            return $in;
        }
        if ($family === 'clarity') {
            $in['image_url'] = (string) ($req['image_url'] ?? '');
            if (trim((string) ($req['negative_prompt'] ?? '')) !== '') { $in['negative_prompt'] = (string) $req['negative_prompt']; }
            if ($in['prompt'] === '') { unset($in['prompt']); }
            $in['enable_safety_checker'] = false;
            return $in;
        }
        // Flux 1.1 Pro Ultra: takes a ratio, not an image_size; raw mode gives the unprocessed, real-photo look.
        if ($family === 'flux_ultra') {
            $in['aspect_ratio']     = (is_string($shape) && strpos($shape, ':') !== false) ? $shape : '1:1';
            if (!isset($in['raw'])) { $in['raw'] = true; }
            $in['num_images']       = $n;
            $in['output_format']    = 'jpeg';
            $in['safety_tolerance'] = '6';
            return $in;
        }
        // Flux family (flux-lora, flux/schnell, flux-pro/v1.1): image_size preset + num_images.
        $in['image_size'] = ($shape !== null) ? $shape : self::flux_size((string) ($req['image_size'] ?? 'square'));
        $in['num_images'] = $n;
        if (!empty($req['loras'])) {
            $in['loras'] = array();
            foreach ((array) $req['loras'] as $l) {
                $in['loras'][] = array('path' => (string) $l['url'], 'scale' => (float) ($l['scale'] ?? 1.0));
            }
        }
        if ($family === 'flux_pro') {
            $in['output_format']    = 'jpeg';
            $in['safety_tolerance'] = '6';
        } else {
            $in['output_format']         = 'png';
            $in['enable_safety_checker'] = false;
        }
        return $in;
    }

    private static function build_video_input(array $req): array {
        $family = self::family($req);
        $in = (array) ($req['params'] ?? array());
        $in['prompt']   = (string) ($req['prompt'] ?? '');
        $images = array_values((array) ($req['image_urls'] ?? array()));
        $videos = array_values(array_filter(array((string) ($req['video_url'] ?? '')), 'strlen'));
        if ($family === 'kling_motion') {
            // Motion control: the character comes from image_url, the movement from video_url; length follows the video.
            $in['image_url'] = (string) ($req['image_url'] ?? ($images[0] ?? ''));
            $in['video_url'] = (string) ($req['video_url'] ?? '');
            if ($in['prompt'] === '') { unset($in['prompt']); }
            return $in;
        }
        if ($family === 'heygen') {
            // Photo to talking video: the face from image_url, lip-synced to audio_url; length follows the audio.
            $in['image_url'] = (string) ($req['image_url'] ?? ($images[0] ?? ''));
            $in['audio_url'] = (string) ($req['audio_url'] ?? '');
            $in['aspect_ratio'] = !empty($req['aspect_value']) ? (string) $req['aspect_value'] : 'auto';
            unset($in['prompt']);
            return $in;
        }
        if ($family === 'wan_ref') {
            // Reference-to-video: references are addressed in the prompt by position ("Video 1", "Image 1").
            if (!empty($images)) { $in['reference_image_urls'] = $images; }
            if (!empty($videos)) { $in['reference_video_urls'] = $videos; }
            $in['duration'] = max(2, min(30, (int) ($req['duration'] ?? 5)));
            if (!empty($req['aspect_value'])) { $in['aspect_ratio'] = (string) $req['aspect_value']; }
            if (!empty($req['seed'])) { $in['seed'] = (int) $req['seed']; }
            return $in;
        }
        if ($family === 'seedance_ref') {
            // Reference-to-video: references are addressed in the prompt as @Image1, @Video1.
            if (!empty($images)) { $in['image_urls'] = $images; }
            if (!empty($videos)) { $in['video_urls'] = $videos; }
            $in['duration'] = (string) max(4, min(30, (int) ($req['duration'] ?? 5)));
            if (!empty($req['aspect_value'])) { $in['aspect_ratio'] = (string) $req['aspect_value']; }
            return $in;
        }
        $in['duration'] = (string) ($req['duration'] ?? '5');
        if ($family === 'kling_i2v') {
            $in['start_image_url'] = (string) ($req['image_url'] ?? '');
            if (trim((string) ($req['negative_prompt'] ?? '')) !== '') { $in['negative_prompt'] = (string) $req['negative_prompt']; }
        } else {
            $in['image_url'] = (string) ($req['image_url'] ?? '');
        }
        if (!empty($req['seed']) && $family !== 'hailuo') { $in['seed'] = (int) $req['seed']; }
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

    /** Shape key (ratio or the older square|portrait|landscape) -> fal Flux image_size: a preset, or width/height. */
    public static function flux_size($size) {
        $map = InfluencerConfig::FLUX_ASPECTS;
        return $map[Aspect::normalize($size, '1:1')];
    }

    private static function submit($endpoint, array $input): array {
        $endpoint = trim($endpoint, "/ \t");
        if ($endpoint === '') { return self::fail('No endpoint configured for this model', 'validation', false); }
        if (self::api_key() === '') { return self::fail('fal.ai is not configured (fal_api_key)', 'auth', false); }
        if (Main::get_environment() === 'development') { error_log('[fal submit] ' . $endpoint . ' ' . json_encode($input, JSON_UNESCAPED_SLASHES)); }   // dev: the exact request body
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

        // "User is locked. Reason: Exhausted balance" has come back with credit on the account and cleared on its own a
        // few minutes later (2026-09-28). Treat it as temporary so callers retry, and never show fal's billing text.
        if (($code === 402 || $code === 403) && (strpos($lc, 'locked') !== false || strpos($lc, 'balance') !== false)) {
            error_log('[fal] account refused (treated as temporary): ' . $msg);
            return self::fail(self::UNAVAILABLE, 'unavailable', true, $code);
        }
        // Some video models refuse any realistic photo of a person as a reference. Say that, not the provider's wording.
        if (strpos($lc, 'likenesses of real people') !== false) {
            return self::fail('This model does not accept realistic photos of people as references. Try another model.', 'content_policy', false, $code);
        }
        // The model's own safety check refused the prompt or one of the images. Say what happened and what to try.
        if (strpos($lc, 'flagged by a content checker') !== false || strpos($lc, 'content checker') !== false) {
            return self::fail('This model\'s safety check refused the photo or the prompt. Try the other model, a different source photo, or plainer wording for the outfit.', 'content_policy', false, $code);
        }
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
