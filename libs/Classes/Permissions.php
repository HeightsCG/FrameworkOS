<?php
/**
 * Generic role-based authorization for the baseline framework.
 *
 * Roles live in the `user_roles` lookup table (id, role_name). A user is linked
 * to a role by `user_accounts.role_id`, which is placed on the session at
 * login (login stores the user_accounts row via Session::set). Nothing is
 * hardcoded — checks return false/'' until there is a logged-in user with a role.
 */
class Permissions {

    private static $role_cache = null;
    private static $admin_cache = null;

    /** Is a user authenticated? */
    public static function is_logged_in(): bool
    {
        return ((int) Session::get('user_id')) > 0;
    }

    /** The current user's role name (from user_roles), or '' when none. */
    public static function role(): string
    {
        if (self::$role_cache !== null) {
            return self::$role_cache;
        }
        self::$role_cache = '';
        if (self::is_logged_in()) {
            $role_id = (int) Session::get('role_id');
            if ($role_id > 0) {
                self::$role_cache = (new UsersModel())->get_role_name_by_id($role_id);
            }
        }
        return self::$role_cache;
    }

    /** Is the current user in the given role? */
    public static function has_role(string $role_name): bool
    {
        return self::role() === $role_name;
    }

    /** True if the signed-in user is a team member (collaborator) acting on an owner's account. */
    public static function is_team_member(): bool
    {
        return self::team_role() !== '' && ((int) Session::get('created_by')) > 0;
    }

    /** The current user's team role (manager|editor|viewer), or '' if they're not a team member. */
    public static function team_role(): string
    {
        return (string) Session::get('team_role');
    }

    /**
     * The account id whose creator studio/content the current user operates on.
     * A team member acts on their owner's account (created_by); everyone else is themselves.
     */
    public static function creator_id(): int
    {
        $uid = (int) Session::get('user_id');
        if ($uid <= 0) { return 0; }
        return self::is_team_member() ? (int) Session::get('created_by') : $uid;
    }

    /** Can this user use the creator studio? (an actual Creator, or a collaborator on one). */
    public static function can_act_as_creator(): bool
    {
        return self::has_role('Creator') || self::is_team_member();
    }

    /** Is this an owner-creator (a Creator who is not themselves a collaborator)? — gates team management. */
    public static function is_owner_creator(): bool
    {
        return self::has_role('Creator') && !self::is_team_member();
    }

    /**
     * Platform staff flag (user_accounts.is_admin), independent of role_id so the
     * platform owner can be both a Creator and an admin. Cached per request.
     */
    public static function is_admin(): bool
    {
        if (self::$admin_cache !== null) {
            return self::$admin_cache;
        }
        self::$admin_cache = false;
        $uid = (int) Session::get('user_id');
        if ($uid > 0) {
            $rows = (new UsersModel())->get_user_by_id($uid);
            $u = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            self::$admin_cache = $u && !empty($u['is_admin']);
        }
        return self::$admin_cache;
    }

}
