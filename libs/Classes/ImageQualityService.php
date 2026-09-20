<?php
/**
 * Render quality check for generated people: hands, fingers, feet, toes, limbs, joints, faces.
 * Flux-family models produce extra toes and impossible feet often enough that an unattended
 * automation would post them; this asks Claude (vision) for a strict verdict so the job engine
 * can re-roll. It is a quality check, not a content check: nothing here judges what is shown.
 * Never throws; when the check cannot run it says so and the image is treated as fine.
 */
class ImageQualityService {

    /** Test hook: a callable(bytes, mime) → array('ok'=>bool,'issues'=>string). */
    public static $override = null;

    const SYSTEM = 'You are a meticulous photo retoucher checking AI-generated photographs of people for anatomy errors before they are published. You only judge anatomy and physical plausibility, never the subject, clothing, pose or content.';

    const ASK = 'Inspect this AI-generated photo of a person for anatomy errors. Count fingers and toes, check that every visible arm and leg has a plausible path to the body, that hands and feet could exist and are attached the right way round, that limbs are not merged or duplicated, and that the face is intact. Rate severity 0-10 with these anchors: 0 = nothing wrong; 2 = fingers slightly soft or hidden, nothing a viewer would notice; 4 = one hand or foot looks a little off on close inspection; 6 = fingers or toes clearly merged or miscounted, a viewer looking at the hand or foot would notice; 8 = an arm or leg missing, extra, or attached impossibly, or feet/hands obviously deformed; 10 = grossly deformed. Hidden or cropped hands and feet are not errors. Answer with JSON only, no prose: {"severity": <0-10>, "issues": "short plain-language description of the noticeable errors, or empty"}.';

    /** Renders at or above this severity are re-rolled. */
    const FAIL_AT = 6;

    /** @return array ['ok'=>bool, 'issues'=>string, 'severity'=>int, 'checked'=>bool] */
    public static function check($bytes, $mime = 'image/jpeg'): array {
        try {
            if (self::$override !== null) { $r = call_user_func(self::$override, $bytes, $mime); return array('ok' => !empty($r['ok']), 'issues' => (string) ($r['issues'] ?? ''), 'severity' => (int) ($r['severity'] ?? 0), 'checked' => true); }
            if (!(int) InfluencerConfig::get('quality_check', 1)) { return array('ok' => true, 'issues' => '', 'checked' => false); }
            if (!ClaudeService::configured()) { return array('ok' => true, 'issues' => '', 'checked' => false); }
            $small = self::downscale($bytes, $mime);
            $j = null;
            for ($try = 0; $try < 2 && !is_array($j); $try++) {   // one retry: the answer occasionally arrives without the JSON
                $res = ClaudeService::vision(self::SYSTEM, self::ASK, $small, 'image/jpeg', 1024, 60, 'medium');   // room for thinking + the JSON
                if (empty($res['ok'])) { error_log('[quality] ' . $res['error']); return array('ok' => true, 'issues' => '', 'checked' => false); }
                $j = self::json($res['text']);
                if (!is_array($j) || !array_key_exists('severity', $j)) { error_log('[quality] unparseable answer: ' . mb_substr((string) $res['text'], 0, 200)); $j = null; }
            }
            if (!is_array($j)) { return array('ok' => true, 'issues' => '', 'checked' => false); }
            $sev = max(0, min(10, (int) $j['severity']));
            return array('ok' => $sev < self::FAIL_AT, 'issues' => mb_substr(trim((string) ($j['issues'] ?? '')), 0, 180), 'severity' => $sev, 'checked' => true);
        } catch (\Throwable $e) {
            error_log('[quality] ' . $e->getMessage());
            return array('ok' => true, 'issues' => '', 'checked' => false);
        }
    }

    /** Keep the request small: longest side 1024, JPEG. Falls back to the original bytes. */
    private static function downscale($bytes, $mime) {
        if (!function_exists('imagecreatefromstring')) { return $bytes; }
        $im = @imagecreatefromstring($bytes);
        if (!$im) { return $bytes; }
        $w = imagesx($im); $h = imagesy($im); $max = 1024;
        if ($w > $max || $h > $max) {
            $s = $max / max($w, $h); $nw = (int) round($w * $s); $nh = (int) round($h * $s);
            $dst = imagecreatetruecolor($nw, $nh); imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); imagedestroy($im); $im = $dst;
        }
        ob_start(); imagejpeg($im, null, 88); $out = ob_get_clean(); imagedestroy($im);
        return $out !== '' ? $out : $bytes;
    }

    private static function json($text) {
        $t = trim((string) $text);
        if (preg_match('/\{.*\}/s', $t, $m)) { $t = $m[0]; }
        return json_decode($t, true);
    }
}
