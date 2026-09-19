<?php
/**
 * AI influencers (one row per persona a creator has created). Creator-scoped: every read
 * and write filters by creator_id. Status/wizard/model pointers are advanced with guarded
 * updates from InfluencerService / InfluencerTrainingService.
 */
class InfluencersModel extends Model {

    const STATUSES = array('draft', 'awaiting_reference', 'training', 'ready', 'failed');

    public function __construct(){ parent::__construct(); }

    /** Returns the new id, or 0 when the name is already used by this creator. */
    public function create($creator_id, $name, $path = null, $is_public = 0){
        $now  = date('Y-m-d H:i:s');
        $name = mb_substr(trim((string) $name), 0, 120);
        try {
            return (int) parent::insert('influencers', array(
                'creator_id'  => (int) $creator_id,
                'name'        => $name,
                'name_lc'     => mb_strtolower($name),
                'status'      => 'draft',
                'path'        => in_array($path, array('photos', 'reference'), true) ? $path : null,
                'is_public'   => $is_public ? 1 : 0,
                'wizard_step' => 'name',
                'created_at'  => $now,
                'updated_at'  => $now,
            ));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { return 0; }
            throw $e;
        }
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM influencers WHERE id = :id AND creator_id = :c AND deleted_at IS NULL",
            array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Unscoped read for workers (the job row already carries the creator). */
    public function get_by_id($id){
        $r = parent::select("SELECT * FROM influencers WHERE id = :id AND deleted_at IS NULL", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Every non-deleted influencer counts against the plan (draft, training, ready or failed). */
    public function count_for_creator($creator_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM influencers WHERE creator_id = :c AND deleted_at IS NULL", array('c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? (int) $r[0]['n'] : 0;
    }

    public function list_for_creator($creator_id){
        return (array) parent::select(
            "SELECT * FROM influencers WHERE creator_id = :c AND deleted_at IS NULL ORDER BY created_at DESC, id DESC",
            array('c' => (int) $creator_id));
    }

    public function list_ready($creator_id){
        return (array) parent::select(
            "SELECT id, name, active_model_id, prompt_defaults, share_accounts FROM influencers
             WHERE creator_id = :c AND deleted_at IS NULL AND status = 'ready' AND active_model_id IS NOT NULL
             ORDER BY name ASC",
            array('c' => (int) $creator_id));
    }

    public function name_taken($creator_id, $name, $except_id = 0){
        $r = parent::select("SELECT id FROM influencers WHERE creator_id = :c AND name_lc = :n AND deleted_at IS NULL AND id <> :x",
            array('c' => (int) $creator_id, 'n' => mb_strtolower(trim((string) $name)), 'x' => (int) $except_id));
        return is_array($r) && count($r) > 0;
    }

    /** Whitelisted field update (wizard inputs, defaults). Returns rows affected, or false on a name collision. */
    public function update_fields($creator_id, $id, array $f){
        $allowed = array('name', 'path', 'input_method', 'is_public', 'source_description', 'reference_model_key',
            'steer_text', 'prompt_defaults', 'negative_prompt', 'face_asset_id', 'reference_asset_id',
            'training_set_group', 'wizard_step', 'share_accounts', 'status', 'last_error');
        $data = array();
        foreach ($allowed as $k) { if (array_key_exists($k, $f)) { $data[$k] = $f[$k]; } }
        if (isset($data['name'])) {
            $data['name']    = mb_substr(trim((string) $data['name']), 0, 120);
            $data['name_lc'] = mb_strtolower($data['name']);
        }
        if (isset($data['share_accounts']) && is_array($data['share_accounts'])) {
            $data['share_accounts'] = json_encode(array_values(array_map('strval', $data['share_accounts'])));
        }
        if (empty($data)) { return 0; }
        $data['updated_at'] = date('Y-m-d H:i:s');
        try {
            return parent::update('influencers', $data, 'id = :id AND creator_id = :c AND deleted_at IS NULL',
                array('id' => (int) $id, 'c' => (int) $creator_id));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { return false; }
            throw $e;
        }
    }

    /**
     * Guarded status/model-pointer transition used by the engine. $where_extra is raw SQL
     * with bound params (e.g. 'pending_model_id = :m'). Returns rows affected (0 = lost the race).
     */
    public function transition($id, array $data, $where_extra = '', array $params = array()){
        $data['updated_at'] = date('Y-m-d H:i:s');
        $params['id'] = (int) $id;
        return parent::update('influencers', $data, 'id = :id' . ($where_extra !== '' ? ' AND ' . $where_extra : ''), $params);
    }

    public function soft_delete($creator_id, $id){
        return parent::update('influencers', array('deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c AND deleted_at IS NULL', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public static function share_accounts(array $row){
        $a = json_decode((string) ($row['share_accounts'] ?? '[]'), true);
        return is_array($a) ? array_values(array_map('strval', $a)) : array();
    }
}
