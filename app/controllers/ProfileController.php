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

        // Free never has a public profile: a creator page exists only while they have a paid plan.
        if (!Plan::has_paid_plan($user)) {
            Errors::page_not_found();
            return;
        }

        // A creator with their own domain: logged-out visitors (and crawlers) go there, so the page has one address.
        // Signed-in fans stay here, where their session is. 302 (the canonical tag carries the SEO): a cached 301
        // would keep sending people to the domain after the creator removes it.
        if ((int) Session::get('user_id') <= 0 && !CustomDomains::is_home_of($handle)) {
            $canonical = CustomDomains::canonical_profile_url($user);
            if (strpos($canonical, '/@') === false) {
                $rest  = implode('/', array_slice($url, 1));
                $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
                header('Location: ' . $canonical . $rest . ($query !== '' ? '?' . $query : ''), true, 302);
                exit;
            }
        }

        TrackingLinks::from_query((int) $user['user_id']);   // ?tl= from a /go tracking link: remember it on this host too
        $profile = (new CreatorProfileModel())->get_for_user($user['user_id']);

        $links = array();
        foreach ((new CreatorLinksModel())->get_for_user($user['user_id']) as $link) {
            if (!empty($link['is_enabled'])) {
                $links[] = $link;
            }
        }

        $plans = (new CreatorPlansModel())->get_active_for_user($user['user_id']);

        // Display fields with sensible fallbacks.
        $display_name = trim(html_entity_decode((string) $profile['display_name'], ENT_QUOTES, 'UTF-8'));   // older rows were stored HTML-encoded
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
                'price_dollars' => Price::input((int) $b['price_credits']),
                'item_count'    => (int) $b['item_count'],
                'owned'         => isset($bundle_unlocked[(int) $b['id']]),
            );
        }

        // Published events this creator is hosting (PRD §23). Access details (venue,
        // link, instructions) are revealed ONLY to a registered attendee or the creator.
        $eventsModel = new EventsModel();
        $make_event_card = function (array $ev) use ($eventsModel, $is_self, $viewer_logged_in, $viewer_id, $plans) {
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
                // Event list: a date tile (SEP / 26) and a short time line ("Sat · 6:30 – 8:00 PM EDT").
                $tile_month = $sd->format('M'); $tile_day = $sd->format('j'); $tile_wday = $sd->format('D');
                $date_long  = $sd->format('l, F j, Y');
                $time_range = $sd->format('g:i A') . (isset($ed) ? ' – ' . ($sd->format('Y-m-d') === $ed->format('Y-m-d') ? $ed->format('g:i A') : $ed->format('M j, g:i A')) : '') . ' ' . $sd->format('T');
                $time_line  = $sd->format('D') . ' · ' . $sd->format('g:i A') . (isset($ed) && $sd->format('Y-m-d') === $ed->format('Y-m-d') ? ' – ' . $ed->format('g:i A') : '') . ' ' . $sd->format('T');
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
            // An in-person event's address is public (people need to know where it is before they register).
            $dec = function ($k) use ($ev) { return trim(html_entity_decode((string) ($ev[$k] ?? ''), ENT_QUOTES, 'UTF-8')); };
            $city_line = implode(', ', array_filter(array($dec('city'), trim($dec('region') . ' ' . $dec('postal_code'))), 'strlen'));
            $structured = ($dec('venue_name') !== '' || $dec('street') !== '' || $city_line !== '');
            $card['tile_month'] = $tile_month ?? ''; $card['tile_day'] = $tile_day ?? ''; $card['tile_wday'] = $tile_wday ?? ''; $card['time_line'] = $time_line ?? $when;
            $card['date_long'] = $date_long ?? $when; $card['time_range'] = $time_range ?? '';
            $card['tier_name'] = '';
            if ((string) $ev['access_type'] === 'tier') {
                foreach ((array) $plans as $pl) { if ((int) $pl['id'] === (int) $ev['tier_id']) { $card['tier_name'] = html_entity_decode((string) $pl['name'], ENT_QUOTES, 'UTF-8'); break; } }
            }
            $card['place'] = array(   // venue / street / city lines; older events only have the one-line location
                'venue'     => $in_person ? $dec('venue_name') : '',
                'street'    => $in_person ? ($structured ? $dec('street') : $dec('location')) : '',
                'city_line' => $in_person ? $city_line : '',
                'line'      => $in_person ? $dec('location') : '',
                'short'     => $in_person ? implode(', ', array_filter(array($dec('venue_name') !== '' ? $dec('venue_name') : $dec('street'), $dec('city')), 'strlen')) : '',
            );
            if ($in_person && $card['place']['short'] === '') { $card['place']['short'] = $dec('location'); }
            // Attendee instructions are for registered attendees only. The meeting link is never on the page: it only
            // travels by email (registration confirmation + reminder). The creator sees the page exactly as fans do.
            if ($registered) {
                $card['access'] = array('instructions' => (string) $ev['access_instructions']);
            }
            // CLS Video: attendees join on this page. The call opens a little before the start and stays open a little after.
            $card['is_video'] = ((string) ($ev['format'] ?? '') === 'cls_video') && LiveKit::enabled();
            if ($card['is_video']) {
                list($v_open, $v_close) = LiveAccess::window($ev);
                $card['video_phase'] = LiveAccess::event_phase($ev);
                // After the scheduled end the call stays joinable for a while so an overrun isn't cut off, but the page
                // only offers "Join Video" then if the host is actually still in the call; otherwise it has ended.
                $sched_end = !empty($ev['end_at']) ? strtotime((string) $ev['end_at'] . ' UTC') : strtotime((string) $ev['start_at'] . ' UTC') + 3600;
                if ($card['video_phase'] === 'open' && time() > $sched_end) {
                    $live_now = LiveKit::room_status(LiveKit::room_for_event((int) $ev['id']));
                    if (!$live_now || empty($live_now['host_in'])) { $card['video_phase'] = 'closed'; }
                }
                $card['video_opens'] = isset($sd) ? (clone $sd)->setTimestamp($v_open)->format('g:i A T') : '';
                $card['video_url']   = '/live/event/' . (int) $ev['id'];
                $card['open_call']    = EventsModel::open_call($ev);
                $card['has_password'] = trim((string) ($ev['call_password'] ?? '')) !== '';
            }
            $card['replay_price'] = (int) ($ev['replay_price_credits'] ?? 0);   // > 0: its replay is on sale (the Events tab says so)
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
            // Who may register: anyone (free / paid) or subscribers (any plan, or one specific plan).
            $fe_access = (string) $row['access_type'];
            $focus_event['tier_name'] = '';
            if ($fe_access === 'tier') {
                foreach ((array) $plans as $pl) { if ((int) $pl['id'] === (int) $row['tier_id']) { $focus_event['tier_name'] = html_entity_decode((string) $pl['name'], ENT_QUOTES, 'UTF-8'); break; } }
            }
            $focus_event['eligible'] = !in_array($fe_access, array('subscribers', 'tier'), true)
                || ($fe_access === 'subscribers' && !empty($subscribed_plan_ids))
                || ($fe_access === 'tier' && in_array((int) $row['tier_id'], array_map('intval', (array) $subscribed_plan_ids), true));
            $focus_event['is_live'] = ((string) $row['status'] === 'published');
            $mine = ($viewer_logged_in && !empty($focus_event['registered'])) ? $eventsModel->going_registration((int) $row['id'], $viewer_id) : null;
            $focus_event['my_paid'] = $mine ? (int) $mine['price_credits'] : 0;   // shown in the cancel dialog (refunded before the start)
            // A replay on sale (one of the call's recordings): watched here by the host, buyers and (if chosen) registered people.
            $rp = EventReplay::info($row);
            $rp_access = $rp ? EventReplay::access($row, $rp, $viewer_id) : '';
            // Shown while it's on sale; once taken off sale, only to people who can still watch it.
            $focus_event['replay'] = ($rp && ($rp['on_sale'] || $rp_access !== '')) ? array('price' => $rp['price'], 'on_sale' => $rp['on_sale'],
                'length' => EventReplay::length($rp['duration']), 'free_attendees' => $rp['free_attendees'], 'access' => $rp_access) : null;
        }

        // Published events this creator is hosting (PRD §23). Access details (venue,
        // link, instructions) are revealed ONLY to a registered attendee or the creator.
        $event_cards = array();
        foreach ($eventsModel->list_public_for_creator($user['user_id']) as $ev) {
            $event_cards[] = $make_event_card($ev);
        }
        // Ended events stay on the profile ("Past Events"), so nothing a fan saw announced just disappears.
        $past_event_cards = array();
        foreach ($eventsModel->list_past_public_for_creator($user['user_id']) as $ev) {
            $past_event_cards[] = $make_event_card($ev);
        }
        // Replays on sale get their own tab: anyone (follower or not) sees them, buys them there and watches on the event page.
        $replay_cards = array();
        foreach ($eventsModel->list_with_replay($user['user_id']) as $ev) {
            $rp = EventReplay::info($ev);
            if (!$rp) { continue; }
            $acc = EventReplay::access($ev, $rp, $viewer_id);
            if (!$rp['on_sale'] && $acc === '') { continue; }   // off sale: only people who can still watch it see it
            $card = $make_event_card($ev);
            $card['replay'] = array('price' => $rp['price'], 'length' => EventReplay::length($rp['duration']), 'access' => $acc, 'free_attendees' => $rp['free_attendees']);
            $replay_cards[] = $card;
        }

        // Published services this creator sells (PRD §22). The instructions are revealed ONLY to a buyer; the creator
        // sees their own service exactly as a fan does.
        $servicesModel = new ServicesModel();
        $make_service_card = function (array $sv) use ($servicesModel, $is_self, $viewer_logged_in, $viewer_id) {
            $purchased = (!$is_self && $viewer_logged_in) ? $servicesModel->has_purchased((int) $sv['id'], $viewer_id) : false;
            $purchases = (int) ($sv['purchases'] ?? $servicesModel->purchase_count((int) $sv['id']));
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
                'is_live'       => ((string) $sv['status'] === 'published'),
            );
            if ($purchased) {
                $card['access'] = array('method' => (string) $sv['delivery_method'], 'details' => (string) $sv['delivery_details']);
                if ((string) $sv['delivery_method'] === 'cls_video' && LiveKit::enabled()) {   // their private call room
                    $card['video_url'] = '/live/booking/' . $servicesModel->paid_purchase_id((int) $sv['id'], $viewer_id);
                }
            }
            return $card;
        };
        // /@handle/services/<id>: one service's own page (the link a creator shares). A draft is visible only to its creator.
        $focus_service = null;
        if (($url_parts[1] ?? '') === 'services' && ctype_digit((string) ($url_parts[2] ?? ''))) {
            $row = $servicesModel->get_one((int) $user['user_id'], (int) $url_parts[2]);
            if (!$row || ((string) $row['status'] !== 'published' && !$is_self)) { Errors::page_not_found(); return; }
            $focus_service = $make_service_card($row);
        }
        $service_cards = array();
        foreach ($servicesModel->list_public_for_creator($user['user_id']) as $sv) {
            $service_cards[] = $make_service_card($sv);
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
                $card['ppv_price_dollars'] = Price::input((int) $p['ppv_price_credits']);
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
                // Grid card uses a small, uniform thumbnail (poster for a video cover). Free posts use the public,
                // cacheable webp copy when it exists (PublicThumbService); anything else stays a signed URL.
                $pub = ($cover && $audience === 'free') ? PublicThumbService::url($cover, 'grid') : '';
                $card['cover'] = $pub !== '' ? $pub : ($cover ? MediaService::signed_variant($cover, ($cover['type'] === 'video' ? 'poster' : 'thumb'), 900) : '');
                $card['cover_alt'] = PublicThumbService::alt($cap, $display_name);
            } else {
                // Non-entitled: only the blurred variant is ever signed.
                $card['cover'] = $cover ? MediaService::signed_variant($cover, 'blurred', 900) : '';
                $card['locked_url'] = $card['cover'];
            }
            $content_cards[] = $card;
        }

        $viewer_credit_balance = $viewer_logged_in ? (new CreditsModel())->get_balance($viewer_id) : 0;
        // One free trial per fan per creator: someone who has had a paid membership here doesn't see trial offers.
        $trial_used = $viewer_logged_in && !$is_self && (new CreatorSubscriptionsModel())->had_paid_with($viewer_id, (int) $user['user_id']);

        // AI disclosure: an account that has published AI media carries the AI badge and a line saying so.
        $ai_creator = (new PostsModel())->has_published_ai((int) $user['user_id']);

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
        // A second paid checkout with the same creator (it slipped past the one-open-checkout rule): cancel it in
        // Stripe instead of recording it over the first membership, which would leave that one billing unseen.
        if ($subs->has_other_active_paid($viewer_id, (int) $creator['user_id'], (string) ($session['subscription_id'] ?? ''))) {
            StripeService::cancel_subscription_now($connect_id, (string) $session['subscription_id']);
            error_log('[membership] duplicate canceled: fan=' . (int) $viewer_id . ' creator=' . (int) $creator['user_id'] . ' sub=' . (string) $session['subscription_id']);
            return false;
        }
        $subs->record_paid($viewer_id, (int) $creator['user_id'], $plan, $session + array('sub_status' => (string) ($session['subscription_status'] ?? '')));
        // Reloading the success URL must not re-count the discount code or re-send the notices.
        if (!$subs->claim_checkout_recorded((string) ($session['subscription_id'] ?? ''))) { return true; }
        TrackingLinks::attribute((int) $creator['user_id'], 'subscription', (int) $viewer_id, (string) ($session['subscription_status'] ?? '') === 'trialing' ? 0 : (int) round((int) ($session['amount_total'] ?? 0) / 10), 'creator_plans', (int) $plan['id']);   // what the card paid, in credits ($1 = 10); 0 on a trial
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
