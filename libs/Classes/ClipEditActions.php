<?php
/**
 * Clip edit projects, shared by the web API (ApiClipEditorController) and the Claude connector
 * (McpTools): create, save, reopen, export. An export is a queued render (ClipRenderJob) whose
 * result lands in the Library as an edited video with lineage. Same contract as the other
 * action classes: array('ok' => bool, 'error' => string, ...payload).
 */
class ClipEditActions {

    private static function fail($error, array $extra = array()){ return array_merge(array('ok' => false, 'error' => (string) $error), $extra); }
    private static function okr(array $payload = array()){ return array_merge(array('ok' => true, 'error' => ''), $payload); }

    /** What the editor needs to show one Library file. */
    public static function asset_json($cid, array $a){
        $type = (string) $a['type'];
        return array('id' => (int) $a['id'], 'type' => ($type === 'gif') ? 'image' : $type, 'name' => (string) ($a['display_name'] ?: $a['filename']),
            'duration' => (float) ($a['duration_sec'] ?? 0), 'width' => (int) $a['width'], 'height' => (int) $a['height'], 'png' => stripos((string) $a['mime'], 'png') !== false,
            'thumb_url' => ($type === 'audio') ? '' : MediaService::signed_url($a, 'thumb', $cid),
            'preview_url' => ($type === 'video' || $type === 'audio') ? MediaService::signed_variant($a, 'original', 3600) : MediaService::signed_url($a, 'display', $cid));
    }

    public static function project_json($cid, array $p, $with_assets = true){
        $norm = ClipRenderer::normalize($cid, EditProjectsModel::timeline($p));
        $out = array('id' => (int) $p['id'], 'name' => (string) $p['name'], 'aspect' => (string) $p['aspect'], 'status' => (string) $p['status'], 'error' => (string) $p['error'],
            'timeline' => $norm['timeline'], 'duration' => $norm['duration'], 'problems' => $norm['errors'], 'updated_at' => (string) $p['updated_at'], 'rendered_at' => (string) $p['rendered_at'], 'result' => null);
        if ($with_assets) {
            $out['assets'] = array();
            foreach ($norm['assets'] as $a) { $out['assets'][(int) $a['id']] = self::asset_json($cid, $a); }
        }
        if ((string) $p['status'] === 'done' && (int) $p['result_asset_id'] > 0) {
            $r = (new MediaAssetsModel())->get_one($cid, (int) $p['result_asset_id']);
            if ($r && (string) $r['status'] === 'ready') {
                $out['result'] = array('id' => (int) $r['id'], 'duration' => (int) $r['duration_sec'], 'width' => (int) $r['width'], 'height' => (int) $r['height'],
                    'thumb_url' => MediaService::signed_url($r, 'thumb', $cid), 'video_url' => MediaService::signed_variant($r, 'original', 3600));
            }
        }
        return $out;
    }

    public static function create($cid, $name = '', $aspect = '9:16'){
        $m = new EditProjectsModel();
        $id = $m->create($cid, trim((string) $name) !== '' ? $name : 'Untitled Edit', $aspect);
        if ($id <= 0) { return self::fail('Could not create the edit.'); }
        return self::okr(array('project' => self::project_json($cid, $m->get_one($cid, $id))));
    }

    public static function get($cid, $id){
        $p = (new EditProjectsModel())->get_one($cid, (int) $id);
        if (!$p) { return self::fail('Edit not found.'); }
        return self::okr(array('project' => self::project_json($cid, $p), 'fonts' => self::font_list()));
    }

    public static function font_list(){
        $out = array();
        foreach (ClipRenderer::fonts() as $k => $f) { $out[] = array('key' => $k, 'label' => $f['label']); }
        return $out;
    }

    public static function listing($cid){
        $out = array(); $mm = new MediaAssetsModel();
        foreach ((new EditProjectsModel())->list_for_creator($cid, 100) as $p) {
            $t = EditProjectsModel::timeline($p);
            $first = (int) (($t['clips'][0]['asset_id'] ?? 0));
            $a = $first > 0 ? $mm->get_one($cid, $first) : null;
            $secs = 0.0;
            foreach ((array) ($t['clips'] ?? array()) as $c) { $secs += (float) ($c['duration'] ?? 0); }
            $out[] = array('id' => (int) $p['id'], 'name' => (string) $p['name'], 'aspect' => (string) $p['aspect'], 'status' => (string) $p['status'], 'clips' => count((array) ($t['clips'] ?? array())),
                'duration' => round($secs), 'updated_at' => (string) $p['updated_at'], 'thumb_url' => ($a && (string) $a['status'] === 'ready') ? MediaService::signed_url($a, 'thumb', $cid) : '');
        }
        return self::okr(array('projects' => $out));
    }

    /** Save name, aspect and timeline. The timeline is tidied against the Library on the way in. */
    public static function save($cid, $id, array $f){
        $m = new EditProjectsModel();
        $p = $m->get_one($cid, (int) $id);
        if (!$p) { return self::fail('Edit not found.'); }
        $data = array();
        if (array_key_exists('name', $f)) { $data['name'] = trim((string) $f['name']) !== '' ? (string) $f['name'] : 'Untitled Edit'; }
        if (array_key_exists('aspect', $f)) {
            if (!isset(ClipRenderer::SIZES[(string) $f['aspect']])) { return self::fail('Pick 9:16 or 3:4.'); }
            $data['aspect'] = (string) $f['aspect'];
        }
        if (array_key_exists('timeline', $f) && is_array($f['timeline'])) { $data['timeline'] = ClipRenderer::normalize($cid, $f['timeline'])['timeline']; }
        $m->save($cid, (int) $id, $data);
        return self::okr(array('project' => self::project_json($cid, $m->get_one($cid, (int) $id), false)));
    }

    public static function delete($cid, $id){
        $n = (new EditProjectsModel())->soft_delete($cid, (int) $id);
        return $n ? self::okr(array('id' => (int) $id)) : self::fail('Edit not found.');
    }

    /** Start an export: the saved timeline is checked, the project marked rendering, and the render queued. */
    public static function export($cid, $id){
        $m = new EditProjectsModel();
        $p = $m->get_one($cid, (int) $id);
        if (!$p) { return self::fail('Edit not found.'); }
        if (!ClipRenderer::available()) { return self::fail('Exporting is not available right now.'); }
        $norm = ClipRenderer::normalize($cid, EditProjectsModel::timeline($p));
        if (!empty($norm['errors'])) { return self::fail($norm['errors'][0], array('problems' => $norm['errors'])); }
        $gb = Plan::limit(InfluencerJobService::user($cid), 'storage_gb');
        if ($gb !== null && (int) $gb > 0 && (int) (new MediaAssetsModel())->total_bytes($cid) >= (int) $gb * 1073741824) { return self::fail("You've reached your plan's storage limit. Remove files to free up space before exporting.", array('need_upgrade' => true)); }
        $token = $m->start_render($cid, (int) $id);
        if ($token === '') { return self::fail('Could not start the export.'); }
        $job = (new DatabaseJobQueue())->dispatch('clip_render', array('project_id' => (int) $id, 'creator_id' => (int) $cid, 'token' => $token), 'clip_render:' . (int) $id . ':' . $token);
        if ($job <= 0) { $m->finish_render((int) $id, $token, false, 0, 'The export could not be queued.'); return self::fail('Could not start the export.'); }
        return self::okr(array('project' => self::project_json($cid, $m->get_one($cid, (int) $id), false), 'seconds' => $norm['duration']));
    }

    public static function status($cid, $id){
        $p = (new EditProjectsModel())->get_one($cid, (int) $id);
        if (!$p) { return self::fail('Edit not found.'); }
        return self::okr(array('project' => self::project_json($cid, $p, false)));
    }

    /**
     * The queued render (ClipRenderJob). Only the export holding the project's current token writes the result, so
     * starting a new export while one runs simply replaces it. Returns a one-line outcome for the queue log.
     */
    public static function run_export($project_id, $cid, $token){
        $m = new EditProjectsModel();
        $p = $m->get_one($cid, (int) $project_id);
        if (!$p || (string) $p['render_token'] !== (string) $token || (string) $p['status'] !== 'rendering') { return 'SKIP superseded or gone'; }
        $norm = ClipRenderer::normalize($cid, EditProjectsModel::timeline($p));
        $r = ClipRenderer::render($cid, $norm, (string) $p['aspect']);
        if (empty($r['ok'])) { $m->finish_render((int) $project_id, $token, false, 0, (string) $r['error']); return 'FAILED ' . $r['error']; }
        try {
            $user = InfluencerJobService::user($cid);
            $name = trim((string) $p['name']) !== '' ? (string) $p['name'] : 'Edit';
            $in = MediaIngestService::ingest_video_file($cid, $user, $r['path'], 'mp4', 'video/mp4', (int) filesize($r['path']), 'Edit · ' . mb_substr($name, 0, 40), '', 0, true);
        } catch (\Throwable $e) {
            if (is_file($r['path'])) { @unlink($r['path']); }
            $m->finish_render((int) $project_id, $token, false, 0, $e->getMessage());
            return 'FAILED ' . $e->getMessage();
        }
        $aid = (int) $in['asset_id'];
        $first = (int) ($norm['timeline']['clips'][0]['asset_id'] ?? 0);
        $fa = $norm['assets'][$first] ?? null;
        // An edit of AI-made clips is itself AI media (for disclosure); an edit of the creator's own uploads is not.
        $ai = false;
        foreach ($norm['assets'] as $a) { if ((string) $a['provenance'] !== 'uploaded' && (string) $a['type'] !== 'audio') { $ai = true; } }
        (new MediaAssetsModel())->set_lineage($cid, $aid, array('provenance' => $ai ? 'edited' : 'uploaded', 'parent_asset_id' => 0, 'source_asset_id' => $first,
            'model_key' => 'clip_editor', 'prompt' => 'Edit project ' . (int) $project_id . ': ' . $name, 'influencer_id' => $fa ? (int) $fa['gen_influencer_id'] : 0));
        $m->finish_render((int) $project_id, $token, true, $aid);
        return 'DONE project ' . (int) $project_id . ' -> asset ' . $aid . ' (' . $norm['duration'] . 's)';
    }
}
