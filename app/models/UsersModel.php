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

    public function create_user($u_name, $enc_p_word, $first_name, $last_name, $user_email, $created_by=0, $updated_by=0){
        return parent::insert('user_accounts', array(
            'u_name'      => $u_name,
            'p_word'      => $enc_p_word,
            'first_name'  => $first_name,
            'last_name'   => $last_name,
            'user_email'  => $user_email,
            'user_status' => 'Active',
            'created_by'  => $created_by,
            'updated_by'  => $updated_by,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
            'deleted'     => 0,
        ));
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

    public function update_profile($user_id, $first_name, $last_name, $user_email, $user_phone, $business_name, $website_url, $updated_by=0){
        return parent::update(
            'user_accounts',
            array(
                'first_name'    => $first_name,
                'last_name'     => $last_name,
                'user_email'    => $user_email,
                'user_phone'    => $user_phone,
                'business_name' => $business_name,
                'website_url'   => $website_url,
                'updated_at'    => date('Y-m-d H:i:s'),
                'updated_by'    => $updated_by,
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    public function get_role_id_by_name($role_name){
        $rows = parent::select(
            "SELECT id FROM user_roles WHERE role_name = :role_name",
            array('role_name' => $role_name)
        );
        return (is_array($rows) && count($rows) === 1) ? (int) $rows[0]['id'] : 0;
    }

    /** Promote a user to the Creator role and record agreement acceptance + start date. */
    public function make_creator($user_id, $updated_by=0){
        $creator_role_id = $this->get_role_id_by_name('Creator');
        if ($creator_role_id === 0) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        return parent::update(
            'user_accounts',
            array(
                'role_id'                       => $creator_role_id,
                'creator_since'                 => $now,
                'creator_agreement_accepted_at' => $now,
                'updated_at'                    => $now,
                'updated_by'                    => $updated_by,
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    /**
     * Permanently hard-delete everything a creator owns. NO soft delete — rows are
     * removed outright. This is the single place to wire in each creator content
     * table as it is built (content items, services, events, subscription tiers,
     * external links, etc.). Returns the number of rows deleted.
     *
     * NOTE: none of those content tables exist yet, so this currently deletes
     * nothing. Add a parent::delete_all(...) call here for each table as it lands.
     */
    public function hard_delete_creator_content($user_id){
        $deleted = 0;
        // Example (enable when the table exists):
        // $deleted += parent::delete_all('creator_content', 'user_id = :uid', array('uid' => (int) $user_id));
        return $deleted;
    }

    /** Revert a creator back to a regular User and clear creator metadata. */
    public function revert_creator($user_id, $updated_by=0){
        $user_role_id = $this->get_role_id_by_name('User');
        if ($user_role_id === 0) {
            return false;
        }
        return parent::update(
            'user_accounts',
            array(
                'role_id'                       => $user_role_id,
                'creator_since'                 => null,
                'creator_agreement_accepted_at' => null,
                'updated_at'                    => date('Y-m-d H:i:s'),
                'updated_by'                    => $updated_by,
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    public function set_adult_content_enabled($user_id, $enabled, $updated_by=0){
        return parent::update(
            'user_accounts',
            array(
                'adult_content_enabled' => $enabled ? 1 : 0,
                'updated_at'            => date('Y-m-d H:i:s'),
                'updated_by'            => $updated_by,
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    public function change_password($user_id, $enc_password, $updated_by=0){
        return parent::update(
            'user_accounts',
            array(
                'p_word' => $enc_password,
                'reset_pw' => 0,
                'reset_token' => null,
                'reset_token_expires' => null,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $updated_by
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    public function delete_account($user_id, $updated_by=0){
        return parent::update(
            'user_accounts',
            array(
                'deleted'    => 1,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $updated_by,
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    public function set_reset_token($user_id){
        $token = bin2hex(random_bytes(32));
        parent::update(
            'user_accounts',
            array(
                'reset_token'         => $token,
                'reset_token_expires' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                'updated_at'          => date('Y-m-d H:i:s'),
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
        return $token;
    }

    public function get_user_by_reset_token($reset_token){
        return parent::select(
            "SELECT
                u.*
            FROM
                user_accounts u
            WHERE
                u.reset_token = :reset_token
                AND
                u.reset_token_expires > NOW()
                AND
                u.deleted = 0",
            array('reset_token' => $reset_token)
        );
    }

}
