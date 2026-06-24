<?php
class UsersModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function username_exists($u_name){
        $rows = parent::select(
            "SELECT 
                u.*
            FROM 
                user_accounts u
            WHERE 
                u.u_name = :u_name 
                AND 
                u.deleted = 0",
            array('u_name' => $u_name)
        );
        return is_array($rows) && count($rows) > 0;
    }

    public function email_exists($user_email){
        $rows = parent::select(
            "SELECT 
                u.*
            FROM 
                user_accounts u
            WHERE 
                u.user_email = :user_email 
                AND 
                u.deleted = 0",
            array('user_email' => $user_email)
        );
        return is_array($rows) && count($rows) > 0;
    }

    public function get_user_by_id($user_id){
        return parent::select(
            "SELECT 
                u.* 
            FROM 
                user_accounts u
            WHERE 
                u.user_id = :user_id 
                AND 
                u.deleted = 0",
            array('user_id' => (int) $user_id)
        );
    }

}
