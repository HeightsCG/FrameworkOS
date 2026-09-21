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

    /** True when either account has blocked the other — the check every gate uses. */
    public function either_blocked($a, $b){
        $a = (int) $a; $b = (int) $b;
        if ($a <= 0 || $b <= 0 || $a === $b) { return false; }
        $rows = parent::select(
            "SELECT id FROM user_blocks
             WHERE (user_id = :a1 AND blocked_user_id = :b1) OR (user_id = :b2 AND blocked_user_id = :a2)
             LIMIT 1",
            array('a1' => $a, 'b1' => $b, 'b2' => $b, 'a2' => $a)
        );
        return is_array($rows) && count($rows) > 0;
    }

    /** Every account id involved in a block with this user, in either direction (for filtering lists in PHP). */
    public function related_ids($user_id){
        $uid = (int) $user_id;
        if ($uid <= 0) { return array(); }
        $rows = parent::select(
            "SELECT blocked_user_id AS other FROM user_blocks WHERE user_id = :u1
             UNION SELECT user_id AS other FROM user_blocks WHERE blocked_user_id = :u2",
            array('u1' => $uid, 'u2' => $uid)
        );
        $out = array();
        foreach ((array) $rows as $r) { $out[(int) $r['other']] = true; }
        return $out;
    }

    /**
     * SQL fragment that drops rows whose $other_col account is in a block with the viewer, either
     * direction. Caller binds the viewer id to BOTH named params (distinct names — PDO forbids reuse).
     */
    public static function exclude_sql($other_col, $p1, $p2){
        return "NOT EXISTS (SELECT 1 FROM user_blocks ub
                    WHERE (ub.user_id = $other_col AND ub.blocked_user_id = :$p1)
                       OR (ub.user_id = :$p2 AND ub.blocked_user_id = $other_col))";
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
