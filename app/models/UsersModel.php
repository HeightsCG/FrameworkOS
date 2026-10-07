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
        if (!preg_match('/[a-z]/', $base)) { $base = ''; }   // a handle needs a letter: never "000"
        if (strlen($base) < 3) {
            $base = ($base === '' ? 'member' : $base . 'user');
        }
        $base = substr($base, 0, 30);

        $candidate = $base;
        $suffix    = 1;
        while ($this->username_exists($candidate) || in_array($candidate, UsernameModel::$reserved_words, true)) {
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
            'user_email'     => $user_email,
            'user_status'    => 'Active',
            'reset_pw'       => 0,   // self-registered users chose their own password — never force a reset
            'email_verified' => 0,   // must confirm their email before they can sign in
            'created_by'     => $created_by,
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

    /**
     * Resolve a sign-in identifier that may be EITHER a username or an email.
     * Signup collects an email and auto-generates the username, so users know
     * their email, not their handle — login and password reset both use this.
     * (Native prepares can't reuse a placeholder, hence :id1/:id2.)
     */
    public function get_user_by_login($identifier){
        return parent::select(
            "SELECT u.user_id, u.*
             FROM user_accounts u
             WHERE (u.u_name = :id1 OR u.user_email = :id2)
               AND u.deleted = 0",
            array('id1' => $identifier, 'id2' => $identifier)
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

    /** Live creator handles for the public sitemap: verified, active, not deleted. */
    public function list_public_creators(){
        return parent::select(
            "SELECT
                u.u_name,
                GREATEST(COALESCE(u.updated_at, u.created_at), COALESCE(p.updated_at, u.created_at)) AS last_modified
            FROM
                user_accounts u
                JOIN user_roles r ON r.id = u.role_id
                LEFT JOIN creator_profiles p ON p.user_id = u.user_id
            WHERE
                r.role_name = 'Creator'
                AND u.deleted = 0
                AND u.user_status = 'Active'
                AND u.email_verified = 1
                AND " . Plan::paid_sql('u') . "
                AND u.u_name <> ''
            ORDER BY
                u.u_name"
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

    public function get_role_name_by_id($role_id){
        $rows = parent::select(
            "SELECT role_name FROM user_roles WHERE id = :id",
            array('id' => (int) $role_id)
        );
        return (is_array($rows) && count($rows) === 1) ? (string) $rows[0]['role_name'] : '';
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

    /** Record that the creator was just active (presence). Stored in UTC. */
    public function touch_last_active($user_id){
        return parent::update(
            'user_accounts',
            array('last_active_at' => gmdate('Y-m-d H:i:s')),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    public function set_content_timezone($user_id, $tz, $updated_by=0){
        return parent::update(
            'user_accounts',
            array(
                'content_timezone' => (string) $tz,
                'updated_at'       => date('Y-m-d H:i:s'),
                'updated_by'       => $updated_by,
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

    /** $ttl: '+1 hour' for a forgotten password; team invites get '+7 days' so the invitee has time to open it. */
    public function set_reset_token($user_id, $ttl = '+1 hour'){
        $token = bin2hex(random_bytes(32));
        parent::update(
            'user_accounts',
            array(
                'reset_token'         => $token,
                'reset_token_expires' => date('Y-m-d H:i:s', strtotime((string) $ttl)),
                'updated_at'          => date('Y-m-d H:i:s'),
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
        return $token;
    }

    /** Mint (or refresh) an email-verification token, valid for 24 hours. Returns the token. */
    public function set_email_verify_token($user_id){
        $token = bin2hex(random_bytes(32));
        parent::update(
            'user_accounts',
            array(
                'email_verify_token'   => $token,
                'email_verify_expires' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                'updated_at'           => date('Y-m-d H:i:s'),
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
        return $token;
    }

    /**
     * The account holding this verification token, or empty. Expiry is NOT filtered
     * here — the caller compares email_verify_expires against PHP time so the check
     * doesn't depend on the DB session timezone, and so an expired vs. unknown token
     * can be reported (and logged) differently.
     */
    public function get_user_by_verify_token($token){
        return parent::select(
            "SELECT u.*
             FROM user_accounts u
             WHERE u.email_verify_token = :token
               AND u.deleted = 0",
            array('token' => (string) $token)
        );
    }

    /**
     * Mark an account's email confirmed. The token is deliberately KEPT until it
     * expires: mail clients and link scanners often open the link before the user
     * does, and burning the token on first use made the user's own click land on
     * "invalid or expired". A repeat visit now resolves to "already verified" instead.
     */
    public function mark_email_verified($user_id){
        return parent::update(
            'user_accounts',
            array(
                'email_verified'       => 1,
                'updated_at'           => date('Y-m-d H:i:s'),
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
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

    /** Every active admin's user id (in-platform notifications for review queues). */
    public function admin_ids(){
        $rows = parent::select("SELECT user_id FROM user_accounts WHERE is_admin = 1 AND deleted = 0");
        $out = array();
        foreach ((array) $rows as $r) { $out[] = (int) $r['user_id']; }
        return $out;
    }


    /** The account a Google login belongs to (google_sub = Google's stable account id). */
    public function get_by_google_sub($sub){
        $r = parent::select("SELECT u.* FROM user_accounts u WHERE u.google_sub = :s AND u.deleted = 0", array('s' => (string) $sub));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** The single account with this email, or null (none, or more than one — ambiguous, never guess). */
    public function get_one_by_email($email){
        $r = parent::select("SELECT u.* FROM user_accounts u WHERE u.user_email = :e AND u.deleted = 0", array('e' => (string) $email));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Google sign-in off for this account: it keeps its password (Google-made accounts set one with Forgot Password). */
    public function unlink_google($user_id){
        return parent::update('user_accounts', array('google_sub' => null, 'updated_at' => date('Y-m-d H:i:s')), 'user_id = :uid', array('uid' => (int) $user_id));
    }

    /** Link Google to an account. Google has verified the address, so the account's email counts as verified too. */
    public function link_google($user_id, $sub){
        return parent::update('user_accounts', array('google_sub' => (string) $sub, 'email_verified' => 1, 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :uid', array('uid' => (int) $user_id));
    }

    /** First-touch attribution from the cls_ft cookie (set by google_analytics.php), saved once at signup. */
    public function record_first_touch($user_id){
        $ft = json_decode((string) ($_COOKIE['cls_ft'] ?? ''), true);
        if (!is_array($ft)) { return; }
        $seen = strtotime((string) ($ft['at'] ?? ''));
        $this->set_acquisition($user_id, array(
            'acq_source' => $ft['s'] ?? '', 'acq_medium' => $ft['m'] ?? '', 'acq_campaign' => $ft['c'] ?? '', 'acq_term' => $ft['t'] ?? '',
            'acq_content' => $ft['n'] ?? '', 'acq_gclid' => $ft['g'] ?? '', 'acq_referrer' => $ft['r'] ?? '', 'acq_landing' => $ft['l'] ?? '',
            'acq_first_seen' => $seen ? gmdate('Y-m-d H:i:s', $seen) : null,
        ));
    }

    /** First-touch attribution for a new account (the cls_ft cookie), saved once at signup. */
    public function set_acquisition($user_id, array $a){
        $f = array();
        foreach (array('acq_source' => 100, 'acq_medium' => 100, 'acq_campaign' => 150, 'acq_term' => 150, 'acq_content' => 150, 'acq_gclid' => 255, 'acq_referrer' => 255, 'acq_landing' => 255) as $k => $max) {
            $v = trim((string) ($a[$k] ?? ''));
            $f[$k] = $v === '' ? null : mb_substr($v, 0, $max);
        }
        $f['acq_first_seen'] = !empty($a['acq_first_seen']) ? $a['acq_first_seen'] : null;
        parent::update('user_accounts', $f, 'user_id = :uid', array('uid' => (int) $user_id));
    }
}
