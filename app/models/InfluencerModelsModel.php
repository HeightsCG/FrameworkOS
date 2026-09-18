<?php
/**
 * Trained weights (LoRAs) for an influencer. Many rows per influencer; exactly one may be
 * active, enforced by the `active_slot` unique key in the schema. A retrain creates a new
 * row that only becomes active when it finishes, so the working model is never lost.
 */
class InfluencerModelsModel extends Model {

    public function __construct(){ parent::__construct(); }

    public function create($influencer_id, $creator_id, array $f){
        $now = date('Y-m-d H:i:s');
        return (int) parent::insert('influencer_models', array(
            'influencer_id'      => (int) $influencer_id,
            'creator_id'         => (int) $creator_id,
            'job_id'             => isset($f['job_id']) ? (int) $f['job_id'] : null,
            'provider'           => (string) ($f['provider'] ?? 'fal'),
            'model_key'          => (string) ($f['model_key'] ?? ''),
            'base_model'         => (string) ($f['base_model'] ?? 'flux-dev'),
            'trigger_word'       => (string) ($f['trigger_word'] ?? ''),
            'status'             => 'training',
            'is_active'          => 0,
            'steps'              => isset($f['steps']) ? (int) $f['steps'] : null,
            'image_count'        => isset($f['image_count']) ? (int) $f['image_count'] : null,
            'training_set_group' => $f['training_set_group'] ?? null,
            'params_json'        => isset($f['params']) ? json_encode($f['params']) : null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ));
    }

    public function get_by_id($id){
        $r = parent::select("SELECT * FROM influencer_models WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM influencer_models WHERE id = :id AND creator_id = :c",
            array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function get_active($influencer_id){
        $r = parent::select("SELECT * FROM influencer_models WHERE influencer_id = :i AND is_active = 1 AND status = 'ready'",
            array('i' => (int) $influencer_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function list_for_influencer($creator_id, $influencer_id){
        return (array) parent::select(
            "SELECT * FROM influencer_models WHERE creator_id = :c AND influencer_id = :i ORDER BY id DESC",
            array('c' => (int) $creator_id, 'i' => (int) $influencer_id));
    }

    public function set_job($id, $job_id){
        return parent::update('influencer_models', array('job_id' => (int) $job_id, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id', array('id' => (int) $id));
    }

    /** training -> ready with the stored weights. Returns rows affected (0 = already resolved). */
    public function mark_ready($id, $weights_key, $weights_url, $weights_bytes){
        $now = date('Y-m-d H:i:s');
        return parent::update('influencer_models', array(
            'status' => 'ready', 'weights_key' => (string) $weights_key, 'weights_url' => mb_substr((string) $weights_url, 0, 1024),
            'weights_bytes' => (int) $weights_bytes, 'error' => null, 'trained_at' => $now, 'updated_at' => $now,
        ), 'id = :id AND status = :s', array('id' => (int) $id, 's' => 'training'));
    }

    public function mark_failed($id, $error){
        return parent::update('influencer_models', array(
            'status' => 'failed', 'error' => mb_substr((string) $error, 0, 2000), 'updated_at' => date('Y-m-d H:i:s'),
        ), 'id = :id AND status = :s', array('id' => (int) $id, 's' => 'training'));
    }

    /**
     * Make $id the one active model for its influencer in a single statement. The old active
     * row is cleared first (ORDER BY is_active DESC) so the unique active_slot never trips.
     */
    public function activate($influencer_id, $id){
        $now = date('Y-m-d H:i:s');
        return parent::sql(
            "UPDATE influencer_models SET is_active = IF(id = :new, 1, 0), updated_at = :now
             WHERE influencer_id = :i AND (id = :new2 OR is_active = 1) AND (id <> :new3 OR status = 'ready')
             ORDER BY is_active DESC",
            array(':new' => (int) $id, ':now' => $now, ':i' => (int) $influencer_id, ':new2' => (int) $id, ':new3' => (int) $id));
    }
}
