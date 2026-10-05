<?php
/**
 * An influencer's voice, shared by the web API (ApiInfluencerVoiceController) and the Claude
 * connector (McpTools): Voice Design (ElevenLabs), saved voices with one active, text to speech
 * with audio tags, and talking video (a close-up lip-synced to speech). Same contract as
 * InfluencerActions: clean values in, array('ok' => bool, 'error' => string, ...payload) out.
 *
 * Speech and talking video are influencer_jobs rows, so charging, refunds and polling are the job
 * engine's. Voice Design is a direct call that returns candidates to listen to; it is charged
 * when it succeeds. Candidates and takes are previews in temporary storage: a voice is kept by
 * saving it, a take by saving it to the Library.
 */
class InfluencerVoiceActions {

    const KEYWORDS = array('influencer', 'supermodel', 'blogger', 'youtuber', 'instagrammer');

    /** Audio tags Eleven v3 understands, grouped for the picker. Inserted into the script as [tag]. */
    const TAGS = array(
        'Emotion'    => array('excited', 'happy', 'sad', 'angry', 'nervous', 'curious', 'sarcastic', 'mischievously', 'tired'),
        'Non-Verbal' => array('laughs', 'chuckles', 'giggles', 'sighs', 'gasps', 'exhales', 'clears throat', 'gulps'),
        'Volume'     => array('whispers', 'quietly', 'loudly', 'shouts'),
        'Pacing'     => array('pause', 'long pause', 'slowly', 'rushed', 'hesitates', 'drawn out'),
    );

    private static function fail($error, array $extra = array()){ return array_merge(array('ok' => false, 'error' => (string) $error), $extra); }
    private static function okr(array $payload = array()){ return array_merge(array('ok' => true, 'error' => ''), $payload); }
    private static function one_line($s, $max = 600){ return mb_substr(trim(preg_replace('/\s+/', ' ', (string) $s)), 0, $max); }
    private static function job_json($cid, $job_id){ return InfluencerJobService::job_json($cid, (new InfluencerJobsModel())->get_by_id($job_id)); }
    private static function tmp_key($cid, $what, $ext = 'mp3'){ return 'vault/' . (int) $cid . '/tmp/' . $what . '_' . bin2hex(random_bytes(8)) . '.' . $ext; }

    private static function write_tmp($bytes, $ext = 'mp3'){
        $base = tempnam(sys_get_temp_dir(), 'voice'); @unlink($base);
        $path = $base . '.' . $ext;
        return (file_put_contents($path, $bytes) !== false) ? $path : '';
    }

    /* =====================================================================
     * Voice Design and saved voices
     * =================================================================== */

    /** The description sent to Voice Design, from the builder fields: age_vibe, keyword, city, country, tone. */
    public static function design_prompt(array $infl, array $f){
        $noun    = InfluencerService::noun($infl);
        $age     = rtrim(self::one_line($f['age_vibe'] ?? '', 200), '.');
        $keyword = in_array($f['keyword'] ?? '', self::KEYWORDS, true) ? (string) $f['keyword'] : self::KEYWORDS[0];
        $city    = self::one_line($f['city'] ?? '', 80);
        $country = self::one_line($f['country'] ?? '', 80);
        $tone    = rtrim(self::one_line($f['tone'] ?? '', 200), '.');
        $p = ($age !== '' ? ucfirst($age) . '. ' : '') . 'A ' . $noun . ' ' . $keyword;
        if ($city !== '' || $country !== '') {
            $place = trim($city . ($city !== '' && $country !== '' ? ', ' : '') . $country);
            $p .= ' from ' . $place . ' with a natural ' . ($city !== '' ? $city : $country) . ' accent';
        }
        $p .= '.' . ($tone !== '' ? ' ' . ucfirst($tone) . '.' : '') . ' Conversational, close to the microphone, clear studio quality.';
        return $p;
    }

    public static function voice_json(array $v){
        return array('id' => (int) $v['id'], 'name' => (string) $v['name'], 'is_active' => (int) $v['is_active'], 'prompt' => (string) $v['prompt'],
            'settings' => InfluencerVoicesModel::settings($v), 'created_at' => (string) $v['created_at']);
    }

    /** Her saved voices (active first), what the next design and a speech run cost, and how many more voices the account may save. */
    public static function voices($cid, array $infl){
        $m = new InfluencerVoicesModel();
        $out = array();
        foreach ($m->list_for_influencer($cid, (int) $infl['id']) as $v) { $out[] = self::voice_json($v); }
        $cap = (int) InfluencerConfig::get('voices_per_creator', 10);
        return self::okr(array('voices' => $out, 'configured' => ElevenLabsService::configured(), 'cap' => $cap, 'saved' => $m->count_for_creator($cid),
            'takes' => (int) InfluencerConfig::get('speech_takes', 2)));
    }

    /** What a Voice Design run with this preview text costs. */
    public static function design_price($text){
        return Plan::ai_price('voice_design', array('params' => array('chars' => mb_strlen((string) $text), 'takes' => (int) InfluencerConfig::get('voice_design_previews', 3))));
    }

    /**
     * Design candidate voices. $f: age_vibe, keyword, city, country, tone, preview_text (100 to 1000 characters).
     * Charged when it succeeds. Returns ['token' (to save a candidate), 'candidates' => [['index', 'url', 'duration']], 'prompt'].
     */
    public static function design($cid, array $user, array $infl, array $f){
        if (!ElevenLabsService::configured()) { return self::fail('Voice is not set up yet.'); }
        if (self::one_line($f['age_vibe'] ?? '') === '') { return self::fail('Describe her age and vibe.', array('field' => 'age_vibe')); }
        $text = trim((string) ($f['preview_text'] ?? ''));
        $len  = mb_strlen($text);
        if ($len < ElevenLabsService::PREVIEW_MIN || $len > ElevenLabsService::PREVIEW_MAX) {
            return self::fail('The preview text needs ' . ElevenLabsService::PREVIEW_MIN . ' to ' . ElevenLabsService::PREVIEW_MAX . ' characters. It has ' . $len . '.', array('field' => 'preview_text'));
        }
        $prompt = self::design_prompt($infl, $f);
        $price  = self::design_price($text);
        $pay = Plan::charge_ai($user, 'voice_design', 'Voice design: ' . mb_substr((string) $infl['name'], 0, 40), array('params' => array('chars' => $len, 'takes' => (int) InfluencerConfig::get('voice_design_previews', 3))));
        if (empty($pay['ok'])) { return self::fail($pay['message'], array('need_credits' => true, 'price' => $pay['price'], 'balance' => $pay['balance'])); }
        $refund = function () use ($cid, $pay) { if ((int) $pay['price'] > 0) { (new AiCreditsModel())->apply_delta($cid, (int) $pay['price'], 'refund', 'Refund: voice design failed'); } };
        $r = ElevenLabsService::design($prompt, $text);
        if (empty($r['ok'])) { $refund(); return self::fail($r['error']); }
        $settings = array('age_vibe' => self::one_line($f['age_vibe'] ?? '', 200), 'keyword' => in_array($f['keyword'] ?? '', self::KEYWORDS, true) ? $f['keyword'] : self::KEYWORDS[0],
            'city' => self::one_line($f['city'] ?? '', 80), 'country' => self::one_line($f['country'] ?? '', 80), 'tone' => self::one_line($f['tone'] ?? '', 200), 'preview_text' => $text);
        $cands = array(); $manifest = array('influencer_id' => (int) $infl['id'], 'prompt' => $prompt, 'settings' => $settings, 'candidates' => array());
        foreach ($r['previews'] as $i => $p) {
            $key = self::tmp_key($cid, 'voicecand');
            if (!S3Service::put_private_bytes($key, $p['bytes'], 'audio/mpeg')) { continue; }
            $manifest['candidates'][] = array('generated_voice_id' => $p['generated_voice_id'], 'key' => $key);
            $cands[] = array('index' => count($manifest['candidates']) - 1, 'url' => S3Service::presigned_get_url($key, 3600), 'duration' => round((float) $p['duration'], 1));
        }
        if (empty($cands)) { $refund(); return self::fail('The candidates could not be stored. Try again.'); }
        $token = bin2hex(random_bytes(12));
        S3Service::put_private_bytes('vault/' . (int) $cid . '/tmp/voicedesign_' . $token . '.json', json_encode($manifest), 'application/json');
        return self::okr(array('token' => $token, 'candidates' => $cands, 'prompt' => $prompt, 'price' => (int) $pay['price']));
    }

    /** The manifest of a design run (which candidates it produced), or null. */
    private static function design_manifest($cid, $token){
        if (!preg_match('/^[0-9a-f]{24}$/', (string) $token)) { return null; }
        $tmp = tempnam(sys_get_temp_dir(), 'vdm');
        if ($tmp === false || !S3Service::get_private_to_file('vault/' . (int) $cid . '/tmp/voicedesign_' . $token . '.json', $tmp)) { if ($tmp) { @unlink($tmp); } return null; }
        $d = json_decode((string) @file_get_contents($tmp), true);
        @unlink($tmp);
        return is_array($d) ? $d : null;
    }

    /** Keep one candidate of a design run as a voice of hers. The first voice saved becomes the active one. */
    public static function save_voice($cid, array $infl, $token, $index, $name){
        $d = self::design_manifest($cid, $token);
        if (!$d || (int) ($d['influencer_id'] ?? 0) !== (int) $infl['id']) { return self::fail('Those candidates have expired. Design the voice again.'); }
        $c = $d['candidates'][(int) $index] ?? null;
        if (!$c) { return self::fail('Pick one of the candidates.'); }
        $m = new InfluencerVoicesModel();
        $cap = (int) InfluencerConfig::get('voices_per_creator', 10);
        if ($m->count_for_creator($cid) >= $cap) { return self::fail('You can keep up to ' . $cap . ' voices. Remove one to save another.', array('at_cap' => true)); }
        $name = self::one_line($name, 120);
        if ($name === '') { $name = $infl['name'] . ' Voice ' . ($m->count_for_influencer($cid, (int) $infl['id']) + 1); }
        $r = ElevenLabsService::save_voice((string) $c['generated_voice_id'], 'CLS ' . (int) $cid . ' ' . $name, (string) $d['prompt']);
        if (empty($r['ok'])) { return self::fail($r['error']); }
        $id = $m->add($cid, (int) $infl['id'], array('name' => $name, 'voice_id' => (string) $r['voice_id'], 'prompt' => (string) $d['prompt'], 'settings' => (array) $d['settings']));
        if ($id <= 0) { ElevenLabsService::delete_voice((string) $r['voice_id']); return self::fail('That voice could not be saved.'); }
        return self::okr(array('voice' => self::voice_json($m->get_one($cid, $id))));
    }

    public static function set_active($cid, array $infl, $voice_id){
        $m = new InfluencerVoicesModel();
        $v = $m->get_one($cid, (int) $voice_id);
        if (!$v || (int) $v['influencer_id'] !== (int) $infl['id']) { return self::fail('Voice not found.'); }
        $m->set_active($cid, (int) $infl['id'], (int) $v['id']);
        return self::okr(array('voice' => self::voice_json($m->get_one($cid, (int) $v['id']))));
    }

    /** Remove a saved voice here and at the voice service (which frees its slot there). */
    public static function delete_voice($cid, array $infl, $voice_id){
        $m = new InfluencerVoicesModel();
        $v = $m->get_one($cid, (int) $voice_id);
        if (!$v || (int) $v['influencer_id'] !== (int) $infl['id']) { return self::fail('Voice not found.'); }
        $r = ElevenLabsService::delete_voice((string) $v['voice_id']);
        if (empty($r['ok'])) { error_log('[voice] voice ' . (int) $v['id'] . ' (' . $v['voice_id'] . ') removed here but not at the voice service: ' . $r['error']); }
        $m->soft_delete($cid, (int) $v['id']);
        return self::okr(array('id' => (int) $v['id'], 'freed' => !empty($r['ok'])));
    }

    /* =====================================================================
     * Text to speech
     * =================================================================== */

    /** Claude adds audio tags to a script where they help, changing no words. */
    public static function enhance($infl, $text){
        $text = trim((string) $text);
        if ($text === '') { return self::fail('Write the script first.'); }
        if (!ClaudeService::configured()) { return self::fail('Enhance is not available right now.'); }
        $tags = array();
        foreach (self::TAGS as $group) { foreach ($group as $t) { $tags[] = '[' . $t . ']'; } }
        $system = 'You add audio tags to a script for an expressive text to speech model. Output ONLY the script with tags inserted, nothing else. '
            . 'Rules: do not add, remove or change any word or punctuation of the script; only insert tags in square brackets immediately before the words they affect; '
            . 'use tags sparingly, at most one for every sentence or two, and only where a real person would naturally do it; '
            . 'use only these tags: ' . implode(' ', $tags) . '. Keep any tags that are already there.'
            . (is_array($infl) && InfluencerService::persona_block($infl) !== '' ? ' The speaker: ' . self::one_line(InfluencerService::persona_block($infl), 800) : '');
        $r = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $text)), max(300, (int) (mb_strlen($text) / 2) + 200), 45, 'low');
        if (empty($r['ok']) || trim((string) $r['text']) === '') { return self::fail('Could not enhance the script right now.'); }
        $out = trim((string) $r['text']);
        // The words must be untouched: strip the tags and compare.
        $bare = function ($s) { return preg_replace('/\s+/', ' ', trim(preg_replace('/\[[^\]\n]{1,40}\]/', '', (string) $s))); };
        if ($bare($out) !== $bare($text)) { return self::fail('Enhance changed the wording, so it was not applied. Try again.'); }
        return self::okr(array('text' => $out));
    }

    /** What a speech run of this script costs (all takes). */
    public static function speech_price($text){
        return Plan::ai_price('speech', array('params' => array('chars' => mb_strlen(trim((string) $text)), 'takes' => (int) InfluencerConfig::get('speech_takes', 2))));
    }

    /** Start a speech run with her active voice (or $voice_id): the takes come back as previews on the job. */
    public static function speech_start($cid, array $infl, $text, $voice_id = 0, $origin = 'studio'){
        if (!ElevenLabsService::configured()) { return self::fail('Voice is not set up yet.'); }
        $text = trim((string) $text);
        if ($text === '') { return self::fail('Write the script first.'); }
        if (mb_strlen($text) > ElevenLabsService::SPEECH_MAX) { return self::fail('A speech run takes up to ' . number_format(ElevenLabsService::SPEECH_MAX) . ' characters. This script has ' . number_format(mb_strlen($text)) . '.'); }
        $m = new InfluencerVoicesModel();
        $v = ((int) $voice_id > 0) ? $m->get_one($cid, (int) $voice_id) : $m->active($cid, (int) $infl['id']);
        if (!$v || (int) $v['influencer_id'] !== (int) $infl['id']) { return self::fail($infl['name'] . ' has no voice yet. Design one first.', array('need_voice' => true)); }
        $takes = (int) InfluencerConfig::get('speech_takes', 2);
        try {
            $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'speech', array(
                'origin' => $origin, 'model_key' => 'eleven_v3', 'prompt' => $text,
                'params' => array('chars' => mb_strlen($text), 'takes' => $takes, 'voice_row' => (int) $v['id'], 'voice_name' => (string) $v['name']),
            ));
        } catch (PlanLimitException $e) {
            return self::fail($e->getMessage(), $e->limit);
        }
        if ($job_id <= 0) { return self::fail('Could not start the speech.'); }
        return self::okr(array('job_id' => $job_id, 'job' => self::job_json($cid, $job_id)));
    }

    /** Called by the job engine: render the takes of a speech job into temporary storage. Returns ['ok', 'takes' => [['key', 'duration']]]. */
    public static function render_takes(array $job){
        $cid = (int) $job['creator_id'];
        $p   = InfluencerJobsModel::params($job);
        $v   = (new InfluencerVoicesModel())->get_one($cid, (int) ($p['voice_row'] ?? 0));
        if (!$v) { return array('ok' => false, 'error' => 'That voice was removed.'); }
        $takes = array();
        for ($i = 0; $i < max(1, (int) ($p['takes'] ?? 2)); $i++) {
            $r = ElevenLabsService::speak((string) $v['voice_id'], (string) $job['prompt'], random_int(1, 2147483647));   // a seed per take: each one is a different read
            if (empty($r['ok'])) { foreach ($takes as $t) { S3Service::delete_key($t['key']); } return array('ok' => false, 'error' => (string) $r['error']); }
            $path = self::write_tmp($r['bytes']);
            $dur  = ($path !== '') ? (float) VideoTools::probe($path)['duration'] : 0.0;
            if ($path !== '') { @unlink($path); }
            $key = self::tmp_key($cid, 'take');
            if (!S3Service::put_private_bytes($key, $r['bytes'], 'audio/mpeg')) { return array('ok' => false, 'error' => 'The speech could not be stored.'); }
            $takes[] = array('key' => $key, 'duration' => round($dur, 2));
        }
        return array('ok' => true, 'takes' => $takes);
    }

    /** Save one take of a speech job to the Library as an audio file (with lineage). Saving twice returns the same asset. */
    public static function speech_save($cid, array $user, $job_id, $take){
        $jobs = new InfluencerJobsModel();
        $job  = $jobs->get_one($cid, (int) $job_id);
        if (!$job || (string) $job['type'] !== 'speech' || (string) $job['status'] !== 'done') { return self::fail('That speech run is not finished.'); }
        $res = InfluencerJobsModel::result($job);
        $t   = $res['takes'][(int) $take] ?? null;
        if (!$t || !FaceMask::owns_key($cid, (string) ($t['key'] ?? ''))) { return self::fail('Pick one of the takes.'); }
        if (!empty($t['asset_id']) && (new MediaAssetsModel())->get_one($cid, (int) $t['asset_id'])) { return self::okr(array('asset_id' => (int) $t['asset_id'], 'already' => true)); }
        $base = tempnam(sys_get_temp_dir(), 'take'); @unlink($base); $path = $base . '.mp3';
        if (!S3Service::get_private_to_file((string) $t['key'], $path)) { return self::fail('That take has expired. Generate it again.'); }
        $infl = (new InfluencersModel())->get_one($cid, (int) $job['influencer_id']);
        try {
            $r = MediaIngestService::ingest_audio_file($cid, $user, $path, ($infl ? $infl['name'] . ' · ' : '') . 'speech · ' . rtrim(mb_substr(preg_replace('/\[[^\]]*\]\s*/', '', (string) $job['prompt']), 0, 30), " .,!?"), 0, 'generated');
        } catch (\Throwable $e) {
            return self::fail($e->getMessage());
        }
        $aid = (int) $r['asset_id'];
        (new MediaAssetsModel())->set_lineage($cid, $aid, array('provenance' => 'generated', 'model_key' => (string) $job['model_key'], 'prompt' => (string) $job['prompt'],
            'influencer_id' => (int) $job['influencer_id'], 'job_id' => (int) $job['id']));
        if ((int) $job['influencer_id'] > 0) {
            (new MediaAssetsModel())->set_tags($cid, $aid, 'influencer:' . (int) $job['influencer_id'] . ',audio');
            (new InfluencerImagesModel())->attach((int) $job['influencer_id'], $cid, $aid, 'audio', null, 0, 0);
        }
        $res['takes'][(int) $take]['asset_id'] = $aid;
        $res['asset_ids'] = array_values(array_unique(array_merge((array) ($res['asset_ids'] ?? array()), array($aid))));
        $jobs->merge_result((int) $job['id'], array('takes' => $res['takes'], 'asset_ids' => $res['asset_ids']));
        return self::okr(array('asset_id' => $aid, 'duration' => (int) $r['duration_sec']));
    }

    /* =====================================================================
     * Talking video
     * =================================================================== */

    /** Split a script into paragraphs that each fit one speech request. */
    public static function paragraphs($script){
        $out = array();
        foreach (preg_split('/\R\s*\R/u', trim((string) $script)) as $para) {
            $para = trim(preg_replace('/[ \t]*\R[ \t]*/u', ' ', $para));
            if ($para === '') { continue; }
            while (mb_strlen($para) > ElevenLabsService::SPEECH_MAX) {   // a very long paragraph breaks at a sentence end
                $cut = mb_substr($para, 0, ElevenLabsService::SPEECH_MAX);
                $at  = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '? '), (int) mb_strrpos($cut, '! '));
                if ($at < 200) { $at = ElevenLabsService::SPEECH_MAX - 1; }
                $out[] = trim(mb_substr($para, 0, $at + 1));
                $para  = trim(mb_substr($para, $at + 1));
            }
            if ($para !== '') { $out[] = $para; }
        }
        return $out;
    }

    /** Pack clips (each ['path', 'duration']) into parts no longer than $max seconds, keeping their order. A clip longer than $max is a part of its own. */
    public static function pack_parts(array $clips, $max){
        $parts = array(); $cur = array(); $len = 0.0;
        foreach ($clips as $c) {
            if (!empty($cur) && $len + (float) $c['duration'] > (float) $max) { $parts[] = $cur; $cur = array(); $len = 0.0; }
            $cur[] = $c; $len += (float) $c['duration'];
        }
        if (!empty($cur)) { $parts[] = $cur; }
        return $parts;
    }

    /** What a talking video costs: the lip sync per second, plus the speech when it is made from a script. */
    public static function talking_price($seconds, $script_chars = 0){
        $video = Plan::ai_price('talking', array('model_key' => 'heygen_avatar4', 'params' => array('duration' => max(1, (int) ceil($seconds)))));
        $voice = ($script_chars > 0) ? Plan::ai_price('speech', array('params' => array('chars' => (int) $script_chars, 'takes' => 1))) : 0;
        return array('video' => $video, 'speech' => $voice, 'total' => $video + $voice);
    }

    /** Estimate before starting: script -> about 15 characters a second; audio -> its real length. */
    public static function talking_estimate($cid, array $infl, array $in){
        $max = (int) InfluencerConfig::get('talking_max_seconds', 300);
        if (!empty($in['audio_asset_id'])) {
            $a = (new MediaAssetsModel())->get_one($cid, (int) $in['audio_asset_id']);
            if (!$a || (string) $a['type'] !== 'audio' || (string) $a['status'] !== 'ready') { return self::fail('Pick a ready audio file.'); }
            $secs = max(1, (int) $a['duration_sec']);
            return self::okr(array('seconds' => $secs, 'exact' => true, 'too_long' => $secs > $max, 'max_seconds' => $max) + self::talking_price($secs, 0));
        }
        $text  = trim((string) ($in['script'] ?? ''));
        $bare  = trim(preg_replace('/\[[^\]\n]{1,40}\]/', '', $text));
        $secs  = ($bare === '') ? 0 : max(1, (int) ceil(mb_strlen($bare) / 15));
        $voice = (new InfluencerVoicesModel())->active($cid, (int) $infl['id']);
        return self::okr(array('seconds' => $secs, 'exact' => false, 'too_long' => $secs > $max, 'max_seconds' => $max, 'has_voice' => (bool) $voice, 'voice_name' => $voice ? (string) $voice['name'] : '')
            + self::talking_price($secs, mb_strlen($text)));
    }

    /**
     * Start a talking video. $in: image_asset_id (a close-up), and script (spoken with her active voice first) or
     * audio_asset_id (a Library audio file). Long audio is rendered in parts and joined. Returns ['group_key', 'job_ids', 'seconds', 'parts'].
     */
    public static function talking_start($cid, array $user, array $infl, array $in, $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        if (!VideoTools::available()) { return self::fail('Talking video is not available right now.'); }
        $img = (new MediaAssetsModel())->get_one($cid, (int) ($in['image_asset_id'] ?? 0));
        if (!$img || (string) $img['type'] !== 'image' || (string) $img['status'] !== 'ready' || (string) $img['moderation_status'] === 'blocked') { return self::fail('Pick a ready close-up image.'); }
        $model = InfluencerConfig::resolve_model('talking', '');
        if (!$model) { return self::fail('No lip sync model is configured.'); }
        $max_total = (int) InfluencerConfig::get('talking_max_seconds', 300);
        $part_max  = max(5, (int) InfluencerConfig::get('talking_part_seconds', 60));
        $script    = trim((string) ($in['script'] ?? ''));
        $clips = array(); $tmp = array(); $speech_paid = 0;
        $cleanup = function () use (&$tmp) { foreach ($tmp as $t) { if (is_file($t)) { @unlink($t); } } };
        $refund_speech = function () use ($cid, &$speech_paid) { if ($speech_paid > 0) { (new AiCreditsModel())->apply_delta($cid, $speech_paid, 'refund', 'Refund: talking video speech'); $speech_paid = 0; } };

        if (!empty($in['audio_asset_id'])) {
            $a = (new MediaAssetsModel())->get_one($cid, (int) $in['audio_asset_id']);
            if (!$a || (string) $a['type'] !== 'audio' || (string) $a['status'] !== 'ready') { return self::fail('Pick a ready audio file.'); }
            $base = tempnam(sys_get_temp_dir(), 'talk'); @unlink($base); $path = $base . '.' . (pathinfo((string) $a['original_key'], PATHINFO_EXTENSION) ?: 'mp3');
            if (!S3Service::get_private_to_file((string) $a['original_key'], $path)) { return self::fail('Could not read that audio file. Try again.'); }
            $tmp[] = $path;
            $dur = (float) VideoTools::probe($path)['duration'];
            if ($dur <= 0) { $cleanup(); return self::fail('That audio file could not be read.'); }
            if ($dur > $max_total) { $cleanup(); return self::fail('That audio is ' . round($dur) . ' seconds. A talking video can be up to ' . $max_total . ' seconds.'); }
            if ($dur > $part_max) {
                foreach (VideoTools::split_audio($path, $part_max) as $seg) { $tmp[] = $seg; $clips[] = array('path' => $seg, 'duration' => (float) VideoTools::probe($seg)['duration']); }
                if (empty($clips)) { $cleanup(); return self::fail('That audio file could not be split.'); }
            } else { $clips[] = array('path' => $path, 'duration' => $dur); }
        } else {
            if ($script === '') { return self::fail('Write the script, or pick an audio file.'); }
            $voice = (new InfluencerVoicesModel())->active($cid, (int) $infl['id']);
            if (!$voice) { return self::fail($infl['name'] . ' has no voice yet. Design one first.', array('need_voice' => true)); }
            $paras = self::paragraphs($script);
            // The speech is charged first (one take), and given back if anything after it fails before a video is started.
            $pay = Plan::charge_ai($user, 'speech', 'Speech for a talking video', array('params' => array('chars' => mb_strlen($script), 'takes' => 1)));
            if (empty($pay['ok'])) { return self::fail($pay['message'], array('need_credits' => true, 'price' => $pay['price'], 'balance' => $pay['balance'])); }
            $speech_paid = (int) $pay['price'];
            foreach ($paras as $para) {
                $r = ElevenLabsService::speak((string) $voice['voice_id'], $para);
                $path = !empty($r['ok']) ? self::write_tmp($r['bytes']) : '';
                if ($path === '') { $cleanup(); $refund_speech(); return self::fail(!empty($r['ok']) ? 'The speech could not be stored.' : (string) $r['error']); }
                $tmp[] = $path;
                $clips[] = array('path' => $path, 'duration' => (float) VideoTools::probe($path)['duration']);
            }
            $total = array_sum(array_column($clips, 'duration'));
            if ($total > $max_total) { $cleanup(); $refund_speech(); return self::fail('That script runs about ' . round($total) . ' seconds. A talking video can be up to ' . $max_total . ' seconds.'); }
        }

        // Paragraphs are packed into parts; each part is one lip sync render.
        $parts = array();
        foreach (self::pack_parts($clips, $part_max) as $group) {
            $paths = array_column($group, 'path');
            $one = (count($paths) === 1) ? $paths[0] : VideoTools::concat_audio($paths);
            if ($one === '') { $cleanup(); $refund_speech(); return self::fail('The speech could not be put together.'); }
            if (count($paths) > 1) { $tmp[] = $one; }
            $key = self::tmp_key($cid, 'talk', pathinfo($one, PATHINFO_EXTENSION) ?: 'mp3');
            if (!S3Service::put_private($key, $one, (pathinfo($one, PATHINFO_EXTENSION) === 'wav') ? 'audio/wav' : 'audio/mpeg')) { $cleanup(); $refund_speech(); return self::fail('The speech could not be stored.'); }
            $parts[] = array('key' => $key, 'duration' => (float) VideoTools::probe($one)['duration']);
        }
        $cleanup();

        // Nothing is started unless the account can pay for every part.
        $need = 0;
        foreach ($parts as $p) { $need += Plan::ai_price('talking', array('model_key' => (string) $model['key'], 'params' => array('duration' => max(1, (int) ceil($p['duration']))))); }
        $bal = (int) (new AiCreditsModel())->get_balance($cid);
        if ($bal < $need) { $refund_speech(); foreach ($parts as $p) { S3Service::delete_key($p['key']); } return self::fail(Plan::credits_message('talking', $need, $bal), array('need_credits' => true, 'price' => $need, 'balance' => $bal)); }

        $aspect = Aspect::for_model($model, Aspect::nearest((int) $img['width'], (int) $img['height']), Aspect::DEFAULT_VIDEO);
        $group  = 'talk_' . (int) $infl['id'] . '_' . bin2hex(random_bytes(5));
        $ids = array();
        foreach ($parts as $i => $p) {
            try {
                $ids[] = InfluencerJobService::create_job($cid, (int) $infl['id'], 'talking', array(
                    'origin' => $origin, 'model_key' => (string) $model['key'], 'prompt' => ($script !== '') ? $script : 'Lip sync to an audio file', 'input_asset_id' => (int) $img['id'],
                    'group_key' => $group, 'group_index' => $i + 1,
                    'params' => array('duration' => max(1, (int) ceil($p['duration'])), 'audio_key' => $p['key'], 'aspect' => $aspect, 'parts' => count($parts),
                        'audio_asset_id' => (int) ($in['audio_asset_id'] ?? 0), 'speech_credits' => ($i === 0) ? $speech_paid : 0),
                ));
            } catch (PlanLimitException $e) {
                if (empty($ids)) { $refund_speech(); }
                return self::fail($e->getMessage(), $e->limit);
            }
        }
        return self::okr(array('group_key' => $group, 'job_ids' => $ids, 'parts' => count($parts), 'seconds' => (int) ceil(array_sum(array_column($parts, 'duration'))),
            'status' => self::talking_status($cid, $group)));
    }

    /** A talking video's state for the page: working | done | failed, parts finished, and the final video once it exists. */
    public static function talking_status($cid, $group_key){
        $jobs = array();
        foreach ((new InfluencerJobsModel())->list_group((string) $group_key) as $j) { if ((int) $j['creator_id'] === (int) $cid && (string) $j['type'] === 'talking') { $jobs[] = $j; } }
        if (empty($jobs)) { return array('state' => 'missing', 'parts' => 0, 'done' => 0, 'asset' => null, 'error' => 'Not found.', 'group_key' => (string) $group_key); }
        $done = 0; $failed = ''; $final = 0;
        foreach ($jobs as $j) {
            if ((string) $j['status'] === 'done') { $done++; }
            if (in_array((string) $j['status'], array('failed', 'cancelled'), true) && $failed === '') { $failed = (string) $j['error']; }
            $r = InfluencerJobsModel::result($j);
            if (!empty($r['final_asset_id'])) { $final = (int) $r['final_asset_id']; }
            if (!empty($r['join_error']) && $failed === '') { $failed = (string) $r['join_error']; }
        }
        $state = ($failed !== '') ? 'failed' : 'working';
        if ($failed === '' && $done === count($jobs)) {
            if (count($jobs) === 1) { $final = (int) $jobs[0]['result_asset_id']; }
            if ($final > 0) { $state = 'done'; }
        }
        $asset = null;
        if ($state === 'done') {
            $a = (new MediaAssetsModel())->get_one($cid, $final);
            if ($a && (string) $a['status'] === 'ready') {
                $asset = array('id' => (int) $a['id'], 'type' => 'video', 'width' => (int) $a['width'], 'height' => (int) $a['height'], 'duration' => (int) $a['duration_sec'],
                    'thumb_url' => MediaService::signed_url($a, 'thumb', $cid), 'display_url' => MediaService::signed_url($a, 'poster', $cid), 'video_url' => MediaService::signed_variant($a, 'original', 900));
            } else { $state = 'working'; }
        }
        return array('state' => $state, 'parts' => count($jobs), 'done' => $done, 'asset' => $asset, 'error' => $failed, 'group_key' => (string) $group_key,
            'job_ids' => array_map(function ($j) { return (int) $j['id']; }, $jobs));
    }

    /**
     * Called by the job engine when one part of a talking video finishes. Once every part is done the clips are
     * joined into the final video and the parts are removed from the Library. A failed part gives the speech back.
     */
    public static function talking_part_finished(array $job){
        $cid  = (int) $job['creator_id'];
        $jobs = new InfluencerJobsModel();
        $all  = array();
        foreach ($jobs->list_group((string) $job['group_key']) as $j) { if ((string) $j['type'] === 'talking' && (int) $j['creator_id'] === $cid) { $all[] = $j; } }
        if (empty($all)) { return; }
        // Speech made for a video that never rendered is refunded (once, with the part that carried it).
        if (in_array((string) $job['status'], array('failed', 'cancelled'), true)) {
            foreach ($all as $j) {
                $sp = (int) (InfluencerJobsModel::params($j)['speech_credits'] ?? 0);
                if ($sp > 0) { (new AiCreditsModel())->refund_once($cid, $sp, 'talking speech ' . (string) $job['group_key']); }
            }
            return;
        }
        foreach ($all as $j) { if ((string) $j['status'] !== 'done') { return; } }
        // The temporary speech audio is no longer needed.
        foreach ($all as $j) { $k = (string) (InfluencerJobsModel::params($j)['audio_key'] ?? ''); if ($k !== '' && FaceMask::owns_key($cid, $k) && strpos($k, '/tmp/') !== false) { S3Service::delete_key($k); } }
        if (count($all) < 2) { return; }
        usort($all, function ($a, $b) { return (int) $a['group_index'] <=> (int) $b['group_index']; });
        if ($jobs->claim_once((int) $all[0]['id'], 'joined') !== 1) { return; }   // another worker is joining
        $mm = new MediaAssetsModel(); $paths = array();
        try {
            foreach ($all as $j) {
                $a = $mm->get_one($cid, (int) $j['result_asset_id']);
                $base = tempnam(sys_get_temp_dir(), 'tpart'); @unlink($base); $path = $base . '.mp4';
                if (!$a || !S3Service::get_private_to_file((string) $a['original_key'], $path)) { throw new RuntimeException('A part of the video could not be read.'); }
                $paths[] = $path;
            }
            $joined = VideoTools::concat($paths, 900);
            if ($joined === '') { throw new RuntimeException('The parts could not be joined.'); }
            $infl = (new InfluencersModel())->get_one($cid, (int) $job['influencer_id']);
            $r = MediaIngestService::ingest_video_file($cid, InfluencerJobService::user($cid), $joined, 'mp4', 'video/mp4', (int) filesize($joined), ($infl ? $infl['name'] : 'Influencer') . ' · talking', '', 0, false);
            $final = (int) $r['asset_id'];
            $mm->set_lineage($cid, $final, array('provenance' => 'generated', 'source_asset_id' => (int) $all[0]['input_asset_id'], 'model_key' => (string) $all[0]['model_key'],
                'prompt' => (string) $all[0]['prompt'], 'influencer_id' => (int) $job['influencer_id'], 'job_id' => (int) $all[0]['id']));
            $im = new InfluencerImagesModel();
            foreach ($all as $j) {   // the parts leave the Library; the joined video takes their place in her gallery
                $im->detach($cid, (int) $job['influencer_id'], (int) $j['result_asset_id']);
                $mm->soft_delete($cid, (int) $j['result_asset_id']);
            }
            $mm->set_tags($cid, $final, 'influencer:' . (int) $job['influencer_id'] . ',video');
            $im->attach((int) $job['influencer_id'], $cid, $final, 'video', null, 0, 0);
            foreach ($all as $k => $j) { $jobs->merge_result((int) $j['id'], array('final_asset_id' => $final, 'asset_ids' => ($k === 0) ? array($final) : array())); }
        } catch (\Throwable $e) {
            error_log('[talking] join of ' . $job['group_key'] . ' failed: ' . $e->getMessage());
            foreach ($all as $j) { $jobs->merge_result((int) $j['id'], array('join_error' => 'The parts rendered but could not be joined. They are in your Library as separate clips.')); }
        }
        foreach ($paths as $p) { if (is_file($p)) { @unlink($p); } }
    }
}
