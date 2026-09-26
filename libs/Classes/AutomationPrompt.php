<?php
/**
 * Reads an automation's saved prompt (scheduler_rules.topic) without rewriting it.
 *
 * Creators write it two ways: one line per scene, or one paragraph ("…rules… look… Scenes: a; b; c"). segments()
 * cuts either into pieces (lines, the items of a "Scenes:" list, the sentences of a long paragraph) and every piece
 * is sorted into scenes / shared (applies to every photo) / caption rules / directions (instructions to the
 * automation itself, which no model should draw). Claude only answers with piece numbers, never text, so nothing is
 * reworded; pick() chooses one scene in code. Every piece reaches the image model and the caption model as typed.
 */
class AutomationPrompt {

    /** How many recently used scene lines to remember per automation (scheduler_rules.scene_history). */
    const HISTORY = 50;

    /** A line longer than this is a paragraph: it is cut into sentences so rules and look can be told apart. */
    const PARAGRAPH = 300;

    /** ['scenes' => [text], 'shared' => [text], 'caption' => [text]], every entry verbatim from $topic. */
    public static function split($topic): array {
        $out  = array('scenes' => array(), 'shared' => array(), 'caption' => array());
        $segs = self::segments($topic);
        if (count($segs) <= 1) { foreach ($segs as $s) { $out['scenes'][] = $s['text']; } return $out; }   // one piece: that is the scene

        // Items of a "Scenes:" list are scenes by construction; only the other pieces need sorting.
        $open = array();
        foreach ($segs as $i => $s) { if ($s['scene']) { $out['scenes'][] = $s['text']; } else { $open[] = $s['text']; } }
        if (!empty($open)) {
            $groups = (count($open) === 1 && !empty($out['scenes'])) ? array('shared' => array(0)) : (self::classify($open, !empty($out['scenes'])) ?? self::classify_plain($open));
            foreach (array('scenes', 'shared', 'caption') as $key) {
                foreach ((array) ($groups[$key] ?? array()) as $i) { $out[$key][] = $open[$i]; }
            }
        }
        // No list of alternatives: the image pieces together are the one scene.
        if (empty($out['scenes']) && !empty($out['shared'])) { $out['scenes'] = array(implode(' ', $out['shared'])); $out['shared'] = array(); }
        return $out;
    }

    /**
     * One scene, chosen at random from the ones this automation has not used recently (all of them once every scene
     * has been used). $history is the rule's scene_history; returns [scene, new history].
     */
    public static function pick(array $scenes, $history): array {
        if (empty($scenes)) { return array('', (string) $history); }
        $used = json_decode((string) $history, true);
        $used = is_array($used) ? $used : array();
        $fresh = array_values(array_filter($scenes, function ($s) use ($used) { return !in_array(md5($s), $used, true); }));
        if (empty($fresh)) {   // every scene used: start a new cycle, but never repeat the one that ran last
            $last = end($used);
            $fresh = array_values(array_filter($scenes, function ($s) use ($last) { return md5($s) !== $last; }));
            $used = array();
            if (empty($fresh)) { $fresh = $scenes; }
        }
        $line = $fresh[random_int(0, count($fresh) - 1)];
        $used[] = md5($line);
        return array($line, json_encode(array_slice($used, -self::HISTORY)));
    }

    /**
     * Enforce, in code, the formatting rules the creator's own caption instructions state, so they hold even when the
     * model slips: lowercase, no em dashes, an emoji limit, no hashtags, no links. A rule nobody wrote is never applied.
     */
    public static function clean_caption($caption, array $rules = array()): string {
        $s = trim((string) $caption);
        $r = implode("\n", $rules);
        if ($s === '' || trim($r) === '') { return $s; }
        if (preg_match('/lower\s*-?\s*case/i', $r)) { $s = mb_strtolower($s, 'UTF-8'); }
        if (preg_match('/em[\s-]?dash/i', $r)) {
            $s = preg_replace('/\s*(—|\s–\s)\s*/u', ', ', $s);          // em dash (and a spaced en dash standing in for one)
            $s = preg_replace('/,\s*([,.!?])/u', '$1', $s);
        }
        $max = null;
        if (preg_match('/\bno\s+emojis?\b/i', $r)) { $max = 0; }
        elseif (preg_match('/\b(one|1|single)\s+emoji|emojis?\s*(max|maximum|limit)\D{0,12}(one|1)\b|at\s+most\s+(one|1)\s+emoji/i', $r)) { $max = 1; }
        if ($max !== null) {
            $n = 0;
            $s = preg_replace_callback(self::EMOJI, function ($m) use (&$n, $max) { return (++$n <= $max) ? $m[0] : ''; }, $s);
        }
        if (preg_match('/\bno\s+hashtags?\b/i', $r)) { $s = preg_replace('/(^|\s)#[\p{L}\p{N}_]+/u', '$1', $s); }
        if (preg_match('/\bno\s+links?\b/i', $r))    { $s = preg_replace('#(https?://|www\.)\S+#iu', '', $s); }
        $s = preg_replace('/[ \t]{2,}/u', ' ', $s);
        $s = preg_replace('/ +([,.!?])/u', '$1', $s);
        return trim($s, " \t\n,");
    }

    /** One emoji as a reader sees it: ZWJ sequences, skin tones, variation selectors and flags count once. */
    const EMOJI = '/(?:\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}]*(?:\x{200D}\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}]*)*|[\x{1F1E6}-\x{1F1FF}]{2}|[0-9#*]\x{FE0F}?\x{20E3})/u';

    /**
     * The prompt cut into pieces, in order: [['text' => string, 'scene' => bool]]. 'scene' is true for the items of a
     * "Scenes:" list (inline "Scenes: a; b; c", or the lines under a "Scenes:" heading line).
     */
    public static function segments($topic): array {
        $out = array();
        $under_scenes = false;
        foreach (preg_split('/\R/u', (string) $topic) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            if (self::is_heading($line)) { $under_scenes = (bool) preg_match('/^(scenes?|shots?|poses?|options?)\b/i', $line); continue; }
            // "…text… Scenes: a; b; c" — the list after the label, one scene per item.
            if (preg_match('/^(.*?)(?:^|[\s.])(?:scenes?|shots?|poses?)\s*:\s*(.+)$/isu', $line, $m) && substr_count($m[2], ';') >= 1) {
                foreach (self::sentences($m[1]) as $t) { $out[] = array('text' => $t, 'scene' => false); }
                foreach (explode(';', $m[2]) as $t) { $t = self::tidy($t); if ($t !== '') { $out[] = array('text' => $t, 'scene' => true); } }
                $under_scenes = false;
                continue;
            }
            if ($under_scenes) { $out[] = array('text' => self::tidy($line), 'scene' => true); continue; }
            foreach ((mb_strlen($line) > self::PARAGRAPH ? self::sentences($line) : array($line)) as $t) { $out[] = array('text' => self::tidy($t), 'scene' => false); }
        }
        return array_values(array_filter($out, function ($s) { return $s['text'] !== ''; }));
    }

    /** A paragraph's sentences, each as typed. Splits after . ! ? only when the next sentence starts with a capital or a quote. */
    private static function sentences($text): array {
        $text = trim((string) $text);
        if ($text === '') { return array(); }
        $parts = preg_split('/(?<=[.!?])\s+(?=[\p{Lu}"“])/u', $text);
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }

    /** Claude sorts the numbered pieces; null when it is unavailable or answers with anything unusable. */
    private static function classify(array $pieces, $has_scene_list = false) {
        if (!ClaudeService::configured()) { return null; }
        $numbered = '';
        foreach ($pieces as $i => $p) { $numbered .= ($i + 1) . ': ' . $p . "\n"; }
        $system = "A creator wrote instructions for an automation that posts one AI photo with a caption on each run. "
                . "Sort their numbered pieces into four groups:\n"
                . ($has_scene_list
                    ? "scenes: leave empty; the scene options are listed separately.\n"
                    : "scenes: pieces that each describe one alternative photo (one option per run).\n")
                . "shared: descriptions of the photo that apply to every run (the person, body, outfit, setting, light, style), and headings about the photos.\n"
                . "caption: anything about the caption or text of the post (voice, tone, case, punctuation, emoji, hashtags, length), and its headings.\n"
                . "directions: instructions or rules addressed to the automation or the AI rather than descriptions of the picture: how to pick or cycle scenes, and rules about how to follow the scene lines (\"HEAD RULE: …\", \"follow…\", \"when it says…\", \"must not…\", \"do not add…\"). A sentence that continues a rule belongs to the same group as the rule.\n"
                . "Every number goes in exactly one group. Reply with JSON only, numbers only, like {\"scenes\":[2,3],\"shared\":[1],\"caption\":[4],\"directions\":[5]}.";
        $res = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $numbered)), 400, 25, 'low');
        if (empty($res['ok']) || !preg_match('/\{.*\}/s', (string) $res['text'], $m)) { return null; }
        $d = json_decode($m[0], true);
        if (!is_array($d)) { return null; }
        $out = array('scenes' => array(), 'shared' => array(), 'caption' => array(), 'directions' => array());
        $taken = array();
        foreach ($out as $key => $_) {
            if ($key === 'scenes' && $has_scene_list) { continue; }
            foreach ((array) ($d[$key] ?? array()) as $n) {
                $i = (int) $n - 1;
                if ($i >= 0 && $i < count($pieces) && !isset($taken[$i])) { $out[$key][] = $i; $taken[$i] = true; }
            }
        }
        foreach ($pieces as $i => $_) { if (!isset($taken[$i])) { $out['shared'][] = $i; } }   // anything left out still reaches the image
        // A rule about the scene lines is never drawn: the image model would take "face", "camera" and "looking" as content.
        foreach ($out['shared'] as $k => $i) {
            if (preg_match(self::DIRECTION, $pieces[$i])) { $out['directions'][] = $i; unset($out['shared'][$k]); }
        }
        foreach ($out as $key => $idx) { $out[$key] = array_values($idx); sort($out[$key]); }
        return $out;
    }

    /** No AI: caption talk goes to the caption, instructions to the automation are dropped, list items are scenes, the rest is shared. */
    private static function classify_plain(array $pieces): array {
        $out = array('scenes' => array(), 'shared' => array(), 'caption' => array(), 'directions' => array());
        foreach ($pieces as $i => $p) {
            if (preg_match('/\b(captions?|hashtags?|emojis?|lowercase|capitali[sz]e|em[ -]?dash(es)?)\b/i', $p)) { $out['caption'][] = $i; }
            elseif (preg_match(self::DIRECTION, $p)) { $out['directions'][] = $i; }
            elseif (preg_match('/^\s*([-*•–]|\d+[.)])\s+/u', $p)) { $out['scenes'][] = $i; }
            else { $out['shared'][] = $i; }
        }
        return $out;
    }

    /** Wording that only ever appears in instructions to the automation, never in a description of the picture. */
    const DIRECTION = '/\b(each run|every run|pick one|cycle through|before repeating|scene lines?|follow the|when it says|must not|must be|do not add|[A-Z]{3,} RULE)\b/u';

    private static function is_heading($l): bool {
        return (bool) preg_match('/^[\p{L}\p{N} \/&\'()-]{1,40}:$/u', trim($l));
    }

    /** Only a list marker or the separator's leftovers go ("- beach at sunset;" → "beach at sunset"); the words stay as typed. */
    private static function tidy($t): string {
        $t = trim(preg_replace('/^\s*([-*•–]|\d+[.)])\s+/u', '', (string) $t));
        return trim(rtrim($t, ';'));
    }
}
