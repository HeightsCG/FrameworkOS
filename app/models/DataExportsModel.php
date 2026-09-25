<?php
/** Account data exports ("Download Your Data"): one row per request, built by DataExportJob. */
class DataExportsModel extends Model {

    public function __construct(){ parent::__construct(); }

    public function create($user_id){
        return (int) parent::insert('data_exports', array('user_id' => (int) $user_id, 'status' => 'queued', 'created_at' => gmdate('Y-m-d H:i:s')));
    }

    public function get($id){
        $r = parent::select("SELECT * FROM data_exports WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** The user's most recent export, or null. */
    public function latest_for_user($user_id){
        $r = parent::select("SELECT * FROM data_exports WHERE user_id = :u ORDER BY id DESC LIMIT 1", array('u' => (int) $user_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Earlier exports of this user that still have a stored file (to delete when a new one is ready). */
    public function stored_before($user_id, $id){
        return (array) parent::select("SELECT * FROM data_exports WHERE user_id = :u AND id < :id AND s3_key IS NOT NULL", array('u' => (int) $user_id, 'id' => (int) $id));
    }

    public function set($id, array $fields){
        parent::update('data_exports', $fields, 'id = :eid', array('eid' => (int) $id));
    }
}
