<?php
class Users extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get_user_by_username($u_name){
        $sql = "SELECT
                    u.*
                FROM
                    user_accounts u
                WHERE
                    u.u_name = :u_name
                    and
                    u.deleted = 0";
        return parent::select($sql, array('u_name' => $u_name));
    }

    public function get_user_by_id($user_id){
        $sql = "SELECT
                    u.*
                FROM
                    user_accounts u
                WHERE
                    u.user_id = :user_id
                    and
                    u.deleted = 0";
        return parent::select($sql, array('user_id' => $user_id));
    }

    public function get_user_by_email($user_email){
        $sql = "SELECT
                    u.*
                FROM
                    user_accounts u
                WHERE
                    u.user_email = :user_email
                    and
                    u.deleted = 0";
        return parent::select($sql, array('user_email' => $user_email));
    }

    public function get_all_users(){
        $sql = "SELECT
                    u.*,
                    r.role_name,
                    c.company_name
                FROM
                    user_accounts u
                        left join user_roles r on u.role_id = r.id and r.deleted = 0
                        left join companies c on u.company_id = c.id and c.deleted = 0
                WHERE
                    u.deleted = 0
                ORDER BY
                    u.u_name";
        return parent::select($sql);
    }

    public function add_user($data){
        return parent::insert('user_accounts', $data);
    }

    public function update_user($user_id, $data){
        return parent::update('user_accounts', $data, 'user_id = :w_user_id', array('w_user_id' => $user_id));
    }

    public function delete_user($user_id){
        $data = array(
            'deleted'    => 1,
            'updated_by' => (int) Session::get('user_id'),
        );
        return parent::update('user_accounts', $data, 'user_id = :w_user_id', array('w_user_id' => $user_id));
    }

    // ---- Passwords ---------------------------------------------------------

    /** Random temp password (for admin-issued accounts). Returns plaintext. */
    public function generate_password(){
        $core = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'Aa'), '=');
        return $core . 'Aa1!';
    }

    /** Server-side password policy. Returns array(valid, message). */
    public function validate_password($password){
        if (strlen($password) < 8) {
            return array('valid' => false, 'message' => 'Password must be at least 8 characters');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return array('valid' => false, 'message' => 'Password must include at least one letter and one number');
        }
        return array('valid' => true, 'message' => '');
    }

    public function verify_current_password($user_id, $current_password){
        $user = $this->get_user_by_id($user_id);
        if (is_array($user) && count($user) === 1) {
            return password_verify($current_password, $user[0]['p_word']);
        }
        return false;
    }

    /**
     * Finish a password change/reset: store the new hash, clear the force-change
     * flag and any outstanding reset token (single-use). $enc_password is already
     * hashed by the caller.
     */
    public function change_password($user_id, $enc_password){
        $data = array(
            'p_word'              => $enc_password,
            'reset_pw'            => 0,
            'reset_token'         => null,
            'reset_token_expires' => null,
            'updated_by'          => (int) Session::get('user_id'),
        );
        return parent::update('user_accounts', $data, 'user_id = :w_user_id', array('w_user_id' => $user_id));
    }

    /** Admin sets a password and forces the user to change it next login. */
    public function admin_reset_password($user_id, $enc_password){
        $data = array(
            'p_word'     => $enc_password,
            'reset_pw'   => 1,
            'updated_by' => (int) Session::get('user_id'),
        );
        return parent::update('user_accounts', $data, 'user_id = :w_user_id', array('w_user_id' => $user_id));
    }

    // ---- Reset tokens (email-link reset) -----------------------------------

    /**
     * Issue a single-use reset token (1h). The RAW token is returned for the
     * email link; only its SHA-256 hash is stored, so a DB read can't reuse it.
     */
    public function set_reset_token($user_id){
        $token = bin2hex(random_bytes(32));
        $data = array(
            'reset_token'         => hash('sha256', $token),
            'reset_token_expires' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'updated_by'          => (int) Session::get('user_id'),
        );
        parent::update('user_accounts', $data, 'user_id = :w_user_id', array('w_user_id' => $user_id));
        return $token;
    }

    /** Resolve a raw reset token (matched by hash) to its user, if unexpired. */
    public function get_user_by_reset_token($token){
        $sql = "SELECT
                    u.*
                FROM
                    user_accounts u
                WHERE
                    u.reset_token = :reset_token
                    and
                    u.reset_token_expires > :now
                    and
                    u.deleted = 0";
        return parent::select($sql, array('reset_token' => hash('sha256', $token), 'now' => date('Y-m-d H:i:s')));
    }

}
