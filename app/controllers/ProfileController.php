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

        $tags = array();
        foreach (explode(',', (string) ($profile['tags'] ?? '')) as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        $handle        = $user['u_name'];
        $public_domain = Main::public_domain();

        // Viewer context for the follow / self-view actions.
        $follows         = new FollowsModel();
        $viewer_id       = (int) Session::get('user_id');
        $is_self         = ($viewer_id === (int) $user['user_id']);
        $viewer_logged_in = ($viewer_id > 0);
        $is_following    = (!$is_self && $viewer_logged_in) ? $follows->is_following($viewer_id, $user['user_id']) : false;
        $follower_count  = $follows->count_followers($user['user_id']);
        $member_since    = !empty($user['creator_since']) ? date('F Y', strtotime((string) $user['creator_since'])) : '';

        // Returning from Stripe Checkout: record the paid membership before rendering.
        $sub_notice = '';
        if (($_GET['sub'] ?? '') === 'cancel') {
            $sub_notice = 'cancel';
        } elseif (($_GET['sub'] ?? '') === 'success' && !empty($_GET['session_id']) && $viewer_logged_in) {
            $sub_notice = $this->record_checkout_success((string) $_GET['session_id'], $user, $viewer_id) ? 'success' : '';
        }

        $subscribed_plan_ids = ($viewer_logged_in && !$is_self)
            ? (new CreatorSubscriptionsModel())->active_plan_ids($viewer_id, $user['user_id'])
            : array();

        // Content feed temporarily disabled: the old content_items/content_assets system
        // was removed in the Content Studio rewrite. Fans will see the new posts once the
        // profile is rewired to the new schema (tracked as a separate task). Until then the
        // profile renders with no content cards rather than referencing removed models.
        $content_cards = array();
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

        $meta = $session['metadata'] ?? array();
        if ((int) ($meta['subscriber_id'] ?? 0) !== (int) $viewer_id
            || (int) ($meta['creator_id'] ?? 0) !== (int) $creator['user_id']) {
            return false;
        }

        $plan = (new CreatorPlansModel())->get_public((int) ($meta['plan_id'] ?? 0));
        if (!$plan) {
            return false;
        }

        (new CreatorSubscriptionsModel())->record_paid($viewer_id, (int) $creator['user_id'], $plan, $session);
        return true;
    }
}
