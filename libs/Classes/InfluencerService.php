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
        'reference' => array('name', 'reference', 'set', 'training', 'done'),   // how the reference is made is chosen on the first step; training starts from the finished set
    );

    /** Gender options (value => label). Required when creating an influencer; drives the subject word and pronouns in every prompt. */
    const GENDERS = array('woman' => 'Woman', 'man' => 'Man');

    /**
     * Who the influencer is, for every AI writer (captions, DMs, automations, launch posts): column => label, max length,
     * textarea rows, placeholder. {Her} in a label follows the influencer's gender.
     */
    const PERSONA = array(
        'persona_description'   => array('label' => 'Description',               'max' => 3000, 'rows' => 3, 'placeholder' => '24, grew up in Tampa, moved to Miami for nursing school'),
        'persona_personality'   => array('label' => 'Personality',               'max' => 1000, 'rows' => 2, 'placeholder' => 'Warm, teasing, a little shy at first'),
        'persona_speaking'      => array('label' => 'Way Of Speaking',           'max' => 1000, 'rows' => 2, 'placeholder' => 'Short sentences, lowercase, no emoji'),
        'persona_niche'         => array('label' => 'Niche',                     'max' => 255,  'rows' => 1, 'placeholder' => 'Fitness and gym life'),
        'persona_vulnerability' => array('label' => 'What Makes {Her} Vulnerable', 'max' => 1000, 'rows' => 2, 'placeholder' => 'Feels invisible next to her older sister'),
    );

    /**
     * The multi-angle reference set: slot => label, shape, and what to ask the edit model for. Approved slots are sent
     * with her reference as identity inputs to Replicate, Carousel, Motion Control and Replace Character.
     */
    const ANGLES = array(
        'front_close_1' => array('label' => 'Front Close-Up 1', 'aspect' => '3:4', 'prompt' => 'Close-up portrait, head and shoulders, facing the camera directly, neutral relaxed expression, eyes to camera'),
        'front_close_2' => array('label' => 'Front Close-Up 2', 'aspect' => '3:4', 'prompt' => 'Close-up portrait, head and shoulders, facing the camera, soft natural smile, slight head tilt'),
        'front_close_3' => array('label' => 'Front Close-Up 3', 'aspect' => '3:4', 'prompt' => 'Close-up portrait, head and shoulders, head turned a three-quarter view, eyes to camera, calm expression'),
        'left_profile'  => array('label' => 'Left Profile',     'aspect' => '3:4', 'prompt' => 'Side profile portrait, head and shoulders, turned ninety degrees so the left side of the face is to the camera, looking straight ahead'),
        'right_profile' => array('label' => 'Right Profile',    'aspect' => '3:4', 'prompt' => 'Side profile portrait, head and shoulders, turned ninety degrees so the right side of the face is to the camera, looking straight ahead'),
        'back'          => array('label' => 'Back View',        'aspect' => '3:4', 'prompt' => 'Seen from directly behind, head and shoulders, the back of the head and hair, face not visible'),
        'full_front'    => array('label' => 'Full Body Front',  'aspect' => '9:16', 'prompt' => 'Full body, standing straight facing the camera, arms relaxed at the sides, head to toe in frame, plain fitted t-shirt and jeans'),
        'full_back'     => array('label' => 'Full Body Back',   'aspect' => '9:16', 'prompt' => 'Full body seen from directly behind, standing straight, arms relaxed at the sides, head to toe in frame, plain fitted t-shirt and jeans'),
    );

    /** The prompt for one angle slot. */
    public static function angle_prompt(array $infl, $slot){
        $def = self::ANGLES[$slot] ?? null;
        if (!$def) { return ''; }
        $body = (strpos((string) $slot, 'full_') === 0) ? self::body_phrase($infl) : '';   // the full-body slots show her build
        return 'Keep the exact same ' . self::noun($infl) . ' as in the reference image: identical face, hair, skin tone and body. '
            . $def['prompt'] . ($body !== '' ? ', ' . $body : '') . '. Plain light grey wall behind, flat daylight. ' . self::REALISM;
    }

    /** The one image that defines her: the approved reference, else her face photo, else her first training photo, else her newest render. 0 when none. */
    public static function base_reference($creator_id, array $infl){
        $mm = new MediaAssetsModel();
        foreach (array((int) ($infl['reference_asset_id'] ?? 0), (int) ($infl['face_asset_id'] ?? 0)) as $aid) {
            if ($aid <= 0) { continue; }
            $a = $mm->get_one($creator_id, $aid);
            if ($a && (string) $a['status'] === 'ready' && (string) $a['moderation_status'] !== 'blocked') { return $aid; }
        }
        $im = new InfluencerImagesModel();
        foreach (array('upload', 'training', 'generated') as $role) {
            $ids = $im->ready_asset_ids($creator_id, (int) $infl['id'], $role);
            if (!empty($ids)) { return ($role === 'generated') ? (int) end($ids) : (int) $ids[0]; }
        }
        return 0;
    }

    /** Identity inputs for the edit and video models: her reference first, then her approved angle references in slot order. */
    public static function identity_refs($creator_id, array $infl, $max = 6){
        $ids  = array();
        $base = self::base_reference($creator_id, $infl);
        if ($base > 0) { $ids[] = $base; }
        $by = array();
        foreach ((new InfluencerImagesModel())->list_for_influencer($creator_id, (int) $infl['id'], 'angle') as $r) {
            if (!empty($r['approved']) && (string) $r['status'] === 'ready' && (string) $r['moderation_status'] !== 'blocked') { $by[(string) $r['angle']] = (int) $r['id']; }
        }
        foreach (array_keys(self::ANGLES) as $slot) { if (isset($by[$slot])) { $ids[] = $by[$slot]; } }
        return array_slice(array_values(array_unique($ids)), 0, max(1, (int) $max));
    }

    /** Her persona fields as set, keyed without the persona_ prefix (empty ones left out). */
    public static function persona(array $infl){
        $out = array();
        foreach (self::PERSONA as $col => $def) {
            $v = trim((string) ($infl[$col] ?? ''));
            if ($v !== '') { $out[substr($col, 8)] = $v; }
        }
        return $out;
    }

    /** The persona as a block of plain text for an AI writer's system prompt; '' when nothing is filled in. */
    public static function persona_block($infl){
        if (!is_array($infl)) { return ''; }
        $p = self::persona($infl);
        if (empty($p)) { return ''; }
        list($pr, $po, $ps) = self::pronouns($infl);
        $lines = array('You are writing as ' . (string) $infl['name'] . '. Stay in ' . $ps . ' voice in everything you write.');
        if (isset($p['description']))   { $lines[] = 'About ' . $po . ': ' . $p['description']; }
        if (isset($p['personality']))   { $lines[] = 'Personality: ' . $p['personality']; }
        if (isset($p['speaking']))      { $lines[] = 'How ' . $pr . ' speaks: ' . $p['speaking']; }
        if (isset($p['niche']))         { $lines[] = 'Niche: ' . $p['niche']; }
        if (isset($p['vulnerability'])) { $lines[] = 'What makes ' . $po . ' vulnerable: ' . $p['vulnerability']; }
        return implode("\n", $lines);
    }

    /** Name ideas per gender. */
    const NAMES = array(
        'woman'     => array('Ava', 'Mia', 'Luna', 'Sofia', 'Isla', 'Aria', 'Chloe', 'Zoe', 'Nova', 'Lila', 'Maya', 'Elena',
            'Camila', 'Stella', 'Ivy', 'Jade', 'Nina', 'Vera', 'Cleo', 'Rosa', 'Talia', 'Bianca', 'Dahlia', 'Freya', 'Leila', 'Margot'),
        'man'       => array('Leo', 'Mateo', 'Kai', 'Theo', 'Luca', 'Milo', 'Ezra', 'Julian', 'Marco', 'Adrian', 'Dante', 'Rafael',
            'Nico', 'Elias', 'Jonah', 'Felix', 'Omar', 'Hugo', 'Silas', 'Tomas', 'Andre', 'Caleb', 'Idris', 'Mason'),
    );

    /**
     * Body settings: column => label, the choices (value => label), and the words each choice adds to a prompt.
     * The face comes from the trained model; the body comes from these words, so they go on every image prompt,
     * on the full-body reference images and on the full-body shots of the training set.
     */
    const BODY = array(
        'body_height' => array('label' => 'Height', 'options' => array('' => 'Not Set', 'short' => 'Short', 'average' => 'Average', 'tall' => 'Tall'),
            'words' => array('short' => 'short stature', 'average' => 'average height', 'tall' => 'tall, long legs')),
        'body_build'  => array('label' => 'Build', 'options' => array('' => 'Not Set', 'thin' => 'Thin', 'average' => 'Average', 'athletic' => 'Athletic', 'muscular' => 'Muscular', 'curvy' => 'Curvy'),
            'words' => array('thin' => 'thin, slender build', 'average' => 'average build', 'athletic' => 'athletic, toned build', 'muscular' => 'muscular build', 'curvy' => 'curvy build')),
        'body_bust'   => array('label' => 'Bust', 'women_only' => true, 'options' => array('' => 'Not Set', 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'),
            'words' => array('small' => 'small bust', 'medium' => 'medium bust', 'large' => 'large bust')),
    );

    /** A valid choice for a body column, or ''. */
    public static function body_value($col, $value){
        $v = strtolower(trim((string) $value));
        return (isset(self::BODY[$col]) && $v !== '' && isset(self::BODY[$col]['options'][$v])) ? $v : '';
    }

    /** Her body in prompt words ("tall, long legs, athletic, toned build"), '' when nothing is set. */
    public static function body_phrase($infl){
        if (!is_array($infl)) { return ''; }
        $out = array();
        foreach (self::BODY as $col => $def) {
            if (!empty($def['women_only']) && self::noun($infl) !== 'woman') { continue; }
            $v = (string) ($infl[$col] ?? '');
            if ($v !== '' && isset($def['words'][$v])) { $out[] = $def['words'][$v]; }
        }
        return implode(', ', $out);
    }

    /** What is added to every image prompt for her: the body words, then her own Always Add To Prompts text. */
    public static function look_defaults($infl){
        $body = self::body_phrase($infl);
        $own  = trim((string) (is_array($infl) ? ($infl['prompt_defaults'] ?? '') : ''));
        return trim($body . (($body !== '' && $own !== '') ? ', ' : '') . $own);
    }

    /**
     * The look every image of an influencer should have: a real, unretouched photograph. Added to the
     * reference image and the training set, because those two decide how everything trained from them looks:
     * a polished, airbrushed set trains a polished, airbrushed model.
     */
    const REALISM = 'Unretouched candid photograph taken on a phone camera, not a studio portrait. Real skin with visible pores, fine lines, faint blemishes and slightly uneven tone, stray flyaway hairs, natural facial asymmetry, ordinary uneven available light, true-to-life colour, slight sensor grain. No airbrushing, no beauty filter, no skin smoothing, no glow, no perfect symmetry, no CGI, render or illustration look.';

    /** A prompt with the realism direction on the end (once). */
    public static function realistic($prompt){
        $p = rtrim(trim((string) $prompt), " .,;");
        if ($p === '') { return self::REALISM; }
        return (stripos($p, 'no airbrushing') !== false) ? $p : $p . '. ' . self::REALISM;
    }

    /** Drop-in face descriptions for the reference step (text input), per gender. */
    const FACE_PROMPTS = array(
        'woman' => array(
            'Phone photo of a woman in her mid 20s, long dark wavy hair a little messy, warm brown eyes, freckles across the nose, no makeup, standing by a kitchen window in daylight, looking at the camera',
            'Phone photo of a woman in her late 20s, blonde shoulder-length hair, blue eyes, light smile, barely any makeup, grey t-shirt, sitting in a parked car in overcast daylight',
            'Phone photo of a woman in her early 30s, black curly hair, dark brown eyes, defined cheekbones, small gold hoop earrings, on a city sidewalk in late afternoon sun',
            'Phone photo of a woman in her mid 20s, auburn straight hair with bangs, green eyes, small nose, bare skin, plain white wall behind, window light from one side',
        ),
        'man' => array(
            'Phone photo of a man in his late 20s, short dark hair with a fade, brown eyes, trimmed beard, plain t-shirt, standing by a window in daylight, looking at the camera',
            'Phone photo of a man in his early 30s, sandy blond hair pushed back, blue eyes, light stubble, easy smile, sitting in a parked car in overcast daylight',
            'Phone photo of a man in his mid 20s, black curly hair, dark brown eyes, strong jawline, clean shaven, on a city sidewalk in late afternoon sun',
            'Phone photo of a man in his early 30s, auburn hair, green eyes, freckles, short beard, plain white wall behind, window light from one side',
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

    /** Training-set variations (reference path). Each becomes one 1:1 job; the user can add steering. {body} is where her body words go (training_variation). */
    const TRAINING_VARIATIONS = array(
        'same person, front-facing head and shoulders, neutral expression, flat daylight from a window, plain wall behind',
        'same person, three-quarter view turned slightly left, small smile, window light from one side, living room behind',
        'same person, three-quarter view turned slightly right, relaxed expression, warm evening lamp light indoors',
        'same person, side profile, hair tucked behind the ear, overcast daylight outdoors',
        'same person, laughing mid-laugh with eyes crinkled, bright daylight, street behind slightly out of focus',
        'same person, looking over the shoulder at the camera, late afternoon sun, park behind',
        'same person, close-up of the face, serious expression, light from one side only, dim room',
        'same person, full body standing, head to toe in frame, {body}plain fitted t-shirt and jeans, overcast daylight, city street behind',
        'same person, from the waist up, {body}at home in a plain fitted t-shirt, hair undone, soft expression, morning light, bedroom behind',
        'same person, from the knees up, {body}sunglasses pushed up on the head, big smile, harsh midday sun, beach behind',
    );

    /** One training-set prompt for her: the variation with her body words filled in. */
    public static function training_variation(array $infl, $i){
        $vars = self::TRAINING_VARIATIONS;
        $body = self::body_phrase($infl);
        return str_replace('{body}', $body !== '' ? $body . ', ' : '', $vars[((int) $i) % count($vars)]);
    }

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
                $max = 'set';   // the finished set is where training starts (there is no separate review step)
            } elseif (!empty($infl['reference_asset_id'])) {
                $max = 'set';
            } elseif (!empty($infl['input_method'])) {
                $max = 'reference';
            } else {
                $max = 'name';
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
            'body_height'         => (string) ($infl['body_height'] ?? ''),
            'body_build'          => (string) ($infl['body_build'] ?? ''),
            'body_bust'           => (string) ($infl['body_bust'] ?? ''),
            'negative_prompt'     => (string) ($infl['negative_prompt'] ?? ''),
            'persona_description'   => (string) ($infl['persona_description'] ?? ''),
            'persona_personality'   => (string) ($infl['persona_personality'] ?? ''),
            'persona_speaking'      => (string) ($infl['persona_speaking'] ?? ''),
            'persona_niche'         => (string) ($infl['persona_niche'] ?? ''),
            'persona_vulnerability' => (string) ($infl['persona_vulnerability'] ?? ''),
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
                'replicate'    => InfluencerConfig::picker_options('replicate'),
                'edit'         => InfluencerConfig::picker_options('edit'),
                'angle'        => InfluencerConfig::picker_options('angle'),
                'motion'       => InfluencerConfig::picker_options('motion'),
                'replace'      => InfluencerConfig::picker_options('replace'),
                'scene'        => InfluencerConfig::picker_options('scene'),
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
            'aspect'  => Aspect::client(),
            'persona' => self::PERSONA,
            'body'    => array_map(function ($d) { return array('label' => $d['label'], 'options' => $d['options'], 'women_only' => !empty($d['women_only'])); }, self::BODY),
            'scene_max_lines' => InfluencerVideoActions::SCENE_MAX_LINES,
            'voice' => array('keywords' => InfluencerVoiceActions::KEYWORDS, 'tags' => InfluencerVoiceActions::TAGS, 'preview_min' => ElevenLabsService::PREVIEW_MIN,
                'preview_max' => ElevenLabsService::PREVIEW_MAX, 'speech_max' => ElevenLabsService::SPEECH_MAX),
            'carousel' => array('min' => InfluencerImageActions::CAROUSEL_MIN, 'max' => InfluencerImageActions::CAROUSEL_MAX,
                'focus' => array_map(function ($f) { return $f['label']; }, InfluencerImageActions::CAROUSEL_FOCUS)),
        );
    }
}
