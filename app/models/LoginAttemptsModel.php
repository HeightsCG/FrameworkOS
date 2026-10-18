<?php
class LoginAttemptsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** Purged now and then on write (no cron). The limits read a day at most; the admin user page's sign-in history reads the rest. */
    const KEEP_DAYS = 30;

    public function record($ip_address, $identifier, $action){
        $id = parent::insert('login_attempts', array(
            'ip_address' => $ip_address,
            'identifier' => $identifier,
            'action'     => $action,
            'created_at' => date('Y-m-d H:i:s'),
        ));
        if (random_int(1, 200) === 1) { $this->purge(self::KEEP_DAYS); }
        return $id;
    }

    public function purge($keep_days){
        return parent::sql("DELETE FROM login_attempts WHERE created_at < :cut LIMIT 5000", array(':cut' => date('Y-m-d H:i:s', strtotime('-' . max(1, (int) $keep_days) . ' days'))));
    }

    /** Recent attempts for one account (login name / user id), whatever the IP: the per-account limit. The column's collation is case-insensitive, so no LOWER() (it would skip the index). */
    public function count_recent_for($identifier, $action, $minutes){
        $rows = parent::select(
            "SELECT COUNT(*) AS cnt FROM login_attempts WHERE identifier = :i AND action = :a AND created_at > :since",
            array('i' => (string) $identifier, 'a' => (string) $action, 'since' => date('Y-m-d H:i:s', strtotime('-' . (int) $minutes . ' minutes'))));
        return isset($rows[0]['cnt']) ? (int) $rows[0]['cnt'] : 0;
    }

    public function count_recent($ip_address, $action, $minutes){
        $since = date('Y-m-d H:i:s', strtotime('-' . (int) $minutes . ' minutes'));
        $rows = parent::select(
            "SELECT
                COUNT(*) AS cnt
            FROM
                login_attempts
            WHERE
                ip_address = :ip_address
                AND
                action = :action
                AND
                created_at > :since",
            array(
                'ip_address' => $ip_address,
                'action'     => $action,
                'since'      => $since,
            )
        );
        return isset($rows[0]['cnt']) ? (int) $rows[0]['cnt'] : 0;
    }

}
