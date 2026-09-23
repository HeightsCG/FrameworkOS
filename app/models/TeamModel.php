<?php
/**
 * Team / seats (PRD §40). A creator (the "owner") can invite collaborators who log in
 * with their own credentials but operate on the OWNER's account (see the acting-as-owner
 * context in Permissions::creator_id() and BaseApiController::require_creator()). Members are
 * user_accounts rows with created_by = owner and a team_role (manager|editor|viewer).
 * The owner occupies one seat; the seat limit comes from the owner's plan tier.
 */
class TeamModel extends Model {

    public static function roles(){ return array('manager', 'editor', 'viewer'); }

    /** Members belonging to an owner (excludes the owner). */
    public function members($owner_id){
        return (array) parent::select(
            "SELECT user_id, u_name, first_name, last_name, user_email, team_role, user_status, reset_pw, created_at, last_active_at
             FROM user_accounts
             WHERE created_by = :o AND team_role IS NOT NULL AND deleted = 0
             ORDER BY created_at ASC",
            array('o' => (int) $owner_id));
    }

    /** Seats in use: the owner (1) + active members. */
    public function seats_used($owner_id){
        $r = parent::select(
            "SELECT COUNT(*) AS n FROM user_accounts
             WHERE created_by = :o AND team_role IS NOT NULL AND deleted = 0 AND user_status = 'Active'",
            array('o' => (int) $owner_id));
        return 1 + (int) (is_array($r) && count($r) ? $r[0]['n'] : 0);
    }

    /** Active team member ids, oldest first (the order plan seat limits keep them unlocked in). */
    public function member_ids_oldest_first($owner_id){
        $r = parent::select(
            "SELECT user_id FROM user_accounts
             WHERE created_by = :o AND team_role IS NOT NULL AND deleted = 0 AND user_status = 'Active'
             ORDER BY user_id ASC",
            array('o' => (int) $owner_id));
        return array_map('intval', array_column((array) $r, 'user_id'));
    }

    public function is_member($owner_id, $member_id){
        $r = parent::select(
            "SELECT user_id FROM user_accounts
             WHERE user_id = :m AND created_by = :o AND team_role IS NOT NULL AND deleted = 0",
            array('m' => (int) $member_id, 'o' => (int) $owner_id));
        return is_array($r) && count($r) === 1;
    }

    public function set_role($owner_id, $member_id, $role){
        if (!in_array($role, self::roles(), true) || !$this->is_member($owner_id, $member_id)) { return false; }
        return parent::update('user_accounts', array('team_role' => $role), 'user_id = :m', array('m' => (int) $member_id));
    }

    public function set_status($owner_id, $member_id, $status){
        if (!in_array($status, array('Active', 'Disabled'), true) || !$this->is_member($owner_id, $member_id)) { return false; }
        return parent::update('user_accounts', array('user_status' => $status), 'user_id = :m', array('m' => (int) $member_id));
    }

    /** Soft-remove a member (frees the seat, blocks sign-in). */
    public function remove($owner_id, $member_id){
        if (!$this->is_member($owner_id, $member_id)) { return false; }
        return parent::update('user_accounts',
            array('deleted' => 1, 'user_status' => 'Disabled'),
            'user_id = :m', array('m' => (int) $member_id));
    }

    /** Finalize a freshly-created member as a team account (owner vouches for the email; force a password set). */
    public function mark_as_member($member_id, $role){
        if (!in_array($role, self::roles(), true)) { $role = 'viewer'; }
        return parent::update('user_accounts',
            array('team_role' => $role, 'email_verified' => 1, 'reset_pw' => 1),
            'user_id = :m', array('m' => (int) $member_id));
    }
}
