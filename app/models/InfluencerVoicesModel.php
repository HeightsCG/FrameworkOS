<?php
/**
 * Voices designed for an influencer. Several can be saved; exactly one is active (the one
 * text to speech and talking videos use). Creator-scoped throughout.
 */
class InfluencerVoicesModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** $f: name, voice_id, prompt, settings (array), provider. The first voice saved becomes the active one. */
    public function add($creator_id, $influencer_id, array $f){
        $now = date('Y-m-d H:i:s');
        $first = ($this->count_for_influencer($creator_id, $influencer_id) === 0);
        return (int) parent::insert('influencer_voices', array(
            'creator_id'    => (int) $creator_id,
            'influencer_id' => (int) $influencer_id,
            'name'          => mb_substr(trim((string) ($f['name'] ?? 'Voice')), 0, 120),
            'provider'      => (string) ($f['provider'] ?? 'elevenlabs'),
            'voice_id'      => (string) ($f['voice_id'] ?? ''),
            'prompt'        => (string) ($f['prompt'] ?? ''),
            'settings_json' => json_encode((array) ($f['settings'] ?? array())),
            'is_active'     => $first ? 1 : 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ));
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM influencer_voices WHERE id = :id AND creator_id = :c AND deleted_at IS NULL", array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function list_for_influencer($creator_id, $influencer_id){
        return (array) parent::select("SELECT * FROM influencer_voices WHERE creator_id = :c AND influencer_id = :i AND deleted_at IS NULL ORDER BY is_active DESC, id DESC",
            array('c' => (int) $creator_id, 'i' => (int) $influencer_id));
    }

    public function count_for_influencer($creator_id, $influencer_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM influencer_voices WHERE creator_id = :c AND influencer_id = :i AND deleted_at IS NULL", array('c' => (int) $creator_id, 'i' => (int) $influencer_id));
        return (is_array($r) && count($r)) ? (int) $r[0]['n'] : 0;
    }

    /** Every saved voice on the account (the platform voice account is one shared pool, capped per creator). */
    public function count_for_creator($creator_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM influencer_voices WHERE creator_id = :c AND deleted_at IS NULL", array('c' => (int) $creator_id));
        return (is_array($r) && count($r)) ? (int) $r[0]['n'] : 0;
    }

    public function active($creator_id, $influencer_id){
        $r = parent::select("SELECT * FROM influencer_voices WHERE creator_id = :c AND influencer_id = :i AND deleted_at IS NULL AND is_active = 1 ORDER BY id DESC LIMIT 1",
            array('c' => (int) $creator_id, 'i' => (int) $influencer_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function set_active($creator_id, $influencer_id, $id){
        $now = date('Y-m-d H:i:s');
        parent::update('influencer_voices', array('is_active' => 0, 'updated_at' => $now), 'creator_id = :c AND influencer_id = :i AND deleted_at IS NULL', array('c' => (int) $creator_id, 'i' => (int) $influencer_id));
        return parent::update('influencer_voices', array('is_active' => 1, 'updated_at' => $now), 'id = :id AND creator_id = :c AND influencer_id = :i AND deleted_at IS NULL',
            array('id' => (int) $id, 'c' => (int) $creator_id, 'i' => (int) $influencer_id));
    }

    /** Remove a voice; when it was the active one the newest remaining voice takes over. */
    public function soft_delete($creator_id, $id){
        $v = $this->get_one($creator_id, $id);
        if (!$v) { return 0; }
        $n = parent::update('influencer_voices', array('deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0), 'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
        if (!empty($v['is_active'])) {
            $rest = $this->list_for_influencer($creator_id, (int) $v['influencer_id']);
            if (!empty($rest)) { $this->set_active($creator_id, (int) $v['influencer_id'], (int) $rest[0]['id']); }
        }
        return $n;
    }

    public static function settings(array $row){
        $s = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        return is_array($s) ? $s : array();
    }
}
