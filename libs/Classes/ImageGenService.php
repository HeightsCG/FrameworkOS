<?php
/**
 * Brand (non-influencer) AI image generation on fal.ai: the same Flux text-to-image
 * models the influencer reference step uses, driven synchronously (submit, poll, fetch)
 * so callers get bytes back and ingest them through the normal media pipeline.
 * Callers: AutoPostService (automations), MediaGenerateJob (Studio "Generate Image"),
 * McpTools generate_image. Not metered in AI credits.
 *
 * Model: InfluencerConfig::get('brand_model') (app.ini infl_brand_model), default flux_pro_11.
 * A 'spicy' request moves to the first brand model that allows it (flux_schnell).
 */
class ImageGenService {

    const BUDGET_SECONDS = 170;

    /** Normalises a size key; kept for callers that used to need OpenAI pixel sizes. */
    public static function dimensions($key){
        return in_array((string) $key, array('square', 'portrait', 'landscape'), true) ? (string) $key : 'square';
    }

    /** The model that will render a brand image at this content level (null when none is configured). */
    public static function model_for($level = 'safe'){
        $spicy = ((string) $level === 'spicy');
        $key   = (string) InfluencerConfig::get('brand_model', 'flux_pro_11');
        $m     = InfluencerConfig::resolve_model('reference', $key);
        if ($m && (!$spicy || in_array('spicy', (array) ($m['levels'] ?? array()), true))) { return $m; }
        foreach (InfluencerConfig::picker('reference') as $opt) {
            $c = InfluencerConfig::model((string) $opt['key']);
            if ($c && in_array('spicy', (array) ($c['levels'] ?? array()), true)) { return $c; }
        }
        return $m;
    }

    /**
     * Generate one image. Returns ['ok'=>bool, 'bytes'|'error', 'ext', 'mime', 'seed', 'model_key'].
     * $size: square|portrait|landscape. $level: safe|spicy.
     */
    public static function generate($prompt, $size = 'square', $level = 'safe'){
        if (!InfluencerConfig::enabled()) { return array('ok' => false, 'error' => 'Image generation is not configured (no fal.ai key).'); }
        $prompt = trim((string) $prompt);
        if ($prompt === '') { return array('ok' => false, 'error' => 'Describe the image you want to generate.'); }
        $level = ((string) $level === 'spicy') ? 'spicy' : 'safe';
        $model = self::model_for($level);
        if (!$model) { return array('ok' => false, 'error' => 'No image model is configured.'); }
        $class = InfluencerConfig::provider_class((string) $model['provider']);
        if ($class === '') { return array('ok' => false, 'error' => 'The image provider is not configured.'); }

        $req = array(
            'endpoint'        => (string) $model['endpoint'],
            'params'          => (array) ($model['params'] ?? array()),
            'prompt'          => $prompt,
            'negative_prompt' => '',
            'seed'            => random_int(1, 2147483647),
            'num_images'      => 1,
            'image_size'      => self::dimensions($size),
            'aspect_ratio'    => '1:1',
            'level'           => $level,
        );
        $sub = $class::generate_image($req);
        if (empty($sub['ok'])) { return array('ok' => false, 'error' => self::friendly($sub)); }
        $handle = (array) $sub['handle'];

        $t_end = time() + self::BUDGET_SECONDS;
        $n = 0; $blips = 0;
        while (time() < $t_end) {
            sleep(min(InfluencerConfig::poll_delay('reference', $n++), max(1, $t_end - time())));
            $st = $class::get_job_status($handle);
            if (empty($st['ok'])) {
                if ((string) $st['state'] === 'failed' || ++$blips > 5) { return array('ok' => false, 'error' => self::friendly($st)); }
                continue;
            }
            if ((string) $st['state'] !== 'completed') { continue; }
            $res = $class::fetch_result($handle);
            if (empty($res['ok'])) { return array('ok' => false, 'error' => self::friendly($res)); }
            $out = null;
            foreach ((array) $res['outputs'] as $o) { if (($o['kind'] ?? '') === 'image') { $out = $o; break; } }
            if (!$out) { return array('ok' => false, 'error' => 'Generation returned no image. Try again.'); }
            if (!$class::output_url_allowed($out['url'])) { return array('ok' => false, 'error' => 'The provider returned an output from an unexpected host.'); }
            try {
                $img = MediaIngestService::fetch_image($out['url'], (int) InfluencerConfig::get('output_max_image_bytes', 31457280), 60);
            } catch (\Throwable $e) {
                error_log('[imagegen] download: ' . $e->getMessage());
                return array('ok' => false, 'error' => 'Could not download the generated image. Try again.');
            }
            return array('ok' => true, 'bytes' => $img['bytes'], 'ext' => (string) $img['ext'], 'mime' => (string) $img['mime'],
                'seed' => $res['seed'] ?? $req['seed'], 'model_key' => (string) $model['key']);
        }
        try { $class::cancel($handle); } catch (\Throwable $e) {}
        return array('ok' => false, 'error' => 'Generation took too long. Try again.');
    }

    private static function friendly(array $r){
        $code = (string) ($r['error_code'] ?? '');
        $msg  = (string) ($r['error'] ?? '');
        if ($msg !== '') { error_log('[imagegen] fal ' . $code . ': ' . $msg); }
        if ($code === 'content_policy') { return 'That prompt was rejected by the safety filter. Try describing something different, or set the content level to Spicy.'; }
        if ($code === 'auth')           { return 'Image generation is not configured (fal.ai key rejected).'; }
        if ($code === 'validation' && $msg !== '') { return 'The image model rejected the request: ' . mb_substr($msg, 0, 200); }
        if (!empty($r['retryable']))    { return 'The image service is busy. Try again in a moment.'; }
        return 'Generation failed. Try again.';
    }
}
