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
