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

    /** A neutral starting handle ("creator" + 6 digits), never built from the email or name. */
    public static function is_neutral_username($u_name): bool {
        return (bool) preg_match('/^creator[0-9]{6}$/', (string) $u_name);
    }

    public function neutral_username(){
        do {
            $candidate = 'creator' . random_int(100000, 999999);
        } while ($this->username_exists($candidate));
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
    /**
     * Creator profiles for the sitemap: active paid creators whose page lives on this host and has something on
     * it. A creator with an active custom domain is left out (their /@handle here redirects to that domain, which
     * carries its own sitemap), and so is an empty profile (no published post, membership tier, service or event):
     * a page of empty states is not worth a crawl slot. last_modified also follows their newest published post.
     */
    /**
     * Does this creator's page have anything on it worth indexing: a published post, an active membership tier, a published
     * service or a published event? The same test list_public_creators() applies for the sitemap, so a page of empty states is
     * neither listed nor indexed (SEO audit 2026-10-12).
     */
    public function has_public_content($user_id){
        $r = parent::select(
            "SELECT (
                   EXISTS (SELECT 1 FROM posts ps WHERE ps.creator_id = :u1 AND ps.state = 'published' AND ps.on_cls = 1)
                OR EXISTS (SELECT 1 FROM creator_plans cp WHERE cp.user_id = :u2 AND cp.is_active = 1)
                OR EXISTS (SELECT 1 FROM services sv WHERE sv.creator_id = :u3 AND sv.status = 'published')
                OR EXISTS (SELECT 1 FROM events ev WHERE ev.creator_id = :u4 AND ev.status = 'published')
            ) AS has_content",
            array('u1' => (int) $user_id, 'u2' => (int) $user_id, 'u3' => (int) $user_id, 'u4' => (int) $user_id)
        );
        return !empty($r[0]['has_content']);
    }

    public function list_public_creators(){
        return parent::select(
            "SELECT
                u.u_name,
                GREATEST(COALESCE(u.updated_at, u.created_at), COALESCE(p.updated_at, u.created_at),
                         COALESCE((SELECT MAX(COALESCE(ps.updated_at, ps.created_at)) FROM posts ps WHERE ps.creator_id = u.user_id AND ps.state = 'published' AND ps.on_cls = 1), u.created_at)) AS last_modified
            FROM
                user_accounts u
                JOIN user_roles r ON r.id = u.role_id
                LEFT JOIN creator_profiles p ON p.user_id = u.user_id
            WHERE
                r.role_name = 'Creator'
                AND u.deleted = 0
                AND u.user_status = 'Active'
                AND u.email_verified = 1
                AND u.is_demo = 0
                AND " . Plan::paid_sql('u') . "
                AND u.u_name <> ''
                AND NOT EXISTS (SELECT 1 FROM creator_domains d WHERE d.user_id = u.user_id AND d.deleted = 0 AND d.status = 'active')
                AND (
                       EXISTS (SELECT 1 FROM posts ps WHERE ps.creator_id = u.user_id AND ps.state = 'published' AND ps.on_cls = 1)
                    OR EXISTS (SELECT 1 FROM creator_plans cp WHERE cp.user_id = u.user_id AND cp.is_active = 1)
                    OR EXISTS (SELECT 1 FROM services sv WHERE sv.creator_id = u.user_id AND sv.status = 'published')
                    OR EXISTS (SELECT 1 FROM events ev WHERE ev.creator_id = u.user_id AND ev.status = 'published')
                )
            ORDER BY
                u.u_name"
        );
    }

    /** Every account with a connected Stripe account (for the one-off profile sync). */
    public function with_connect_accounts(): array {
        $r = parent::select("SELECT user_id, u_name, stripe_connect_account_id FROM user_accounts WHERE stripe_connect_account_id IS NOT NULL AND stripe_connect_account_id <> ''");
        return is_array($r) ? $r : array();
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

    /** Record Creator Agreement acceptance (date, version, ip): at a Creator/Studio checkout or the Settings backstop. Role and creator_since unchanged. */
    public function accept_creator_agreement($user_id, $updated_by=0, $version='', $ip=''){
        $now = gmdate('Y-m-d H:i:s');
        return parent::update(
            'user_accounts',
            array('creator_agreement_accepted_at' => $now, 'creator_agreement_version' => (string) $version === '' ? null : mb_substr((string) $version, 0, 20),
                  'creator_agreement_ip' => (string) $ip === '' ? null : mb_substr((string) $ip, 0, 45), 'updated_at' => $now, 'updated_by' => $updated_by),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    /** Promote a user to the Creator role and record the start date. The Creator Agreement is accepted at the Creator/Studio checkout. */
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
                'creator_agreement_version'     => null,
                'creator_agreement_ip'          => null,
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
        $done = parent::update(
            'user_accounts',
            array(
                'deleted'    => 1,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $updated_by,
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
        $this->release_identifiers($user_id);
        (new CreatorDomainsModel())->remove_all_for_user((int) $user_id);   // custom domains can be claimed again
        return $done;
    }

    /**
     * Free a soft-deleted account's unique handle and Google link so the same person (or a new one) can sign up,
     * be re-invited or take the handle. The old handle is archived in username_history (not reserved).
     */
    public function release_identifiers($user_id){
        $user_id = (int) $user_id;
        $rows = parent::select("SELECT u_name FROM user_accounts WHERE user_id = :u", array('u' => $user_id));
        if (!is_array($rows) || count($rows) !== 1) { return false; }
        $old  = (string) $rows[0]['u_name'];
        $free = UsernameModel::DELETED_PREFIX . $user_id;
        $now  = date('Y-m-d H:i:s');
        if ($old !== '' && $old !== $free) {
            parent::insert('username_history', array('user_id' => $user_id, 'u_name' => $old, 'changed_at' => $now, 'reserved_until' => $now));
        }
        return parent::update('user_accounts', array('u_name' => $free, 'google_sub' => null), 'user_id = :u', array('u' => $user_id));
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
        Analytics::event('email_verified', array('user_id' => (int) $user_id));
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

    /**
     * The paid-ad touch behind a signup (2026-10-16): the cls_ad cookie /start sets from its own URL (utm_* and ttclid,
     * last ad landing wins), else just the TikTok click id the pixel block keeps in cls_ttclid. Kept apart from acq_*.
     */
    public function record_ad_touch($user_id){
        $ad = json_decode((string) ($_COOKIE['cls_ad'] ?? ''), true);
        $ad = is_array($ad) ? $ad : array();
        $tt = trim((string) ($ad['tt'] ?? '')); if ($tt === '') { $tt = trim((string) ($_COOKIE['cls_ttclid'] ?? '')); }
        $f = array();
        foreach (array('ad_source' => array('s', 100), 'ad_medium' => array('m', 100), 'ad_campaign' => array('c', 150), 'ad_content' => array('n', 150), 'ad_term' => array('t', 150), 'ad_landing' => array('l', 255)) as $col => $k) {
            $v = trim(is_string($ad[$k[0]] ?? null) ? $ad[$k[0]] : '');
            $f[$col] = $v === '' ? null : mb_substr($v, 0, $k[1]);
        }
        $f['ad_ttclid'] = ($tt !== '' && preg_match('/^[A-Za-z0-9._-]{1,500}$/', $tt)) ? $tt : null;
        if (count(array_filter($f, function ($v) { return $v !== null; })) === 0) { return; }
        $seen = strtotime((string) ($ad['at'] ?? ''));
        $f['ad_seen'] = $seen ? gmdate('Y-m-d H:i:s', $seen) : null;
        parent::update('user_accounts', $f, 'user_id = :uid', array('uid' => (int) $user_id));
    }

    /**
     * TikTok CompleteRegistration (2026-10-16): a new account gets its event id here (email signup, Google signup, team
     * invite). The same id goes to the browser pixel and to the server Events API copy, so TikTok counts the signup once.
     */
    public function start_tt_registration($user_id): string {
        $id = 'cls_reg_' . bin2hex(random_bytes(12));
        parent::update('user_accounts', array('tt_reg_event_id' => $id), 'user_id = :uid', array('uid' => (int) $user_id));
        return $id;
    }

    /**
     * The one time the browser is handed the event: marks it sent and returns what the pixel needs (event id, SHA-256 of
     * the lowercase trimmed email, SHA-256 of the account id), or null when there is nothing to send: an account from
     * before this change, or one already handed out (a refresh, a later sign-in, a second tab).
     */
    public function claim_tt_registration($user_id): ?array {
        $uid = (int) $user_id;
        if ($uid <= 0) { return null; }
        $n = parent::update('user_accounts', array('tt_reg_sent_at' => gmdate('Y-m-d H:i:s')), 'user_id = :uid AND tt_reg_event_id IS NOT NULL AND tt_reg_sent_at IS NULL', array('uid' => $uid));
        if ((int) $n !== 1) { return null; }
        $rows = parent::select('SELECT tt_reg_event_id, user_email FROM user_accounts WHERE user_id = :uid', array('uid' => $uid));
        if (!is_array($rows) || count($rows) !== 1) { return null; }
        $email = strtolower(trim((string) $rows[0]['user_email']));
        return array('event_id' => (string) $rows[0]['tt_reg_event_id'], 'email' => $email !== '' ? hash('sha256', $email) : '', 'external_id' => hash('sha256', (string) $uid));
    }

    /** The signup came with the founding trial offer (?founding=1 / cls_founding): verify_email hands the flag to whichever browser opens the link. */
    public function set_signup_founding($user_id){
        return parent::update('user_accounts', array('signup_founding' => 1), 'user_id = :uid', array('uid' => (int) $user_id));
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

    /** Founding creator flag (Founding::activate / lapse). */
    public function set_founding($user_id, $on){
        return parent::update('user_accounts', array('is_founding' => $on ? 1 : 0), 'user_id = :uid', array('uid' => (int) $user_id));
    }

    /** Clean ?plan= / ?role= / ?ref= values: plan creator|studio, role creator|fan, ref a handle; anything else is ''. */
    public static function signup_params($in): array {
        $in   = is_array($in) ? $in : array();
        $plan = strtolower(trim((string) ($in['plan'] ?? '')));
        $role = strtolower(trim((string) ($in['role'] ?? '')));
        $ref  = strtolower(ltrim(trim((string) ($in['ref'] ?? '')), '@'));
        return array(
            'plan' => in_array($plan, array('creator', 'studio'), true) ? $plan : '',
            'role' => in_array($role, array('creator', 'fan'), true) ? $role : '',
            'ref'  => preg_match('/^[a-z0-9_.-]{1,40}$/', $ref) ? $ref : '',
        );
    }

    /** The signup params kept in the cls_signup cookie (set by IndexController / landing.js). */
    public static function signup_cookie(): array {
        return self::signup_params(json_decode((string) ($_COOKIE['cls_signup'] ?? ''), true));
    }

    /** Used once an account is made from them: expire cls_signup so a later sign-up in this browser starts clean. */
    public static function clear_signup_cookie(): void {
        setcookie('cls_signup', '', array('expires' => time() - 3600, 'path' => '/', 'secure' => strpos(Main::get_base_domain(), 'https:') === 0, 'httponly' => false, 'samesite' => 'Lax'));
        unset($_COOKIE['cls_signup']);
    }

    /** A referring creator by handle: a Creator with a public page (paid plan), active, verified, not a demo. 0 when none. */
    public function referrer_id_by_handle($handle){
        if ((string) $handle === '') { return 0; }
        $r = parent::select(
            "SELECT u.user_id
             FROM user_accounts u
             JOIN user_roles r ON r.id = u.role_id
             WHERE u.u_name = :h AND r.role_name = 'Creator' AND u.deleted = 0 AND u.user_status = 'Active'
               AND u.email_verified = 1 AND u.is_demo = 0 AND " . Plan::paid_sql('u'),
            array('h' => (string) $handle)
        );
        return (is_array($r) && count($r) === 1) ? (int) $r[0]['user_id'] : 0;
    }

    /**
     * Save the signup params on a new account. role=creator with $make_creator makes it a Creator (the register form);
     * without it the account stays a User with signup_role 'creator'. The Creator Agreement is accepted at checkout.
     */
    public function record_signup_params($user_id, array $p, $make_creator = false){
        $user_id = (int) $user_id;
        $ref_id  = $this->referrer_id_by_handle((string) ($p['ref'] ?? ''));
        if ($ref_id === $user_id) { $ref_id = 0; }   // never their own referrer
        parent::update('user_accounts', array(
            'signup_plan'            => (string) ($p['plan'] ?? '') === '' ? null : (string) $p['plan'],
            'signup_role'            => (string) ($p['role'] ?? '') === '' ? null : (string) $p['role'],
            'referred_by_creator_id' => $ref_id > 0 ? $ref_id : null,
        ), 'user_id = :uid', array('uid' => $user_id));
        if ((string) ($p['role'] ?? '') === 'creator' && $make_creator) {
            $this->make_creator($user_id, $user_id);
            $fresh = $this->get_user_by_id($user_id);
            if (is_array($fresh) && count($fresh) === 1) { Plan::grant_monthly($fresh[0]); }   // same as become_creatorAction (a no-op on Free)
        }
    }
}
