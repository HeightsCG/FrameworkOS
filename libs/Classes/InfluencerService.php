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
        'reference' => array('name', 'reference', 'body', 'set', 'training', 'done'),   // how the reference is made is chosen on the first step; training starts from the finished set
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
        // Profiles are described by where the nose points in the frame: image models mix up "her left" and "her right",
        // but follow "toward the left edge of the picture" reliably.
        'left_profile'  => array('label' => 'Left Profile',     'aspect' => '3:4', 'prompt' => 'Strict side profile, head and shoulders, the head turned a full ninety degrees so the nose points toward the LEFT edge of the picture; the camera sees the left cheek and left ear, only one eye is visible, the far side of the face is hidden; looking straight ahead, not at the camera'),
        'right_profile' => array('label' => 'Right Profile',    'aspect' => '3:4', 'prompt' => 'Strict side profile, head and shoulders, the head turned a full ninety degrees so the nose points toward the RIGHT edge of the picture; the camera sees the right cheek and right ear, only one eye is visible, the far side of the face is hidden; looking straight ahead, not at the camera'),
        'back'          => array('label' => 'Back View',        'aspect' => '3:4', 'prompt' => 'Seen from directly behind, head and shoulders, the back of the head and hair, face not visible'),
        'full_front'    => array('label' => 'Full Body Front',  'aspect' => '9:16', 'prompt' => 'Wide full-length photo taken from several steps back with the camera level at chest height: the whole body from the top of the head to the sneakers on the floor, standing straight facing the camera, arms relaxed at the sides, plain fitted t-shirt and jeans'),
        'full_back'     => array('label' => 'Full Body Back',   'aspect' => '9:16', 'prompt' => 'Full body seen from directly behind, standing straight, arms relaxed at the sides, head to toe in frame, plain fitted t-shirt and jeans'),
    );

    /** The prompt for one angle slot. */
    public static function angle_prompt(array $infl, $slot){
        $def = self::ANGLES[$slot] ?? null;
        if (!$def) { return ''; }
        $body = (strpos((string) $slot, 'full_') === 0) ? self::body_phrase($infl) : '';   // the full-body slots show her build
        // Her reference is a face, so it cannot fix her body: with a build described, the words decide it and are stated as the thing that must show.
        $keep = ($body !== '') ? 'identical face, hair and skin tone' : 'identical face, hair, skin tone and body';
        $full = (strpos((string) $slot, 'full_') === 0);
        return ($full ? $def['prompt'] . '. ' : '') . 'Keep the exact same ' . self::noun($infl) . ' as in the reference image: ' . $keep . '. '
            . ($full ? '' : $def['prompt'] . '. ') . ($body !== '' ? ucfirst(self::pronouns($infl)[2]) . ' body, which must be clearly visible in the picture: ' . $body . '. ' : '')
            . 'Plain light grey wall behind, flat daylight. ' . self::REALISM;
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
            // Every angle she has is used: the newest ready image of each (the list is oldest first, so a later one replaces an earlier one).
            // There is no separate approval step: an angle that looks wrong is regenerated, which replaces it.
            if ((string) $r['status'] === 'ready' && (string) $r['moderation_status'] !== 'blocked') { $by[(string) $r['angle']] = (int) $r['id']; }
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

    /** Drop-in body descriptions for the Body step, per gender. Starting points: the field stays free text. */
    const BODY_PROMPTS = array(
        'woman' => array(
            'Tall and slim, long legs, narrow waist, small bust, straight posture',
            'Average height, athletic and toned, defined shoulders and arms, flat stomach, medium bust',
            'Petite, slim, narrow shoulders, small bust, slender arms and legs',
            'Curvy hourglass figure, full bust, narrow waist, wide hips, soft arms',
            'Tall, fit gym build, strong legs and glutes, toned arms, visible abs, medium bust',
            'Average height, soft natural build, medium bust, slight belly, full thighs',
            'Plus size, full figure, large bust, wide hips, soft arms and stomach',
            'Short, curvy, full bust, narrow waist, thick thighs',
            'Tall, lean runner build, long limbs, small bust, narrow hips',
            'Average height, slim with a narrow waist, large bust, slender legs',
            'Muscular, broad shoulders, strong back and arms, powerful legs, small bust',
            'Petite, athletic, toned legs, flat stomach, medium bust',
        ),
        'man' => array(
            'Tall and lean, long legs, narrow waist, light muscle definition',
            'Average height, athletic and toned, defined chest and arms, flat stomach',
            'Tall, muscular gym build, broad shoulders, thick arms, narrow waist',
            'Average height, slim, narrow shoulders, slender arms and legs',
            'Stocky, broad chest and shoulders, strong arms, solid legs',
            'Tall, heavy-set, big frame, soft stomach, thick arms',
            'Short, compact and muscular, wide back, strong legs',
            'Average height, soft natural build, slight belly, average arms',
            'Lean runner build, long limbs, low body fat, narrow hips',
            'Bodybuilder physique, very broad shoulders, large chest and arms, visible abs, thick legs',
            'Tall, swimmer build, wide shoulders, long torso, narrow waist',
            'Slim and wiry, lean arms, flat stomach, narrow frame',
        ),
    );

    /** A body description as stored: one line, at most 600 characters. */
    public static function body_text($text){
        return mb_substr(trim(preg_replace('/\s+/', ' ', (string) $text), " ,"), 0, 600);
    }

    /**
     * Her body in the creator's own words ("tall, long legs, narrow waist"), '' when not set. The face comes from
     * the trained model; the body comes from these words, so they go on every image prompt, on her full-body
     * reference and on the full-body shots of the training set.
     */
    public static function body_phrase($infl){
        return is_array($infl) ? self::body_text($infl['body_description'] ?? '') : '';
    }

    /** Her full-body reference image (the Full Body Front angle): the newest one. 0 when none. */
    public static function body_reference($creator_id, array $infl){
        $best = 0;
        foreach ((new InfluencerImagesModel())->list_for_influencer($creator_id, (int) $infl['id'], 'angle') as $r) {   // oldest first
            if ((string) $r['angle'] !== 'full_front' || (string) $r['status'] !== 'ready' || (string) $r['moderation_status'] === 'blocked') { continue; }
            $best = (int) $r['id'];   // the newest one
        }
        if ($best <= 0) { return 0; }
        // A body made from a face she no longer has is not hers any more: after the face reference changes, there is no
        // body reference until it is made again.
        $face = max((int) ($infl['reference_asset_id'] ?? 0), 0) ?: (int) ($infl['face_asset_id'] ?? 0);
        if ($face > 0) {
            foreach ((new InfluencerJobsModel())->list_for_influencer($creator_id, (int) $infl['id'], 'angle', 80) as $j) {   // newest first
                if ((int) $j['result_asset_id'] !== $best) { continue; }
                if ((int) $j['input_asset_id'] > 0 && (int) $j['input_asset_id'] !== $face) { return 0; }
                break;
            }
        }
        return $best;
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
    // Lifelike without being harsh: asking for blemishes, fine lines, flyaway hair and grain made faces look rough and
    // unkempt, and asking for nothing made them airbrushed. This sits between the two.
    const REALISM = 'Natural, true-to-life photograph of a real person taken on a phone: healthy skin with real texture, clean well-kept hair, flattering natural light, true-to-life colour, sharp focus on the eyes. No airbrushing, no beauty filter, no CGI, render or illustration look.';

    /** A prompt with the realism direction on the end (once). */
    public static function realistic($prompt){
        $p = rtrim(trim((string) $prompt), " .,;");
        if ($p === '') { return self::REALISM; }
        return (stripos($p, 'no airbrushing') !== false) ? $p : $p . '. ' . self::REALISM;
    }

    /** Faces to start from on the reference step, per gender: a short name the list shows, and the description it fills in. */
    const FACE_PROMPTS = array(
        'woman' => array(
            'Girl Next Door'      => 'Woman, 24. Long chestnut hair, a little messy, tucked behind one ear. Warm brown eyes, light freckles across the nose and cheeks, soft round cheeks, natural full brows. Bare skin, relaxed half smile, looking straight at the camera. Daylight from a window.',
            'Beach Blonde'        => 'Woman, 26. Sun-lightened blonde waves past the shoulders, darker at the roots. Blue eyes, tanned skin with a few sun freckles, slim nose, wide easy smile showing teeth. Hair slightly salty and windblown. Bright open shade outdoors.',
            'Dark And Striking'   => 'Woman, 27. Jet black hair, long and straight, centre part. Dark almond eyes, high cheekbones, sharp jawline, full lips, strong arched brows. Calm, direct look with the mouth closed. Soft light from one side.',
            'Curly And Bright'    => 'Woman, 25. Big dark brown curls to the shoulders. Deep brown skin, dark eyes, round cheeks, wide bright smile, small gold nose stud. Head tilted slightly, laughing. Outdoors in late afternoon sun.',
            'Redhead'             => 'Woman, 23. Copper red hair, loose and wavy, mid length. Pale skin covered in freckles, green eyes, light lashes, small upturned nose, faint smile. No makeup. Overcast daylight.',
            'Fitness Girl'        => 'Woman, 28. Honey brown hair pulled back in a high ponytail, a few strands loose. Hazel eyes, lightly tanned skin, defined jaw, healthy flush on the cheeks, confident closed-mouth smile. A little sweat at the hairline. Morning light.',
            'Soft And Sweet'      => 'Woman, 22. Shoulder-length light brown hair with wispy bangs. Big grey-blue eyes, fair skin, small nose, round face, pink cheeks, shy smile. Looks young and gentle. Soft indoor light by a window.',
            'Glam Brunette'       => 'Woman, 29. Thick dark brown hair in loose blowout waves. Olive skin, brown eyes, full lips, long lashes, defined brows, small beauty mark above the lip. Light everyday makeup, slight knowing smile. Warm evening light.',
            'Edgy Short Hair'     => 'Woman, 25. Platinum blonde pixie cut, dark roots showing. Grey eyes, sharp cheekbones, straight brows, small silver hoop in one ear, tiny stud in the nose. Serious look, chin slightly down. Flat daylight.',
            'Elegant Thirties'    => 'Woman, 34. Dark blonde hair in a low loose bun, a few strands framing the face. Blue-grey eyes, fine lines at the corners when she smiles, slim face, straight nose. Calm, warm expression. Late afternoon light.',
            'Tan And Dark Eyed'   => 'Woman, 26. Very long dark brown hair, glossy, slight wave at the ends. Golden tan skin, large dark brown eyes, thick lashes, full brows, soft wide smile. Thin gold chain at the neck. Morning light on a balcony.',
            'Cute And Sporty'     => 'Woman, 24. Straight black hair cut to the collarbone, tucked behind both ears. Fair skin, dark eyes, soft round face, small nose, dimples when she smiles. No makeup, cheerful open smile. Bright daylight.',
        ),
        'man' => array(
            'Guy Next Door'       => 'Man, 27. Short brown hair, slightly messy on top. Brown eyes, light stubble, friendly open face, straight nose, easy closed-mouth smile. Looking straight at the camera. Daylight from a window.',
            'Surfer Blond'        => 'Man, 26. Sun-bleached blond hair to the ears, pushed back, a little salty. Blue eyes, tanned skin, light scruff, wide relaxed grin. Squinting slightly in the sun. Bright open shade outdoors.',
            'Dark And Sharp'      => 'Man, 29. Black hair, short on the sides and longer on top, swept back. Dark eyes, strong jaw, defined cheekbones, heavy brows, clean shaven. Serious, direct look. Soft light from one side.',
            'Bearded And Warm'    => 'Man, 31. Short cropped black hair, neat full beard. Deep brown skin, dark eyes, broad nose, wide warm smile showing teeth. Laughing a little. Outdoors in late afternoon sun.',
            'Redhead'             => 'Man, 28. Curly red hair, short red beard. Pale freckled skin, blue eyes, light lashes, crooked half smile. Overcast daylight.',
            'Gym Guy'             => 'Man, 28. Dark brown hair in a short fade. Hazel eyes, lightly tanned skin, thick neck, square jaw, short stubble, confident smirk. A little sweat at the hairline. Morning light.',
            'Clean Cut'           => 'Man, 24. Light brown hair, neat side part. Grey-blue eyes, fair skin, smooth clean-shaven face, slim nose, polite smile. Looks young and tidy. Soft indoor light by a window.',
            'Rugged'              => 'Man, 35. Shaggy dark hair to the collar, thick uneven beard. Weathered tan skin, green eyes, lines across the forehead, small scar through one eyebrow. Steady look, no smile. Warm evening light.',
            'Shaved Head'         => 'Man, 30. Shaved head, heavy dark stubble. Dark eyes, strong brow, wide jaw, small tattoo on the side of the neck. Chin slightly down, serious look. Flat daylight.',
            'Silver Fox'          => 'Man, 44. Salt and pepper hair, short and neat, grey in the stubble. Grey-blue eyes, lines at the corners of the eyes, slim face, calm half smile. Late afternoon light.',
            'Tan And Dark Eyed'   => 'Man, 27. Thick dark wavy hair, medium length. Golden tan skin, dark brown eyes, full brows, short stubble, soft wide smile. Thin silver chain at the neck. Morning light on a balcony.',
            'Long Hair'           => 'Man, 26. Long brown hair tied back, a few strands loose around the face. Hazel eyes, light beard, straight nose, relaxed smile. Outdoors in open shade.',
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
        'car selfie of {subject} in the driver seat, seatbelt on, overcast daylight through the windscreen, relaxed half smile',
        'photo of {subject} cooking in a small apartment kitchen, hair tied back, apron over a t-shirt, steam from a pan, window light',
        'photo of {subject} on a hiking trail lookout, backpack on, wind jacket, mountains behind, squinting slightly in the sun',
        'photo of {subject} by a hotel pool, sitting on the edge with feet in the water, towel over one shoulder, bright midday sun',
        'photo of {subject} at an airport gate, carry-on beside the seat, headphones around the neck, coffee cup in hand, flat terminal light',
        'photo of {subject} curled up on a sofa on a rainy day, blanket, mug in both hands, grey window light, looking at the camera',
        'photo of {subject} at a farmers market, tote bag on the shoulder, holding up a bunch of flowers, busy stalls behind',
        'photo of {subject} at a desk with a laptop, late evening, warm desk lamp, hair a little messy, tired smile',
    );

    /** Drop-in motion prompts for Generate Videos (image-to-video: the still supplies the look); {Subject} becomes "The woman" or "The man". */
    const VIDEO_PROMPTS = array(
        '{Subject} turns toward the camera and smiles, hair moving in a light breeze, slow push in',
        '{Subject} laughs and glances away, then back at the lens, handheld feel, soft natural light',
        '{Subject} looks over one shoulder at the camera, then back out to the view, gentle dolly left',
        '{Subject} takes a slow sip of a drink and glances up, steady camera, shallow depth of field',
        '{Subject} walks slowly toward the camera, clothes and hair moving, sun flare passing through',
        '{Subject} stretches and settles back with a relaxed smile, camera drifts in slowly, warm light',
        '{Subject} tucks hair behind one ear and looks down, then up at the lens, static camera',
        '{Subject} waves at the camera and blows a quick kiss, handheld phone feel, natural light',
        '{Subject} adjusts sunglasses and tilts the head back toward the sun, slow orbit to the right',
        '{Subject} spins once on the spot, clothes swinging out, then stops facing the camera, steady wide shot',
        '{Subject} nods along to music and mouths a few words, slight camera sway, evening light',
        '{Subject} leans in close to the lens with a playful grin, then leans back, handheld selfie feel',
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
        $faces = array();
        foreach (self::FACE_PROMPTS[$n] as $name => $text) { $faces[] = array('name' => $name, 'text' => $text); }
        return array('face' => $faces, 'body' => self::BODY_PROMPTS[$n], 'image' => $fill(self::IMAGE_PROMPTS), 'video' => $fill(self::VIDEO_PROMPTS));
    }

    /** Training-set variations (reference path). Each becomes one 1:1 job; the user can add steering. {body} is where her body words go (training_variation). */
    const TRAINING_VARIATIONS = array(
        'same person, front-facing head and shoulders, wearing a plain grey t-shirt, neutral expression, flat daylight from a window, plain wall behind',
        'same person, three-quarter view turned slightly left, wearing a white tank top, small smile, window light from one side, living room behind',
        'same person, three-quarter view turned slightly right, wearing an oversized hoodie, relaxed expression, warm evening lamp light indoors',
        'same person, side profile, hair tucked behind the ear, wearing a knit sweater, overcast daylight outdoors',
        'same person, laughing mid-laugh with eyes crinkled, wearing a plain white t-shirt, bright daylight, street behind slightly out of focus',
        'same person, looking over the shoulder at the camera, wearing a denim jacket, late afternoon sun, park behind',
        'same person, close-up of the face, the collar of a dark t-shirt just in frame, serious expression, light from one side only, dim room',
        'same person, full body standing, head to toe in frame, {body}plain fitted t-shirt and jeans, overcast daylight, city street behind',
        'same person, from the waist up, {body}at home in a plain fitted t-shirt, hair undone, soft expression, morning light, bedroom behind',
        'same person, from the knees up, {body}wearing a loose linen shirt over a t-shirt and shorts, sunglasses pushed up on the head, big smile, harsh midday sun, beach promenade behind',
    );

    /** One training-set prompt for her: the variation with her body words filled in. */
    public static function training_variation(array $infl, $i){
        $vars = self::TRAINING_VARIATIONS;
        $body = self::body_phrase($infl);
        return str_replace('{body}', $body !== '' ? $body . ', ' : '', $vars[((int) $i) % count($vars)]);
    }

    /**
     * The same prompt with her body words taken out, and plain clothes named when none are: what a shot is rerun
     * with after a model's content checker refused it (body words in a scene with no clothing named read as swimwear
     * to the checker). Returns the prompt unchanged when there is nothing to take out.
     */
    public static function softened_prompt(array $infl, $prompt){
        $out  = (string) $prompt;
        $body = self::body_phrase($infl);
        if ($body !== '') { $out = str_ireplace(array($body . ', ', ', ' . $body, $body), '', $out); }
        if (!preg_match('/\b(wearing|t-shirt|shirt|sweater|hoodie|jacket|dress|jeans|shorts)\b/i', $out)) {
            $out = preg_replace('/^(same person,[^,]*,)/i', '$1 wearing a plain t-shirt and jeans,', $out, 1, $n);
            if (empty($n)) { $out = 'wearing a plain t-shirt and jeans, ' . $out; }
        }
        return trim((string) preg_replace('/\s*,(\s*,)+/', ',', $out));
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
        // Work in flight owns the step: a set being made opens on the set, a body reference rendering on the body,
        // a face (or Face Adjust) on the reference. The photos path has one step, so any run keeps it there.
        $jobs = new InfluencerJobsModel();
        if ($path === 'photos') {
            if ($jobs->has_pending((int) $infl['id'])) { return $max; }
        } else {
            if ($jobs->has_pending((int) $infl['id'], 'training_set')) { return 'set'; }
            if ($jobs->has_pending((int) $infl['id'], 'angle'))        { return 'body'; }
            if ($jobs->has_pending((int) $infl['id'], 'reference'))    { return 'reference'; }
        }
        return ($si > $mi) ? $max : $stored;
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
            'body_description'    => (string) ($infl['body_description'] ?? ''),
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
            'scene_max_lines' => InfluencerVideoActions::SCENE_MAX_LINES,
            'voice' => array('keywords' => InfluencerVoiceActions::KEYWORDS, 'tags' => InfluencerVoiceActions::TAGS, 'preview_min' => ElevenLabsService::PREVIEW_MIN,
                'preview_max' => ElevenLabsService::PREVIEW_MAX, 'speech_max' => ElevenLabsService::SPEECH_MAX),
            'carousel' => array('min' => InfluencerImageActions::CAROUSEL_MIN, 'max' => InfluencerImageActions::CAROUSEL_MAX,
                'focus' => array_map(function ($f) { return $f['label']; }, InfluencerImageActions::CAROUSEL_FOCUS)),
        );
    }
}
