<?php
/**
 * Clip edit projects (Content Studio, New Edit): a saved timeline of clips, stills, text and image
 * overlays and an audio track. Creator-scoped. An export marks the project 'rendering' with a fresh
 * render_token; only the export holding the current token may write the result back.
 */
class EditProjectsModel extends Model {

    const ASPECTS = array('9:16', '3:4');

    public function __construct(){ parent::__construct(); }

    public function create($creator_id, $name = '', $aspect = '9:16'){
        $now = date('Y-m-d H:i:s');
        return (int) parent::insert('edit_projects', array(
            'creator_id' => (int) $creator_id, 'name' => mb_substr(trim((string) $name), 0, 160),
            'aspect' => in_array($aspect, self::ASPECTS, true) ? $aspect : '9:16', 'timeline_json' => json_encode(new stdClass()),
            'status' => 'draft', 'created_at' => $now, 'updated_at' => $now));
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM edit_projects WHERE id = :id AND creator_id = :c AND deleted_at IS NULL", array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function get_by_id($id){
        $r = parent::select("SELECT * FROM edit_projects WHERE id = :id AND deleted_at IS NULL", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function list_for_creator($creator_id, $limit = 50){
        $limit = max(1, min(200, (int) $limit));
        return (array) parent::select("SELECT * FROM edit_projects WHERE creator_id = :c AND deleted_at IS NULL ORDER BY updated_at DESC, id DESC LIMIT $limit", array('c' => (int) $creator_id));
    }

    /** Save the editable parts of a project: name, aspect, timeline (array). */
    public function save($creator_id, $id, array $f){
        $data = array('updated_at' => date('Y-m-d H:i:s'));
        if (array_key_exists('name', $f))     { $data['name'] = mb_substr(trim((string) $f['name']), 0, 160); }
        if (array_key_exists('aspect', $f) && in_array($f['aspect'], self::ASPECTS, true)) { $data['aspect'] = $f['aspect']; }
        if (array_key_exists('timeline', $f)) { $data['timeline_json'] = json_encode($f['timeline']); }
        return parent::update('edit_projects', $data, 'id = :id AND creator_id = :c AND deleted_at IS NULL', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Mark an export as started; returns the token that export must present to finish. */
    public function start_render($creator_id, $id){
        $token = bin2hex(random_bytes(12));
        $n = parent::update('edit_projects', array('status' => 'rendering', 'render_token' => $token, 'error' => null, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c AND deleted_at IS NULL', array('id' => (int) $id, 'c' => (int) $creator_id));
        return ($n === 1 || $n === true) ? $token : '';
    }

    /** Finish the export that holds $token (a newer export has replaced any other). */
    public function finish_render($id, $token, $ok, $asset_id = 0, $error = ''){
        return parent::update('edit_projects', array('status' => $ok ? 'done' : 'failed', 'result_asset_id' => $ok ? (int) $asset_id : null,
            'error' => $ok ? null : mb_substr((string) $error, 0, 500), 'rendered_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND render_token = :t', array('id' => (int) $id, 't' => (string) $token));
    }

    /**
     * A project left 'rendering' for more than $minutes (the worker died) is marked failed. The render_token stays,
     * so a worker that is in fact still on it can still finish by token. Returns rows changed (1 when it was stale).
     */
    public function fail_stale_render($creator_id, $id, $minutes, $error){
        return parent::update('edit_projects', array('status' => 'failed', 'error' => mb_substr((string) $error, 0, 500), 'updated_at' => date('Y-m-d H:i:s')),
            "id = :id AND creator_id = :c AND status = 'rendering' AND updated_at < :cut AND deleted_at IS NULL",
            array('id' => (int) $id, 'c' => (int) $creator_id, 'cut' => date('Y-m-d H:i:s', time() - max(1, (int) $minutes) * 60)));
    }

    public function soft_delete($creator_id, $id){
        return parent::update('edit_projects', array('deleted_at' => date('Y-m-d H:i:s')), 'id = :id AND creator_id = :c AND deleted_at IS NULL', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public static function timeline(array $row){
        $t = json_decode((string) ($row['timeline_json'] ?? '{}'), true);
        return is_array($t) ? $t : array();
    }
}
