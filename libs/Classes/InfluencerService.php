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
        'persona_description'   => array('label' => 'Description',               'max' => 20000, 'rows' => 3, 'placeholder' => '24, grew up in Tampa, moved to Miami for nursing school'),
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
        'full_front'    => array('label' => 'Full Body Front',  'aspect' => '9:16', 'prompt' => 'Wide full-length photo taken from several steps back with the camera level at chest height: the whole body from the top of the head to the feet on the floor, standing straight facing the camera, arms relaxed at the sides, {outfit}'),
        'full_back'     => array('label' => 'Full Body Back',   'aspect' => '9:16', 'prompt' => 'Full body seen from directly behind, standing straight, arms relaxed at the sides, head to toe in frame, {outfit}'),
    );

    /** What she wears in the body shots: the build she was given has to be visible, so as little as a photo on her page would show. */
    public static function body_outfit(array $infl){
        return (self::noun($infl) === 'man') ? 'a fitted tank top and athletic shorts' : 'a fitted crop top and short shorts';
    }

    /**
     * What she wears in the full-body reference pictures (2026-10-13): plain swimwear, so the creator can see the build they
     * described. A crop top and leggings hid it. Only the body reference uses this; the training set keeps body_outfit(), so
     * the trained model does not learn that she is always in swimwear.
     */
    public static function body_reference_outfit(array $infl){
        return (self::noun($infl) === 'man') ? 'wearing plain swim shorts, bare chest, barefoot' : 'wearing a plain solid-colour two-piece swimsuit, barefoot';
    }

    /**
     * A body description with its clothing sentences taken out. Creators often write an outfit into the description
     * ("fitted top, high-waisted leggings, white sneakers"), and the image model follows those words over the outfit the
     * body reference asks for, which hides the build the picture exists to show.
     */
    public static function body_unclothed($text){
        $keep = array();
        foreach (preg_split('/(?<=[.!?;])\s+/', trim((string) $text)) as $sentence) {
            if (preg_match('/\b(wear(?:s|ing)?|dress(?:ed)?(?: her| him)?|cloth(?:es|ing|ed)|outfit|attire|garment|leggings?|trousers|pants|jeans|shorts|skirt|top|t-?shirt|shirt|blouse|sweater|hoodie|jacket|bra|sneakers?|shoes?|footwear|heels|boots|socks|activewear|athleisure|sportswear|swimsuit|bikini|lingerie|neckline)\b/i', $sentence)) { continue; }
            $keep[] = $sentence;
        }
        return trim(implode(' ', $keep), " ,");
    }

    /** The prompt for one angle slot. */
    public static function angle_prompt(array $infl, $slot){
        $def = self::ANGLES[$slot] ?? null;
        if (!$def) { return ''; }
        $def['prompt'] = str_replace('{outfit}', self::body_reference_outfit($infl), (string) $def['prompt']);   // the body reference shows her in swimwear
        $body = (strpos((string) $slot, 'full_') === 0) ? self::body_unclothed(self::body_phrase($infl)) : '';   // the full-body slots show her build; clothing named in her description is left out, the picture sets its own
        // Her reference is a face, so it cannot fix her body. An edit model copies the body it guesses from the face unless told
        // the reference holds no body at all; so the build is stated first, as the thing being made, and the face second.
        $keep = ($body !== '') ? 'identical face, hair and skin tone' : 'identical face, hair, skin tone and body';
        $full = (strpos((string) $slot, 'full_') === 0);
        $noun = self::noun($infl); $pr = self::pronouns($infl);
        $build = ($body !== '')
            ? 'The reference image shows only this ' . $noun . '\'s face and hair; ' . $pr[2] . ' body is not in it. Build ' . $pr[2] . ' body from these words, and it must be clearly visible in the picture: ' . $body . '. '
            : '';
        // The outfit is fixed for this picture, whatever the description said about clothes: stated again, last, so it wins.
        if ($full) { $build .= 'Clothing in this picture: ' . preg_replace('/^wearing /', '', self::body_reference_outfit($infl)) . ', and nothing else. No top, t-shirt, leggings, trousers, skirt, dress, socks or shoes. '; }
        return ($full ? $def['prompt'] . '. ' : '') . $build
            . 'Keep the exact same ' . $noun . ' as in the reference image: ' . $keep . '. '
            . ($full ? '' : $def['prompt'] . '. ')
            . 'Plain light grey wall behind, flat daylight. ' . self::rendering();
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

    /** The longest body or face description kept (2026-10-13: 600 and then 2,000 both cut a careful description mid-word). Both columns are TEXT. */
    const BODY_MAX = 20000;
    /** The longest "adjust this image" instruction, and the longest "add to every photo" line of the training set. */
    const CHANGE_MAX = 20000;
    /** Prompts, scripts and instructions are not cut to a short length (Daniel, 2026-10-13: stop limiting the character count). This only keeps a value inside its TEXT column. */
    const TEXT_MAX = 20000;

    /** A body description as stored: one line, at most BODY_MAX characters. */
    public static function body_text($text){
        return mb_substr(trim(preg_replace('/\s+/', ' ', (string) $text), " ,"), 0, self::BODY_MAX);
    }

    /**
     * Her body in the creator's own words ("tall, long legs, narrow waist"), '' when not set. The face comes from
     * the trained model; the body comes from these words, so they go on every image prompt, on her full-body
     * reference and on the full-body shots of the training set.
     */
    /**
     * Her build in a few words, for scene prompts (2026-10-13). The full body description can be a page long and is written
     * for one picture, the full-body reference: it names a pose, a studio background, lighting and an outfit. Put in front of
     * every scene prompt, it made every image that full-body studio shot and pushed the scene past what the model reads.
     * This keeps only the sentences about her build (no clothing, no composition, camera, background, light, pose or face),
     * about 300 characters at most. A short description ("tall, athletic, long legs") comes through unchanged.
     */
    public static function build_phrase($infl){
        $keep = array(); $len = 0;
        foreach (preg_split('/(?<=[.!?;])\s+/', self::body_unclothed(self::body_phrase($infl))) as $s) {
            $s = trim($s);
            if ($s === '') { continue; }
            if (preg_match('/\b(composition|camera|lens|fram(?:e|es|ed|ing)|crop(?:ped)?|background|backdrop|studio|lighting|light|shadows?|reference|image|images|photo(?:graph|graphy)?s?|picture|render(?:ed|ing)?|video|videos|avoid|priorit(?:y|ies)|full[- ]length|full[- ]body|head[- ]to[- ]toe|standing|stands?|walking|sitting|pos(?:e|es|ed|ing)|facing|front[- ]facing|feet|perspective|social media|consisten(?:t|cy)|identity|facial|face|smile|hair|eyes?|expression|retouch(?:ed|ing)?|skin texture)\b/i', $s)) { continue; }
            if ($keep && $len + mb_strlen($s) > 300) { break; }
            $keep[] = $s; $len += mb_strlen($s) + 1;
        }
        return trim(mb_substr(implode(' ', $keep), 0, 400), " ,.");
    }

    /**
     * Styles for a picture made with her trained model (Generate Images, 2026-10-13). Both use the same model; the style only
     * decides what is added after the scene. 'photoreal' adds camera, light and skin cues in plain photographic terms (no
     * "hyperrealistic", "8K" or "flawless", which push a model toward digital art). 'plain' adds nothing.
     * Later the same day the lens and depth-of-field words came out: they asked for a portrait-studio look, and faces came back
     * more sculpted than her reference (fuller lips, narrower jaw).
     * The style says only what to show: this model has no way to be told what to avoid, so "not a glamour portrait, not a
     * render" named the very looks it was meant to prevent.
     */
    const IMAGE_STYLES = array(
        'photoreal' => array('label' => 'Photorealistic', 'purpose' => 'Adds camera, light and skin-texture cues',
            'suffix' => 'Candid photograph of a real person, natural believable light, true colour. Natural skin texture with visible pores, fine flyaway hairs, natural proportions, a soft natural face with its own fullness. An everyday unretouched snapshot.'),
        'plain'     => array('label' => 'As Written', 'purpose' => 'Your prompt only, nothing added', 'suffix' => ''),
    );
    const IMAGE_STYLE_DEFAULT = 'photoreal';

    /** What a style adds to a prompt ('' for none, an unknown style, or when the prompt already carries it). */
    public static function style_suffix($style, $prompt = ''){
        $s = (string) (self::IMAGE_STYLES[(string) $style]['suffix'] ?? '');
        return ($s !== '' && stripos((string) $prompt, $s) === false) ? $s : '';
    }

    /**
     * Her hair and skin in a few words, for every picture made with her trained model (2026-10-13). First this carried her
     * whole face (eyes, brows, nose, lips, smile). Tested the same day, those words turned every picture into a tight,
     * camera-facing portrait with the same smile, whatever the scene was: naming her eyes and smile asks the model to show
     * them. Hair and skin words keep her hair length, colour and skin tone steady without that pull, so only those are kept.
     * Taken from the description her reference was made from. '' when it names neither. About 160 characters at most.
     */
    /**
     * Did the creator say how her hair should be (a ponytail, a bun, wet, windblown, a part, hair up)? Then their words decide
     * the hairstyle, and face_phrase() adds her hair colour only: her stored length and style ("shoulder-length", "waves")
     * argue with the request. Tested 2026-10-13 on four hairstyles: with the stored words a wet, slicked-back request came
     * out as her usual dry loose hair; colour was the same in every picture either way.
     */
    public static function scene_names_hair($scene){
        return (bool) preg_match('/\b(hair|hairstyle|haircut|ponytail|pigtails?|braids?|braided|plaits?|updo|top[- ]?knot|bangs|fringe|curls|curly|wavy|straightened|slicked|windblown|windswept|tousled|bedhead|(?:messy|low|high|sleek|tight|loose) bun|(?:cent(?:er|re)|side|middle) part(?:ing)?)\b/i', (string) $scene);
    }

    /** One hair clause cut down to its colour ("Shoulder-length dark brown hair" -> "dark brown hair"); '' when it names no colour. */
    public static function hair_colour_only($clause){
        if (!preg_match_all('/\b(platinum|ash|strawberry|dirty|sandy|golden|honey|caramel|jet|dark|light|medium|deep|warm|cool|blonde?|brunette|brown|black|red|auburn|ginger|copper|chestnut|silver|gr[ae]y|white|pink|blue|purple|green)\b/i', (string) $clause, $m)) { return ''; }
        $words = array_map('strtolower', $m[1]);
        if (!array_diff($words, array('dark', 'light', 'medium', 'deep', 'warm', 'cool'))) { return ''; }   // a shade with no colour says nothing
        return implode(' ', $words) . ' hair';
    }

    public static function face_phrase($infl, $hair_colour_only = false){
        $text = is_array($infl) ? trim((string) preg_replace('/\s+/', ' ', html_entity_decode((string) ($infl['source_description'] ?? ''), ENT_QUOTES, 'UTF-8'))) : '';
        if ($text === '') { return ''; }
        $keep = array(); $len = 0;
        foreach (preg_split('/\s*(?:[.;:!?]\s+|,\s+(?:and\s+)?|\s+with\s+)/u', $text) as $s) {
            $s = trim($s, " .,;:");
            // an instruction's verb goes, the feature it names stays ("Give her a playful, asymmetric smile" -> "a playful, asymmetric smile")
            $s = trim((string) preg_replace('/^(?:and\s+)?(?:give (?:her|him|them)|add|keep(?: (?:her|his|their))?|preserve(?: (?:this|her|his))?|maintain(?:ing)?)\s+/i', '', $s));
            if ($s === '' || mb_strlen($s) > 90) { continue; }
            if (!preg_match('/\b(hair|curls|waves|skin|complexion)\b/i', $s)) { continue; }
            if (preg_match('/\b(eyes?|eyelids?|lashes|brows?|eyebrows?|nose|cheeks?|cheekbones?|jaw|jawline|chin|lips?|mouth|smile|teeth|freckles?|freckled|dimples?|beauty mark|mole|face|smooth|retouch(?:ed|ing)?|texture)\b/i', $s)) { continue; }   // face features pull the picture into a portrait
            if (preg_match('/\b(light(?!\s+(?:smile|freckles|brown|blonde|skin|lashes|eyes))|lighting|daylight|sunlight|afternoon|shade|windblown|salty|pulled back|tied back|ponytail|bun|slightly down|bare|camera|lens|focus(?:ed)?|background|backdrop|studio|photo|portrait|image|shirt|t-shirt|top|dress|wearing|makeup|make-up|identity|texture|pores|avoid|unobstructed|looking|eye contact|sitting|standing|outdoors|indoors)\b/i', $s)) { continue; }
            if ($hair_colour_only && preg_match('/\b(hair|curls|waves)\b/i', $s)) { $s = self::hair_colour_only($s); if ($s === '' || in_array($s, $keep, true)) { continue; } }
            if ($keep && $len + mb_strlen($s) > 160) { break; }
            $keep[] = lcfirst($s); $len += mb_strlen($s) + 2;
        }
        if (!$keep) { return ''; }
        return ucfirst(implode(', ', $keep));
    }

    /**
     * Did the creator say how she faces the camera (an angle, a gaze, a portrait, a selfie, a pose)? When they did not, the
     * trained model's habit is a face-on picture looking at the camera, in every scene (tested 2026-10-13: 6 of 6). Then
     * candid_line() opens the prompt, and she is shown doing what the scene says (4 of 6 in the same test).
     */
    public static function scene_directs_camera($scene){
        return (bool) preg_match('/\b(camera|lens|eye contact|portrait|headshot|selfie|wink(?:s|ing)?|tongue|blow(?:s|ing)? a kiss|pout(?:s|ing)?|duck face|silly face|funny face|mak(?:es|ing) a face|peace sign|pos(?:e|es|ed|ing)|close[- ]up|profile|three[- ]quarter|side[- ]on|sideways|from the side|from behind|back view|over (?:her|his|the) shoulder|look(?:s|ing)? (?:away|back|down|up|at|out|over|toward|towards|into|straight)|gaz(?:e|es|ing)|glanc(?:e|es|ing)|facing|front[- ]facing|turn(?:s|ed|ing))\b/i', (string) $scene);
    }

    public static function candid_line($infl){
        $n = self::noun(is_array($infl) ? $infl : array()); $pr = self::pronouns(is_array($infl) ? $infl : array());
        return 'Candid photo of a ' . $n . ' absorbed in what ' . $pr[0] . ' is doing, ' . $pr[2] . ' eyes on it, unaware of the camera.';
    }

    /**
     * Does a scene need fitting to the image model before it is sent (2026-10-13)? Two things made pictures ignore what was
     * asked: the model reads only the first part of a long prompt, so instructions at the end were never seen; and it cannot
     * be told what to leave out, so "not a frontal portrait, no eye contact" named exactly the picture it then made.
     * A short scene with nothing to avoid is sent as written.
     */
    const SCENE_FIT_OVER = 600;
    public static function scene_needs_fit($scene){
        $scene = (string) $scene;
        if (mb_strlen($scene) > self::SCENE_FIT_OVER || self::scene_has_avoid($scene) || self::scene_has_others($scene)) { return true; }
        // an angle asked for somewhere after the opening words is not followed: the model takes its shot type from the start
        return mb_strlen($scene) > 160 && (bool) preg_match('/\b(profile|side[- ]on|from the side|sideways|three[- ]quarter|from behind|back view|over (?:her|his|the) shoulder|looking away|turned away|facing away)\b/i', mb_substr($scene, 60));
    }

    /**
     * The scene puts other people in the picture. The trained model draws every person with her face and body (a party scene
     * on prod 2026-10-13 came out with three of her), so the scene writer has to keep them far back and faceless.
     */
    public static function scene_has_others($scene){
        return (bool) preg_match('/\b(guests?|crowd(?:s|ed)?|friends?|girlfriends?|boyfriends?|people|partygoers|party|strangers|couples?|group|men|women|girls|guys|waiters?|waitress(?:es)?|bartenders?|audience|fans|dancers|bridesmaids|colleagues|coworkers|family|kids|children|husband|wife|partner|(?:with|and|beside|next to) (?:a|an|another|one|two|three|some|other) (?:man|woman|guy|girl|model))\b/i', (string) $scene);
    }

    public static function scene_has_avoid($scene){
        return (bool) preg_match('/(?:^|[\s.,;:(])(?:no|not|never|avoid|avoiding|without|don\'t|do not|isn\'t|is not|rather than|instead of)\s+\w/i', (string) $scene);
    }

    /** What the scene writer is told: put the pose first, say what to show in place of what to avoid, keep it short. */
    public static function scene_fit_system($noun, $pronoun){
        $pronoun_obj = ($pronoun === 'his') ? 'him' : (($pronoun === 'their') ? 'them' : 'her');
        return 'You rewrite a creator\'s description of one photo so an image model can follow it. The model reads only the first 150 words or so, '
            . 'follows the opening words most strongly, and cannot be told what to leave out: anything that is named tends to appear in the picture, even after "no" or "not". '
            . 'Write one paragraph, plain present tense, as short as the creator\'s details allow: about 90 words for a simple scene, longer when they gave many details. Never leave out a concrete detail to save space. '
            . 'OPEN with the shot type in the short, standard words photographers use, then where ' . $pronoun . ' face points and where ' . $pronoun . ' eyes look. '
            . 'When the creator wants ' . $pronoun . ' turned sideways, in three-quarter view, looking away, or anything else that is not face-on, the description must begin with exactly this form: '
            . '"Side profile photo of a ' . $noun . ', ' . $pronoun . ' face in profile, looking to the right at someone out of frame, photographed from the side." (or "to the left"). '
            . 'Use it for three-quarter requests too: the model turns ' . $pronoun . ' less than asked, and softer wording such as "three-quarter view", "seen from a side-on view" or "body angled away from the lens" comes out face-on. '
            . 'For a back view begin with "Photo from behind of a ' . $noun . ', looking back over ' . $pronoun . ' shoulder." or "Photo from behind of a ' . $noun . ', ' . $pronoun . ' face not visible." '
            . 'When no angle is asked for, open with "Photo of a ' . $noun . '" and the framing (close-up, waist-up, full length). '
            . 'When the creator does not say where ' . $pronoun . ' face points or where ' . $pronoun . ' eyes look, leave both out: never write that ' . $pronoun . ' eyes are on the camera or the lens unless the creator asked for it. '
            . 'Then the outfit, the place, the light, the expression. '
            . 'Turn every "not X", "no X" or "avoid X" into what should be seen instead, and never name the thing to avoid. '
            . 'The ' . $noun . ' is the only person whose face or figure is seen: the model gives every person it draws ' . $pronoun . ' face and body, so a second person comes out as ' . $pronoun . ' double. '
            . 'A friend, partner or photographer the creator mentions is "someone out of frame", never described and never shown. '
            . 'Guests, a crowd or staff the creator asks for are written once, as the last sentence, in exactly this form: "Far behind ' . $pronoun_obj . ', small out-of-focus figures of other guests, seen from behind." (or "other people"). '
            . 'Never place anyone beside or around ' . $pronoun_obj . ', never give them clothes, drinks or faces, and do not use the words crowd, mingling or surrounded. Show a party or a busy place through its setting: tables, glasses, lights, decorations. '
            . 'Keep every concrete detail the creator gave, each one by name: clothes, colours, jewellery, nails, hair, place, city, time of day, light, props, what each hand does, pose, expression, every object in the background, camera angle. Before you finish, read the creator\'s text again and add any detail you missed. '
            . 'Leave out sentences about how the photo should feel, quality words, and anything said twice. Add nothing the creator did not ask for. '
            . 'Call the subject "the ' . $noun . '" or "a ' . $noun . '". Leave out any name. Output only the rewritten description, no quotes, no preamble.';
    }

    /** With no writer available: the sentences and clauses that only say what to avoid are dropped, so they stop naming it. */
    public static function scene_without_avoid($scene){
        $keep = array();
        foreach (preg_split('/(?<=[.!?])\s+|\n+/', trim((string) $scene)) as $s) {
            $s = trim($s);
            if ($s === '' || preg_match('/^(?:no|not|never|avoid|without|don\'t|do not)\b/i', $s)) { continue; }
            $s = (string) preg_replace('/[,;]\s*(?:and\s+|or\s+)?(?:no|not|never|without|avoid(?:ing)?)\s+[^,;.]+/i', '', $s);
            $s = trim($s, " ,;");
            if ($s !== '') { $keep[] = preg_match('/[.!?]$/', $s) ? $s : $s . '.'; }
        }
        return trim(implode(' ', $keep));
    }

    /** Does the scene put packaging, products or signs in the picture (a shop, a box, a bottle, a poster)? */
    public static function scene_shows_labels($scene){
        return (bool) preg_match('/\\b(supermarket|grocery|store|shop(?:s|ping)?|aisle|market|pharmacy|mall|box(?:es)?|pack(?:et|age|ages|aging)?s?|bottles?|cans?|cartons?|jars?|products?|labels?|logos?|brands?|signs?|billboards?|posters?|banners?|menus?|cereal|soda|beer|wine|energy drink|takeaway|take-out|delivery)\\b/i', (string) $scene);
    }

    /**
     * The prompt for a model that draws her from her reference pictures (InfluencerConfig 'identity' => 'refs'). These
     * models read the whole prompt and follow it, so the creator's words go in as written, with no fitting and no trigger
     * word. Her build keeps her body hers; the last sentence keeps real brand names off packaging and signs (the first
     * test printed real pizza brands on the boxes).
     */
    public static function reference_prompt($infl, $scene, $defaults = '', $style = 'plain'){
        $n = self::noun(is_array($infl) ? $infl : array()); $pr = self::pronouns(is_array($infl) ? $infl : array());
        $scene = trim((string) $scene); $defaults = trim((string) $defaults);
        $out = ($style === 'photoreal' ? 'A candid phone photo of the ' : 'A photo of the ') . $n . ' from the reference images, keeping ' . $pr[2] . ' exact face, hair colour, skin tone and body. '
            . $scene . (preg_match('/[.!?]$/', $scene) ? '' : '.');
        if ($defaults !== '') { $out .= ' ' . $defaults . (preg_match('/[.!?]$/', $defaults) ? '' : '.'); }
        if ($style === 'photoreal') { $out .= ' Natural skin texture, an unposed moment.'; }
        // Only where packaging or signs belong in the scene: said about a park bench, the model drew boxes and labels to match the sentence.
        return $out . (self::scene_shows_labels($scene) ? ' Every package, sign and label in the picture is generic, with invented names.' : '');
    }

    /** One image prompt: the trigger word, the scene as written, then her build and defaults. */
    public static function scene_prompt($lead, $scene, $defaults){
        $scene = trim((string) $scene); $defaults = trim((string) $defaults);
        if ($defaults === '') { return trim($lead . $scene); }
        if ($scene === '') { return trim($lead . $defaults); }
        return trim($lead . $scene . (preg_match('/[.!?]$/', $scene) ? ' ' : '. ') . $defaults);
    }

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
        $body = self::build_phrase($infl);   // her build in a few words; the full description is for her body reference only
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
    /**
     * Rendering direction, kept apart from the description of the person (what they look like is the creator's text;
     * these say only how the picture was taken). Neither block asks for beauty, glamour or flawless skin, which a model
     * reads as a retouched portrait; neither lists flaws either, which it reads just as literally and turns into an
     * unkempt, tired-looking person (redness, shadows, stray hair). Real = an ordinary, put-together person photographed
     * without retouching.
     *   REALISM  : an unretouched phone photo (Most Realistic; also the body, Change Look and training-set shots, so the
     *              trained identity carries real skin and natural light).
     *   POLISHED : a clean, lightly retouched editorial photo (Polished and Quick Draft keep the look they had).
     */
    const REALISM  = 'Natural photo of a real person taken on a good phone: natural daylight, true colour, real skin texture kept rather than smoothed, tidy hair, natural proportions, an easy everyday expression. An unretouched snapshot, not a glamour portrait, illustration or render.';
    const POLISHED = 'Clean editorial photograph of a real person: soft even light, true colour, lightly retouched skin that keeps its texture, natural proportions. No illustration or render look.';
    /**
     * CAPTURE: how a real phone picture is taken, said about the camera and the light, never about the person. The
     * training set and body shots use it: an edit model given "clean, even, retouched" returns a glossy render, and a
     * model trained on those renders can only make more of them. Nothing here makes the person plainer.
     */
    const CAPTURE  = 'A real photograph, candid and unposed: the light in the room as it is, with real shadows and slightly uneven exposure, a little natural grain, ordinary everyday framing, true colour, fine skin texture and single hair strands visible at full size. Nothing retouched, no studio lighting, no editorial finish. A photograph only: never an illustration, painting, anime, render, screenshot or a picture shown on a device.';

    /** Which rendering block a model key gets: the catalog's `style` ('candid' | 'polished'); polished when unset, the look Daniel approved. */
    public static function rendering($model_key = ''){
        $m = ($model_key !== '') ? InfluencerConfig::model((string) $model_key) : null;
        return ($m && (string) ($m['style'] ?? '') === 'candid') ? self::REALISM : self::POLISHED;
    }

    /** A prompt with the rendering direction for $model_key on the end (once). */
    public static function realistic($prompt, $model_key = ''){
        $p = rtrim(trim((string) $prompt), " .,;");
        $r = self::rendering($model_key);
        if ($p === '') { return $r; }
        return (strpos($p, rtrim(self::REALISM, '.')) !== false || strpos($p, rtrim(self::POLISHED, '.')) !== false) ? trim((string) $prompt) : $p . '. ' . $r;
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
/**
     * The new shot list (version 2, 2026-10-13; see training_descriptive). The first list had her facing or looking at the camera in most shots, and a
     * model trained on it pulled every picture toward a face-on portrait. Here seven of ten look away or are turned: two
     * three-quarter views, two side profiles (one each way), a close-up looking aside, a walking full-length shot and a seated
     * sideways shot. Three keep her facing the camera so her face is still learned straight on. The close-up stays at
     * position 7 and the three body shots at 8 to 10, as before.
     */
    const TRAINING_VARIATIONS_V2 = array(
        'same person, front-facing head and shoulders, looking at the camera, wearing a plain grey t-shirt, neutral expression, flat daylight from a window, plain wall behind',
        'same person, head and shoulders in three-quarter view with the head turned to the left, looking away from the camera at something to the left, wearing a white tank top, small smile, window light from one side, living room behind',
        'same person, three-quarter view with the head turned to the right, looking down at a phone held in one hand, wearing an oversized hoodie, relaxed expression, warm evening lamp light indoors',
        'same person, side profile facing left, the face in full profile, looking straight ahead, hair tucked behind the ear, wearing a knit sweater, overcast daylight outdoors',
        'same person, side profile facing right, the face in full profile, alone in the frame, laughing mid-laugh with eyes crinkled, wearing a plain white t-shirt, bright daylight, street behind slightly out of focus',
        'same person, seen from behind, looking back over the shoulder at the camera, wearing a denim jacket, late afternoon sun, park behind',
        'same person, close-up of the face, head tilted, looking off to one side, the collar of a dark t-shirt just in frame, serious expression, light from one side only, dim room',
        'same person, full body walking, head to toe in frame, seen from a three-quarter angle, looking ahead along the street, {body}{outfit}, overcast daylight, city street behind',
        'same person, from the waist up, facing the camera, {body}at home in a plain fitted t-shirt, hair undone, soft expression, morning light, bedroom behind',
        'same person, from the knees up, seated sideways to the camera on a low wall, alone in the frame, head turned to look along the promenade, {body}wearing a loose linen shirt over a t-shirt and shorts, sunglasses pushed up on the head, big smile, harsh midday sun, beach promenade behind',
    );
    /**
     * Off until a trial training has shown the new shot list and the descriptive captions give a better model: with it off,
     * training sets and captions are exactly what they were. InfluencerConfig 'training_descriptive' turns it on; a trial
     * script can set $training_descriptive for one run.
     */
    public static $training_descriptive = null;
    public static function training_descriptive(){
        return self::$training_descriptive !== null ? (bool) self::$training_descriptive : (bool) InfluencerConfig::get('training_descriptive', 0);
    }
    /** The shot list in use, and its version number (recorded on each training-set job). */
    public static function training_vars(){ return self::training_descriptive() ? self::TRAINING_VARIATIONS_V2 : self::TRAINING_VARIATIONS; }
    public static function training_version(){ return self::training_descriptive() ? 2 : 1; }

    /** The first shot list, in use until the new one is validated. A set made from it can be captioned by what each picture shows. */
    const TRAINING_VARIATIONS = array(
        'same person, front-facing head and shoulders, wearing a plain grey t-shirt, neutral expression, flat daylight from a window, plain wall behind',
        'same person, three-quarter view turned slightly left, wearing a white tank top, small smile, window light from one side, living room behind',
        'same person, three-quarter view turned slightly right, wearing an oversized hoodie, relaxed expression, warm evening lamp light indoors',
        'same person, side profile, hair tucked behind the ear, wearing a knit sweater, overcast daylight outdoors',
        'same person, laughing mid-laugh with eyes crinkled, wearing a plain white t-shirt, bright daylight, street behind slightly out of focus',
        'same person, looking over the shoulder at the camera, wearing a denim jacket, late afternoon sun, park behind',
        'same person, close-up of the face, the collar of a dark t-shirt just in frame, serious expression, light from one side only, dim room',
        'same person, full body standing, head to toe in frame, {body}{outfit}, overcast daylight, city street behind',
        'same person, from the waist up, {body}at home in a plain fitted t-shirt, hair undone, soft expression, morning light, bedroom behind',
        'same person, from the knees up, {body}wearing a loose linen shirt over a t-shirt and shorts, sunglasses pushed up on the head, big smile, harsh midday sun, beach promenade behind',
    );

    /**
     * The caption that goes with one training picture (2026-10-13). Until now every picture was captioned with the trigger
     * word alone, so the model tied everything in the set to her name: her face, but also the camera-facing pose, the gaze
     * and the clothes. A caption that says what the picture shows (angle, gaze, expression, clothing, light, place) leaves
     * only what is never described, her face and build, to the trigger word. $shot is 1-based; $version picks the list the
     * picture was made from. A picture with no known shot (an uploaded photo) gets the trigger word and the subject only.
     */
    public static function training_caption(array $infl, $trigger, $shot = 0, $version = 1){
        $lead = trim((string) $trigger . ', a ' . self::noun($infl), ', ');
        $vars = ((int) $version >= 2) ? self::TRAINING_VARIATIONS_V2 : self::TRAINING_VARIATIONS;
        if ((int) $shot < 1) { return $lead; }
        $v = $vars[((int) $shot - 1) % count($vars)];
        $v = str_replace(array('{body}', '{outfit}'), array('', 'wearing ' . self::body_outfit($infl)), $v);
        $v = trim((string) preg_replace('/^same person,\s*/i', '', $v));
        return $lead . ', ' . $v;
    }

    /** One training-set prompt for her: the variation with her body words filled in. */
    public static function training_variation(array $infl, $i){
        $vars = self::training_vars();
        $body = self::build_phrase($infl);
        return str_replace(array('{body}', '{outfit}'), array($body !== '' ? $body . ', ' : '', self::body_outfit($infl)), $vars[((int) $i) % count($vars)]);
    }

    /**
     * One training shot's full instruction. With a body reference the model is told what each image is and that both
     * must match; the build words go on every shot that shows any of her (all but the face close-up), not only the
     * full-body ones: a head-and-shoulders picture still shows a bust and a frame, and left unsaid they come out
     * as whatever the model guesses.
     */
    public static function training_prompt(array $infl, $i, $has_body_ref = false){
        $vars = self::training_vars(); $v = $vars[((int) $i) % count($vars)];
        $body = self::build_phrase($infl); $pr = self::pronouns($infl);
        $intro = $has_body_ref
            ? 'The first image is ' . $pr[2] . ' face, the second ' . $pr[2] . ' body: the same ' . self::noun($infl) . ' in this picture, with the same face, hair, skin tone and exactly the build of the second image, in natural real-life proportions, nothing enlarged or exaggerated. '
            : '';
        $shot = self::training_variation($infl, $i);
        if ($body !== '' && strpos($v, '{body}') === false && stripos($v, 'close-up') === false) { $shot .= ', same build as in the second image (' . $body . ')'; }
        return $intro . $shot;
    }

    /**
     * The same prompt with her body words taken out, and plain clothes named when none are: what a shot is rerun
     * with after a model's content checker refused it (body words in a scene with no clothing named read as swimwear
     * to the checker). Returns the prompt unchanged when there is nothing to take out.
     */
    public static function softened_prompt(array $infl, $prompt){
        $out  = (string) $prompt;
        $body = self::body_phrase($infl);
        foreach (array_unique(array($body, self::body_unclothed($body), self::build_phrase($infl))) as $b) { if ($b !== '') { $out = str_ireplace(array($b . ', ', ', ' . $b, $b), '', $out); } }
        // a refused swimwear shot is rerun in the covered outfit: the outfit is swapped and the "nothing else" line goes
        $out = str_replace(self::body_reference_outfit($infl), 'wearing ' . self::body_outfit($infl), $out);
        $out = preg_replace('/Clothing in this picture: .*? or shoes\. /', '', $out);
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

    /** How long a signed URL for the creator's own influencer images lives: a wizard page stays open far longer than the 10-minute default. */
    const URL_TTL = 14400;

    /** Ready, signed image rows for a role (wizard grids, still pickers). */
    public static function images_json($creator_id, $influencer_id, $role = '', $include_excluded = false){
        $out = array();
        foreach ((new InfluencerImagesModel())->list_for_influencer($creator_id, $influencer_id, $role, $include_excluded) as $a) {
            $out[] = array(
                'id' => (int) $a['id'], 'role' => (string) $a['role'], 'job_id' => (int) $a['job_id'], 'result_index' => (int) $a['result_index'],
                'sort_order' => (int) $a['sort_order'], 'is_excluded' => (int) $a['is_excluded'], 'status' => (string) $a['status'],
                'type' => (string) $a['type'], 'width' => (int) $a['width'], 'height' => (int) $a['height'], 'duration' => (int) $a['duration_sec'],
                'thumb_url'   => ($a['status'] === 'ready') ? MediaService::signed_url($a, 'thumb', $creator_id, self::URL_TTL) : '',
                'display_url' => ($a['status'] === 'ready') ? MediaService::signed_url($a, $a['type'] === 'video' ? 'poster' : 'display', $creator_id, self::URL_TTL) : '',
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
                'dialogue'     => InfluencerConfig::picker_options('dialogue'),
                'video_enhance'=> InfluencerConfig::picker_options('video_enhance'),
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
