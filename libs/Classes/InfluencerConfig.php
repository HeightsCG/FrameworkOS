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
 * Operations: reference | training_set | image | video | enhance | training, plus the metered
 * ones priced from provider cost (METERED_OPS): replicate | edit | angle | motion | replace | scene.
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
        'replicate'    => array('fal'),
        'edit'         => array('fal'),
        'angle'        => array('fal'),
        'motion'       => array('fal'),
        'replace'      => array('fal'),
        'scene'        => array('fal'),
        'talking'      => array('fal'),
    );

    /**
     * Ops whose AI credit price is worked out from the model's provider cost (credits_from_usd)
     * instead of a flat PlanTiers::AI_PRICES number. A model can still set its own 'credits'.
     */
    const METERED_OPS = array('replicate', 'edit', 'angle', 'motion', 'talking', 'replace', 'scene', 'speech', 'voice_design');

    /** AI credits charged per provider dollar, rounded to CREDIT_STEP, never under CREDIT_FLOOR. */
    const CREDITS_PER_USD = 700;
    const CREDIT_STEP     = 10;
    const CREDIT_FLOOR    = 10;

    /* Output shapes per model family: Aspect ratio key => the value that endpoint takes. */
    const FLUX_ASPECTS = array('1:1' => 'square_hd', '3:4' => 'portrait_4_3', '4:5' => array('width' => 896, 'height' => 1120),
        '9:16' => 'portrait_16_9', '4:3' => 'landscape_4_3');
    const RATIO_ASPECTS = array('1:1' => '1:1', '3:4' => '3:4', '4:5' => '4:5', '9:16' => '9:16', '4:3' => '4:3');
    const GROK_ASPECTS = array('1:1' => '1:1', '3:4' => '3:4', '9:16' => '9:16', '4:3' => '4:3');   // no 4:5
    const VIDEO_ASPECTS = array('9:16' => '9:16', '3:4' => '3:4', '1:1' => '1:1', '4:3' => '4:3');   // Seedance / Wan reference-to-video: no 4:5
    const SEEDREAM_ASPECTS = array('1:1' => array('width' => 2048, 'height' => 2048), '3:4' => array('width' => 1920, 'height' => 2560),
        '4:5' => array('width' => 1920, 'height' => 2400), '9:16' => array('width' => 2160, 'height' => 3840), '4:3' => array('width' => 2560, 'height' => 1920));

    /**
     * Model catalog. `label`/`purpose` are what the user sees; `endpoints` maps each provider
     * that can serve the entry to its endpoint id (a fallback provider needs one here or via
     * infl_endpoint_<model_key>_<provider>); `price_usd` + `price_unit` feed the cost estimate;
     * `params` are fixed inputs merged into the request. `family` picks the provider's input
     * builder; `aspects` lists the shapes it renders (none = follows its source image); `ops`
     * lets one entry serve further operations; `max_refs` caps the reference images it accepts.
     */
    const MODELS = array(
        // -- reference image (text -> image), reference path only --
        // `style` picks the rendering sentence put after the face description (InfluencerService::rendering): candid = an
        // unretouched phone photo, polished = a lightly retouched editorial one.
        // Pro 1.1 with the editorial wording (2026-10-07): Ultra, raw or not, and the candid wording all gave an over-bright,
        // symmetric AI face or a dishevelled one; the Polished recipe (this endpoint + POLISHED) made the picture Daniel
        // held up as what "most realistic" should give. So the two presets now share one recipe; drop one when the UI
        // is next touched. The key keeps its name (stored on influencers and jobs).
        'flux_ultra_raw' => array('provider' => 'fal', 'op' => 'reference', 'endpoints' => array('fal' => 'fal-ai/flux-pro/v1.1'),
            'label' => 'Most Realistic', 'purpose' => 'Looks like a real photo', 'style' => 'polished',
            'price_usd' => 0.04, 'price_unit' => 'image', 'params' => array(), 'family' => 'flux_pro', 'aspects' => self::FLUX_ASPECTS),
        'flux_pro_11' => array('provider' => 'fal', 'op' => 'reference', 'endpoints' => array('fal' => 'fal-ai/flux-pro/v1.1'),
            'label' => 'Polished', 'purpose' => 'Smoother, more retouched look', 'style' => 'polished',
            'price_usd' => 0.04, 'price_unit' => 'image', 'params' => array(), 'family' => 'flux_pro', 'aspects' => self::FLUX_ASPECTS),
        'flux_schnell' => array('provider' => 'fal', 'op' => 'reference', 'endpoints' => array('fal' => 'fal-ai/flux/schnell'),
            'label' => 'Quick Draft', 'purpose' => 'Fast and cheap, good for testing', 'style' => 'polished',
            'price_usd' => 0.003, 'price_unit' => 'image', 'params' => array('num_inference_steps' => 4), 'family' => 'flux', 'aspects' => self::FLUX_ASPECTS),
        // -- reference-based edits (image + prompt -> image): training set, face photo -> reference --
        'nano_banana_edit' => array('provider' => 'fal', 'op' => 'training_set', 'endpoints' => array('fal' => 'fal-ai/nano-banana/edit'),
            'label' => 'Consistent Likeness', 'purpose' => 'Keeps the same face across variations',
            'price_usd' => 0.039, 'price_unit' => 'image', 'params' => array(), 'family' => 'nano_banana', 'aspects' => self::RATIO_ASPECTS,
            'ops' => array('edit', 'angle'), 'max_refs' => 8),
        // -- replicate a photo (source + identity references -> image) --
        'nano_banana_pro_edit' => array('provider' => 'fal', 'op' => 'replicate', 'endpoints' => array('fal' => 'fal-ai/nano-banana-pro/edit'),
            'label' => 'Best Match', 'purpose' => 'Closest to the source photo and her face',
            'price_usd' => 0.15, 'price_unit' => 'image', 'params' => array('resolution' => '2K'), 'family' => 'nano_banana', 'aspects' => self::RATIO_ASPECTS,
            'ops' => array('angle', 'reference'), 'max_refs' => 8),   // 'reference': Change Look edits her reference image
        'seedream_45_edit' => array('provider' => 'fal', 'op' => 'replicate', 'endpoints' => array('fal' => 'fal-ai/bytedance/seedream/v4.5/edit'),
            'label' => 'Budget', 'purpose' => 'Good likeness at a lower price',
            'price_usd' => 0.04, 'price_unit' => 'image', 'params' => array(), 'family' => 'seedream', 'aspects' => self::SEEDREAM_ASPECTS, 'max_refs' => 10,
            'ops' => array('angle')),   // 'angle': her full-body reference, where the described build has to show (Nano Banana keeps the body it guesses)
        // -- edit by instruction (image + instruction -> image) --
        'grok_edit' => array('provider' => 'fal', 'op' => 'edit', 'endpoints' => array('fal' => 'xai/grok-imagine-image/edit'),
            'label' => 'Precise Edit', 'purpose' => 'Changes only what you ask for',
            'price_usd' => 0.022, 'price_unit' => 'image', 'params' => array('resolution' => '2k'), 'family' => 'grok', 'aspects' => self::GROK_ASPECTS, 'max_refs' => 3),
        // -- generation with the trained weights --
        'flux_lora_quality' => array('provider' => 'fal', 'op' => 'image', 'endpoints' => array('fal' => 'fal-ai/flux-lora'),
            'label' => 'Best Quality', 'purpose' => 'Most detail, best for final posts',
            'price_usd' => 0.035, 'price_unit' => 'image',
            'params' => array('num_inference_steps' => 28, 'guidance_scale' => 3.5, 'acceleration' => 'none'), 'family' => 'flux', 'aspects' => self::FLUX_ASPECTS),
        'flux_lora_fast' => array('provider' => 'fal', 'op' => 'image', 'endpoints' => array('fal' => 'fal-ai/flux-lora'),
            'label' => 'Fast', 'purpose' => 'Quicker drafts to explore ideas',
            'price_usd' => 0.035, 'price_unit' => 'image',
            'params' => array('num_inference_steps' => 16, 'guidance_scale' => 3.5, 'acceleration' => 'regular'), 'family' => 'flux', 'aspects' => self::FLUX_ASPECTS),
        // -- image -> video --
        'hailuo_02' => array('provider' => 'fal', 'op' => 'video', 'endpoints' => array('fal' => 'fal-ai/minimax/hailuo-02/standard/image-to-video'),
            'label' => 'Natural Motion', 'purpose' => 'Smooth, budget friendly',
            'price_usd' => 0.045, 'price_unit' => 'second',
            'durations' => array('6', '10'), 'params' => array('resolution' => '768P', 'prompt_optimizer' => true), 'credits' => array('6' => 200, '10' => 340), 'family' => 'hailuo'),
        'kling_v3' => array('provider' => 'fal', 'op' => 'video', 'endpoints' => array('fal' => 'fal-ai/kling-video/v3/standard/image-to-video'),
            'label' => 'Cinematic', 'purpose' => 'Higher quality, with sound',
            'price_usd' => 0.084, 'price_unit' => 'second',
            'durations' => array('5', '10'), 'params' => array('generate_audio' => true, 'cfg_scale' => 0.5), 'credits' => array('5' => 300, '10' => 600), 'family' => 'kling_i2v'),
        // -- motion control (a reference motion video + a first frame -> video); priced per second of the reference video --
        'kling_v3_motion_std' => array('provider' => 'fal', 'op' => 'motion', 'endpoints' => array('fal' => 'fal-ai/kling-video/v3/standard/motion-control'),
            'label' => '720p', 'purpose' => 'Standard quality', 'price_usd' => 0.126, 'price_unit' => 'second', 'quality' => '720p',
            'params' => array('character_orientation' => 'video', 'keep_original_sound' => true), 'family' => 'kling_motion', 'min_seconds' => 3, 'max_seconds' => 30),
        'kling_v3_motion_pro' => array('provider' => 'fal', 'op' => 'motion', 'endpoints' => array('fal' => 'fal-ai/kling-video/v3/pro/motion-control'),
            'label' => '1080p', 'purpose' => 'Highest quality', 'price_usd' => 0.168, 'price_unit' => 'second', 'quality' => '1080p',
            'params' => array('character_orientation' => 'video', 'keep_original_sound' => true), 'family' => 'kling_motion', 'min_seconds' => 3, 'max_seconds' => 30),
        // -- character replacement in a source video; priced per second of the source --
        'wan_30_ref' => array('provider' => 'fal', 'op' => 'replace', 'endpoints' => array('fal' => 'alibaba/wan-3.0/reference-to-video'),
            'label' => 'Wan 3.0', 'purpose' => 'Best at keeping the original scene', 'price_usd' => 0.10, 'price_unit' => 'second',
            'params' => array('resolution' => '720p', 'aspect_ratio' => 'adaptive', 'audio' => true, 'enable_prompt_expansion' => false, 'enable_safety_checker' => false),
            'family' => 'wan_ref', 'min_seconds' => 2, 'max_seconds' => 15, 'max_refs' => 6),
        'seedance_20_ref' => array('provider' => 'fal', 'op' => 'replace', 'endpoints' => array('fal' => 'bytedance/seedance-2.0/reference-to-video'),
            'label' => 'Seedance 2.0', 'purpose' => 'Stronger likeness, costs more', 'price_usd' => 0.1814, 'price_unit' => 'second',
            'params' => array('resolution' => '720p', 'aspect_ratio' => 'auto', 'generate_audio' => true),
            'family' => 'seedance_ref', 'min_seconds' => 2, 'max_seconds' => 15, 'max_refs' => 6),
        // -- long dialogue scene in a single take (character references + a script -> video with speech) --
        'wan_30_scene_final' => array('provider' => 'fal', 'op' => 'scene', 'endpoints' => array('fal' => 'alibaba/wan-3.0/reference-to-video'),
            'label' => 'Final', 'purpose' => 'Full quality, 1080p', 'price_usd' => 0.20, 'price_unit' => 'second',
            'params' => array('resolution' => '1080p', 'audio' => true, 'enable_prompt_expansion' => false, 'enable_safety_checker' => false),
            'family' => 'wan_ref', 'aspects' => self::VIDEO_ASPECTS, 'min_seconds' => 4, 'max_seconds' => 30, 'max_refs' => 8),
        'wan_30_scene_draft' => array('provider' => 'fal', 'op' => 'scene', 'endpoints' => array('fal' => 'alibaba/wan-3.0/reference-to-video'),
            'label' => 'Draft', 'purpose' => 'Check the take first, 480p', 'price_usd' => 0.05, 'price_unit' => 'second',
            'params' => array('resolution' => '480p', 'audio' => true, 'enable_prompt_expansion' => false, 'enable_safety_checker' => false),
            'family' => 'wan_ref', 'aspects' => self::VIDEO_ASPECTS, 'min_seconds' => 4, 'max_seconds' => 30, 'max_refs' => 8),
        // Seedance (2.0 and 2.5) on fal refuses photorealistic reference images of people ("may contain likenesses of real
        // people", partner_validation_failed; checked 2026-10-05), so it cannot take an influencer's references. The entries
        // stay for when that changes: offer them again through PICKERS (or infl_pickers_scene / infl_pickers_replace).
        'seedance_25_final' => array('provider' => 'fal', 'op' => 'scene', 'endpoints' => array('fal' => 'bytedance/seedance-2.5/reference-to-video'),
            'label' => 'Final', 'purpose' => 'Full quality, 720p', 'price_usd' => 0.473, 'price_unit' => 'second',
            'params' => array('resolution' => '720p', 'generate_audio' => true), 'family' => 'seedance_ref', 'aspects' => self::VIDEO_ASPECTS,
            'min_seconds' => 4, 'max_seconds' => 30, 'max_refs' => 8),
        'seedance_25_draft' => array('provider' => 'fal', 'op' => 'scene', 'endpoints' => array('fal' => 'bytedance/seedance-2.5/reference-to-video'),
            'label' => 'Draft', 'purpose' => 'Check the take first, 480p', 'price_usd' => 0.2205, 'price_unit' => 'second',
            'params' => array('resolution' => '480p', 'generate_audio' => true), 'family' => 'seedance_ref', 'aspects' => self::VIDEO_ASPECTS,
            'min_seconds' => 4, 'max_seconds' => 30, 'max_refs' => 8),
        // -- talking video: a close-up + speech audio -> lip-synced video, priced per second of audio --
        'heygen_avatar4' => array('provider' => 'fal', 'op' => 'talking', 'endpoints' => array('fal' => 'fal-ai/heygen/avatar4/image-to-video'),
            'label' => 'Lip Sync', 'purpose' => 'Photo to talking video, 1080p', 'price_usd' => 0.10, 'price_unit' => 'second',
            'params' => array('resolution' => '1080p', 'talking_style' => 'expressive'), 'family' => 'heygen',
            'aspects' => array('9:16' => '9:16', '4:5' => '4:5', '1:1' => '1:1'), 'min_seconds' => 1, 'max_seconds' => 600),
        // -- voice: ElevenLabs, called directly (ElevenLabsService), priced per 1,000 characters. price_usd is an estimate of
        //    the platform plan's cost per 1,000 characters: set infl_price_eleven_v3 / infl_price_eleven_voice_design to the real one. --
        'eleven_v3' => array('provider' => 'elevenlabs', 'op' => 'speech', 'endpoints' => array(),
            'label' => 'Eleven V3', 'purpose' => 'Expressive speech with audio tags', 'price_usd' => 0.20, 'price_unit' => '1000_chars', 'params' => array()),
        'eleven_voice_design' => array('provider' => 'elevenlabs', 'op' => 'voice_design', 'endpoints' => array(),
            'label' => 'Voice Design', 'purpose' => 'Three candidate voices from a description', 'price_usd' => 0.20, 'price_unit' => '1000_chars', 'params' => array()),
        // -- enhance --
        'clarity_upscaler' => array('provider' => 'fal', 'op' => 'enhance', 'endpoints' => array('fal' => 'fal-ai/clarity-upscaler'),
            'label' => 'Enhance', 'purpose' => 'Upscale 2x with more detail',
            'price_usd' => 0.03, 'price_unit' => 'image',
            'params' => array('upscale_factor' => 2, 'creativity' => 0.3, 'resemblance' => 0.8), 'family' => 'clarity'),
        // -- training --
        'flux_lora_fast_training' => array('provider' => 'fal', 'op' => 'training', 'endpoints' => array('fal' => 'fal-ai/flux-lora-fast-training'),
            'label' => 'Standard Training', 'purpose' => 'Flux LoRA, about 1000 steps',
            'price_usd' => 2.00, 'price_unit' => 'per_1000_steps', 'params' => array()),
    );

    /** Model keys offered in each picker, in display order (first = default). */
    const PICKERS = array(
        'reference'    => array('flux_ultra_raw', 'flux_pro_11', 'flux_schnell'),
        'training_set' => array('nano_banana_edit'),
        'image'        => array('flux_lora_quality', 'flux_lora_fast'),
        'video'        => array('hailuo_02', 'kling_v3'),
        'enhance'      => array('clarity_upscaler'),
        'training'     => array('flux_lora_fast_training'),
        'replicate'    => array('nano_banana_pro_edit', 'seedream_45_edit'),
        'edit'         => array('grok_edit', 'nano_banana_edit'),
        'angle'        => array('nano_banana_edit', 'nano_banana_pro_edit'),
        'motion'       => array('kling_v3_motion_std', 'kling_v3_motion_pro'),
        'replace'      => array('wan_30_ref'),
        'scene'        => array('wan_30_scene_final', 'wan_30_scene_draft'),
        'talking'      => array('heygen_avatar4'),
        'speech'       => array('eleven_v3'),
        'voice_design' => array('eleven_voice_design'),
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
        'ceiling_motion'              => 2400,
        'ceiling_replace'             => 2400,
        'ceiling_scene'               => 2400,
        'ceiling_talking'             => 2400,
        'voices_per_creator'          => 10,     // saved voices per account: the platform voice account is one shared pool
        'voice_design_previews'       => 3,      // candidates a Voice Design run returns (what a run is charged for)
        'speech_takes'                => 2,      // takes per text to speech run
        'talking_part_seconds'        => 60,     // a longer talking video is rendered in parts of this length and joined
        'talking_max_seconds'         => 300,
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
        'motion'       => array(20, 20, 30, 30, 60),
        'replace'      => array(20, 20, 30, 30, 60),
        'scene'        => array(20, 20, 30, 30, 60),
        'talking'      => array(15, 15, 20, 30, 60),
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
        if ($m && self::serves($m, $op)) { return $m; }
        return self::model(self::default_model_key($op));
    }

    /** Can this catalog entry run $op (its own op, or one listed under 'ops')? */
    public static function serves(array $m, $op){
        return ($m['op'] ?? '') === (string) $op || in_array((string) $op, (array) ($m['ops'] ?? array()), true);
    }

    /** Provider dollars -> AI credits: CREDITS_PER_USD, rounded to CREDIT_STEP, at least CREDIT_FLOOR. */
    public static function credits_from_usd($usd){
        $usd = (float) $usd;
        if ($usd <= 0) { return 0; }
        $per  = (float) self::get('credits_per_usd', self::CREDITS_PER_USD);
        $step = self::CREDIT_STEP;
        return (int) max(self::CREDIT_FLOOR, round($usd * $per / $step) * $step);
    }

    /**
     * AI credits for ONE unit of a metered model: one image, or one run of $duration seconds for a
     * per-second model, or $duration characters for a per-1,000-characters (voice) model. The model's own 'credits' wins when it has one.
     */
    public static function metered_credits(array $m, $duration = ''){
        $own = self::credits_for($m, $duration);
        if ($own !== null && $own > 0) { return $own; }
        $units = 1;
        if ((string) ($m['price_unit'] ?? '') === '1000_chars') {   // $duration carries the character count
            return self::credits_from_usd((float) ($m['price_usd'] ?? 0) * max(1, (int) $duration) / 1000);
        }
        if ((string) ($m['price_unit'] ?? '') === 'second') {
            $durs  = array_values((array) ($m['durations'] ?? array()));
            $units = max(1, (int) ($duration !== '' && $duration !== null ? $duration : ($durs[0] ?? 5)));
        }
        return self::credits_from_usd((float) ($m['price_usd'] ?? 0) * $units);
    }

    /**
     * AI credits a model charges per run, or null when it uses its type's default (PlanTiers::AI_PRICES).
     * A video model prices each length ('credits' => ['6' => 200, '10' => 340]: in proportion to its length);
     * an unknown or empty $duration means the model's first (default) length.
     */
    public static function credits_for(array $m, $duration){
        if (!isset($m['credits'])) { return null; }
        if (!is_array($m['credits'])) { return (int) $m['credits']; }
        $d = (string) $duration;
        return isset($m['credits'][$d]) ? (int) $m['credits'][$d] : (int) reset($m['credits']);
    }

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
                'credits'   => isset($m['credits']) ? self::credits_for($m, '')
                    : (in_array((string) $purpose, self::METERED_OPS, true) ? self::metered_credits($m) : null),   // AI credits per run at the default length
                'aspects'   => Aspect::supported($m),   // shapes it renders; empty = follows its source image
                'min_seconds' => (int) ($m['min_seconds'] ?? 0), 'max_seconds' => (int) ($m['max_seconds'] ?? 0),   // length limits of a per-second model
                'credits_per_second' => ((string) ($m['price_unit'] ?? '') === 'second' && in_array((string) $purpose, self::METERED_OPS, true)) ? (float) $m['price_usd'] * (float) self::get('credits_per_usd', self::CREDITS_PER_USD) : null,
                'quality'   => (string) ($m['quality'] ?? ''),
                'max_refs'  => (int) ($m['max_refs'] ?? 0),
                'credits_by_duration' => (isset($m['credits']) && is_array($m['credits'])) ? array_map('intval', $m['credits']) : null,   // video: price per length
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
