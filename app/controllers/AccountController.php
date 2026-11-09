<?php
class AccountController extends Controller {

    public $protected = 1;
    private $userModel;

    public function __construct(){
        parent::__construct();
        $this->userModel = new UsersModel();
    }

    public function settingsAction(){
        $user = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user) || count($user) !== 1) {
            self::bounce();
        }
        $user = $user[0];   // the acting user — personal settings (security, notifications, AI credits)

        // Creator config belongs to the account OWNER (a collaborator acts on it). Role decides
        // which sections show: content (Editor+) = profile/brand; manage (Manager+) = plans/integrations;
        // payouts/billing = owner only.
        $creator_id      = Permissions::creator_id();
        $owner           = $user;
        if (Permissions::is_team_member()) {
            $orows = $this->userModel->get_user_by_id($creator_id);
            $owner = (is_array($orows) && count($orows) === 1) ? $orows[0] : $user;
        }
        $can_act_creator  = Permissions::can_act_as_creator();
        $can_content      = $can_act_creator && Permissions::team_allows('content');   // Editor+
        $can_manage       = $can_act_creator && Permissions::team_allows('manage');    // Manager+
        $is_owner_creator = Permissions::is_owner_creator();                           // payouts / billing

        $can_post = Plan::can_social_post($owner);
        $this->view->can_trials = true;   // free trials are included on every plan

        // Platform display metadata (label, Font Awesome icon), in display order.
        $platform_meta = array(
            'linkedin'  => array('LinkedIn',    'fa-linkedin'),
            'bluesky'   => array('Bluesky',     'fa-bluesky'),
            'x'         => array('X (Twitter)', 'fa-x-twitter'),
            'facebook'  => array('Facebook',    'fa-facebook'),
            'instagram' => array('Instagram',   'fa-instagram'),
            'threads'   => array('Threads',     'fa-threads'),
            'tiktok'    => array('TikTok',      'fa-tiktok'),
            'youtube'   => array('YouTube',     'fa-youtube'),
            'pinterest' => array('Pinterest',   'fa-pinterest'),
        );

        // Notification categories (label, description). Keys/order match NotificationPrefsModel::$categories (PRD 27.2).
        $notification_meta = array(
            'messages'           => array('Messages',           'Direct messages, including automatic replies and welcome messages.'),
            'creator_activity'   => array('Creator activity',   'New posts from creators you follow, and new followers on your profile.'),
            'broadcasts'         => array('Broadcasts',         'Messages a creator sends to all their fans at once.'),
            'subscriptions'      => array('Subscriptions',      'Your Creator Link Studio plan: renewals, changes and failed payments.'),
            'credits'            => array('Credits',            'AI credit purchases and changes to your AI credit balance.'),
            'security'           => array('Security',           'Password and two-factor changes. Always emailed.'),
            'system'             => array('System',             'Verification decisions and account notices.'),
            'marketing'          => array('Marketing',          'Product news and promotions.'),
            'tips_offers'        => array('Tips and Offers',    'Getting-started tips and plan offers for your creator account.'),
        );

        // This user's connected social accounts, grouped by platform.
        $connected = array();
        if ($can_post) {
            $accountsModel = new SocialAccountsModel();
            foreach ($accountsModel->get_for_user($owner['user_id']) as $a) {
                if (($a['status'] ?? '') === 'connected') {
                    $connected[$a['platform']][] = $a;
                }
            }
        }

        $prefsModel   = new NotificationPrefsModel();
        $blocksModel  = new BlocksModel();
        $creator_role_id = $this->userModel->get_role_id_by_name('Creator');
        $is_creator      = $can_act_creator;   // show creator sections to collaborators too; role gates the specifics

        $this->view->user               = $user;
        $this->view->is_creator          = $is_creator;
        $this->view->can_content         = $can_content;
        $this->view->can_manage          = $can_manage;
        $this->view->is_owner_creator    = $is_owner_creator;
        // The Creator Agreement backstop: only a selling creator (paid plan) who never accepted; new Free creators accept at checkout.
        $this->view->needs_agreement     = $is_owner_creator && Plan::can_use_creator_features($user) && !CreatorAgreement::accepted((int) Session::get('user_id'));
        $this->view->creator_profile     = $can_content ? (new CreatorProfileModel())->get_for_user($owner['user_id']) : array();
        // Creator Directory: off, listed (showing now) or waiting (switched on, not eligible yet).
        $this->view->directory_state     = empty($this->view->creator_profile['directory_listed']) ? 'off'
            : ((new CreatorProfileModel())->directory_eligible($owner['user_id']) ? 'listed' : 'waiting');
        $this->view->directory_missing   = $this->view->directory_state === 'waiting' ? (new CreatorProfileModel())->directory_missing($owner['user_id']) : '';   // the label says which rule is missing
        $me_profile = (new CreatorProfileModel())->get_for_user((int) Session::get('user_id'));
        $this->view->my_avatar          = (string) ($me_profile['avatar_url'] ?? '');
        $this->view->creator_links       = $can_content ? (new CreatorLinksModel())->get_for_user($owner['user_id']) : array();
        $this->view->creator_brand       = $can_content ? (new CreatorBrandModel())->get_for_user($owner['user_id']) : array();
        $this->view->verified            = !empty($owner['verified']);
        $this->view->verif_state         = $is_owner_creator ? IdentityVerification::state((int) $owner['user_id']) : array('status' => 'none', 'reason' => '');
        $this->view->verif_status        = $this->view->verif_state['status'];
        $this->view->creator_terms       = $this->creator_terms(Main::site_name());
        $this->view->can_inbox           = $can_manage;   // inbox automation is included on every plan
        $this->view->inbox_settings      = $can_manage ? (new InboxSettingsModel())->get_for_creator($owner['user_id']) : InboxSettingsModel::defaults();
        $this->view->inbox_pending       = ($can_manage && $this->view->can_inbox) ? (new InboxRepliesModel())->count_pending($owner['user_id']) : 0;
        $this->view->claude_ok           = ClaudeService::configured();
        // AI replies are a per-plan switch (Free has none); welcome and trigger messages work on every plan.
        $this->view->inbox_ai            = Plan::has_feature($owner, 'inbox_ai');
        $inbox_up                        = PlanTiers::lowest_with_feature('inbox_ai');
        $this->view->inbox_ai_upgrade    = $inbox_up ? (string) $inbox_up['name'] : '';
        $this->view->can_social_post     = $can_post;
        $this->view->platform_meta       = $platform_meta;
        $this->view->notification_meta   = $notification_meta;
        $this->view->connected           = $connected;
        $this->view->mcp_connected       = $can_manage && (new ApiTokensModel())->has_active_for_user($owner['user_id']);
        $this->view->mcp_url             = Main::get_base_domain() . '/mcp';
        $this->view->notification_prefs  = $prefsModel->get_prefs_map($user['user_id']);
        $this->view->blocked_users       = $blocksModel->get_for_user($user['user_id']);
        $this->view->data_export         = DataExportService::state_json((new DataExportsModel())->latest_for_user($user['user_id']), (string) ($user['content_timezone'] ?? 'UTC'));
        $this->view->username_next_change = (new UsernameModel())->next_change_date($user['u_name_changed_at']);
        $this->view->public_domain        = Main::public_domain();

        $mfaModel = new MfaModel();
        $this->view->google_linked     = (string) ($user['google_sub'] ?? '') !== '';
        $this->view->mfa_totp_enabled  = !empty($user['mfa_totp_enabled']);
        $this->view->mfa_email_enabled = !empty($user['mfa_email_enabled']);
        $this->view->mfa_backup_count  = $mfaModel->count_unused_backup($user['user_id']);

        $this->view->render();
    }

    /**
     * Post for Me redirects the browser here after a connection attempt
     * (configure this URL as the Project Redirect URL in the PFM dashboard).
     */
    public function social_callbackAction(){
        if (Session::get('user_id') == 0) {
            self::bounce();
        }
        $user_id = (int) Session::get('user_id');

        // Integrations are creator-only — bounce anyone else.
        $urows = $this->userModel->get_user_by_id($user_id);
        $urow  = (is_array($urows) && count($urows) === 1) ? $urows[0] : null;
        if (!$urow || (int) $urow['role_id'] !== $this->userModel->get_role_id_by_name('Creator')) {
            Header('Location: /account/settings');
            exit;
        }

        // A collaborator connects the owner's accounts (ApiSocialIntegrationsController::social_user sent the owner's id).
        if (!empty($urow['team_role']) && (int) ($urow['created_by'] ?? 0) > 0) { $user_id = (int) $urow['created_by']; }
        $accountsModel = new SocialAccountsModel();

        // Post for Me refuses an account already connected under another of our user ids ("External Id already exists for
        // account spc_..."). The person just signed in to that account on the network, so it is theirs: move it to them on
        // Post for Me and here. Its existing connection (tokens) stays valid.
        $moved = 0;
        $error = (string) ($_GET['error'] ?? '');
        if (preg_match_all('/External Id already exists for account (spc_[A-Za-z0-9]+)/', $error, $mm)) {
            foreach (array_unique($mm[1]) as $pfm_id) {
                if (!PostForMeService::set_external_id($pfm_id, $user_id)) { continue; }
                if ($accountsModel->get_by_pfm_id($pfm_id)) { $accountsModel->reassign($pfm_id, $user_id); }
                $moved++;
            }
        }

        if (($_GET['isSuccess'] ?? '') === 'true' || $moved > 0) {
            // Re-list this user's accounts by external_id and bring the local rows in line.
            $accountsModel->sync_from_pfm($user_id, PostForMeService::get_accounts($user_id));
            Header('Location: /account/settings?section=connected&connected=1');
        } else {
            $why = trim(preg_replace('/\s*\|\s*/', '. ', $error));
            Header('Location: /account/settings?section=connected&error=1' . ($why !== '' ? '&why=' . rawurlencode(mb_substr($why, 0, 200)) : ''));
        }
        exit;
    }

    public function billingAction(){
        $user = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user) || count($user) !== 1) { self::bounce(); }
        $u   = $user[0];
        $uid = (int) $u['user_id'];
        // App-managed billing: plan, add-ons, schedule and card live in billing_accounts (BillingService).
        $acct = BillingService::account($uid);
        // Old data says "paid" but nothing is paying (no app plan, no Stripe subscription): it is Free.
        if (!BillingService::is_paid($acct) && Plan::has_paid_plan($u) && (string) ($u['stripe_subscription_id'] ?? '') === '') {
            (new BillingModel())->save_plan_mirror($uid, null, null, null, 0);
            $u = $this->userModel->get_user_by_id($uid)[0];
        }
        $this->view->user       = $u;
        $this->view->acct       = $acct;
        $this->view->tier       = Plan::tier($u);
        $this->view->has_plan   = BillingService::is_paid($acct);
        $this->view->is_creator = Plan::is_creator_row($u);
        $this->view->usage      = $this->view->tier !== '' ? Plan::usage($u) : null;
        $this->view->buckets    = (new AiCreditsModel())->buckets($uid);
        $this->view->ai_history = $this->view->tier !== '' ? (array) (new AiCreditsModel())->get_transactions($uid, 10) : array();
        $this->view->next       = BillingService::next_charge($acct);
        $this->view->charges    = (new BillingChargesModel())->history($uid, 24);
        $this->view->awaiting   = (new BillingChargesModel())->awaiting_action($uid);
        $this->view->creator_terms = self::creator_terms(Main::site_name());   // the Creator Agreement, ticked at the Creator/Studio checkout
        // Plan cards from PlanTiers; retired plans only show to the people already on them.
        $this->view->plan_rows = array();
        foreach (PlanTiers::offered($this->view->tier) as $t) {
            $this->view->plan_rows[] = array('tier' => $t, 'addons' => PlanTiers::addons_for($t['key']));
        }
        // Invoices from before app billing (the old Stripe subscriptions), kept as history.
        $this->view->invoices  = StripeService::get_invoices((string) ($u['stripe_customer_id'] ?? ''));
        $this->view->stripe_pk = StripeService::publishable_key();
        $this->view->render();
    }

    /* ---- Sign in / sign up with Google (GoogleAuth). Redirect-only, so they run for logged-out visitors. ---- */

    /** /account/google_start: remember state + PKCE verifier, then off to Google's account chooser. */
    public function google_startAction(){
        $me = (int) Session::get('user_id');
        if (!GoogleAuth::configured()) { Header('Location: ' . ($me > 0 ? '/account/settings?section=security&google_link_error=unavailable' : '/?auth=login&google_error=unavailable')); exit; }
        list($url, $state, $verifier) = GoogleAuth::authorize_url();
        $flow = array('state' => $state, 'verifier' => $verifier, 'started' => time());
        if ($me > 0) { $flow['link'] = $me; }   // signed in: connecting Google to this account (Settings > Security), not signing in
        $flow['signup'] = array_merge(UsersModel::signup_cookie(), array_filter(UsersModel::signup_params($_GET), 'strlen'));   // ?plan= / ?role= / ?ref= on this link, each one else the cls_signup cookie
        if (UsersModel::google_signup_from_start($_GET)) { $flow['start'] = 1; }   // the /start Google button: a new account is a Creator at signup, like the /start email form
        $next = Main::safe_path((string) ($_GET['next'] ?? ''));   // ?next= from the sign-in dialog (Controller::bounce): where to land after Google, same site only
        if ($next !== '/') { $flow['next'] = $next; }
        Session::set('google_oauth', $flow);
        Header('Location: ' . $url);
        exit;
    }

    /**
     * /account/google_callback. Signed in (flow link): the Google id is attached to this account, nothing else.
     * Signed out: the account carrying this Google id, else a new account. An existing account with the same email
     * is never linked from here, verified or not: its owner signs in with their password and connects Google in
     * Settings. Then the same gate as a password login (suspended, seat lock, second factor) via LoginGate.
     */
    public function google_callbackAction(){
        $fail = function ($why) { Header('Location: /?auth=login&google_error=' . $why); exit; };
        $flow = Session::get('google_oauth');
        Session::destroyValue('google_oauth');
        $state = (string) ($_GET['state'] ?? '');
        $code  = (string) ($_GET['code'] ?? '');
        if (!is_array($flow) || $state === '' || !hash_equals((string) ($flow['state'] ?? ''), $state) || (time() - (int) ($flow['started'] ?? 0)) > 900) {
            error_log('[google] callback: state mismatch or expired flow');
            $fail('expired');
        }
        if ($code === '') { $fail('denied'); }   // they cancelled on Google's screen (error=access_denied)

        $attempts = new LoginAttemptsModel();
        $ip = $this->get_ip_address();
        if ($attempts->count_recent($ip, 'google', 15) >= 20) { $fail('busy'); }
        $attempts->record($ip, 'google', 'google');

        $g = GoogleAuth::identify($code, (string) ($flow['verifier'] ?? ''));
        if (!$g || $g['sub'] === '') { $fail('google'); }
        if (empty($g['email_verified']) || !filter_var($g['email'], FILTER_VALIDATE_EMAIL)) { $fail('unverified'); }

        $users = $this->userModel;

        // Connect Google from Settings: attach the Google id to the signed-in account. No sign-in happens here.
        if (!empty($flow['link'])) {
            $me   = (int) Session::get('user_id');
            $back = function ($q) { Header('Location: /account/settings?section=security&' . $q); exit; };
            if ($me <= 0 || $me !== (int) $flow['link']) { $back('google_link_error=session'); }
            $other = $users->get_by_google_sub($g['sub']);
            if ($other && (int) $other['user_id'] !== $me) { $back('google_link_error=taken'); }
            $users->link_google($me, $g['sub']);
            $back('google_connected=1');
        }

        $user = $users->get_by_google_sub($g['sub']);
        $new  = false;
        if (!$user) {
            // No account carries this Google id. One with the same email (verified or not) is never linked from here:
            // its owner signs in with their password and connects Google in Settings. Otherwise it is a new account.
            if ($users->email_exists($g['email'])) { $fail('exists'); }
            $first = $g['given_name'] !== '' ? $g['given_name'] : explode('@', $g['email'])[0];
            $last  = $g['family_name'];
            // neutral handle, never the email or name: they choose their own at /setup.
            $uid = (int) $users->create_user($users->neutral_username(), password_hash(bin2hex(random_bytes(18)), PASSWORD_DEFAULT), $first, $last, $g['email']);
            if ($uid <= 0) { error_log('[google] create_user returned no id for ' . $g['email']); $fail('google'); }
            $users->link_google($uid, $g['sub']);
            SignupAlertJob::queue($uid, 'google');   // admins get an email with the new account's details (first, so nothing below can stop it)
            WelcomeMessage::send($uid);   // the welcome message in their Inbox, from Creator Link Studio
            try {
                $users->record_first_touch($uid);
                $users->record_ad_touch($uid);   // the paid ad behind it (cls_ad from /start, else cls_ttclid)
                if ((string) ($_COOKIE['cls_founding'] ?? '') === '1') { $users->set_signup_founding($uid); }
            } catch (\Throwable $e) { error_log('[google] attribution user_id=' . $uid . ': ' . $e->getMessage()); }   // attribution never blocks signing in
            TrackingLinks::attribute(0, 'signup', $uid);   // came in through a creator's tracking link (cls_tl)
            // Google: stays a User with signup_role creator (/setup sends them to Become a Creator), except from the /start button
            // ($flow['start']), which makes a role=creator signup a Creator now, like the /start email form. The agreement comes at checkout.
            try { $users->record_signup_params($uid, UsersModel::signup_params($flow['signup'] ?? array()), !empty($flow['start'])); }
            catch (\Throwable $e) { error_log('[google] record_signup_params user_id=' . $uid . ': ' . $e->getMessage()); }
            Affiliates::attribute_signup($uid);   // came in through an affiliate link (cls_aff) or an approved affiliate's ?ref=
            UsersModel::clear_signup_cookie();
            try { $users->start_tt_registration($uid); }   // the account's one TikTok signup event id (TikTokRegistration)
            catch (\Throwable $e) { error_log('[google] tt registration user_id=' . $uid . ': ' . $e->getMessage()); }
            Analytics::event('signup', array('user_id' => $uid, 'meta' => 'google'));
            TikTokRegistration::settle_on_next_page($uid);   // the next page this browser renders: CompleteRegistration for a creator, else FanSignup, once (google_analytics.php)
            $new = true;
            $rows = $users->get_user_by_id($uid);
            $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            if (!$user) { $fail('google'); }
        }
        if ($g['picture'] !== '') { GoogleAuth::import_avatar((int) $user['user_id'], $g['picture']); }

        $blocked = LoginGate::blocked($user);
        if ($blocked !== '') { $fail($blocked); }
        $done = LoginGate::finish($user);
        if (isset($done['mfa'])) {
            $m = array_keys(array_filter($done['mfa']));
            Header('Location: /?auth=mfa&m=' . implode(',', $m) . (!empty($flow['next']) ? '&next=' . rawurlencode((string) $flow['next']) : ''));   // the code form lands on ?next= (cls_after_login)
            exit;
        }
        if ($done['reset_pw'] === 1) { Header('Location: /account/force_reset'); exit; }
        if ($new && (string) ($user['signup_role'] ?? '') === 'creator') {   // a new creator starts on /setup
            $plan = (string) ($user['signup_plan'] ?? '');
            Header('Location: /setup' . ($plan !== '' ? '?plan=' . rawurlencode($plan) : ''));
            exit;
        }
        $land = !empty($flow['next']) ? Main::safe_path((string) $flow['next']) : '/';   // the page they were sent to sign in from, else home
        Header('Location: ' . $land . (strpos($land, '?') === false ? '?' : '&') . 'signed_in=google' . ($new ? '&new=1' : ''));
        exit;
    }

    public function usersAction(){
        if (!Permissions::is_owner_creator()) { self::bounce(); }
        $owner_id = (int) Session::get('user_id');
        $rows  = $this->userModel->get_user_by_id($owner_id);
        $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        $team  = new TeamModel();
        $limit = Plan::limit($owner, 'seats');
        $this->view->owner      = $owner;
        $this->view->members    = $team->members($owner_id);
        $this->view->seats_used = $team->seats_used($owner_id);
        $this->view->seat_limit = ($limit === null) ? 1 : (int) $limit;   // 0 = unlimited
        $this->view->timezone   = (string) ($owner['content_timezone'] ?? 'UTC');
        $this->view->render();
    }

    /** Password-reset page, opened from the link in the reset email. Standalone form (no app chrome). */
    public function resetAction(){
        $this->view->reset_password();
    }

    /** Email-verification page, opened from the link in the signup email. Confirms the token, then sends the user to sign in. */
    public function verifyAction(){
        $this->view->verify_email();
    }

    /** Forced-password-change page (a login with reset_pw=1 lands here). Standalone form. */
    public function force_resetAction(){
        $this->view->force_reset_form();
    }

    /** Didit sends the creator back here after the ID check: record the result if the webhook hasn't, then on to Settings. */
    public function identity_verificationAction(){
        $uid = (int) Session::get('user_id');
        if ($uid <= 0) { Controller::bounce(); }
        $status = IdentityVerification::refresh_if_pending($uid);
        header('Location: /account/settings?section=creator&identity_verification=' . rawurlencode($status), true, 302);
        exit;
    }

    /** Plain-text Creator Terms & Conditions with the site name substituted in. */
    public static function creator_terms($site){
        return <<<TERMS
{$site} Creator Terms and Conditions
Last Updated: November 9, 2026

These Creator Terms and Conditions (the "Terms") govern your access to and use of the {$site} platform (the "Platform") as a creator. {$site} is referred to in these Terms as "we," "us," or "our." By creating a creator account or publishing content through the Platform, you agree to be bound by these Terms, our Terms of Service and our Privacy Policy, each of which is incorporated by reference.

If you do not agree to these Terms, do not use the Platform as a creator.

1. Eligibility and Account Registration
1.1. You must be at least 18 years of age, or the age of majority in your jurisdiction if higher, to register as a creator.
1.2. You must provide accurate, current, and complete information during registration and keep it updated.
1.3. We may ask you to verify your identity, including with government-issued photo identification and a liveness check, before showing a verified badge on your page. Accounts that provide false identity information may be restricted or closed.
1.4. You are responsible for maintaining the confidentiality of your account credentials and for all activity that occurs under your account, including activity by team members you invite. Notify us immediately of any unauthorized use.
1.5. Accounts may not be sold, transferred, or shared outside the team seats feature.

2. Your Content and License Grant
2.1. You retain ownership of the content you create, upload, generate, and publish on the Platform ("Creator Content"), to the extent the law allows ownership of it.
2.2. You grant {$site} a non-exclusive, worldwide, royalty-free, sublicensable license to host, store, reproduce, transcode, display, distribute, and publish your Creator Content solely to operate, provide, and improve the Platform, including posting it to the social accounts you connect. This license ends when you delete the content or close your account, except that (a) we may retain copies as required for legal, compliance, and dispute resolution purposes, and (b) residual copies may persist in routine backups for a limited period.
2.3. You represent and warrant that you own or have secured all rights, licenses, consents, and releases necessary to publish your Creator Content, including rights to any music, images, trademarks, likenesses, and contributions of other individuals appearing in the content.
2.4. You are solely responsible for your Creator Content. {$site} acts as a software and hosting provider, not as a publisher or co-creator.

3. AI-Generated Content
3.1. The Platform lets you create AI influencers, images, video, voice, captions, and messages. You are responsible for AI output you choose to save, publish, or send, and you should review it before you do.
3.2. You may not use AI features to create the likeness or voice of a real, identifiable person without that person's documented permission.
3.3. You may not create AI content that depicts or appears to depict anyone under 18 in a sexualized way, or any sexually explicit or pornographic content.
3.4. Where a social network, advertising rule, or law requires AI-generated content or an AI persona to be labeled as such, you are responsible for labeling it.
3.5. AI features use credits as described on the pricing page. We may limit, change, or replace the AI models behind a feature at any time.

4. Prohibited Content and Conduct
You may not publish, generate, send, or promote content that:
4.1. Is illegal under applicable law, or facilitates illegal activity.
4.2. Infringes any copyright, trademark, right of publicity, right of privacy, or other proprietary right.
4.3. Involves any person under 18 in any sexualized context whatsoever, whether real, simulated, illustrated, or AI-generated.
4.4. Is sexually explicit or pornographic.
4.5. Is deceptive, fraudulent, or misleading, including impersonation or use of another person's likeness without authorization, including AI-generated likenesses.
4.6. Depicts or threatens violence against any person, promotes hatred or discrimination, or harasses, doxxes, or endangers any individual.
4.7. Contains malware or spam, or attempts to manipulate Platform systems or rankings.
You may not use the Platform to send spam, break the rules of social networks you publish to, or artificially inflate engagement metrics.

5. Content Review, Moderation, and Enforcement
5.1. We may review, monitor, restrict, remove, or refuse to publish any content at any time, with or without notice, for any reason consistent with these Terms and applicable law. Uploaded images are scanned automatically. We are under no obligation to pre-screen content but reserve the right to do so.
5.2. We may suspend or terminate your creator access, in whole or in part, for violations of these Terms or applicable law, or where we reasonably believe your activity creates legal or reputational risk to the Platform.
5.3. For violations involving minors, non-consensual content, or fraud, termination may be immediate, without notice, and with referral to law enforcement.
5.4. Where practical and lawful, we will notify you of enforcement actions and give you a way to ask for a review through support.

6. Copyright and DMCA
6.1. We respond to notices of alleged copyright infringement under the Digital Millennium Copyright Act. Notices may be submitted through support.
6.2. If your content is removed in response to a DMCA notice, you may submit a counter-notification. Repeat infringers will have their accounts terminated.

7. Term and Termination
7.1. You may close your creator account at any time in your account settings. Closure does not relieve you of obligations arising before closure, including indemnification obligations.
7.2. Upon termination, your right to publish through the Platform ends. Plan charges and unused AI credits are handled as described in the Terms of Service. We may retain records as required by law.
7.3. Sections 2.2 (retained copies), 8, 9, 10, and 11 survive termination.

8. Disclaimers
THE PLATFORM IS PROVIDED "AS IS" AND "AS AVAILABLE." TO THE FULLEST EXTENT PERMITTED BY LAW, WE DISCLAIM ALL WARRANTIES, EXPRESS OR IMPLIED, INCLUDING MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT. WE DO NOT GUARANTEE ANY LEVEL OF REACH, ENGAGEMENT, TRAFFIC, OR PLATFORM AVAILABILITY, OR THAT AI OUTPUT WILL BE ACCURATE OR SUITABLE.

9. Limitation of Liability
TO THE FULLEST EXTENT PERMITTED BY LAW, {$site} AND ITS OFFICERS, EMPLOYEES, AND AGENTS WILL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF PROFITS, REVENUE, DATA, OR GOODWILL. OUR TOTAL AGGREGATE LIABILITY ARISING FROM OR RELATED TO THESE TERMS OR THE PLATFORM WILL NOT EXCEED THE GREATER OF (A) THE AMOUNT YOU PAID US IN THE SIX MONTHS PRECEDING THE CLAIM, OR (B) ONE HUNDRED U.S. DOLLARS. SOME JURISDICTIONS DO NOT ALLOW CERTAIN LIMITATIONS, SO SOME OF THE ABOVE MAY NOT APPLY TO YOU.

10. Indemnification
You will defend, indemnify, and hold harmless {$site} and its officers, employees, and agents from and against any claims, damages, losses, and expenses (including reasonable attorneys' fees) arising from or related to: your Creator Content, including AI content you generate and publish; your breach of these Terms; and your violation of any law or the rights of any third party, including individuals appearing in your content.

11. Governing Law and Dispute Resolution
11.1. These Terms are governed by the laws of the State of Florida, without regard to conflict of law principles.
11.2. Any dispute arising out of or relating to these Terms or the Platform will be resolved by binding arbitration administered by the American Arbitration Association under its Commercial Arbitration Rules, seated in Orange County, Florida. YOU AND {$site} WAIVE THE RIGHT TO A JURY TRIAL AND TO PARTICIPATE IN ANY CLASS ACTION OR CLASS-WIDE ARBITRATION. Either party may bring an individual claim in small claims court, and either party may seek injunctive relief in court for intellectual property misuse or unauthorized Platform access.
11.3. You may opt out of the arbitration provision by written notice within 30 days of first accepting these Terms.

12. Changes to These Terms
We may update these Terms from time to time. For material changes, we will provide at least 30 days' notice through the Platform or by email. Your continued use of the Platform as a creator after the effective date constitutes acceptance of the updated Terms. If you do not agree to a change, your remedy is to stop publishing and close your account before the change takes effect.

13. General
13.1. These Terms, together with the policies incorporated by reference, are the entire agreement between you and {$site} regarding your creator activity on the Platform.
13.2. Our failure to enforce a provision is not a waiver. If any provision is found unenforceable, the remainder stays in effect.
13.3. You may not assign these Terms without our written consent. We may assign these Terms in connection with a merger, acquisition, or sale of assets.
13.4. Nothing in these Terms creates an employment, agency, partnership, or joint venture relationship. You are an independent party.
TERMS;
    }

}
