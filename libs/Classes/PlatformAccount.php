<?php
/**
 * "Creator Link Studio" in the Inbox: the team side of admin messages (/admin user page, Users > Messages) and
 * announcements (Users > Announcements). It is not an account: a team conversation has participant 0 on its creator
 * side, so every admin answers from the shared inbox at /admin/messages, and users see the name and the logo. Which
 * admin sent what is in the admin audit log (every admin send is audited).
 */
class PlatformAccount {

    const NAME   = 'Creator Link Studio';
    const AVATAR = '/images/logo/apple-touch-icon.png';
    /** The team's participant id in conversations, messages and broadcasts. */
    const ID     = 0;

    public static function id(): int { return self::ID; }

    /** Is this participant id the team? Only an id that is really 0 (never null or a missing value). */
    public static function is($user_id): bool {
        return $user_id !== null && $user_id !== '' && $user_id !== false && (int) $user_id === self::ID;
    }

    /** How the team shows in identity maps. */
    public static function identity(): array {
        return array('handle' => '', 'name' => self::NAME, 'avatar' => self::AVATAR, 'is_creator' => false, 'is_team' => true);
    }
}
