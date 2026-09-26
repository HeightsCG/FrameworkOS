<?php
class LoginAttemptsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function record($ip_address, $identifier, $action){
        return parent::insert('login_attempts', array(
            'ip_address' => $ip_address,
            'identifier' => $identifier,
            'action'     => $action,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    /** Recent attempts for one account (login name / user id), whatever the IP: the per-account limit. */
    public function count_recent_for($identifier, $action, $minutes){
        $rows = parent::select(
            "SELECT COUNT(*) AS cnt FROM login_attempts WHERE LOWER(identifier) = LOWER(:i) AND action = :a AND created_at > :since",
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
