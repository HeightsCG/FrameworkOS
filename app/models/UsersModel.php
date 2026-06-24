<?php
class UsersModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function username_exists($u_name){
        return parent::select(
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
    }

    public function email_exists($user_email){
        return parent::select(
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
    }

    public function generate_unique_username($first_name, $last_name){
        $base = strtolower($first_name . $last_name);
        $base = preg_replace('/[^a-z0-9]/', '', $base);
        if (strlen($base) < 3) {
            $base = ($base === '' ? 'user' : $base . 'user');
        }
        $base = substr($base, 0, 30);

        $candidate = $base;
        $suffix    = 1;
        while ($this->username_exists($candidate)) {
            $suffix++;
            $tail      = (string) $suffix;
            $candidate = substr($base, 0, 30 - strlen($tail)) . $tail;
        }
        return $candidate;
    }

    public function create_user($u_name, $enc_p_word, $first_name, $last_name, $user_email){
        return parent::insert('user_accounts', array(
            'u_name'      => $u_name,
            'p_word'      => $enc_p_word,
            'first_name'  => $first_name,
            'last_name'   => $last_name,
            'user_email'  => $user_email,
            'user_status' => 'active',
            'deleted'     => 0,
        ));
    }

    public function get_user_by_id($user_id){
        return parent::select(
            "SELECT 
                u.user_id,`
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

    public function get_user_by_username($u_name){
        return parent::select(
            "SELECT 
                u.user_id,
                u.* 
            FROM 
                user_accounts u
            WHERE 
                u.u_name = :u_name 
                AND 
                u.deleted = 0",
            array('u_name' => $u_name)
        );
    }

}
