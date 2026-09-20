<?php
/**
 * In-platform notifications (PRD §27) — the delivery layer for the notification bell.
 * push() records a notification only if the recipient has that category's in-platform
 * channel enabled (NotificationPrefsModel). Email delivery is a separate channel and
 * stays with NotificationsModel; this is the on-site feed.
 */
class UserNotificationsModel extends Model {

    /** True when a notification with this title reached the user in the last $hours (dedupes repeated warnings). */
    public function recent_with_title($user_id, $title, $hours){
        $rows = parent::select("SELECT id FROM notifications WHERE user_id = :u AND title = :t AND created_at >= :since LIMIT 1",
            array('u' => (int) $user_id, 't' => (string) $title, 'since' => date('Y-m-d H:i:s', time() - (int) $hours * 3600)));
        return is_array($rows) && count($rows) > 0;
    }

    /** Deliver an in-platform notification (respects the user's per-category in-platform pref). */
    public function push($user_id, $category, $title, $body = '', $link = '', $icon = ''){
        $user_id = (int) $user_id;
        if ($user_id <= 0 || (string) $title === '') { return false; }
        $prefs = (new NotificationPrefsModel())->get_prefs_map($user_id);
        if (isset($prefs[$category]) && empty($prefs[$category]['in_platform'])) { return false; }   // opted out
        return (int) parent::insert('notifications', array(
            'user_id'    => $user_id,
            'category'   => mb_substr((string) $category, 0, 32),
            'icon'       => mb_substr((string) $icon, 0, 32),
            'title'      => mb_substr((string) $title, 0, 255),
            'body'       => mb_substr((string) $body, 0, 500),
            'link'       => mb_substr((string) $link, 0, 255),
            'is_read'    => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    public function unread_count($user_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND is_read = 0", array('u' => (int) $user_id));
        return is_array($r) && count($r) ? (int) $r[0]['n'] : 0;
    }

    public function recent($user_id, $limit = 15){
        $limit = max(1, min(50, (int) $limit));
        return (array) parent::select(
            "SELECT id, category, icon, title, body, link, is_read, created_at
             FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT $limit",
            array('u' => (int) $user_id));
    }

    public function mark_all_read($user_id){
        return parent::update('notifications', array('is_read' => 1),
            'user_id = :u AND is_read = 0', array('u' => (int) $user_id));
    }

    public function mark_read($user_id, $id){
        return parent::update('notifications', array('is_read' => 1),
            'user_id = :u AND id = :id', array('u' => (int) $user_id, 'id' => (int) $id));
    }
}
