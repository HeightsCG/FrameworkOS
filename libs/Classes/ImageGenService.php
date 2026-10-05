<?php
/**
 * Brand (non-influencer) AI image generation on fal.ai: the same Flux text-to-image
 * models the influencer reference step uses, driven synchronously (submit, poll, fetch)
 * so callers get bytes back and ingest them through the normal media pipeline.
 * Callers: AutoPostService (automations), MediaGenerateJob (Studio "Generate Image"),
 * McpTools generate_image. Not metered in AI credits.
 *
 * Model: InfluencerConfig::get('brand_model') (app.ini infl_brand_model), default flux_pro_11.
 * Renders with fal's safety filter off (safety_tolerance 6); cron/moderate.php flags what lands.
 */
class ImageGenService {

    const BUDGET_SECONDS = 170;

    /** Normalises a shape key (a ratio, or the older square|portrait|landscape) to a ratio the brand model renders. */
    public static function dimensions($key){
        $model = self::model_for();
        return $model ? Aspect::for_model($model, $key, '1:1') : Aspect::normalize($key, '1:1');
    }

    /** The model that renders brand images (null when none is configured). */
    public static function model_for(){
        return InfluencerConfig::resolve_model('reference', (string) InfluencerConfig::get('brand_model', 'flux_pro_11'));
    }

    /**
     * Generate one image. Returns ['ok'=>bool, 'bytes'|'error', 'ext', 'mime', 'seed', 'model_key'].
     * $size: a ratio key (Aspect::RATIOS) or the older square|portrait|landscape.
     */
    public static function generate($prompt, $size = 'square'){
        if (!InfluencerConfig::enabled()) { return array('ok' => false, 'error' => 'Image generation is not configured (no fal.ai key).'); }
        $prompt = trim((string) $prompt);
        if ($prompt === '') { return array('ok' => false, 'error' => 'Describe the image you want to generate.'); }
        $model = self::model_for();
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
        );
        $sub = $class::generate_image($req);
        // fal briefly refusing the account: a scheduled run (CLI) waits and tries again; a click in the Studio doesn't hang.
        for ($try = 1; empty($sub['ok']) && ($sub['error_code'] ?? '') === 'unavailable' && php_sapi_name() === 'cli' && $try <= 3; $try++) {
            sleep(30 * $try);
            $sub = $class::generate_image($req);
        }
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
                if (!empty($out['nsfw']) || MediaIngestService::is_blank_image($img['bytes'])) {
                    return array('ok' => false, 'error' => 'The model returned a blank image (its content filter fired). Try a different prompt or a less revealing scene.');
                }
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
        if ($code === 'content_policy') { return 'That prompt was rejected by the safety filter. Try describing something different.'; }
        if ($code === 'unavailable')    { return FalProvider::UNAVAILABLE; }
        if ($code === 'auth')           { return 'Image generation is not configured (fal.ai key rejected).'; }
        if ($code === 'validation' && $msg !== '') { return 'The image model rejected the request: ' . mb_substr($msg, 0, 200); }
        if (!empty($r['retryable']))    { return 'The image service is busy. Try again in a moment.'; }
        return 'Generation failed. Try again.';
    }
}
