<?php
/**
 * Code-owned configuration for the AI influencer feature (PlanTiers style): which
 * provider serves each operation (ordered fallback), the model catalog behind every
 * picker (presented to the user by purpose, not by model name), concurrency caps,
 * per-type ceilings, poll backoff and training parameters.
 *
 * Every scalar/list can be overridden without a deploy from app.ini [global]:
 *   fal_api_key                     provider credential (secrets stay in app.ini, never the DB)
 *   infl_providers_<op>             comma-ordered provider keys, e.g. infl_providers_image = fal
 *   infl_pickers_<purpose>          comma-ordered model keys shown in that picker (first = default)
 *   infl_endpoint_<model_key>       endpoint override for the entry's primary provider
 *   infl_endpoint_<model_key>_<provider>  endpoint for another provider (enables fallback for that entry)
 *   infl_price_<model_key>          price override (USD per unit as documented on the entry)
 *   infl_cap_per_influencer / infl_cap_per_account / infl_cap_wait_seconds
 *   infl_ceiling_<type>             seconds before a job is failed with the provider error kept
 *   infl_training_steps / infl_training_set_size / infl_training_min_photos / infl_training_max_photos
 *   infl_training_lora_scale / infl_training_zip_url_ttl / infl_training_zip_max_bytes / infl_training_lora_max_bytes
 *
 * Operations: reference | training_set | image | video | enhance | training.
 */
class InfluencerConfig {

    /** Provider key => class implementing InfluencerProvider. Add a provider here + its class. */
    const PROVIDERS = array(
        'fal' => 'FalProvider',
    );

    /** Ordered fallback list per operation (first is primary). */
    const PROVIDER_ORDER = array(
        'reference'    => array('fal'),
        'training_set' => array('fal'),
        'image'        => array('fal'),
        'video'        => array('fal'),
        'enhance'      => array('fal'),
        'training'     => array('fal'),
    );

    /**
     * Model catalog. `label`/`purpose` are what the user sees; `endpoints` maps each provider
     * that can serve the entry to its endpoint id (a fallback provider needs one here or via
     * infl_endpoint_<model_key>_<provider>); `price_usd` + `price_unit` feed the cost estimate;
     * `levels` are the content levels the entry accepts; `params` are fixed inputs merged into
     * the request.
     */
    const MODELS = array(
        // -- reference image (text -> image), reference path only --
        'flux_pro_11' => array('provider' => 'fal', 'op' => 'reference', 'endpoints' => array('fal' => 'fal-ai/flux-pro/v1.1'),
            'label' => 'Best likeness', 'purpose' => 'Sharper faces and skin, slower',
            'price_usd' => 0.04, 'price_unit' => 'image', 'levels' => array('safe'), 'params' => array()),
        'flux_schnell' => array('provider' => 'fal', 'op' => 'reference', 'endpoints' => array('fal' => 'fal-ai/flux/schnell'),
            'label' => 'Quick draft', 'purpose' => 'Fast and cheap, good for testing',
            'price_usd' => 0.003, 'price_unit' => 'image', 'levels' => array('safe', 'spicy'), 'params' => array('num_inference_steps' => 4)),
        // -- reference-based edits (image + prompt -> image): training set, face photo -> reference --
        'nano_banana_edit' => array('provider' => 'fal', 'op' => 'training_set', 'endpoints' => array('fal' => 'fal-ai/nano-banana/edit'),
            'label' => 'Consistent likeness', 'purpose' => 'Keeps the same face across variations',
            'price_usd' => 0.039, 'price_unit' => 'image', 'levels' => array('safe'), 'params' => array()),
        // -- generation with the trained weights --
        'flux_lora_quality' => array('provider' => 'fal', 'op' => 'image', 'endpoints' => array('fal' => 'fal-ai/flux-lora'),
            'label' => 'Best quality', 'purpose' => 'Most detail, best for final posts',
            'price_usd' => 0.035, 'price_unit' => 'image', 'levels' => array('safe', 'spicy'),
            'params' => array('num_inference_steps' => 28, 'guidance_scale' => 3.5, 'acceleration' => 'none')),
        'flux_lora_fast' => array('provider' => 'fal', 'op' => 'image', 'endpoints' => array('fal' => 'fal-ai/flux-lora'),
            'label' => 'Fast', 'purpose' => 'Quicker drafts to explore ideas',
            'price_usd' => 0.035, 'price_unit' => 'image', 'levels' => array('safe', 'spicy'),
            'params' => array('num_inference_steps' => 16, 'guidance_scale' => 3.5, 'acceleration' => 'regular')),
        // -- image -> video --
        'hailuo_02' => array('provider' => 'fal', 'op' => 'video', 'endpoints' => array('fal' => 'fal-ai/minimax/hailuo-02/standard/image-to-video'),
            'label' => 'Natural motion', 'purpose' => 'Smooth, budget friendly',
            'price_usd' => 0.045, 'price_unit' => 'second', 'levels' => array('safe'),
            'durations' => array('6', '10'), 'params' => array('resolution' => '768P', 'prompt_optimizer' => true), 'credits' => 20),
        'kling_v3' => array('provider' => 'fal', 'op' => 'video', 'endpoints' => array('fal' => 'fal-ai/kling-video/v3/standard/image-to-video'),
            'label' => 'Cinematic', 'purpose' => 'Higher quality, with sound',
            'price_usd' => 0.084, 'price_unit' => 'second', 'levels' => array('safe'),
            'durations' => array('5', '10'), 'params' => array('generate_audio' => true, 'cfg_scale' => 0.5), 'credits' => 30),
        // -- enhance --
        'clarity_upscaler' => array('provider' => 'fal', 'op' => 'enhance', 'endpoints' => array('fal' => 'fal-ai/clarity-upscaler'),
            'label' => 'Enhance', 'purpose' => 'Upscale 2x with more detail',
            'price_usd' => 0.03, 'price_unit' => 'image', 'levels' => array('safe', 'spicy'),
            'params' => array('upscale_factor' => 2, 'creativity' => 0.3, 'resemblance' => 0.8)),
        // -- training --
        'flux_lora_fast_training' => array('provider' => 'fal', 'op' => 'training', 'endpoints' => array('fal' => 'fal-ai/flux-lora-fast-training'),
            'label' => 'Standard training', 'purpose' => 'Flux LoRA, about 1000 steps',
            'price_usd' => 2.00, 'price_unit' => 'per_1000_steps', 'levels' => array('safe', 'spicy'), 'params' => array()),
    );

    /** Model keys offered in each picker, in display order (first = default). */
    const PICKERS = array(
        'reference'    => array('flux_pro_11', 'flux_schnell'),
        'training_set' => array('nano_banana_edit'),
        'image'        => array('flux_lora_quality', 'flux_lora_fast'),
        'video'        => array('hailuo_02', 'kling_v3'),
        'enhance'      => array('clarity_upscaler'),
        'training'     => array('flux_lora_fast_training'),
    );

    const DEFAULTS = array(
        'brand_model'                 => 'flux_pro_11',   // brand (non-influencer) images: a 'reference' op model
        'cap_per_influencer'          => 3,
        'cap_per_account'             => 6,
        'cap_wait_seconds'            => 20,
        'landing_stale_seconds'       => 600,
        'ceiling_reference'           => 300,
        'ceiling_training_set'        => 300,
        'ceiling_image'               => 300,
        'ceiling_enhance'             => 300,
        'ceiling_video'               => 900,
        'ceiling_training'            => 3600,
        'training_steps'              => 1000,
        'training_set_size'           => 10,
        'training_min_photos'         => 10,
        'training_max_photos'         => 50,
        'training_lora_scale'         => 1.0,
        'training_zip_url_ttl'        => 21600,
        'training_zip_max_bytes'      => 209715200,
        'training_lora_max_bytes'     => 536870912,
        'training_image_max_side'     => 1536,
        'weights_url_ttl'             => 3600,
        'input_url_ttl'               => 1800,
        'output_max_image_bytes'      => 31457280,
        'output_max_video_bytes'      => 1073741824,
        'inline_budget_seconds'       => 240,
    );

    const POLL = array(
        'image'        => array(5, 5, 10, 10, 15, 20, 30, 45, 60),
        'reference'    => array(5, 5, 10, 10, 15, 20, 30, 45, 60),
        'training_set' => array(5, 5, 10, 10, 15, 20, 30, 45, 60),
        'enhance'      => array(5, 5, 10, 10, 15, 20, 30, 45, 60),
        'video'        => array(15, 15, 30, 30, 60),
        'training'     => array(30, 60, 60, 120),
    );

    /* ---- overrides ---- */

    private static $overrides = array();

    /** Runtime override of an infl_<key> value (tests, one-off scripts). Null removes it. */
    public static function set_override($key, $value){
        if ($value === null) { unset(self::$overrides[$key]); } else { self::$overrides[$key] = $value; }
    }

    /** Raw app.ini [global] value for infl_<key> (or a runtime override), or null when absent/empty. */
    private static function ini($key){
        static $g = null;
        if (array_key_exists($key, self::$overrides)) { return self::$overrides[$key]; }
        if ($g === null) { $cfg = Main::get_config(); $g = (array) ($cfg['global'] ?? array()); }
        $v = $g['infl_' . $key] ?? null;
        return ($v === null || $v === '') ? null : $v;
    }

    public static function get($key, $default = null){
        $v = self::ini($key);
        if ($v !== null) { return is_numeric($v) ? $v + 0 : $v; }
        return array_key_exists($key, self::DEFAULTS) ? self::DEFAULTS[$key] : $default;
    }

    private static function list_override($key, array $default){
        $v = self::ini($key);
        if ($v === null) { return $default; }
        $out = array_values(array_filter(array_map('trim', explode(',', (string) $v)), 'strlen'));
        return empty($out) ? $default : $out;
    }

    /* ---- providers ---- */

    public static function providers_for($op){
        return self::list_override('providers_' . $op, self::PROVIDER_ORDER[$op] ?? array('fal'));
    }

    private static $extra_providers = array();

    /** Register a provider class at runtime (a second adapter shipped later, or a test double). */
    public static function register_provider($key, $class){ self::$extra_providers[(string) $key] = (string) $class; }

    /** Class name for a provider key, or '' when unknown (an unknown key is simply skipped). */
    public static function provider_class($key){
        $class = self::$extra_providers[$key] ?? (self::PROVIDERS[$key] ?? '');
        return ($class !== '' && class_exists($class)) ? $class : '';
    }

    /* ---- models / pickers ---- */

    public static function model($key){
        $m = self::MODELS[$key] ?? null;
        if (!$m) { return null; }
        $m['key'] = $key;
        $m['endpoint'] = self::endpoint_for($m, (string) $m['provider']);   // primary provider's endpoint
        $pr = self::ini('price_' . $key);
        if ($pr !== null && is_numeric($pr)) { $m['price_usd'] = (float) $pr; }
        return $m;
    }

    /** Endpoint id a given provider uses for this catalog entry, or '' when it cannot serve it. */
    public static function endpoint_for(array $model, $provider){
        $key = (string) ($model['key'] ?? '');
        $v = self::ini('endpoint_' . $key . '_' . $provider);
        if ($v !== null) { return (string) $v; }
        if ($provider === (string) ($model['provider'] ?? '')) {
            $v = self::ini('endpoint_' . $key);
            if ($v !== null) { return (string) $v; }
        }
        return (string) (($model['endpoints'] ?? array())[$provider] ?? '');
    }

    /** Ordered catalog entries for a picker purpose; the first one is the default. */
    public static function picker($purpose){
        $out = array();
        foreach (self::list_override('pickers_' . $purpose, self::PICKERS[$purpose] ?? array()) as $k) {
            $m = self::model($k);
            if ($m) { $out[] = $m; }
        }
        return $out;
    }

    public static function default_model_key($purpose){
        $p = self::picker($purpose);
        return empty($p) ? '' : (string) $p[0]['key'];
    }

    /** Resolve a model key for an op; falls back to the picker default when the key is unknown or for another op. */
    public static function resolve_model($op, $key){
        $m = ($key !== '' && $key !== null) ? self::model($key) : null;
        if ($m && ($m['op'] ?? '') === $op) { return $m; }
        return self::model(self::default_model_key($op));
    }

    /** Public picker payload for the UI: key, label, purpose, price hint, durations. */
    public static function picker_options($purpose){
        $out = array();
        foreach (self::picker($purpose) as $m) {
            $out[] = array(
                'key'       => $m['key'],
                'label'     => $m['label'],
                'purpose'   => $m['purpose'],
                'price_usd' => (float) $m['price_usd'],
                'price_unit'=> $m['price_unit'],
                'durations' => array_values((array) ($m['durations'] ?? array())),
                'credits'   => isset($m['credits']) ? (int) $m['credits'] : null,   // AI credits per run when it differs from the type's default
            );
        }
        return $out;
    }

    /* ---- caps / ceilings / polling ---- */

    public static function cap($scope){ return max(1, (int) self::get('cap_per_' . $scope, 3)); }

    public static function ceiling($type){ return max(60, (int) self::get('ceiling_' . $type, 300)); }

    public static function poll_delay($type, $n){
        $seq = self::POLL[$type] ?? self::POLL['image'];
        $n = max(0, (int) $n);
        return (int) ($seq[$n] ?? $seq[count($seq) - 1]);
    }

    /* ---- cost ---- */

    /** Estimated USD for one job of this model. $units = images, video seconds, or training steps. */
    public static function price($model_key, $units = 1){
        $m = self::model($model_key);
        if (!$m) { return 0.0; }
        $p = (float) $m['price_usd'];
        switch ((string) $m['price_unit']) {
            case 'per_1000_steps': return round($p * max(1, (int) $units) / 1000, 4);
            case 'second':         return round($p * max(1, (int) $units), 4);
            default:               return round($p * max(1, (int) $units), 4);
        }
    }

    /** Rendering is available once a provider key is configured (infl_enabled=1 forces it on). */
    public static function enabled(){
        $force = self::ini('enabled');
        if ($force !== null) { return (string) $force === '1'; }
        $cfg = Main::get_config();
        return trim((string) ($cfg['global']['fal_api_key'] ?? '')) !== '';
    }
}
