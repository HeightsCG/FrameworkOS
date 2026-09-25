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
            Header('Location: /');
            exit;
        }
        $user = $user[0];   // the acting user — personal settings (security, notifications, wallet, subscriptions)

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
            'purchases'          => array('Purchases',          'Your unlock receipts, and sales of your posts, bundles and messages.'),
            'subscriptions'      => array('Subscriptions',      'New, renewed, ending and failed memberships, and your Creator Link Studio plan.'),
            'events'             => array('Events',             'Registrations and bookings for events.'),
            'services'           => array('Services',           'Bookings and confirmations for services.'),
            'credits'            => array('Credits',            'Credit and AI credit purchases, and payouts to your bank.'),
            'auto_replenishment' => array('Auto-replenishment', 'When your wallet tops itself up, or a top-up fails.'),
            'refunds'            => array('Refunds',            'Refunds issued to you or to your buyers.'),
            'security'           => array('Security',           'Password and two-factor changes. Always emailed.'),
            'system'             => array('System',             'Verification decisions and account notices.'),
            'marketing'          => array('Marketing',          'Product news and promotions.'),
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
        $creditsModel = new CreditsModel();

        // Wallet: cards are only available once the user has a Stripe customer.
        $cards = array();
        if (!empty($user['stripe_customer_id'])) {
            $cards = StripeService::get_payment_methods($user['stripe_customer_id']);
        }

        $creator_role_id = $this->userModel->get_role_id_by_name('Creator');
        $is_creator      = $can_act_creator;   // show creator sections to collaborators too; role gates the specifics

        // Payouts (Stripe Connect) — OWNER only (a collaborator never sees cash-out).
        $payout_status  = array('exists' => false, 'details_submitted' => false, 'payouts_enabled' => false, 'requirements_due' => false);
        $payout_balance = array('available' => 0, 'pending' => 0, 'currency' => 'USD');
        $payouts        = array();
        if ($is_owner_creator && !empty($user['stripe_connect_account_id'])) {
            $payout_status = StripeService::connect_account_status($user['stripe_connect_account_id']);
            if (!empty($payout_status['payouts_enabled'])) {
                // "Pending" = money already transferred to their account, in transit to the bank.
                $stripe_bal = StripeService::connect_balance($user['stripe_connect_account_id']);
                $payout_balance['pending']  = (int) $stripe_bal['pending'];
                $payout_balance['currency'] = $stripe_bal['currency'];
            }
        }
        // "Available" to cash out is the creator's earned credit wallet ($1 = 10 credits),
        // not the Stripe balance — credits are the platform's internal currency. History
        // is the creator's own cash-out events (the Stripe bank payout lags on a schedule).
        if ($is_owner_creator) {
            $payout_credits = (int) $creditsModel->get_balance($user['user_id']);
            $payout_balance['available']         = $payout_credits * 10;
            $payout_balance['available_credits'] = $payout_credits;
            $payouts = $creditsModel->get_payout_history($user['user_id']);
        }

        $this->view->user               = $user;
        $this->view->is_creator          = $is_creator;
        $this->view->can_content         = $can_content;
        $this->view->can_manage          = $can_manage;
        $this->view->is_owner_creator    = $is_owner_creator;
        $this->view->creator_profile     = $can_content ? (new CreatorProfileModel())->get_for_user($owner['user_id']) : array();
        $me_profile = (new CreatorProfileModel())->get_for_user((int) Session::get('user_id'));
        $this->view->my_avatar          = (string) ($me_profile['avatar_url'] ?? '');
        $this->view->creator_links       = $can_content ? (new CreatorLinksModel())->get_for_user($owner['user_id']) : array();
        $this->view->creator_plans       = $can_manage  ? (new CreatorPlansModel())->get_for_user($owner['user_id']) : array();
        $this->view->promo_codes         = $can_manage  ? (new CreatorPromoCodesModel())->get_for_user($owner['user_id']) : array();
        $this->view->can_promo           = true;   // discount codes are included on every plan

        // Content bundles: the creator's bundles + the Library media they can add.
        $bundlesModel = new ContentBundlesModel();
        $bundles = $can_manage ? (array) $bundlesModel->get_for_creator($owner['user_id']) : array();
        foreach ($bundles as &$b) { $b['asset_ids'] = $bundlesModel->get_item_asset_ids((int) $b['id']); }
        unset($b);
        $bundle_media = array();
        if ($can_manage) {
            foreach ((array) (new MediaAssetsModel())->get_for_creator($owner['user_id'], array()) as $a) {
                if (($a['status'] ?? '') !== 'ready' || !empty($a['deleted_at'])) { continue; }
                $name = trim((string) ($a['display_name'] ?? ''));
                if ($name === '') { $name = (string) ($a['filename'] ?? 'Untitled'); }
                $bundle_media[] = array(
                    'id'    => (int) $a['id'],
                    'type'  => (string) $a['type'],
                    'title' => mb_substr($name, 0, 60),
                    'thumb' => MediaService::signed_variant($a, ($a['type'] === 'video' ? 'poster' : 'thumb'), 900),
                );
            }
        }
        $this->view->content_bundles     = $bundles;
        $this->view->bundle_media        = $bundle_media;
        $this->view->can_bundles         = true;   // bundles are included on every plan
        $this->view->creator_brand       = $can_content ? (new CreatorBrandModel())->get_for_user($owner['user_id']) : array();
        $this->view->payout_status       = $payout_status;
        $this->view->payout_balance      = $payout_balance;
        $this->view->payouts             = $payouts;
        $this->view->has_connect         = ($is_owner_creator && !empty($user['stripe_connect_account_id']));
        $this->view->verified            = !empty($owner['verified']);
        $this->view->verif_status        = $is_owner_creator ? (new VerificationsModel())->status_for($owner['user_id']) : '';
        $this->view->creator_terms       = $this->creator_terms(Main::site_name());
        $this->view->fanvue              = $can_post ? (new FanvueAccountsModel())->get_for_user($owner['user_id']) : null;
        $this->view->can_inbox           = $can_manage;   // inbox automation is included on every plan
        $this->view->inbox_settings      = $can_manage ? (new InboxSettingsModel())->get_for_creator($owner['user_id']) : InboxSettingsModel::defaults();
        $this->view->inbox_pending       = ($can_manage && $this->view->can_inbox) ? (new InboxRepliesModel())->count_pending($owner['user_id']) : 0;
        $this->view->claude_ok           = ClaudeService::configured();
        // AI replies are a per-plan switch (Free has none); welcome and trigger messages work on every plan.
        $this->view->inbox_ai            = Plan::has_feature($owner, 'inbox_ai');
        $inbox_up                        = PlanTiers::lowest_with_feature('inbox_ai');
        $this->view->inbox_ai_upgrade    = $inbox_up ? (string) $inbox_up['name'] : '';
        $this->view->fanvue_configured   = FanvueService::configured();
        $this->view->can_social_post     = $can_post;
        $this->view->platform_meta       = $platform_meta;
        $this->view->notification_meta   = $notification_meta;
        $this->view->connected           = $connected;
        $this->view->mcp_connected       = $can_manage && (new ApiTokensModel())->has_active_for_user($owner['user_id']);
        $this->view->mcp_url             = Main::get_base_domain() . '/mcp';
        $this->view->notification_prefs  = $prefsModel->get_prefs_map($user['user_id']);
        $this->view->blocked_users       = $blocksModel->get_for_user($user['user_id']);
        $this->view->data_export         = DataExportService::state_json((new DataExportsModel())->latest_for_user($user['user_id']), (string) ($user['content_timezone'] ?? 'UTC'));
        $this->view->credit_balance      = $creditsModel->get_balance($user['user_id']);
        $this->view->credit_transactions = $creditsModel->get_transactions($user['user_id']);
        $this->view->credit_packages     = CreditsModel::$packages;
        $this->view->autoreplenishment   = $creditsModel->get_autoreplenishment($user['user_id']);
        $this->view->cards               = $cards;
        $this->view->stripe_pk           = StripeService::publishable_key();
        $this->view->username_next_change = (new UsernameModel())->next_change_date($user['u_name_changed_at']);
        $this->view->public_domain        = Main::public_domain();

        $this->view->my_subscriptions = (new CreatorSubscriptionsModel())->get_for_subscriber($user['user_id']);

        $mfaModel = new MfaModel();
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
            Header('Location: /');
            exit;
        }
        $user_id = (int) Session::get('user_id');

        // Integrations are creator-only — bounce anyone else.
        $urows = $this->userModel->get_user_by_id($user_id);
        $urow  = (is_array($urows) && count($urows) === 1) ? $urows[0] : null;
        if (!$urow || (int) $urow['role_id'] !== $this->userModel->get_role_id_by_name('Creator')) {
            Header('Location: /account/settings');
            exit;
        }

        if (($_GET['isSuccess'] ?? '') === 'true') {
            // Re-list this user's accounts by external_id and upsert them locally.
            $accounts      = PostForMeService::get_accounts($user_id);
            $accountsModel = new SocialAccountsModel();
            foreach ($accounts as $acct) {
                if (!empty($acct['id'])) {
                    $accountsModel->upsert_from_pfm($user_id, $acct);
                }
            }
            Header('Location: /account/settings?section=connected&connected=1');
        } else {
            Header('Location: /account/settings?section=connected&error=1');
        }
        exit;
    }

    public function billingAction(){
        $user = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user) || count($user) !== 1) { Header('Location: /'); exit; }
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

    /**
     * Fanvue OAuth redirect target. Verifies state, exchanges the code (PKCE), looks
     * up the Fanvue user and stores the connection for the OWNER account recorded
     * when the flow started. Always lands back on Settings > Integrations.
     */
    public function fanvue_callbackAction(){
        if (Session::get('user_id') == 0) {
            Header('Location: /');
            exit;
        }
        $flow  = Session::get('fanvue_oauth');
        $back  = '/account/settings?section=' . ((is_array($flow) && ($flow['return_section'] ?? '') === 'inbox') ? 'inbox' : 'connected');
        Session::destroyValue('fanvue_oauth');

        $state = (string) ($_GET['state'] ?? '');
        $code  = (string) ($_GET['code'] ?? '');
        if (!is_array($flow) || $state === '' || !hash_equals((string) ($flow['state'] ?? ''), $state)
            || (time() - (int) ($flow['started'] ?? 0)) > 900) {
            error_log('[fanvue] callback: state mismatch or expired flow');
            Header('Location: ' . $back . '&fanvue_error=state');
            exit;
        }
        if ($code === '') {
            // User declined on Fanvue's consent screen (error=access_denied) or Fanvue errored.
            error_log('[fanvue] callback: no code (' . (string) ($_GET['error'] ?? '') . ' ' . (string) ($_GET['error_description'] ?? '') . ')');
            Header('Location: ' . $back . '&fanvue_error=denied');
            exit;
        }

        $tokens = FanvueService::exchange_code($code, (string) ($flow['verifier'] ?? ''));
        if (!$tokens) {
            Header('Location: ' . $back . '&fanvue_error=token');
            exit;
        }
        $me = FanvueService::whoami((string) $tokens['access_token']);
        if (!$me) {
            Header('Location: ' . $back . '&fanvue_error=profile');
            exit;
        }
        (new FanvueAccountsModel())->connect((int) $flow['user_id'], $tokens, $me);
        Header('Location: ' . $back . '&fanvue_connected=1');
        exit;
    }

    public function usersAction(){
        if (!Permissions::is_owner_creator()) { Header('Location: /'); exit; }
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

    /** Plain-text Creator Terms & Conditions with the site name substituted in. */
    private function creator_terms($site){
        return <<<TERMS
{$site} Creator Terms and Conditions
Last Updated: July 3, 2026

These Creator Terms and Conditions (the "Terms") govern your access to and use of the {$site} platform (the "Platform") as a creator. {$site} is referred to in these Terms as "we," "us," or "our." By creating a creator account, publishing content, or receiving payouts through the Platform, you agree to be bound by these Terms, our Content Policy, our Privacy Policy, and our Acceptable Use Policy, each of which is incorporated by reference.

If you do not agree to these Terms, do not use the Platform as a creator.

1. Eligibility and Account Registration
1.1. You must be at least 18 years of age, or the age of majority in your jurisdiction if higher, to register as a creator.
1.2. You must provide accurate, current, and complete information during registration and keep it updated. This includes your legal name, contact information, and any identity or tax documentation we or our payment partners require.
1.3. We may require identity verification, including government-issued photo identification and biometric or liveness verification, before you may publish content or receive payouts. You agree to complete verification promptly when requested. Accounts that fail or refuse verification may be restricted or closed.
1.4. You are responsible for maintaining the confidentiality of your account credentials and for all activity that occurs under your account. Notify us immediately of any unauthorized use.
1.5. One creator account per person or legal entity unless we approve otherwise in writing. Accounts may not be sold, transferred, or shared.

2. Your Content and License Grant
2.1. You retain ownership of the content you create and publish on the Platform ("Creator Content").
2.2. You grant {$site} a non-exclusive, worldwide, royalty-free, sublicensable license to host, store, reproduce, transcode, display, distribute, and promote your Creator Content solely for the purposes of operating, marketing, and improving the Platform. This license ends when you delete the content or close your account, except that (a) content already purchased by customers remains accessible to those customers per your sale terms, (b) we may retain copies as required for legal, compliance, and dispute resolution purposes, and (c) residual copies may persist in routine backups for a limited period.
2.3. You represent and warrant that you own or have secured all rights, licenses, consents, and releases necessary to publish and monetize your Creator Content, including rights to any music, images, trademarks, likenesses, and contributions of other individuals appearing in the content.
2.4. You are solely responsible for your Creator Content. {$site} does not endorse Creator Content and acts as a hosting platform and payment facilitator, not as a publisher or co-creator.

3. Content Classification
3.1. You must accurately classify every piece of content you publish using the Platform's classification system: General, Mature, or Adult.
3.2. Misclassification of content, including publishing Adult content under a General or Mature classification, is a material violation of these Terms and may result in immediate content removal, payout holds, and account termination.
3.3. We may reclassify content at our discretion. Repeated misclassification may result in mandatory pre-publication review of all your content.

4. Adult Content Requirements
If you publish content classified as Adult, the following additional requirements apply.
4.1. Every individual appearing in Adult content must have been at least 18 years of age at the time the content was produced. No exceptions.
4.2. Before publishing Adult content, you must upload, for every individual appearing in the content: (a) valid government-issued photo identification, and (b) a signed written consent and release authorizing the recording and its distribution on the Platform. We may reject or remove content lacking complete documentation.
4.3. You must maintain records sufficient to comply with 18 U.S.C. § 2257 and its implementing regulations where applicable, and you must produce those records to us upon request within five business days.
4.4. Adult content must comply with the rules of our payment partners and card networks, which prohibit, among other things: any depiction of persons under 18 or persons appearing to be under 18; non-consensual acts or the depiction of non-consent; incest; bestiality; content produced or shared without the documented consent of every person depicted; and content depicting intoxication or incapacitation in a sexual context. These restrictions apply regardless of the legality of the content in your jurisdiction.
4.5. Adult content may be subject to review before publication. We may delay, restrict, or decline publication at our discretion.
4.6. Individuals depicted in content have the right to request removal. Upon receipt of a removal request from a depicted individual, or a report of non-consensual publication, we will disable the content pending resolution.

5. Prohibited Content and Conduct
You may not publish, sell, or promote content that:
5.1. Is illegal under applicable law, or facilitates illegal activity.
5.2. Infringes any copyright, trademark, right of publicity, right of privacy, or other proprietary right.
5.3. Involves any person under 18 in any sexualized context whatsoever, whether real, simulated, illustrated, or AI-generated.
5.4. Is deceptive, fraudulent, or misleading, including impersonation, misrepresentation of what a customer will receive, or use of another person's likeness without authorization, including AI-generated likenesses.
5.5. Depicts or threatens violence against any person, promotes hatred or discrimination, or harasses, doxxes, or endangers any individual.
5.6. Contains malware, spam, or attempts to manipulate Platform systems, rankings, or payment flows.
You may not use the Platform to circumvent payment processing, direct customers to off-platform payment for content sold on the Platform, engage in wash transactions or self-purchases, or artificially inflate engagement or sales metrics.

6. Fees, Payments, and Payouts
6.1. {$site} charges a platform fee on each sale, subscription, tip, or other transaction processed through the Platform. Current fee rates are published in your creator dashboard and may be updated with at least 30 days' notice.
6.2. Payment processing is provided by third-party payment partners. To receive payouts, you must complete payment and payout onboarding, including any identity, banking, and tax documentation required by our payment partners. We are not responsible for delays caused by incomplete onboarding.
6.3. Payouts are made on the schedule published in your creator dashboard, subject to minimum payout thresholds, processing timelines, and any holds or reserves described in these Terms.
6.4. We may withhold, hold in reserve, or offset amounts otherwise payable to you where reasonably necessary to cover chargebacks, refunds, fraud investigations, suspected Terms violations, legal holds, or amounts you owe us. Reserves will be released when the underlying risk is resolved.
6.5. All amounts are stated and paid in U.S. dollars unless otherwise indicated. Currency conversion, banking fees, and payout method fees are your responsibility.

7. Refunds and Chargebacks
7.1. Refunds are handled per the Platform's published refund policy. You authorize us to issue refunds to customers in accordance with that policy, applicable law, and card network rules.
7.2. You are financially responsible for chargebacks, refunds, and associated fees arising from your sales. These amounts may be deducted from your balance, future payouts, or reserve.
7.3. Excessive chargeback rates may result in payout holds, mandatory reserves, restrictions on your account, or termination, including where required by our payment partners or card networks.

8. Taxes
8.1. You are solely responsible for determining, reporting, and paying all taxes arising from your earnings on the Platform.
8.2. You must provide accurate tax documentation (such as a W-9 or W-8 series form) when requested. We may withhold payouts pending receipt of required documentation and may report your earnings to tax authorities as required by law, including issuing Form 1099 or equivalent.
8.3. Where we are required by law to collect and remit sales tax, VAT, or similar transaction taxes on Platform sales, we will do so, and such amounts are not part of your earnings.

9. Content Review, Moderation, and Enforcement
9.1. We may review, monitor, restrict, remove, or refuse to publish any content at any time, with or without notice, for any reason consistent with these Terms and applicable law. We are under no obligation to pre-screen content but reserve the right to do so.
9.2. We may suspend or terminate your creator access, in whole or in part, for violations of these Terms, the Content Policy, applicable law, or payment partner requirements, or where we reasonably believe your activity creates legal, financial, or reputational risk to the Platform.
9.3. For violations involving minors, non-consensual content, or fraud, termination may be immediate, without notice, with forfeiture of pending payouts to the extent permitted by law, and with referral to law enforcement.
9.4. Where practical and lawful, we will notify you of enforcement actions and provide a means to appeal through the process published in your creator dashboard.

10. Copyright and DMCA
10.1. We respond to notices of alleged copyright infringement under the Digital Millennium Copyright Act. Notices may be submitted to our designated agent through the contact options in your creator dashboard.
10.2. If your content is removed in response to a DMCA notice, you may submit a counter-notification. Repeat infringers will have their accounts terminated.

11. Term and Termination
11.1. You may close your creator account at any time through your dashboard. Closure does not relieve you of obligations arising before closure, including chargeback liability and indemnification obligations.
11.2. Upon termination, your right to publish and sell through the Platform ends. Earned, undisputed balances above the minimum payout threshold will be paid on the next regular payout cycle, subject to Section 6.4 holds and any legally required withholding. We may retain records as required by law and payment partner rules.
11.3. Sections 2.2 (surviving license rights), 7, 8, 12, 13, 14, and 15 survive termination.

12. Disclaimers
THE PLATFORM IS PROVIDED "AS IS" AND "AS AVAILABLE." TO THE FULLEST EXTENT PERMITTED BY LAW, WE DISCLAIM ALL WARRANTIES, EXPRESS OR IMPLIED, INCLUDING MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT. WE DO NOT GUARANTEE ANY LEVEL OF SALES, TRAFFIC, EARNINGS, OR PLATFORM AVAILABILITY.

13. Limitation of Liability
TO THE FULLEST EXTENT PERMITTED BY LAW, {$site} AND ITS OFFICERS, EMPLOYEES, AND AGENTS WILL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF PROFITS, REVENUE, DATA, OR GOODWILL. OUR TOTAL AGGREGATE LIABILITY ARISING FROM OR RELATED TO THESE TERMS OR THE PLATFORM WILL NOT EXCEED THE GREATER OF (A) THE PLATFORM FEES WE RETAINED FROM YOUR SALES IN THE SIX MONTHS PRECEDING THE CLAIM, OR (B) ONE HUNDRED U.S. DOLLARS. SOME JURISDICTIONS DO NOT ALLOW CERTAIN LIMITATIONS, SO SOME OF THE ABOVE MAY NOT APPLY TO YOU.

14. Indemnification
You will defend, indemnify, and hold harmless {$site} and its officers, employees, and agents from and against any claims, damages, losses, and expenses (including reasonable attorneys' fees) arising from or related to: your Creator Content; your breach of these Terms; your violation of any law or the rights of any third party, including individuals appearing in your content; and any dispute between you and a customer.

15. Governing Law and Dispute Resolution
15.1. These Terms are governed by the laws of the State of Florida, without regard to conflict of law principles.
15.2. Any dispute arising out of or relating to these Terms or the Platform will be resolved by binding arbitration administered by the American Arbitration Association under its Commercial Arbitration Rules, seated in Orange County, Florida. YOU AND {$site} WAIVE THE RIGHT TO A JURY TRIAL AND TO PARTICIPATE IN ANY CLASS ACTION OR CLASS-WIDE ARBITRATION. Either party may bring an individual claim in small claims court, and either party may seek injunctive relief in court for intellectual property misuse or unauthorized Platform access.
15.3. You may opt out of the arbitration provision by written notice within 30 days of first accepting these Terms.

16. Changes to These Terms
We may update these Terms from time to time. For material changes, we will provide at least 30 days' notice through the Platform or by email. Your continued use of the Platform as a creator after the effective date constitutes acceptance of the updated Terms. If you do not agree to a change, your remedy is to stop publishing and close your account before the change takes effect.

17. General
17.1. These Terms, together with the policies incorporated by reference, are the entire agreement between you and {$site} regarding your creator activity on the Platform.
17.2. Our failure to enforce a provision is not a waiver. If any provision is found unenforceable, the remainder stays in effect.
17.3. You may not assign these Terms without our written consent. We may assign these Terms in connection with a merger, acquisition, or sale of assets.
17.4. Nothing in these Terms creates an employment, agency, partnership, or joint venture relationship. You are an independent party.
TERMS;
    }

}
