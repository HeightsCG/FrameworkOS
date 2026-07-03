<?php
class NotificationPrefsModel extends Model {

    /**
     * Notification categories (PRD 27.2). This is the authoritative key list and
     * per-channel defaults. Everything is opt-out (on by default) except
     * marketing, which is opt-in. Labels/descriptions live in the view.
     * Each entry: array(in_platform_default, email_default).
     */
    public static $categories = array(
        'messages'           => array(1, 1),
        'creator_activity'   => array(1, 1),
        'broadcasts'         => array(1, 1),
        'purchases'          => array(1, 1),
        'subscriptions'      => array(1, 1),
        'events'             => array(1, 1),
        'services'           => array(1, 1),
        'credits'            => array(1, 1),
        'auto_replenishment' => array(1, 1),
        'refunds'            => array(1, 1),
        'security'           => array(1, 1),
        'system'             => array(1, 1),
        'marketing'          => array(0, 0),
    );

    public function __construct(){
        parent::__construct();
    }

    /**
     * Returns a category => array('in_platform' => bool, 'email' => bool) map for
     * the user, with defaults filled in for any category without a saved row.
     */
    public function get_prefs_map($user_id){
        $saved = array();
        $rows  = parent::select(
            "SELECT category, in_platform, email FROM user_notification_prefs WHERE user_id = :user_id",
            array('user_id' => (int) $user_id)
        );
        foreach ($rows as $row) {
            $saved[$row['category']] = $row;
        }

        $map = array();
        foreach (self::$categories as $key => $defaults) {
            if (isset($saved[$key])) {
                $map[$key] = array(
                    'in_platform' => (int) $saved[$key]['in_platform'] === 1,
                    'email'       => (int) $saved[$key]['email'] === 1,
                );
            } else {
                $map[$key] = array(
                    'in_platform' => $defaults[0] === 1,
                    'email'       => $defaults[1] === 1,
                );
            }
        }
        return $map;
    }

    /**
     * Upserts the given preferences. $incoming is category => array('in_platform'
     * => 0/1, 'email' => 0/1). Unknown categories are ignored.
     */
    public function save_prefs($user_id, $incoming){
        $now = date('Y-m-d H:i:s');
        foreach ($incoming as $category => $channels) {
            if (!isset(self::$categories[$category])) {
                continue;
            }
            $in_platform = !empty($channels['in_platform']) ? 1 : 0;
            $email       = !empty($channels['email']) ? 1 : 0;

            $existing = parent::select(
                "SELECT id FROM user_notification_prefs WHERE user_id = :user_id AND category = :category",
                array('user_id' => (int) $user_id, 'category' => $category)
            );

            if (is_array($existing) && count($existing) === 1) {
                parent::update(
                    'user_notification_prefs',
                    array(
                        'in_platform' => $in_platform,
                        'email'       => $email,
                        'updated_at'  => $now,
                    ),
                    'user_id = :user_id AND category = :category',
                    array('user_id' => (int) $user_id, 'category' => $category)
                );
            } else {
                parent::insert('user_notification_prefs', array(
                    'user_id'     => (int) $user_id,
                    'category'    => $category,
                    'in_platform' => $in_platform,
                    'email'       => $email,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ));
            }
        }
        return true;
    }

}
