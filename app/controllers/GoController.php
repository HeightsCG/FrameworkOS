<?php
/**
 * Outbound link click-through: /go/<link_id>. Records a click for the creator's
 * link, then 302-redirects to the destination the creator configured. Public (no
 * auth) — profile-link clicks come from anyone. The destination is never taken from
 * user input; it's the stored URL for that link id, so this is not an open redirect.
 */
class GoController extends Controller {

    public $protected = 0;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        $url = Main::get_url();
        $seg = (string) ($url[1] ?? '');
        if ($seg !== '' && !ctype_digit($seg)) { $this->inbound($seg); }   // /go/<code>: an inbound tracking link
        $id  = (int) $seg;   // /go/<id> (dispatched from Bootstrap)

        $link = $id > 0 ? (new CreatorLinksModel())->get_public($id) : null;
        if (!$link || (int) $link['is_enabled'] !== 1) {
            header('Location: /', true, 302);
            exit;
        }

        // Only follow http/https destinations (defence-in-depth against a bad stored value).
        // Links saved before links were stored plain still carry &amp; etc.: decode defensively.
        $dest = trim(html_entity_decode((string) $link['url'], ENT_QUOTES, 'UTF-8'));
        if (!preg_match('#^https?://#i', $dest)) {
            header('Location: /', true, 302);
            exit;
        }

        (new LinkClicksModel())->record((int) $link['id'], (int) $link['user_id']);
        header('Location: ' . $dest, true, 302);
        exit;
    }

    /**
     * Inbound tracking link /go/<code>: count the click, set the cls_tl cookie (TrackingLinks), then go to the
     * creator's page (or the deeper path they chose) at its canonical address, their own domain when they have one,
     * with ?tl=<code> so the page sets the cookie on that host too. clssim<id> is the system link behind the
     * "More Creators Like This" block (?from=<profile id>); its row is made only for a directory-eligible creator.
     */
    private function inbound($code){
        $m = new TrackingLinksModel();
        $link = TrackingLinks::valid_code($code) ? $m->get_by_code($code) : null;
        $u = null;
        if (!$link && preg_match('/^clssim(\d+)$/i', $code, $mt)) {
            $u = $this->creator((int) $mt[1]);
            if ($u && (string) ($u['user_status'] ?? '') === 'Active' && (new CreatorProfileModel())->directory_eligible((int) $u['user_id'])) {
                $link = $m->system_link((int) $u['user_id'], TrackingLinks::similar_code((int) $u['user_id']), TrackingLinks::SIMILAR_LABEL);
            } elseif ($u) {
                header('Location: ' . CustomDomains::canonical_profile_url($u), true, 302);   // not in the directory: no row, no click
                exit;
            }
        }
        if ($link && !$u) { $u = $this->creator((int) $link['creator_id']); }
        if (!$link || !$u) {
            header('Location: /', true, 302);
            exit;
        }
        $from = (strpos((string) $link['code'], 'clssim') === 0) ? (int) ($_GET['from'] ?? 0) : 0;
        try { $m->record_event((int) $link['id'], (int) $link['creator_id'], 'click', (int) Session::get('user_id'), 0, $from > 0 ? 'similar_from' : '', $from); }
        catch (\Throwable $e) { error_log('[tracking_links] click: ' . $e->getMessage()); }
        TrackingLinks::set_cookie($link);
        $base = CustomDomains::canonical_profile_url($u);   // 'https://lexivaughn.com/' or 'https://www.../@handle'
        $path = TrackingLinks::clean_path($link['target_path']);
        $dest = rtrim($base, '/') . ($path !== '' ? $path : (substr($base, -1) === '/' ? '/' : ''));
        header('Location: ' . $dest . '?tl=' . rawurlencode((string) $link['code']), true, 302);
        exit;
    }

    /** The account behind a tracking link, while it is an active creator. */
    private function creator($user_id){
        $users = new UsersModel();
        $rows  = $users->get_user_by_id((int) $user_id);
        $u = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        return ($u && empty($u['deleted']) && (string) ($u['u_name'] ?? '') !== '' && (int) $u['role_id'] === (int) $users->get_role_id_by_name('Creator')) ? $u : null;
    }
}
