<?php
/**
 * The scene template library (Studio, Scenes): admin-managed base prompts a creator runs with one
 * of their influencers for four variants, then rates. Adult templates are listed only for
 * accounts that opted in to adult content (user_accounts.adult_content_enabled), the same switch
 * that gates adult posts. Shared by the web API and the Claude connector.
 */
class SceneTemplates {

    const VARIANTS = 4;

    private static function fail($error, array $extra = array()){ return array_merge(array('ok' => false, 'error' => (string) $error), $extra); }
    private static function okr(array $payload = array()){ return array_merge(array('ok' => true, 'error' => ''), $payload); }

    public static function shows_adult($user){ return is_array($user) && !empty($user['adult_content_enabled']); }

    public static function thumb_url($row){
        $key = (string) ($row['thumb_key'] ?? '');
        return ($key !== '') ? S3Service::presigned_get_url($key, 3600) : '';
    }

    public static function json(array $row, $admin = false){
        $out = array('id' => (int) $row['id'], 'title' => (string) $row['title'], 'category' => (string) $row['category'], 'is_adult' => (int) $row['is_adult'],
            'default_aspect' => Aspect::normalize($row['default_aspect']), 'thumb_url' => self::thumb_url($row));
        if ($admin) {
            $out += array('base_prompt' => (string) $row['base_prompt'], 'is_active' => (int) $row['is_active'], 'sort_order' => (int) $row['sort_order'],
                'ups' => (int) ($row['ups'] ?? 0), 'downs' => (int) ($row['downs'] ?? 0));
        }
        return $out;
    }

    /** Templates this account may pick, grouped for the page: ['templates' => [...], 'categories' => [...]]. */
    public static function for_user($user){
        $out = array(); $cats = array();
        foreach ((new SceneTemplatesModel())->list_active(self::shows_adult($user)) as $r) {
            $out[] = self::json($r);
            if ((string) $r['category'] !== '' && !in_array((string) $r['category'], $cats, true)) { $cats[] = (string) $r['category']; }
        }
        return array('templates' => $out, 'categories' => $cats);
    }

    /** The prompt a template renders with for this influencer. */
    public static function prompt_for(array $tpl, array $infl){
        $noun = InfluencerService::noun($infl);
        return trim(str_replace(array('{subject}', '{Subject}'), array($noun, ucfirst($noun)), (string) $tpl['base_prompt']));
    }

    /** Run a template with an influencer: one job, four variants. $aspect '' = the template's default. */
    public static function run($cid, array $user, array $infl, $template_id, $aspect = '', $model_key = '', $origin = 'studio'){
        $tpl = (new SceneTemplatesModel())->get_one((int) $template_id);
        if (!$tpl || empty($tpl['is_active'])) { return self::fail('That scene is not available.'); }
        if (!empty($tpl['is_adult']) && !self::shows_adult($user)) { return self::fail('That scene is not available.'); }
        // A model the caller named must do image generation: it is refused, never swapped for the default and re-priced.
        if ((string) $model_key !== '') { $m_err = ''; if (!InfluencerImageActions::model_for('image', $model_key, $m_err)) { return self::fail($m_err); } }
        $r = InfluencerActions::generate_image($cid, $infl, array(
            'prompt' => self::prompt_for($tpl, $infl), 'model_key' => (string) $model_key, 'num_images' => self::VARIANTS,
            'aspect' => ($aspect !== '') ? $aspect : (string) $tpl['default_aspect'],
            'extra_params' => array('template_id' => (int) $tpl['id']),
        ), $origin);
        if (!empty($r['ok'])) { $r['template'] = self::json($tpl); }
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
