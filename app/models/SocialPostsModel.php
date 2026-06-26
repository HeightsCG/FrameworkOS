<?php
class SocialPostsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function create($user_id, $pfm_post_id, $caption, $status, $scheduled_at, $account_ids){
        $now = date('Y-m-d H:i:s');
        return parent::insert('social_posts', array(
            'user_id'             => (int) $user_id,
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
