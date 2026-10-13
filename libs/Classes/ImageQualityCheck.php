<?php
/**
 * A second look at one finished picture (2026-10-13, admin testing mode). A vision model is shown the picture and the
 * creator's own prompt and answers: is the anatomy right, was the angle and the gaze that were asked for delivered, is
 * the composition what was asked. The answer is recorded on the job (result_json.quality) with a confidence per item.
 * Nothing is rejected or made again on the strength of it: it is here to measure how often it is right and what it costs.
 */
class ImageQualityCheck {

    const SYSTEM = 'You inspect one AI-generated photo of a person against the description its creator wrote. Report only what is visible. '
        . 'Reply with one JSON object and nothing else, in exactly this shape: '
        . '{"anatomy":{"ok":true,"issues":[],"confidence":0.0},'
        . '"pose":{"asked":"","seen":"","match":null,"confidence":0.0},'
        . '"gaze":{"asked":"","seen":"","match":null,"confidence":0.0},'
        . '"composition":{"ok":true,"issues":[],"confidence":0.0},'
        . '"people":1,"summary":""}. '
        . 'anatomy: count the arms, hands and legs that are visible. ok is false ONLY for something physically impossible or visibly malformed: more than two arms or legs on one person, an extra or fused hand, a hand with clearly too many or too few fingers, a limb attached in the wrong place, a torso that faces two ways at once, a body floating with nothing under it. '
        . 'A limb that is hidden, cropped, behind the body or out of frame is normal and is NOT a problem. A pose, prop or hand position that differs from the description is NOT an anatomy problem: put that under composition. Slightly soft or blurred fingers are not a problem. When in doubt, ok is true with a lower confidence. '
        . 'pose.asked and pose.seen are each one of: "face-on", "three-quarter", "side profile", "from behind", "not stated" (asked only). Judge "seen" from where the nose points relative to the camera. '
        . 'gaze.asked and gaze.seen are each one of: "at camera", "away", "not visible", "not stated" (asked only). '
        . 'match is true or false, or null when nothing was asked. A face-on picture when a side or three-quarter view was asked is match false. A picture turned further from the camera than asked (a side profile when three-quarter was asked) is match true. '
        . 'composition: flag a crop, framing, setting or outfit that clearly differs from what was asked, and a second person who looks like a copy of the subject. '
        . 'people is the number of people clearly visible. confidence is 0 to 1: how sure you are of that item. summary is one plain sentence.';

    /** Check the job's finished picture. Returns ['ok', 'quality' => [...], 'error']. Records the result on the job when it succeeds. */
    public static function run(array $job){
        if ((string) $job['status'] !== 'done' || empty($job['result_asset_id'])) { return array('ok' => false, 'error' => 'This job has no finished picture.'); }
        if (!ClaudeService::configured()) { return array('ok' => false, 'error' => 'The checker is not configured.'); }
        $a = (new MediaAssetsModel())->get_one((int) $job['creator_id'], (int) $job['result_asset_id']);
        if (!$a) { return array('ok' => false, 'error' => 'The picture is no longer in the library.'); }
        $key = (string) ($a['display_key'] ?: ($a['original_key'] ?: $a['thumb_key']));
        $url = $key !== '' ? S3Service::presigned_get_url($key, 300) : '';
        $bytes = $url !== '' ? @file_get_contents($url) : '';
        if ((string) $bytes === '') { return array('ok' => false, 'error' => 'Could not read the picture.'); }
        $mime = (string) (@getimagesizefromstring($bytes)['mime'] ?? 'image/jpeg');
        $p = InfluencerJobsModel::params($job);
        $asked = trim((string) ($p['user_prompt'] ?? '')) !== '' ? (string) $p['user_prompt'] : (string) $job['prompt'];
        $t0 = microtime(true);
        $r = ClaudeService::vision(self::SYSTEM, "The creator's description:\n" . mb_substr($asked, 0, 4000), $bytes, $mime, 600, 60, 'low');
        if (empty($r['ok'])) { return array('ok' => false, 'error' => 'The checker did not answer.'); }
        $q = self::parse((string) $r['text']);
        if (!$q) { return array('ok' => false, 'error' => 'The checker\'s answer could not be read.'); }
        $q['checked_at'] = gmdate('Y-m-d H:i:s');
        $q['seconds']    = round(microtime(true) - $t0, 1);
        $q['model']      = ClaudeService::model();
        $q['input_tokens']  = (int) ($r['input_tokens'] ?? 0);   // recorded so the cost of a check can be worked out from real use
        $q['output_tokens'] = (int) ($r['output_tokens'] ?? 0);
        (new InfluencerJobsModel())->merge_result((int) $job['id'], array('quality' => $q));
        return array('ok' => true, 'quality' => $q, 'error' => '');
    }

    /** The checker's JSON, cleaned to the fields the page shows. null when it is not usable. */
    public static function parse($text){
        $text = trim((string) $text);
        if (preg_match('/\{.*\}/s', $text, $m)) { $text = $m[0]; }
        $d = json_decode($text, true);
        if (!is_array($d) || !isset($d['anatomy']) || !isset($d['pose'])) { return null; }
        $conf = function ($v) { return max(0, min(1, round((float) $v, 2))); };
        $list = function ($v) { return array_values(array_filter(array_map(function ($s) { return mb_substr(trim((string) $s), 0, 200); }, (array) $v), 'strlen')); };
        $pair = function ($x) use ($conf) { $x = (array) $x; return array('asked' => mb_substr((string) ($x['asked'] ?? ''), 0, 30), 'seen' => mb_substr((string) ($x['seen'] ?? ''), 0, 30),
            'match' => array_key_exists('match', $x) && $x['match'] !== null ? (bool) $x['match'] : null, 'confidence' => $conf($x['confidence'] ?? 0)); };
        $flag = function ($x) use ($conf, $list) { $x = (array) $x; return array('ok' => !empty($x['ok']), 'issues' => $list($x['issues'] ?? array()), 'confidence' => $conf($x['confidence'] ?? 0)); };
        return array('anatomy' => $flag($d['anatomy']), 'pose' => $pair($d['pose']), 'gaze' => $pair($d['gaze'] ?? array()), 'composition' => $flag($d['composition'] ?? array('ok' => true)),
            'people' => max(0, (int) ($d['people'] ?? 1)), 'summary' => mb_substr(trim((string) ($d['summary'] ?? '')), 0, 300));
    }

    /** One word for the list: Passed, Failed, or '' when not checked. Failed = bad anatomy, or an asked angle or gaze not delivered. Outfit, crop and setting differences are notes only. */
    public static function verdict($quality){
        if (!is_array($quality) || empty($quality['anatomy'])) { return ''; }
        $bad = empty($quality['anatomy']['ok']) || ($quality['pose']['match'] ?? null) === false || ($quality['gaze']['match'] ?? null) === false;   // composition notes are shown, they do not decide the word
        return $bad ? 'Failed' : 'Passed';
    }
}
