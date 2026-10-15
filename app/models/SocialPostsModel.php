<?php
class SocialPostsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function create($user_id, $pfm_post_id, $caption, $status, $scheduled_at, $account_ids, $post_id = null){
        $now = date('Y-m-d H:i:s');
        return parent::insert('social_posts', array(
            'user_id'             => (int) $user_id,
            'post_id'             => $post_id ? (int) $post_id : null,
            'post_for_me_post_id' => $pfm_post_id,
            'caption'             => $caption,
            'status'              => $status,
            'scheduled_at'        => $scheduled_at ? date('Y-m-d H:i:s', strtotime($scheduled_at)) : null,
            'target_account_ids'  => json_encode(array_values($account_ids)),
            'platform_count'      => count($account_ids),
            'created_at'          => $now,
            'updated_at'          => $now,
        ));
    }

    /** Distinct platforms a given studio post was shared to (for the "shared" column). */
    public function platforms_for_post($post_id){
        return parent::select(
            "SELECT sp.target_account_ids FROM social_posts sp WHERE sp.post_id = :p",
            array('p' => (int) $post_id)
        );
    }

    /** Union of Post-for-Me account ids this studio post was shared to. */
    public function account_ids_for_post($post_id){
        $rows = parent::select(
            "SELECT target_account_ids FROM social_posts WHERE post_id = :p",
            array('p' => (int) $post_id)
        );
        $ids = array();
        foreach ((array) $rows as $r) {
            $a = json_decode((string) $r['target_account_ids'], true);
            if (is_array($a)) { foreach ($a as $x) { $ids[(string) $x] = true; } }
        }
        return array_keys($ids);
    }

    public function count_for_post($post_id){
        $rows = parent::select(
            "SELECT COUNT(*) AS n FROM social_posts WHERE post_id = :p",
            array('p' => (int) $post_id)
        );
        return (is_array($rows) && count($rows) === 1) ? (int) $rows[0]['n'] : 0;
    }

    public function get_by_pfm_id($pfm_post_id){
        $rows = parent::select("SELECT * FROM social_posts WHERE post_for_me_post_id = :p LIMIT 1", array('p' => (string) $pfm_post_id));
        return is_array($rows) && count($rows) === 1 ? $rows[0] : null;
    }

    /** Take accounts the platform refused off a cross-post, so the editor no longer counts them as posted. */
    public function remove_accounts($pfm_post_id, array $account_ids){
        $row = $this->get_by_pfm_id($pfm_post_id);
        if (!$row) { return false; }
        $left = array_values(array_diff(array_map('strval', json_decode((string) $row['target_account_ids'], true) ?: array()), array_map('strval', $account_ids)));
        return parent::update('social_posts', array('target_account_ids' => json_encode($left), 'platform_count' => count($left), 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id', array('id' => (int) $row['id']));
    }

    public function update_status($pfm_post_id, $status){
        return parent::update(
            'social_posts',
            array('status' => $status, 'updated_at' => date('Y-m-d H:i:s')),
            'post_for_me_post_id = :pfm_id',
            array('pfm_id' => $pfm_post_id)
        );
    }

    public function get_recent_for_user($user_id, $limit = 10){
        $limit = (int) $limit;
        return parent::select(
            "SELECT * FROM social_posts WHERE user_id = :user_id ORDER BY created_at DESC LIMIT $limit",
            array('user_id' => (int) $user_id)
        );
    }
}
