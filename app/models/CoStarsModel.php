<?php
/**
 * Co-star (release) registry — the people other than the creator who appear in content.
 * Legal name + DOB are encrypted at rest ([[Crypto]]); ID / release documents live in a
 * private S3 prefix and are served only via short-lived signed URLs, access-logged.
 * A co-star must be `verified` before content featuring them can be published.
 */
class CoStarsModel extends Model {

    public function create($creator_id, array $f){
        $now = date('Y-m-d H:i:s');
        parent::insert('co_stars', array(
            'creator_id'     => (int) $creator_id,
            'stage_name'     => (string) $f['stage_name'],
            'legal_name_enc' => Crypto::encrypt($f['legal_name'] ?? ''),
            'dob_enc'        => Crypto::encrypt($f['dob'] ?? ''),
            'status'         => 'pending',
            'created_at'     => $now,
            'updated_at'     => $now,
        ));
        return (int) $this->db->lastInsertId();
    }

    public function update_costar($creator_id, $id, array $f){
        $data = array('updated_at' => date('Y-m-d H:i:s'));
        if (array_key_exists('stage_name', $f)) { $data['stage_name'] = (string) $f['stage_name']; }
        if (array_key_exists('legal_name', $f)) { $data['legal_name_enc'] = Crypto::encrypt($f['legal_name']); }
        if (array_key_exists('dob', $f))        { $data['dob_enc'] = Crypto::encrypt($f['dob']); }
        return parent::update('co_stars', $data, 'id = :id AND creator_id = :c AND deleted_at IS NULL',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM co_stars WHERE id = :id AND creator_id = :c AND deleted_at IS NULL",
            array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function list_for_creator($creator_id){
        return parent::select(
            "SELECT * FROM co_stars WHERE creator_id = :c AND deleted_at IS NULL ORDER BY stage_name ASC",
            array('c' => (int) $creator_id)
        );
    }

    /** Store an uploaded doc key. $which = 'id_doc_key' | 'release_doc_key'. */
    public function set_doc($creator_id, $id, $which, $key){
        if (!in_array($which, array('id_doc_key', 'release_doc_key'), true)) { return false; }
        return parent::update('co_stars',
            array($which => (string) $key, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c AND deleted_at IS NULL',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Set verification status (v1: manual — admin/tooling flips to 'verified'). */
    public function set_status($creator_id, $id, $status, $verified_at = null, $expires_at = null){
        $status = in_array($status, array('pending', 'verified', 'rejected', 'expired'), true) ? $status : 'pending';
        return parent::update('co_stars',
            array(
                'status'      => $status,
                'verified_at' => ($status === 'verified') ? ($verified_at ?: date('Y-m-d H:i:s')) : null,
                'expires_at'  => $expires_at,
                'updated_at'  => date('Y-m-d H:i:s'),
            ),
            'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function delete_costar($creator_id, $id){
        parent::delete_all('post_co_stars', 'co_star_id = :id', array('id' => (int) $id));
        return parent::update('co_stars',
            array('deleted_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Map of verified co-star ids for a creator (publish gate). */
    public function verified_ids($creator_id){
        $r = parent::select(
            "SELECT id FROM co_stars WHERE creator_id = :c AND deleted_at IS NULL AND status = 'verified'
             AND (expires_at IS NULL OR expires_at > NOW())",
            array('c' => (int) $creator_id));
        $out = array();
        foreach ((array) $r as $row) { $out[(int) $row['id']] = true; }
        return $out;
    }

    public function log_access($co_star_id, $actor_id, $action){
        parent::insert('release_access_log', array(
            'co_star_id' => (int) $co_star_id,
            'actor_id'   => (int) $actor_id,
            'action'     => (string) $action,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    // ---- post ↔ co-star tagging ----

    public function set_post_costars($post_id, array $co_star_ids){
        parent::delete_all('post_co_stars', 'post_id = :p', array('p' => (int) $post_id));
        foreach (array_unique(array_map('intval', $co_star_ids)) as $cid) {
            if ($cid > 0) { parent::insert('post_co_stars', array('post_id' => (int) $post_id, 'co_star_id' => $cid)); }
        }
        return true;
    }

    public function post_costar_ids($post_id){
        $r = parent::select("SELECT co_star_id FROM post_co_stars WHERE post_id = :p", array('p' => (int) $post_id));
        return array_map(function ($x) { return (int) $x['co_star_id']; }, (array) $r);
    }
}
