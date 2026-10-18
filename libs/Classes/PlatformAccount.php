<?php
/**
 * The "Creator Link Studio" team account (user_accounts.is_platform, sql/2026-10-18_platform_account.sql).
 * Admin messages and announcements are sent from it; users reply to it in their Inbox and admins answer at /admin/messages.
 * It can't sign in.
 */
class PlatformAccount {

    const NAME   = 'Creator Link Studio';
    const AVATAR = '/images/logo/apple-touch-icon.png';

    private static $id = null;

    public static function id(): int {
        if (self::$id === null) {
            try { self::$id = (int) (new UsersModel())->platform_id(); } catch (\Throwable $e) { self::$id = 0; }   // column missing until the SQL runs
        }
        return self::$id;
    }

    public static function is($user_id): bool {
        return (int) $user_id > 0 && (int) $user_id === self::id();
    }
}
