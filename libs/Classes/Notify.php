<?php
/**
 * The one notification funnel (PRD §27). Every event in the product goes through here so
 * the switches in Settings › Notifications mean something: the on-site feed honours the
 * in-app preference, and email honours the email preference for the same category.
 * Usable from controllers, services, jobs and webhooks alike. Never throws.
 */
class Notify {

    /**
     * @param int    $user_id           recipient
     * @param string $category          a key of NotificationPrefsModel::$categories
     * @param bool   $email_if_offline  only email when the recipient is not active right now (DMs)
     * @param bool   $force_email       ignore the email preference (account safety notices)
     */
    public static function send($user_id, $category, $title, $body = '', $link = '', $icon = '', $email_if_offline = false, $force_email = false, $image = ''): void {
        try {
            $user_id = (int) $user_id;
            if ($user_id <= 0 || (string) $title === '') { return; }

            // On-site feed (push re-checks the in-platform pref and no-ops if opted out).
            (new UserNotificationsModel())->push($user_id, $category, $title, $body, $link, $icon);

            if (!$force_email) {
                $prefs = (new NotificationPrefsModel())->get_prefs_map($user_id);
                if (empty($prefs[$category]['email'])) { return; }
            }
            $rows = (new UsersModel())->get_user_by_id($user_id);
            $u    = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            if (!$u || (string) ($u['user_email'] ?? '') === '') { return; }
            if ($email_if_offline && self::is_online($u)) { return; }
            (new NotificationsModel())->send_notification_email(
                (string) $u['user_email'],
                trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')),
                (string) $title, (string) $body, (string) $link, (string) $image);
        } catch (\Throwable $e) {
            error_log('[notify] ' . $category . ': ' . $e->getMessage());
        }
    }

    /** Same notice to many people (broadcast recipients, followers). */
    public static function many(array $user_ids, $category, $title, $body = '', $link = '', $icon = '', $email_if_offline = false, $image = ''): int {
        $n = 0;
        foreach (array_values(array_unique(array_filter(array_map('intval', $user_ids)))) as $uid) {
            self::send($uid, $category, $title, $body, $link, $icon, $email_if_offline, false, $image);
            $n++;
        }
        return $n;
    }

    /** Online = active within the last 5 minutes (last_active_at is UTC). */
    public static function is_online(array $user_row): bool {
        $la = $user_row['last_active_at'] ?? null;
        return $la !== null && strtotime((string) $la . ' UTC') >= time() - 300;
    }

    /** Platform display name for an account (no PII), '' if unknown. */
    public static function name_of($user_id): string {
        try {
            $m = (new MessagesModel())->identity_map(array((int) $user_id));
            return (string) ($m[(int) $user_id]['name'] ?? '');
        } catch (\Throwable $e) { return ''; }
    }

    public static function handle_of($user_id): string {
        try {
            $m = (new MessagesModel())->identity_map(array((int) $user_id));
            return (string) ($m[(int) $user_id]['handle'] ?? '');
        } catch (\Throwable $e) { return ''; }
    }

    /** Credits in a notice ("49 credits"). Everything inside the platform is credits; dollars only for card charges and cash-outs. */
    public static function credits($n): string { return self::credit_count($n); }

    /** A count of credits for refunds ("100 credits"): a refund goes back to the wallet as credits, never to a card. */
    public static function credit_count($n): string { return Price::credits($n); }
}
