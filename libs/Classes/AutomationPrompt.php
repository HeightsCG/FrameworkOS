<?php
/**
 * Reads an automation's saved prompt (scheduler_rules.topic) without rewriting it.
 *
 * The prompt is usually a list of scene lines plus some lines that apply to every image and some caption rules.
 * split() sorts the LINES into those three groups (Claude answers with line numbers only, never text, so it cannot
 * reword anything); pick() chooses one scene line in code. Every line reaches the image model and the caption model
 * exactly as the creator typed it.
 */
class AutomationPrompt {

    /** How many recently used scene lines to remember per automation (scheduler_rules.scene_history). */
    const HISTORY = 50;

    /** ['scenes' => [line], 'shared' => [line], 'caption' => [line]], every entry verbatim from $topic. */
    public static function split($topic): array {
        $lines = array();
        foreach (preg_split('/\R/u', (string) $topic) as $l) { if (trim($l) !== '') { $lines[] = trim($l); } }
        $out = array('scenes' => array(), 'shared' => array(), 'caption' => array());
        if (count($lines) <= 1) { $out['scenes'] = $lines; return $out; }   // one line: that is the scene

        $groups = self::classify($lines) ?? self::classify_plain($lines);
        foreach ($groups as $key => $idx) {
            foreach ($idx as $i) { $out[$key][] = self::strip_marker($lines[$i]); }
        }
        // No list of alternatives: the image lines together are the one scene.
        if (empty($out['scenes']) && !empty($out['shared'])) { $out['scenes'] = array(implode(' ', $out['shared'])); $out['shared'] = array(); }
        return $out;
    }

    /**
     * One scene line, chosen at random from the lines this automation has not used recently (all of them once every
     * line has been used). $history is the rule's scene_history; returns [line, new history].
     */
    public static function pick(array $scenes, $history): array {
        if (empty($scenes)) { return array('', (string) $history); }
        $used = json_decode((string) $history, true);
        $used = is_array($used) ? $used : array();
        $fresh = array_values(array_filter($scenes, function ($s) use ($used) { return !in_array(md5($s), $used, true); }));
        if (empty($fresh)) {   // every line used: start a new cycle, but never repeat the line that ran last
            $last = end($used);
            $fresh = array_values(array_filter($scenes, function ($s) use ($last) { return md5($s) !== $last; }));
            $used = array();
            if (empty($fresh)) { $fresh = $scenes; }
        }
        $line = $fresh[random_int(0, count($fresh) - 1)];
        $used[] = md5($line);
        return array($line, json_encode(array_slice($used, -self::HISTORY)));
    }

    /** The saved caption house style, enforced in code: all lowercase, no em dashes, at most one emoji. */
    public static function clean_caption($caption): string {
        $s = mb_strtolower(trim((string) $caption), 'UTF-8');
        $s = preg_replace('/\s*(—|\s–\s)\s*/u', ', ', $s);          // em dash (and a spaced en dash standing in for one)
        $s = preg_replace('/,\s*([,.!?])/u', '$1', $s);               // "word, ." → "word."
        $seen = false;
        $s = preg_replace_callback(self::EMOJI, function ($m) use (&$seen) {
            if ($seen) { return ''; }
            $seen = true; return $m[0];
        }, $s);
        $s = preg_replace('/[ \t]{2,}/u', ' ', $s);
        $s = preg_replace('/ +([,.!?])/u', '$1', $s);
        return trim($s, " \t\n,");
    }

    /** One emoji as a reader sees it: ZWJ sequences, skin tones, variation selectors and flags count once. */
    const EMOJI = '/(?:\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}]*(?:\x{200D}\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}]*)*|[\x{1F1E6}-\x{1F1FF}]{2}|[0-9#*]\x{FE0F}?\x{20E3})/u';

    /** Claude sorts the numbered lines; null when it is unavailable or answers with anything unusable. */
    private static function classify(array $lines) {
        if (!ClaudeService::configured()) { return null; }
        $numbered = '';
        foreach ($lines as $i => $l) { $numbered .= ($i + 1) . ': ' . $l . "\n"; }
        $system = "A creator wrote instructions for an automation that posts one AI photo with a caption on each run. "
                . "Sort their numbered lines into three groups:\n"
                . "scenes: lines that each describe one alternative photo (one option per run).\n"
                . "shared: lines about the photo that apply to every run (the person, look, style, lighting), and section headings about the photos.\n"
                . "caption: lines about the caption or text of the post (tone, case, punctuation, emoji, hashtags, length), and their headings.\n"
                . "Every line number goes in exactly one group. Reply with JSON only, numbers only, like {\"scenes\":[2,3],\"shared\":[1],\"caption\":[4]}.";
        $res = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $numbered)), 300, 25, 'low');
        if (empty($res['ok']) || !preg_match('/\{.*\}/s', (string) $res['text'], $m)) { return null; }
        $d = json_decode($m[0], true);
        if (!is_array($d)) { return null; }
        $out = array('scenes' => array(), 'shared' => array(), 'caption' => array());
        $taken = array();
        foreach ($out as $key => $_) {
            foreach ((array) ($d[$key] ?? array()) as $n) {
                $i = (int) $n - 1;
                if ($i >= 0 && $i < count($lines) && !isset($taken[$i])) { $out[$key][] = $i; $taken[$i] = true; }
            }
        }
        foreach ($lines as $i => $_) { if (!isset($taken[$i])) { $out['shared'][] = $i; } }   // anything left out still reaches the image
        foreach ($out as $key => $idx) { sort($out[$key]); }
        // Headings ("Scenes:", "Caption rules:") are not content.
        foreach ($out as $key => $idx) { $out[$key] = array_values(array_filter($idx, function ($i) use ($lines) { return !self::is_heading($lines[$i]); })); }
        return $out;
    }

    /** No AI: list items are scenes, lines under a caption heading or about captions are caption rules, the rest is shared. */
    private static function classify_plain(array $lines): array {
        $out = array('scenes' => array(), 'shared' => array(), 'caption' => array());
        $section = '';
        foreach ($lines as $i => $l) {
            if (self::is_heading($l)) { $section = preg_match('/caption|text|copy/i', $l) ? 'caption' : ''; continue; }
            if ($section === 'caption' || preg_match('/\b(captions?|hashtags?|emojis?|lowercase|em[ -]?dash(es)?)\b/i', $l)) { $out['caption'][] = $i; }
            elseif (preg_match('/^\s*([-*•–]|\d+[.)])\s+/u', $l)) { $out['scenes'][] = $i; }
            else { $out['shared'][] = $i; }
        }
        return $out;
    }

    private static function is_heading($l): bool {
        return (bool) preg_match('/^[\p{L}\p{N} \/&\'()-]{1,40}:$/u', trim($l));
    }

    /** "- beach at sunset" → "beach at sunset". Only the list marker goes; the words stay as typed. */
    private static function strip_marker($l): string {
        return trim(preg_replace('/^\s*([-*•–]|\d+[.)])\s+/u', '', $l));
    }
}
