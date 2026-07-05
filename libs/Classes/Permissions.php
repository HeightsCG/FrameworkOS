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

}
