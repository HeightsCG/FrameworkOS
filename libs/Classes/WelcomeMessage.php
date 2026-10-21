<?php
/**
 * The welcome message every new account finds in its Inbox, from Creator Link Studio (PlatformAccount, participant 0).
 * Sent once at signup (email and Google); a reply lands in the team inbox at /admin/messages like any team conversation.
 * No email or notification: the verification email already goes out, and the Inbox badge shows it.
 */
class WelcomeMessage {

    const TRIGGER = 'welcome';
    const BODY = "Welcome to Creator Link Studio.\n\nThis is your Inbox. Messages from creators, fans and our team land here.\n\nIf you have a question or get stuck, reply to this message and someone on our team will answer.";

    /** Put the welcome message in {uid}'s Inbox, once. Never throws: signing up must not depend on it. */
    public static function send($uid): bool {
        $uid = (int) $uid;
        if ($uid <= 0) { return false; }
        try {
            $m = new MessagesModel();
            $conv = $m->get_or_create(PlatformAccount::ID, $uid);   // the team is always the creator_id side
            if ($m->has_trigger($conv, self::TRIGGER)) { return false; }
            $m->send($conv, PlatformAccount::ID, self::BODY, null, array(), 0, self::TRIGGER);
            return true;
        } catch (\Throwable $e) {
            error_log('[welcome] user_id=' . $uid . ': ' . $e->getMessage());
            return false;
        }
    }
}
