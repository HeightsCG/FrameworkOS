<?php
/**
 * The scene template library (Influencers, Generate Images, Scenes): base prompts a creator runs with one of their
 * influencers for four variants, then rates. Platform scenes are admin-managed and read-only for
 * creators; a creator also keeps scenes of their own (add, edit, turn off, delete). Adult templates
 * are listed only for accounts that opted in to adult content (user_accounts.adult_content_enabled),
 * the same switch that gates adult posts. Shared by the web API, /admin and the Claude connector.
 */
class SceneTemplates {

    const VARIANTS = 4;
    const THUMB_PX = 600;

    private static function fail($error, array $extra = array()){ return array_merge(array('ok' => false, 'error' => (string) $error), $extra); }
    private static function okr(array $payload = array()){ return array_merge(array('ok' => true, 'error' => ''), $payload); }

    public static function shows_adult($user){ return is_array($user) && !empty($user['adult_content_enabled']); }

    public static function thumb_url($row){
        $key = (string) ($row['thumb_key'] ?? '');
        return ($key !== '') ? S3Service::presigned_get_url($key, 3600) : '';
    }

    public static function json(array $row, $admin = false){
        $out = array('id' => (int) $row['id'], 'title' => (string) $row['title'], 'category' => (string) $row['category'], 'is_adult' => (int) $row['is_adult'],
            'default_aspect' => Aspect::normalize($row['default_aspect']), 'thumb_url' => self::thumb_url($row),
            'is_active' => (int) $row['is_active'], 'mine' => !empty($row['mine']));
        if ($admin || !empty($row['mine'])) {
            $out += array('base_prompt' => (string) $row['base_prompt'], 'sort_order' => (int) $row['sort_order']);
        }
        if ($admin) {
            $out += array('ups' => (int) ($row['ups'] ?? 0), 'downs' => (int) ($row['downs'] ?? 0));
        }
        return $out;
    }

    /** Templates this account may pick, own first: ['templates' => [...], 'categories' => [...]]. */
    public static function for_user($user){
        $out = array(); $cats = array();
        foreach ((new SceneTemplatesModel())->list_for_creator((int) ($user['user_id'] ?? 0), self::shows_adult($user)) as $r) {
            $out[] = self::json($r);
            if ((string) $r['category'] !== '' && !in_array((string) $r['category'], $cats, true)) { $cats[] = (string) $r['category']; }
        }
        return array('templates' => $out, 'categories' => $cats);
    }

    /** A creator's own row as the page shows it. */
    private static function own_json($cid, $id){
        $r = (new SceneTemplatesModel())->get_own($cid, $id);
        if (!$r) { return null; }
        $r['mine'] = true;
        return self::json($r);
    }

    /* ---- a creator's own scenes ---- */

    /** Field checks shared by the web API and the connector: [['input' => .., 'msg' => ..], ...]. */
    public static function errors(array $f){
        $errors = array();
        if (trim((string) ($f['title'] ?? '')) === '') { $errors[] = array('input' => 'title', 'msg' => 'Give the scene a title.'); }
        $prompt = trim((string) ($f['base_prompt'] ?? ''));
        if ($prompt === '') { $errors[] = array('input' => 'base_prompt', 'msg' => 'Write the base prompt.'); }
        elseif (stripos($prompt, '{subject}') === false) { $errors[] = array('input' => 'base_prompt', 'msg' => 'The prompt needs {subject} where the influencer goes.'); }
        if (!Aspect::valid((string) ($f['default_aspect'] ?? ''))) { $errors[] = array('input' => 'default_aspect', 'msg' => 'Pick a default shape.'); }
        return $errors;
    }

    /**
     * Create ($id 0) or update one of the creator's own scenes. $f: title, category, base_prompt,
     * is_adult, default_aspect, sort_order (is_active is kept on update, on for a new scene).
     */
    public static function save($cid, $id, array $f){
        $errors = self::errors($f);
        if (!empty($errors)) { return self::fail($errors[0]['msg'], array('errors' => $errors)); }
        $m = new SceneTemplatesModel(); $id = (int) $id;
        if ($id > 0) {
            $cur = $m->get_own($cid, $id);
            if (!$cur) { return self::fail('Scene not found.'); }
            $f['is_active'] = array_key_exists('is_active', $f) ? !empty($f['is_active']) : !empty($cur['is_active']);
            $m->update_own($cid, $id, $f);
        } else {
            $f['is_active'] = array_key_exists('is_active', $f) ? !empty($f['is_active']) : true;
            $id = (int) $m->create_own($cid, $f);
            if ($id <= 0) { return self::fail('Could not save the scene.'); }
        }
        return self::okr(array('id' => $id, 'scene' => self::own_json($cid, $id)));
    }

    public static function set_active($cid, $id, $active){
        $m = new SceneTemplatesModel();
        if (!$m->get_own($cid, (int) $id)) { return self::fail('Scene not found.'); }
        $m->set_active_own($cid, (int) $id, (bool) $active);
        return self::okr(array('id' => (int) $id, 'scene' => self::own_json($cid, (int) $id)));
    }

    public static function delete($cid, $id){
        $m = new SceneTemplatesModel();
        $t = $m->get_own($cid, (int) $id);
        if (!$t) { return self::fail('Scene not found.'); }
        $m->delete_own($cid, (int) $id);
        return self::okr(array('id' => (int) $id, 'title' => (string) $t['title']));
    }

    /**
     * Store an uploaded thumbnail for a template row (JPG/PNG/WebP, sniffed, re-encoded through GD
     * as a 600px JPEG under scenes/<id>/). $cid NULL = a platform row (admin), else the creator's own.
     * $file is the $_FILES entry. Returns ok/error plus the new key.
     */
    public static function store_thumb(array $t, $file, $cid = null){
        if (!is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) { return self::fail('Choose an image to upload.'); }
        if ((int) $file['size'] > MediaLimits::MAX_IMAGE_BYTES) { return self::fail('That image is too large. Images can be up to 15 MB.'); }
        try { $img = MediaIngestService::verify_image_bytes((string) file_get_contents($file['tmp_name'])); }
        catch (\Throwable $e) { return self::fail('Use a JPG, PNG or WebP image.'); }
        $src = @imagecreatefromstring($img['bytes']);
        if (!$src) { return self::fail('Could not read that image.'); }
        $w = imagesx($src); $h = imagesy($src); $scale = min(1, self::THUMB_PX / max($w, $h));
        $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagejpeg($dst, null, 86); $jpeg = (string) ob_get_clean();
        imagedestroy($src); imagedestroy($dst);
        $id  = (int) $t['id'];
        $key = 'scenes/' . $id . '/thumb_' . bin2hex(random_bytes(4)) . '.jpg';
        if (!S3Service::put_private_bytes($key, $jpeg, 'image/jpeg')) { return self::fail('Could not store the thumbnail.'); }
        if (!empty($t['thumb_key'])) { S3Service::delete_key((string) $t['thumb_key']); }
        (new SceneTemplatesModel())->set_thumb($id, $key, $cid);
        return self::okr(array('id' => $id, 'key' => $key));
    }

    /** The creator-side thumbnail upload: ownership, then the shared store. */
    public static function thumb($cid, $id, $file){
        $t = (new SceneTemplatesModel())->get_own($cid, (int) $id);
        if (!$t) { return self::fail('Scene not found.'); }
        $r = self::store_thumb($t, $file, $cid);
        if (empty($r['ok'])) { return $r; }
        return self::okr(array('id' => (int) $id, 'scene' => self::own_json($cid, (int) $id)));
    }

    /* ---- running ---- */

    /** The prompt a template renders with for this influencer. */
    public static function prompt_for(array $tpl, array $infl){
        $noun = InfluencerService::noun($infl);
        return trim(str_replace(array('{subject}', '{Subject}'), array($noun, ucfirst($noun)), (string) $tpl['base_prompt']));
    }

    /** Run a template with an influencer: one job, four variants. $aspect '' = the template's default. A platform scene or the creator's own; inactive ones are refused. */
    public static function run($cid, array $user, array $infl, $template_id, $aspect = '', $model_key = '', $origin = 'studio'){
        $tpl = (new SceneTemplatesModel())->get_one((int) $template_id);
        if (!$tpl || empty($tpl['is_active'])) { return self::fail('That scene is not available.'); }
        if ((int) ($tpl['creator_id'] ?? 0) > 0 && (int) $tpl['creator_id'] !== (int) $cid) { return self::fail('That scene is not available.'); }
        if (!empty($tpl['is_adult']) && !self::shows_adult($user)) { return self::fail('That scene is not available.'); }
        // A model the caller named must do image generation: it is refused, never swapped for the default and re-priced.
        if ((string) $model_key !== '') { $m_err = ''; if (!InfluencerImageActions::model_for('image', $model_key, $m_err)) { return self::fail($m_err); } }
        $r = InfluencerActions::generate_image($cid, $infl, array(
            'prompt' => self::prompt_for($tpl, $infl), 'model_key' => (string) $model_key, 'num_images' => self::VARIANTS,
            'aspect' => ($aspect !== '') ? $aspect : (string) $tpl['default_aspect'],
            'extra_params' => array('template_id' => (int) $tpl['id']),
        ), $origin);
        if (!empty($r['ok'])) { $tpl['mine'] = ((int) ($tpl['creator_id'] ?? 0) === (int) $cid && (int) $cid > 0); $r['template'] = self::json($tpl); }
        return $r;
    }

    /** Thumbs up (1), down (-1) or clear (0) on one variant. The asset must be the creator's and made from a template. */
    public static function vote($cid, $asset_id, $vote){
        $a = (new MediaAssetsModel())->get_one($cid, (int) $asset_id);
        if (!$a || (int) $a['gen_job_id'] <= 0) { return self::fail('That image was not made from a scene.'); }
        $job = (new InfluencerJobsModel())->get_one($cid, (int) $a['gen_job_id']);
        $tid = $job ? (int) (InfluencerJobsModel::params($job)['template_id'] ?? 0) : 0;
        if ($tid <= 0) { return self::fail('That image was not made from a scene.'); }
        (new SceneTemplatesModel())->vote($cid, $tid, (int) $asset_id, (int) $job['id'], $vote);
        return self::okr(array('asset_id' => (int) $asset_id, 'vote' => ((int) $vote > 0) ? 1 : (((int) $vote < 0) ? -1 : 0)));
    }
}
