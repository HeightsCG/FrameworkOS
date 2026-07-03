<?php
class BlocksModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** Blocked accounts for a user, newest first, with the blocked account's public identity. */
    public function get_for_user($user_id){
        return parent::select(
            "SELECT
                b.blocked_user_id,
                u.u_name,
                u.first_name,
                u.last_name
            FROM user_blocks b
            JOIN user_accounts u ON u.user_id = b.blocked_user_id AND u.deleted = 0
            WHERE b.user_id = :user_id
            ORDER BY b.created_at DESC",
            array('user_id' => (int) $user_id)
        );
    }

    public function is_blocked($user_id, $blocked_user_id){
        $rows = parent::select(
            "SELECT id FROM user_blocks WHERE user_id = :user_id AND blocked_user_id = :blocked_user_id",
            array('user_id' => (int) $user_id, 'blocked_user_id' => (int) $blocked_user_id)
        );
        return is_array($rows) && count($rows) === 1;
    }

    public function add_block($user_id, $blocked_user_id){
        if ($this->is_blocked($user_id, $blocked_user_id)) {
            return true;
        }
        return parent::insert('user_blocks', array(
            'user_id'         => (int) $user_id,
            'blocked_user_id' => (int) $blocked_user_id,
            'created_at'      => date('Y-m-d H:i:s'),
        ));
    }

    public function remove_block($user_id, $blocked_user_id){
        return parent::delete(
            'user_blocks',
            'user_id = :user_id AND blocked_user_id = :blocked_user_id',
            1,
            array('user_id' => (int) $user_id, 'blocked_user_id' => (int) $blocked_user_id)
        );
    }

}
