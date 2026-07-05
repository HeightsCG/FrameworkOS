<?php
/**
 * Follows — a fan (follower_id) following a creator (creator_id).
 * Unique per pair; following is idempotent.
 */
class FollowsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function is_following($follower_id, $creator_id){
        $rows = parent::select(
            "SELECT id FROM follows WHERE follower_id = :f AND creator_id = :c",
            array('f' => (int) $follower_id, 'c' => (int) $creator_id)
        );
        return is_array($rows) && count($rows) === 1;
    }

    public function follow($follower_id, $creator_id){
        if ((int) $follower_id === (int) $creator_id || $this->is_following($follower_id, $creator_id)) {
            return true;
        }
        return parent::insert('follows', array(
            'follower_id' => (int) $follower_id,
            'creator_id'  => (int) $creator_id,
            'created_at'  => date('Y-m-d H:i:s'),
        ));
    }

    public function unfollow($follower_id, $creator_id){
        return parent::delete(
            'follows',
            'follower_id = :f AND creator_id = :c',
            1,
            array('f' => (int) $follower_id, 'c' => (int) $creator_id)
        );
    }

    public function count_followers($creator_id){
        $rows = parent::select(
            "SELECT COUNT(*) AS c FROM follows WHERE creator_id = :c",
            array('c' => (int) $creator_id)
        );
        return is_array($rows) && count($rows) ? (int) $rows[0]['c'] : 0;
    }

    public function count_following($follower_id){
        $rows = parent::select(
            "SELECT COUNT(*) AS c FROM follows WHERE follower_id = :f",
            array('f' => (int) $follower_id)
        );
        return is_array($rows) && count($rows) ? (int) $rows[0]['c'] : 0;
    }
}
