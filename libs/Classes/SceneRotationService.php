<?php
/**
 * Scene rotation for scheduler automations. A rule carries three lists (poses, outfits,
 * lighting) as JSON arrays; each run combines one line from each into the image prompt
 *   "{pose}, {outfit}, {lighting}. {scene_suffix}"
 * and remembers the last RECENT_LIMIT index triples in `recent_combos` so consecutive runs
 * never repeat a combination. Pure functions — persistence is SchedulerRulesModel's job.
 * Nothing here calls an AI model: the string returned by pick() is sent verbatim.
 */
class SceneRotationService {

    const RECENT_LIMIT   = 30;
    const MAX_LINES      = 100;
    const MAX_LINE_CHARS = 200;
    const ENUMERATE_MAX  = 5000;   // above this many combinations, sample instead of enumerating

    /** Textarea text (one entry per line) or an array → clean, de-duplicated list of strings. */
    public static function parse_lines($v): array {
        if (is_string($v)) { $v = preg_split('/\r\n|\r|\n/', $v); }
        $out = array();
        foreach ((array) $v as $line) {
            $line = trim(preg_replace('/\s+/', ' ', (string) $line));
            if ($line === '') { continue; }
            $out[] = mb_substr($line, 0, self::MAX_LINE_CHARS);
            if (count($out) >= self::MAX_LINES) { break; }
        }
        return array_values(array_unique($out));
    }

    /** Decode the three JSON columns → ['poses' => [], 'outfits' => [], 'lighting' => []]. */
    public static function lists(array $rule): array {
        $dec = function ($col) use ($rule) {
            $a = json_decode((string) ($rule[$col] ?? ''), true);
            return is_array($a) ? array_values(array_map('strval', $a)) : array();
        };
        return array('poses' => $dec('scene_poses'), 'outfits' => $dec('scene_outfits'), 'lighting' => $dec('scene_lighting'));
    }

    public static function combo_count(array $rule): int {
        $l = self::lists($rule);
        return count($l['poses']) * count($l['outfits']) * count($l['lighting']);
    }

    /** Rotation only runs when all three lists have at least one line. */
    public static function configured(array $rule): bool {
        return self::combo_count($rule) > 0;
    }

    /** Decoded ring of [p, o, l] triples, oldest first. */
    public static function recent(array $rule): array {
        $r = json_decode((string) ($rule['recent_combos'] ?? ''), true);
        $out = array();
        foreach ((array) $r as $t) {
            if (is_array($t) && count($t) === 3) { $out[] = array((int) $t[0], (int) $t[1], (int) $t[2]); }
        }
        return $out;
    }

    /**
     * Choose a combination not in the ring and build the prompt.
     * Returns ['prompt' => string, 'combo' => [p, o, l]] or null when the rule has no lists.
     * When every combination has been used recently (fewer than 30 combos exist), any
     * combination except the most recent one is allowed again.
     */
    public static function pick(array $rule): ?array {
        $l = self::lists($rule);
        $np = count($l['poses']); $no = count($l['outfits']); $nl = count($l['lighting']);
        if ($np === 0 || $no === 0 || $nl === 0) { return null; }
        $recent = self::recent($rule);
        $used = array();
        foreach ($recent as $t) { $used[implode('/', $t)] = true; }
        $total = $np * $no * $nl;
        $combo = null;

        if ($total <= self::ENUMERATE_MAX) {
            $candidates = array();
            for ($i = 0; $i < $np; $i++) { for ($j = 0; $j < $no; $j++) { for ($k = 0; $k < $nl; $k++) {
                if (!isset($used["$i/$j/$k"])) { $candidates[] = array($i, $j, $k); }
            } } }
            if (empty($candidates)) {
                // Ring covers every combination: allow all but the most recent one.
                $last = !empty($recent) ? implode('/', end($recent)) : '';
                for ($i = 0; $i < $np; $i++) { for ($j = 0; $j < $no; $j++) { for ($k = 0; $k < $nl; $k++) {
                    if ($total === 1 || "$i/$j/$k" !== $last) { $candidates[] = array($i, $j, $k); }
                } } }
            }
            $combo = $candidates[random_int(0, count($candidates) - 1)];
        } else {
            // Huge space: rejection-sample; 50 tries against a 30-entry ring cannot realistically fail.
            $c = array(0, 0, 0);
            for ($try = 0; $try < 50; $try++) {
                $c = array(random_int(0, $np - 1), random_int(0, $no - 1), random_int(0, $nl - 1));
                if (!isset($used[implode('/', $c)])) { $combo = $c; break; }
            }
            if ($combo === null) { $combo = $c; }
        }

        $prompt = $l['poses'][$combo[0]] . ', ' . $l['outfits'][$combo[1]] . ', ' . $l['lighting'][$combo[2]] . '.';
        $suffix = trim((string) ($rule['scene_suffix'] ?? ''));
        if ($suffix !== '') { $prompt .= ' ' . $suffix; }
        return array('prompt' => $prompt, 'combo' => $combo);
    }

    /** Append a triple and keep only the newest RECENT_LIMIT entries. */
    public static function push_recent(array $recent, array $combo): array {
        $recent[] = array((int) $combo[0], (int) $combo[1], (int) $combo[2]);
        return array_values(array_slice($recent, -self::RECENT_LIMIT));
    }
}
