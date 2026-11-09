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

        // A suspended account's page is gone while it is suspended; only creators have a public profile; Free never has one
        // (a creator page exists only while they have a paid plan). ProfilePosts::creator_visible also gates "Load More".
        if (!ProfilePosts::creator_visible($user, 0)) {
            Errors::page_not_found();
            return;
        }

        Session::cache_header(300);   // a signed-out read has no session and no cookie, so it may be cached briefly; a signed-in one stays private
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

        // Events and services were removed (2026-11-09): their old shared links (/@handle/events/<id>, /@handle/services/<id>) are gone.
        $url_parts = Main::get_url();
        if (in_array((string) ($url_parts[1] ?? ''), array('events', 'services'), true)) { Errors::page_not_found(); return; }

        // Published Content Studio posts: the first page here, the rest through /api/profile_posts ("Load More").
        $posts_page    = ProfilePosts::page($user, $display_name, $viewer_id, 0);
        $content_cards = $posts_page['cards'];

        // AI disclosure: an account that has published AI media carries the AI badge and a line saying so.
        $ai_creator = (new PostsModel())->has_published_ai((int) $user['user_id']);

        // An empty page (no post) is not indexed: the same test the sitemap uses.
        $seo_thin = false;
        try { $seo_thin = !(new UsersModel())->has_public_content((int) $user['user_id']); } catch (\Throwable $e) { error_log('[profile] thin check: ' . $e->getMessage()); }

        require Main::app_path() . '/app/views/profile/view.php';
    }
}
