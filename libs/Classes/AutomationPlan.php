<?php
/**
 * The AI step of an influencer video automation. Each run it reads the creator's Scene brief and writes three things:
 *
 *   image_prompt : one still photo of her for the base image (Nano Banana Pro from her references). No text of any kind
 *                  in it: no signs, notes, captions, written words or readable screens. Models misspell letters.
 *   hook         : the on-screen line, burned onto the video in code afterwards (TextOverlay), never drawn by a model.
 *   motion       : one concrete action for the video model, e.g. "she looks up from her phone, laughs, and turns the
 *                  screen toward the camera". Only this goes to the video model, never the brief.
 *
 * Image automations use text_from_scene(): when a scene asks for words in the picture, those words come out of the
 * image prompt and are overlaid in code instead; everything else in the scene stays exactly as typed.
 */
class AutomationPlan {

    /** Appended to every motion action: how it is filmed. */
    const CAMERA = 'handheld selfie camera, real-time speed';

    /** How many recent hook lines the writer is told to avoid repeating. */
    const RECENT = 12;

    /**
     * Plan one run. $notes: the rule's own Motion field (how the video should feel), passed as guidance only.
     * Returns ['ok' => true, 'image_prompt', 'hook', 'motion'] or ['ok' => false, 'error'].
     */
    public static function video(array $infl, $brief, $notes = '', array $recent_hooks = array()){
        if (!ClaudeService::configured()) { return array('ok' => false, 'error' => 'The AI writer is not configured.'); }
        $name = trim((string) ($infl['name'] ?? '')) ?: 'the influencer';
        $persona = InfluencerService::persona_block($infl);
        $system = "You plan one short vertical video post of {$name}, an AI influencer, for an automation that posts every day. "
            . "The creator's brief says what the videos are for and what they should say. Read it and plan THIS run only.\n\n"
            . "Write three things:\n"
            . "1. hook: the one line of on-screen text for this video, taken from or written in the spirit of the brief. "
            . "Short (at most 60 characters), plain words, correctly spelled, no emoji, no hashtags, no quotation marks. "
            . "Keep the brief's casing style (if its example lines are lowercase, write lowercase). It must be different from every recent line listed.\n"
            . "2. image_prompt: one still photo of {$name} that the video starts from: where she is, what she wears, what she holds, her pose and expression, the light. "
            . "NEVER describe her face, hair (colour, length, style, updo, clips), makeup, skin or body: those come only from her reference pictures, and describing them changes who she is. "
            . "It reads like a real iPhone photo, natural light, real skin. "
            . "ABSOLUTELY NO TEXT in the picture: no writing, words, letters, numbers, signs, sticky notes, napkins or cups with writing, captions, logos, "
            . "and no readable screens (a phone or laptop screen faces away from the camera or shows only a blur of light). "
            . "If the brief imagines the line written on something in the scene, leave the writing out: the line is added later as an overlay. "
            . "Frame her close, like a selfie: waist-up or closer, her face large and clear in the middle third of the picture, and only plain background (wall, ceiling, sky) in the top third above her head: the line is placed there and must not cover her face. "
            . "Do not name the influencer; say \"she\". 40 to 80 words.\n"
            . "3. motion: ONE concrete physical action she does in the clip, in plain words, as she would really do it, "
            . "for example: \"she looks up from her phone, laughs, and turns the screen toward the camera\". "
            . "One sentence, at most 25 words, present tense, starting with \"she\". Only movement: no text, no camera directions, no style words, no dialogue.\n\n"
            . "The photo and the action must fit together and suit the hook. Vary the setting and action from run to run.\n"
            . ($persona !== '' ? "About her:\n" . $persona . "\n\n" : '')
            . "Reply with JSON only: {\"hook\":\"...\",\"image_prompt\":\"...\",\"motion\":\"...\"}";
        $user = "BRIEF:\n" . trim((string) $brief)
            . (trim((string) $notes) !== '' ? "\n\nHOW THE VIDEO SHOULD FEEL (guidance for the action only):\n" . trim((string) $notes) : '')
            . "\n\nRECENT HOOK LINES (do not repeat):\n" . (empty($recent_hooks) ? '(none yet)' : '- ' . implode("\n- ", array_slice($recent_hooks, 0, self::RECENT)));
        $res = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $user)), 900, 60, 'low');
        if (empty($res['ok']) || !preg_match('/\{.*\}/s', (string) $res['text'], $m)) {
            return array('ok' => false, 'error' => 'The AI writer did not answer: ' . (string) ($res['error'] ?? 'no plan returned'));
        }
        $d = json_decode($m[0], true);
        $hook   = self::line($d['hook'] ?? '');
        $prompt = trim((string) ($d['image_prompt'] ?? ''));
        $motion = trim(rtrim(trim((string) ($d['motion'] ?? '')), '.'));
        if ($hook === '' || $prompt === '' || $motion === '') { return array('ok' => false, 'error' => 'The AI writer returned an incomplete plan.'); }
        return array('ok' => true, 'hook' => $hook, 'image_prompt' => $prompt . (preg_match('/[.!?]$/', $prompt) ? '' : '.') . ' Close selfie framing, her face large in the middle third, plain background in the top third above her head. No text, words or writing anywhere in the picture.',
            'motion' => $motion . ', ' . self::CAMERA . '.');
    }

    /**
     * An image automation's scene: if it asks for words to appear in the picture, return them as the overlay line and
     * the scene with only that request removed. Returns ['scene' => string, 'line' => string]; a scene without words in
     * it (or when the writer is unavailable) comes back unchanged with line ''.
     */
    public static function text_from_scene($scene){
        $scene = trim((string) $scene);
        $out = array('scene' => $scene, 'line' => '');
        if ($scene === '' || !ClaudeService::configured()) { return $out; }
        // Cheap screen first: only scenes that talk about writing go to the writer.
        if (!preg_match('/\b(text|says?|saying|written|writes?|writing|reads?|reading|words?|caption|sign|note|label|quote|line|lettering|typed|handwritten|scribbled)\b|["“”]/iu', $scene)) { return $out; }
        $system = "An image model will draw this photo description, but it misspells any words it draws, so on-screen words are added afterwards in code. "
            . "If the description asks for specific words to be visible in the picture (on a sign, note, screen, cup, mirror, shirt, a caption, and so on), "
            . "return those exact words as \"line\" and the description with ONLY the request for writing removed (keep every other word exactly as written; the object itself may stay, blank). "
            . "If no words are asked for, return line as \"\" and the description unchanged. Reply with JSON only: {\"line\":\"...\",\"scene\":\"...\"}";
        $res = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $scene)), 600, 30, 'low');
        if (empty($res['ok']) || !preg_match('/\{.*\}/s', (string) $res['text'], $m)) { return $out; }
        $d = json_decode($m[0], true);
        $line = self::line($d['line'] ?? '');
        $new  = trim((string) ($d['scene'] ?? ''));
        if ($line === '' || $new === '') { return $out; }
        return array('scene' => $new . (preg_match('/[.!?]$/', $new) ? '' : '.') . ' No text, words or writing anywhere in the picture.', 'line' => $line);
    }

    /** One clean overlay line: no wrapping quotes, no emoji, single spaces. */
    private static function line($s){
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        $s = trim($s, " \"'“”‘’");
        $s = preg_replace(AutomationPrompt::EMOJI, '', $s);
        return trim(mb_substr($s, 0, 120));
    }
}
