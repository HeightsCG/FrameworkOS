<?php
/**
 * Influencer wizard + presentation logic: the per-path step order, the resume rule (state
 * beats a stale stored step), name suggestions, prebuilt prompts, and the JSON shape the
 * pages consume. Persistence stays in the Influencer*Model classes; rendering jobs are
 * created through InfluencerJobService / InfluencerTrainingService.
 */
class InfluencerService {

    /** Wizard steps per path, in order. 'training' and 'done' are reached by the engine. */
    const STEPS = array(
        'photos'    => array('name', 'photos', 'training', 'done'),
        'reference' => array('name', 'input', 'reference', 'set', 'review', 'training', 'done'),
    );

    /** Gender options (value => label). Required when creating an influencer; drives the subject word and pronouns in every prompt. */
    const GENDERS = array('woman' => 'Woman', 'man' => 'Man');

    /** Name ideas per gender. */
    const NAMES = array(
        'woman'     => array('Ava', 'Mia', 'Luna', 'Sofia', 'Isla', 'Aria', 'Chloe', 'Zoe', 'Nova', 'Lila', 'Maya', 'Elena',
            'Camila', 'Stella', 'Ivy', 'Jade', 'Nina', 'Vera', 'Cleo', 'Rosa', 'Talia', 'Bianca', 'Dahlia', 'Freya', 'Leila', 'Margot'),
        'man'       => array('Leo', 'Mateo', 'Kai', 'Theo', 'Luca', 'Milo', 'Ezra', 'Julian', 'Marco', 'Adrian', 'Dante', 'Rafael',
            'Nico', 'Elias', 'Jonah', 'Felix', 'Omar', 'Hugo', 'Silas', 'Tomas', 'Andre', 'Caleb', 'Idris', 'Mason'),
    );

    /** Drop-in face descriptions for the reference step (text input), per gender. */
    const FACE_PROMPTS = array(
        'woman' => array(
            'Portrait photo of a woman in her mid 20s, long dark wavy hair, warm brown eyes, soft freckles, natural makeup, neutral background, soft daylight, looking at the camera',
            'Portrait photo of a woman in her late 20s, blonde shoulder-length hair, blue eyes, light smile, minimal makeup, clean studio background, even lighting',
            'Portrait photo of a woman in her early 30s, black curly hair, dark brown eyes, defined cheekbones, gold hoop earrings, neutral background, golden hour light',
            'Portrait photo of a woman in her mid 20s, auburn straight hair with bangs, green eyes, small nose, natural look, plain background, soft window light',
        ),
        'man' => array(
            'Portrait photo of a man in his late 20s, short dark hair with a fade, brown eyes, trimmed beard, neutral background, soft daylight, looking at the camera',
            'Portrait photo of a man in his early 30s, sandy blond hair swept back, blue eyes, light stubble, easy smile, clean studio background, even lighting',
            'Portrait photo of a man in his mid 20s, black curly hair, dark brown eyes, strong jawline, clean shaven, neutral background, golden hour light',
            'Portrait photo of a man in his early 30s, auburn hair, green eyes, freckles, short beard, plain background, soft window light',
        ),
    );

    /** Drop-in scene prompts for Generate Images; {subject} becomes "a woman" or "a man" (the trigger word is added at render time). */
    const IMAGE_PROMPTS = array(
        'photo of {subject} at a rooftop cafe at golden hour, iced coffee in hand, city skyline behind, looking at the camera, film grain',
        'mirror selfie of {subject} in a bright bedroom, oversized knit sweater, morning light, phone in hand',
        'photo of {subject} walking on a beach boardwalk at sunset, linen shirt, wind in the hair, shot on 35mm',
        'photo of {subject} sitting on a cafe patio with a croissant, sunglasses pushed up, soft bokeh background, smiling',
        'gym mirror photo of {subject} in athletic wear, water bottle, bright overhead light, confident pose',
        'night out portrait of {subject}, string lights behind, subtle smile, shallow depth of field',
    );

    /** Drop-in motion prompts for Generate Videos (image-to-video: the still supplies the look); {Subject} becomes "The woman" or "The man". */
    const VIDEO_PROMPTS = array(
        '{Subject} turns toward the camera and smiles, hair moving in a light breeze, slow push in',
        '{Subject} laughs and glances away, then back at the lens, handheld feel, soft natural light',
        '{Subject} looks over one shoulder at the camera, then back out to the view, gentle dolly left',
        '{Subject} takes a slow sip of a drink and glances up, steady camera, shallow depth of field',
        '{Subject} walks slowly toward the camera, clothes and hair moving, sun flare passing through',
        '{Subject} stretches and settles back with a relaxed smile, camera drifts in slowly, warm light',
    );

    /** Validate a gender input: 'woman' | 'man', or '' when it is neither (the caller rejects it). */
    public static function gender($in): string {
        $g = is_array($in) ? (string) ($in['gender'] ?? '') : (string) $in;
        return isset(self::GENDERS[$g]) ? $g : '';
    }

    /** The subject word image models need for this influencer: "woman" or "man". */
    public static function noun(array $infl): string {
        return ((string) ($infl['gender'] ?? '') === 'man') ? 'man' : 'woman';
    }

    /** Pronouns for AI-written prompts: [subject, object, possessive], she/her/her or he/him/his. */
    public static function pronouns(array $infl): array {
        return self::noun($infl) === 'man' ? array('he', 'him', 'his') : array('she', 'her', 'her');
    }

    /** Suggestion lists for one gender, with the subject word filled in. */
    public static function prompts_for($gender): array {
        $n = ($gender === 'man') ? 'man' : 'woman';
        $fill = function ($list) use ($n) { return array_map(function ($p) use ($n) { return str_replace(array('{subject}', '{Subject}'), array('a ' . $n, 'The ' . $n), $p); }, $list); };
        return array('face' => self::FACE_PROMPTS[$n], 'image' => $fill(self::IMAGE_PROMPTS), 'video' => $fill(self::VIDEO_PROMPTS));
    }

    /** Training-set variations (reference path). Each becomes one 1:1 job; the user can add steering. */
    const TRAINING_VARIATIONS = array(
        'same person, front-facing portrait, neutral expression, soft studio light, plain background, 1:1 crop of head and shoulders',
        'same person, three-quarter view turned slightly left, gentle smile, natural window light, plain background',
        'same person, three-quarter view turned slightly right, relaxed expression, warm evening light, plain background',
        'same person, profile view, hair tucked behind ear, soft light, plain background',
        'same person, laughing with eyes crinkled, bright daylight, outdoor blurred background',
        'same person, looking over shoulder at the camera, golden hour, outdoor background',
        'same person, close-up of the face, serious expression, dramatic side lighting, dark background',
        'same person, upper body, arms crossed, confident look, overcast daylight, city street background',
        'same person, relaxed at-home look, soft expression, morning light, bedroom background',
        'same person, wearing sunglasses pushed up on the head, big smile, beach background, midday sun',
    );

    /* ---- steps ---- */

    public static function steps_for($path){
        return self::STEPS[$path] ?? self::STEPS['photos'];
    }

    /**
     * Which step the wizard should open at. The stored step is honoured only while it is at
     * or before what the data allows; once training starts the engine owns the step.
     */
    public static function resume_step(array $infl, $counts = null){
        $path = (string) ($infl['path'] ?? '');
        if ($path === '') { return 'path'; }
        if ((string) $infl['status'] === 'ready' && !empty($infl['active_model_id']) && empty($infl['pending_model_id'])) { return 'done'; }
        if (!empty($infl['pending_model_id'])) { return 'training'; }
        $steps  = self::steps_for($path);
        $stored = (string) ($infl['wizard_step'] ?? 'name');
        if ($counts === null) { $counts = self::counts($infl); }

        if ($path === 'photos') {
            $max = 'photos';   // uploading and training happen on the same step
        } else {
            if (!empty($infl['training_set_group'])) {
                $max = ($counts['training_done'] >= (int) InfluencerConfig::get('training_set_size', 10)) ? 'review' : 'set';
            } elseif (!empty($infl['reference_asset_id'])) {
                $max = 'set';
            } elseif (!empty($infl['input_method'])) {
                $max = 'reference';
            } else {
                $max = 'input';
            }
        }
        // Training/done are engine-owned; a stale 'training' with no pending model means it failed.
        if (in_array($stored, array('training', 'done'), true)) { $stored = $max; }
        $si = array_search($stored, $steps, true); $mi = array_search($max, $steps, true);
        if ($si === false) { return $max; }
        // Jobs in flight for this step keep the user on the furthest step.
        $in_flight = (new InfluencerJobsModel())->has_pending((int) $infl['id']);
        return ($in_flight || $si > $mi) ? $max : $stored;
    }

    /** Per-role counts + training-set completion for the resume rule and the cards. */
    public static function counts(array $infl){
        $im = new InfluencerImagesModel();
        $cid = (int) $infl['creator_id']; $iid = (int) $infl['id'];
        $out = array(
            'upload'    => $im->count_role($cid, $iid, 'upload'),
            'training'  => $im->count_role($cid, $iid, 'training'),
            'generated' => $im->count_role($cid, $iid, 'generated'),
            'video'     => $im->count_role($cid, $iid, 'video'),
            'training_done' => 0,
        );
        if (!empty($infl['training_set_group'])) {
            $out['training_done'] = count($im->ready_asset_ids($cid, $iid, 'training'));
        }
        return $out;
    }

    /* ---- names ---- */

    public static function name_suggestions($creator_id, $n = 4, $gender = 'woman'){
        $m = new InfluencersModel();
        $pool = self::NAMES[$gender === 'man' ? 'man' : 'woman']; shuffle($pool);
        $out = array();
        foreach ($pool as $name) {
            if (!$m->name_taken($creator_id, $name)) { $out[] = $name; }
            if (count($out) >= $n) { break; }
        }
        return $out;
    }

    /* ---- engine hooks ---- */

    /** Reference / training-set job finished: keep the wizard status in step. */
    public static function on_wizard_job_finished(array $job){
        $m = new InfluencersModel();
        $infl = $m->get_by_id($job['influencer_id']);
        if (!$infl) { return; }
        if ((string) $job['type'] === 'reference' && (string) $job['status'] === 'done' && empty($infl['reference_asset_id'])) {
            $m->transition($infl['id'], array('status' => 'awaiting_reference', 'wizard_step' => 'reference'), "status IN ('draft','awaiting_reference','failed')");
        }
    }

    /* ---- presentation ---- */

    /** Card / wizard payload for one influencer. */
    public static function influencer_json($creator_id, array $infl, $with_counts = true){
        $creator_id = (int) $creator_id;
        $counts = $with_counts ? self::counts($infl) : null;
        $models = new InfluencerModelsModel();
        $active = !empty($infl['active_model_id']) ? $models->get_by_id($infl['active_model_id']) : null;
        $pending = !empty($infl['pending_model_id']) ? $models->get_by_id($infl['pending_model_id']) : null;
        $cover = (new InfluencerImagesModel())->cover_asset($creator_id, (int) $infl['id']);
        $out = array(
            'id'                  => (int) $infl['id'],
            'name'                => (string) $infl['name'],
            'gender'              => self::gender($infl),
            'status'              => (string) $infl['status'],
            'path'                => (string) ($infl['path'] ?? ''),
            'input_method'        => (string) ($infl['input_method'] ?? ''),
            'is_public'           => (int) $infl['is_public'],
            'source_description'  => (string) ($infl['source_description'] ?? ''),
            'reference_model_key' => (string) ($infl['reference_model_key'] ?? ''),
            'steer_text'          => (string) ($infl['steer_text'] ?? ''),
            'prompt_defaults'     => (string) ($infl['prompt_defaults'] ?? ''),
            'negative_prompt'     => (string) ($infl['negative_prompt'] ?? ''),
            'face_asset_id'       => (int) ($infl['face_asset_id'] ?? 0),
            'reference_asset_id'  => (int) ($infl['reference_asset_id'] ?? 0),
            'training_set_group'  => (string) ($infl['training_set_group'] ?? ''),
            'active_model_id'     => (int) ($infl['active_model_id'] ?? 0),
            'pending_model_id'    => (int) ($infl['pending_model_id'] ?? 0),
            'trigger_word'        => $active ? (string) $active['trigger_word'] : ($pending ? (string) $pending['trigger_word'] : ''),
            'trained_at'          => $active ? (string) $active['trained_at'] : '',
            'share_accounts'      => InfluencersModel::share_accounts($infl),
            'locked'              => Plan::is_locked(InfluencerJobService::user($creator_id), 'influencers', (int) $infl['id']),
            'last_error'          => (string) ($infl['last_error'] ?? ''),
            'wizard_step'         => self::resume_step($infl, $counts),
            'counts'              => $counts,
            'cover_url'           => $cover ? MediaService::signed_url($cover, 'thumb', $creator_id) : '',
            'created_at'          => (string) $infl['created_at'],
            'updated_at'          => (string) $infl['updated_at'],
        );
        return $out;
    }

    /** Ready, signed image rows for a role (wizard grids, still pickers). */
    public static function images_json($creator_id, $influencer_id, $role = '', $include_excluded = false){
        $out = array();
        foreach ((new InfluencerImagesModel())->list_for_influencer($creator_id, $influencer_id, $role, $include_excluded) as $a) {
            $out[] = array(
                'id' => (int) $a['id'], 'role' => (string) $a['role'], 'job_id' => (int) $a['job_id'], 'result_index' => (int) $a['result_index'],
                'sort_order' => (int) $a['sort_order'], 'is_excluded' => (int) $a['is_excluded'], 'status' => (string) $a['status'],
                'type' => (string) $a['type'], 'width' => (int) $a['width'], 'height' => (int) $a['height'], 'duration' => (int) $a['duration_sec'],
                'thumb_url'   => ($a['status'] === 'ready') ? MediaService::signed_url($a, 'thumb', $creator_id) : '',
                'display_url' => ($a['status'] === 'ready') ? MediaService::signed_url($a, $a['type'] === 'video' ? 'poster' : 'display', $creator_id) : '',
                'moderation'  => (string) $a['moderation_status'],
            );
        }
        return $out;
    }

    /** Static config the pages need: pickers by purpose, limits, prompts, whether rendering is configured. */
    public static function page_config(){
        return array(
            'enabled' => InfluencerConfig::enabled(),
            'pickers' => array(
                'reference'    => InfluencerConfig::picker_options('reference'),
                'training_set' => InfluencerConfig::picker_options('training_set'),
                'image'        => InfluencerConfig::picker_options('image'),
                'video'        => InfluencerConfig::picker_options('video'),
                'enhance'      => InfluencerConfig::picker_options('enhance'),
                'training'     => InfluencerConfig::picker_options('training'),
            ),
            'limits' => array(
                'min_photos' => (int) InfluencerConfig::get('training_min_photos', 10),
                'max_photos' => (int) InfluencerConfig::get('training_max_photos', 50),
                'set_size'   => (int) InfluencerConfig::get('training_set_size', 10),
                'steps'      => (int) InfluencerConfig::get('training_steps', 1000),
            ),
            'training_cost_usd' => InfluencerConfig::price(InfluencerConfig::default_model_key('training'), (int) InfluencerConfig::get('training_steps', 1000)),
            'ai_prices' => PlanTiers::AI_PRICES,   // what each run costs the account in AI credits
            'ai_credits' => 0,                     // the owner's balance; set per request by the page controller
            'prompts' => array('woman' => self::prompts_for('woman'), 'man' => self::prompts_for('man')),   // picked by the influencer's gender
            'genders' => self::GENDERS,
            'steps'   => self::STEPS,
        );
    }
}
