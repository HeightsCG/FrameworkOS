<?php
/**
 * Public creator profile at /@handle (a "link in bio" page).
 *
 * Dispatched from Bootstrap when the first URL segment starts with "@", so it
 * never collides with real app routes. Renders a self-contained public page
 * (no app chrome, no auth). Unknown handles that were previously used 301 to
 * the account's current handle via UsernameModel; anything else is a 404.
 */
class ProfileController extends Controller {

    public $protected = 0;
    private $userModel;

    public function __construct(){
        parent::__construct();
        $this->userModel = new UsersModel();
    }

    public function viewAction(){
        $url    = Main::get_url();
        $handle = preg_replace('/[^a-z0-9_]/', '', strtolower(ltrim($url[0] ?? '', '@')));

        if ($handle === '') {
            Errors::page_not_found();
            return;
        }

        $rows = $this->userModel->get_user_by_username($handle);

        // Unknown handle — 301 to the current handle if this one was released, else 404.
        if (!is_array($rows) || count($rows) !== 1) {
            $current = (new UsernameModel())->resolve_current_username($handle);
            if ($current !== '' && $current !== $handle) {
                header('Location: /@' . rawurlencode($current), true, 301);
                exit;
            }
            Errors::page_not_found();
            return;
        }

        $user = $rows[0];

        // A suspended account's page is gone while it is suspended.
        if ((string) ($user['user_status'] ?? 'Active') === 'Disabled') {
            Errors::page_not_found();
            return;
        }

        // Only creators have a public profile.
        $creator_role_id = $this->userModel->get_role_id_by_name('Creator');
        if ((int) $user['role_id'] !== $creator_role_id) {
            Errors::page_not_found();
            return;
        }

        $profile = (new CreatorProfileModel())->get_for_user($user['user_id']);

        $links = array();
        foreach ((new CreatorLinksModel())->get_for_user($user['user_id']) as $link) {
            if (!empty($link['is_enabled'])) {
                $links[] = $link;
            }
        }

        $plans = (new CreatorPlansModel())->get_active_for_user($user['user_id']);

        // Display fields with sensible fallbacks.
        $display_name = trim((string) $profile['display_name']);
        if ($display_name === '') {
            $display_name = trim($user['first_name'] . ' ' . $user['last_name']);
        }
        if ($display_name === '') {
            $display_name = '@' . $user['u_name'];
        }

        $handle        = $user['u_name'];
        $public_domain = Main::public_domain();

        // Viewer context for the follow / self-view actions.
        $follows         = new FollowsModel();
        $viewer_id       = (int) Session::get('user_id');
        $is_self         = ($viewer_id === (int) $user['user_id']);
        $viewer_logged_in = ($viewer_id > 0);
        if ($viewer_logged_in && !$is_self && (new BlocksModel())->either_blocked($viewer_id, (int) $user['user_id'])) {
            Errors::page_not_found();
            return;
        }
        $is_following    = $viewer_logged_in ? $follows->is_following($viewer_id, $user['user_id']) : false;

        // Log a profile view (PRD §7.5) — the creator's own visits don't count. viewer_key
        // mirrors post_views so unique-visitor counts line up across the two.
        if (!$is_self) {
            $vkey = $viewer_id > 0
                ? ('u:' . $viewer_id)
                : ('ip:' . substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 40));
            (new ProfileViewsModel())->record((int) $user['user_id'], $vkey);
        }
        $follower_count  = $follows->count_followers($user['user_id']);
        $member_since    = !empty($user['creator_since']) ? date('F Y', strtotime((string) $user['creator_since'])) : '';

        // Presence: "online" if the creator was active within the last 5 minutes.
        $last_active     = $user['last_active_at'] ?? null;
        $is_online       = $last_active !== null && strtotime((string) $last_active . ' UTC') >= time() - 300;

        // Returning from Stripe Checkout: record the paid membership before rendering.
        $sub_notice = '';
        if (($_GET['sub'] ?? '') === 'cancel') {
            $sub_notice = 'cancel';
        } elseif (($_GET['sub'] ?? '') === 'success' && !empty($_GET['session_id']) && $viewer_logged_in) {
            $sub_notice = $this->record_checkout_success((string) $_GET['session_id'], $user, $viewer_id) ? 'success' : '';
        }

        // Content gating treats the creator like any other visitor (same rule as Discover): the
        // Studio is where they see their own work in full. Only management affordances use $is_self.
        $subscribed_plan_ids = $viewer_logged_in
            ? (new CreatorSubscriptionsModel())->active_plan_ids($viewer_id, $user['user_id'])
            : array();

        // Content bundles this creator sells (active + non-empty), with the viewer's ownership.
        $bundlesModel    = new ContentBundlesModel();
        $bundle_unlocked = $viewer_logged_in
            ? $bundlesModel->unlocked_map_for_creator($viewer_id, $user['user_id'])
            : array();
        $bundle_cards = array();
        foreach ($bundlesModel->get_active_for_creator($user['user_id']) as $b) {
            $bundle_cards[] = array(
                'id'            => (int) $b['id'],
                'name'          => (string) $b['name'],
                'description'   => (string) $b['description'],
                'price_credits' => (int) $b['price_credits'],
                'price_dollars' => (int) round(((int) $b['price_credits']) / 10),
                'item_count'    => (int) $b['item_count'],
                'owned'         => isset($bundle_unlocked[(int) $b['id']]),
            );
        }

        // Published events this creator is hosting (PRD §23). Access details (venue,
        // link, instructions) are revealed ONLY to a registered attendee or the creator.
        $eventsModel = new EventsModel();
        $make_event_card = function (array $ev) use ($eventsModel, $is_self, $viewer_logged_in, $viewer_id) {
            $ev_tz = (string) ($ev['timezone'] !== '' ? $ev['timezone'] : 'UTC');
            $when  = '';
            try {
                $sd = new DateTime((string) $ev['start_at'], new DateTimeZone('UTC'));
                $sd->setTimezone(new DateTimeZone($ev_tz));
                $when = $sd->format('D, M j, Y · g:i A');
                if (!empty($ev['end_at'])) {
                    $ed = new DateTime((string) $ev['end_at'], new DateTimeZone('UTC'));
                    $ed->setTimezone(new DateTimeZone($ev_tz));
                    $when .= ($sd->format('Y-m-d') === $ed->format('Y-m-d'))
                        ? ' – ' . $ed->format('g:i A')
                        : ' – ' . $ed->format('M j, g:i A');
                }
                $when .= ' ' . $sd->format('T');
            } catch (\Throwable $x) { $when = ''; }

            $registered = (!$is_self && $viewer_logged_in) ? $eventsModel->is_registered((int) $ev['id'], $viewer_id) : false;
            $attendees  = (int) ($ev['attendees'] ?? $eventsModel->attendee_count((int) $ev['id']));
            $capacity   = (int) $ev['capacity'];
            $in_person  = (($ev['format'] ?? 'virtual') === 'in_person');
            $end_ts     = strtotime((string) (!empty($ev['end_at']) ? $ev['end_at'] : $ev['start_at']) . ' UTC');
            $card = array(
                'id'            => (int) $ev['id'],
                'title'         => (string) $ev['title'],
                'description'   => (string) $ev['description'],
                'when'          => $when,
                'access_type'   => (string) $ev['access_type'],
                'price_credits' => (int) $ev['price_credits'],
                'price_dollars' => number_format(((int) $ev['price_credits']) / 10, 2),
                'attendees'     => $attendees,
                'capacity'      => $capacity,
                'is_full'       => ($capacity > 0 && $attendees >= $capacity),
                'is_online'     => !$in_person,
                'is_inperson'   => $in_person,
                'is_past'       => $end_ts !== false && $end_ts < time(),
                'is_canceled'   => ((string) $ev['status'] === 'canceled'),
                'is_draft'      => ((string) $ev['status'] === 'draft'),
                'registered'    => $registered,
                'is_self'       => $is_self,
            );
            if ($registered || $is_self) {
                $card['access'] = array(
                    'url'          => $in_person ? '' : (string) $ev['external_url'],
                    'location'     => $in_person ? (string) $ev['location'] : '',
                    'instructions' => (string) $ev['access_instructions'],
                );
            }
            return $card;
        };

        // /@handle/events/<id>: one event's own page (the link a creator shares). Live or canceled
        // events are public; a draft is visible only to its creator.
        $url_parts   = Main::get_url();
        $focus_event = null;
        if (($url_parts[1] ?? '') === 'events' && ctype_digit((string) ($url_parts[2] ?? ''))) {
            $row = $eventsModel->get_one((int) $user['user_id'], (int) $url_parts[2]);
            if (!$row || ((string) $row['status'] === 'draft' && !$is_self)) { Errors::page_not_found(); return; }
            $focus_event = $make_event_card($row);
        }

        // Published events this creator is hosting (PRD §23). Access details (venue,
        // link, instructions) are revealed ONLY to a registered attendee or the creator.
        $event_cards = array();
        foreach ($eventsModel->list_public_for_creator($user['user_id']) as $ev) {
            $event_cards[] = $make_event_card($ev);
        }

        // Published services this creator sells (PRD §22). Booking + delivery details
        // are revealed ONLY to a buyer (or the creator).
        $servicesModel = new ServicesModel();
        $service_cards = array();
        foreach ($servicesModel->list_public_for_creator($user['user_id']) as $sv) {
            $purchased = (!$is_self && $viewer_logged_in) ? $servicesModel->has_purchased((int) $sv['id'], $viewer_id) : false;
            $purchases = (int) $sv['purchases'];
            $capacity  = (int) $sv['capacity'];
            $card = array(
                'id'            => (int) $sv['id'],
                'name'          => (string) $sv['name'],
                'description'   => (string) $sv['description'],
                'price_credits' => (int) $sv['price_credits'],
                'price_dollars' => number_format(((int) $sv['price_credits']) / 10, 2),
                'duration_min'  => (int) $sv['duration_min'],
                'delivery_method' => (string) $sv['delivery_method'],
                'category'      => (string) $sv['category'],
                'refund_policy' => (string) $sv['refund_policy'],
                'capacity'      => $capacity,
                'purchases'     => $purchases,
                'is_full'       => ($capacity > 0 && $purchases >= $capacity),
                'purchased'     => $purchased,
                'is_self'       => $is_self,
            );
            if ($purchased || $is_self) {
                $card['access'] = array(
                    'method'         => (string) $sv['delivery_method'],
                    'scheduling_url' => (string) $sv['scheduling_url'],
                    'details'        => (string) $sv['delivery_details'],
                );
            }
            $service_cards[] = $card;
        }

        // Published Content Studio posts, gated per audience. Entitlement is decided
        // HERE (server-side): the real media URL is signed only for entitled viewers;
        // everyone else gets only the blurred locked preview — the clear rendition is
        // never signed for them, so it never reaches the browser.
        $posts_model    = new PostsModel();
        $published      = $posts_model->get_published_for_creator($user['user_id']);
        $max_tier_price = $viewer_logged_in
            ? (new CreatorSubscriptionsModel())->max_active_tier_price($viewer_id, $user['user_id'])
            : null;
        $plan_prices = array();
        foreach ((new CreatorPlansModel())->get_for_user($user['user_id']) as $pl) {
            $plan_prices[(int) $pl['id']] = (int) $pl['price_cents'];
        }

        // Which of these posts the viewer has already liked.
        $post_ids  = array_map(function ($p) { return (int) $p['id']; }, $published);
        $liked_map = ($viewer_logged_in && !empty($post_ids))
            ? (new PostLikesModel())->liked_map($viewer_id, $post_ids)
            : array();
        $unlocked_map = ($viewer_logged_in && !empty($post_ids))
            ? (new PpvUnlocksModel())->unlocked_map($viewer_id, $post_ids)
            : array();

        // Moderation gate: content must be scanned before it's available to viewers.
        //  - 'pending' (an image not yet cleared) → hidden from everyone but the creator.
        //  - 'flagged' (adult) → hidden from viewers with "show adult content" off.
        // No owner exemption: the creator sees exactly what a visitor with their settings sees.
        // Logged-out viewers default to adult OFF.
        $show_adult = false;
        if ($viewer_logged_in) {
            $viewer_rows = $this->userModel->get_user_by_id($viewer_id);
            $viewer_row  = (is_array($viewer_rows) && count($viewer_rows) === 1) ? $viewer_rows[0] : null;
            $show_adult  = !empty($viewer_row['adult_content_enabled']);
        }
        // Always compute the gate (even for the creator) so 'blocked' content is hidden
        // from EVERYONE, including the creator's own public profile.
        $moderation_map = !empty($post_ids) ? $posts_model->moderation_map($post_ids) : array();
        $tier_map       = !empty($post_ids) ? $posts_model->tiers_for_posts($post_ids) : array();

        $content_cards = array();
        foreach ($published as $p) {
            $mod = $moderation_map[(int) $p['id']] ?? '';
            if ($mod === 'blocked') { continue; }                       // quarantined — never shown to anyone
            if ($mod === 'pending') { continue; }                       // not yet scanned — not available to anyone
            if ($mod === 'adult' && !$show_adult) { continue; }         // approved adult — viewer opted out
            $audience = $p['audience'];
            if ($audience === 'free') {
                $entitled = true;
            } elseif ($audience === 'ppv') {
                // PPV is locked for everyone (incl. subscribers) until purchased.
                $entitled = isset($unlocked_map[(int) $p['id']]);
            } else { // subscribers-only: any of the post's tiers (or any active plan when none set)
                $entitled = PostsModel::tier_entitled($p, $tier_map[(int) $p['id']] ?? array(), (array) $subscribed_plan_ids, $max_tier_price, $plan_prices);
            }

            $assets = $posts_model->get_assets((int) $p['id']);
            $cover  = null;
            foreach ($assets as $a) { if ((int) $a['is_cover'] === 1) { $cover = $a; break; } }
            if (!$cover && !empty($assets)) { $cover = $assets[0]; }
            $cap = trim((string) $p['caption']);

            $card = array(
                'id'           => (int) $p['id'],
                'caption'      => $cap,
                'excerpt'      => mb_substr($cap, 0, 120),
                'audience'     => $audience,
                'entitled'     => $entitled,
                'published_at' => !empty($p['published_at']) ? date('M j, Y', strtotime((string) $p['published_at'])) : '',
                'media_count'  => count($assets),
                'has_video'    => false,
                'cover'        => '',   // uniform grid thumbnail
                'likes'        => (int) $p['likes'],
                'liked'        => isset($liked_map[(int) $p['id']]),
                'comments'     => (int) $p['comments'],
                'views'        => (int) $p['views'],
                'comments_enabled' => (int) $p['comments_enabled'],
            );
            if ($audience === 'ppv') {
                $card['ppv_price_credits'] = (int) $p['ppv_price_credits'];
                $card['ppv_price_dollars'] = (int) round(((int) $p['ppv_price_credits']) / 10);
                $card['unlocked']          = isset($unlocked_map[(int) $p['id']]);
            }

            if ($entitled) {
                // PPV is bought per-post — an entitled viewer paid (or owns it), so serve it
                // unwatermarked. Free/subscriber content keeps the watermarked display variant.
                $img_variant = ($audience === 'ppv') ? 'original' : 'display';
                $card['assets'] = array();
                foreach ($assets as $a) {
                    if (!empty($a['deleted_at']) || $a['status'] !== 'ready') { continue; }
                    if ($a['type'] === 'video') {
                        $card['has_video'] = true;
                        $card['assets'][] = array('type' => 'video',
                            'url' => MediaService::signed_variant($a, 'original', 900),
                            'poster' => MediaService::signed_variant($a, 'poster', 900));
                    } else {
                        $card['assets'][] = array('type' => 'image',
                            'url' => MediaService::signed_variant($a, $img_variant, 900), 'poster' => '');
                    }
                }
                // Grid card uses a small, uniform thumbnail (poster for a video cover).
                $card['cover'] = $cover ? MediaService::signed_variant($cover, ($cover['type'] === 'video' ? 'poster' : 'thumb'), 900) : '';
            } else {
                // Non-entitled: only the blurred variant is ever signed.
                $card['cover'] = $cover ? MediaService::signed_variant($cover, 'blurred', 900) : '';
                $card['locked_url'] = $card['cover'];
            }
            $content_cards[] = $card;
        }

        $viewer_credit_balance = $viewer_logged_in ? (new CreditsModel())->get_balance($viewer_id) : 0;

        require Main::app_path() . '/app/views/profile/view.php';
    }

    /**
     * Verify a returning Checkout session against the creator's connected account
     * and record the paid membership. Guards against replay: the session metadata's
     * subscriber must match the signed-in viewer. Returns true when recorded.
     */
    private function record_checkout_success($session_id, $creator, $viewer_id){
        $connect_id = (string) ($creator['stripe_connect_account_id'] ?? '');
        if ($connect_id === '') {
            return false;
        }

        $session = StripeService::retrieve_checkout_session($connect_id, $session_id);
        if (($session['status'] ?? '') !== 'complete' || ($session['payment_status'] ?? '') !== 'paid') {
            return false;
        }
        // An old success URL stays "complete + paid" forever; only a still-live subscription may
        // (re)activate the membership, or a fan could cancel and replay the link for free access.
        if (!in_array((string) ($session['subscription_status'] ?? ''), array('active', 'trialing'), true)) {
            return false;
        }

        $meta = $session['metadata'] ?? array();
        if ((int) ($meta['subscriber_id'] ?? 0) !== (int) $viewer_id
            || (int) ($meta['creator_id'] ?? 0) !== (int) $creator['user_id']) {
            return false;
        }

        $plan = (new CreatorPlansModel())->get_public((int) ($meta['plan_id'] ?? 0));
        if (!$plan) {
            return false;
        }

        $subs = new CreatorSubscriptionsModel();
        $subs->record_paid($viewer_id, (int) $creator['user_id'], $plan, $session);
        // Reloading the success URL must not re-count the discount code or re-send the notices.
        if (!$subs->claim_checkout_recorded((string) ($session['subscription_id'] ?? ''))) { return true; }
        $cname = Notify::name_of((int) $creator['user_id']); $chandle = Notify::handle_of((int) $creator['user_id']);
        Notify::send((int) $viewer_id, 'subscriptions', 'You\'re subscribed to ' . ($cname !== '' ? $cname : $plan['name']), $plan['name'] . ' · $' . number_format(((int) $plan['price_cents']) / 100, 2) . ' per ' . (string) ($plan['billing_interval'] ?? 'month') . '. Manage it in Settings › My Subscriptions.', $chandle !== '' ? '/@' . $chandle : '/', 'fa-heart');
        Notify::send((int) $creator['user_id'], 'subscriptions', 'New subscriber', (Notify::name_of((int) $viewer_id) ?: 'Someone') . ' subscribed to ' . $plan['name'] . '.', '/audience', 'fa-user-plus');
        InboxAutomationService::trigger((int) $creator['user_id'], (int) $viewer_id, 'new_subscriber');
        if (!empty($meta['promo_id'])) {
            (new CreatorPromoCodesModel())->redeem((int) $meta['promo_id']);   // count the discount-code use
        }
        return true;
    }
}
