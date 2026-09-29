<?php
/**
 * CLS Video call page: /live/event/<event id> and /live/booking/<service purchase id>.
 * Signed-in only. The page itself shows no secrets: it asks /api/live_join for a pass when the person clicks Join,
 * and LiveAccess decides there. Here we only work out what to show (title, time, where "Back" goes).
 */
class LiveController extends Controller {

    // Guests may open a free event's call (open to everyone); everything else asks them to sign in (see below).
    public $protected = 0;

    public function __construct(){
        parent::__construct();
    }

    public function eventAction(){
        $id = (int) (Main::get_url()[2] ?? 0);
        $ev = (new EventsModel())->get_public($id);
        if (!$ev || (string) ($ev['format'] ?? '') !== 'cls_video' || !LiveKit::enabled()) { Errors::page_not_found(); return; }
        $this->keep_on_own_domain((int) $ev['creator_id']);
        $guest = (int) Session::get('user_id') <= 0;
        if ($guest && !EventsModel::open_call($ev)) { $this->sign_in(); return; }
        $handle = Notify::handle_of((int) $ev['creator_id']);
        list($opens, $closes) = LiveAccess::window($ev);
        $host = LiveAccess::is_host((int) $ev['creator_id']);
        $this->show(array(
            'kind' => 'event', 'id' => (int) $ev['id'],
            'title' => html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8'),
            'when' => EventRefunds::when($ev, (int) Session::get('user_id')),
            'host_name' => Notify::name_of((int) $ev['creator_id']),
            'back' => ($host && !CustomDomains::current()) ? '/events/manage/' . (int) $ev['id'] : ($handle !== '' ? CustomDomains::profile_path($handle) . '/events/' . (int) $ev['id'] : '/'),
            'opens_at' => $opens, 'closes_at' => $closes, 'is_host' => $host, 'guest' => $guest,
            'open' => EventsModel::open_call($ev),
            'password' => $host ? trim((string) ($ev['call_password'] ?? '')) : '',   // the host sees it so they can share it
            'needs_password' => !$host && trim((string) ($ev['call_password'] ?? '')) !== '',
        ));
    }

    public function bookingAction(){
        if ((int) Session::get('user_id') <= 0) { $this->sign_in(); return; }
        $id = (int) (Main::get_url()[2] ?? 0);
        $services = new ServicesModel();
        $p  = $services->purchase_by_id($id);
        $sv = $p ? $services->get_by_id((int) $p['service_id']) : null;
        if (!$sv || (string) ($sv['delivery_method'] ?? '') !== 'cls_video' || !LiveKit::enabled()) { Errors::page_not_found(); return; }
        $this->keep_on_own_domain((int) $sv['creator_id']);
        $host = LiveAccess::is_host((int) $sv['creator_id']);
        $handle = Notify::handle_of((int) $sv['creator_id']);
        $this->show(array(
            'kind' => 'booking', 'id' => (int) $p['id'],
            'title' => html_entity_decode((string) $sv['name'], ENT_QUOTES, 'UTF-8'),
            'when' => $host ? 'Booked by ' . (Notify::name_of((int) $p['buyer_id']) ?: 'a fan') : 'Your booking',
            'host_name' => Notify::name_of((int) $sv['creator_id']),
            'back' => ($host && !CustomDomains::current()) ? '/services/manage/' . (int) $sv['id'] : ($handle !== '' ? CustomDomains::profile_path($handle) . '/services/' . (int) $sv['id'] : '/'),
            'opens_at' => 0, 'closes_at' => 0, 'is_host' => $host, 'guest' => false, 'open' => false, 'password' => '', 'needs_password' => false,
        ));
    }

    /** On a creator's own domain, only their own calls: anyone else's goes to the platform address. */
    private function keep_on_own_domain(int $creator_id): void{
        $d = CustomDomains::current();
        if ($d && (int) $d['user_id'] !== $creator_id) {
            header('Location: ' . CustomDomains::platform_base() . CustomDomains::safe_path($_SERVER['REQUEST_URI'] ?? '/'), true, 302);
            exit;
        }
    }

    /** Sign in first: on a creator's own domain through the platform (it comes back here), else the sign-in page. */
    private function sign_in(): void{
        if (CustomDomains::current()) {
            header('Location: ' . CustomDomains::login_url(CustomDomains::safe_path($_SERVER['REQUEST_URI'] ?? '/')), true, 302);
            exit;
        }
        $this->view->login_form();
    }

    private function show(array $call){
        $call['me_name'] = $call['guest'] ? '' : Notify::name_of((int) Session::get('user_id'));
        // No app shell for visitors without an account (the site banner + the call), nor on a creator's own domain,
        // which is their brand: the call alone, like the rest of their pages there.
        $call['own_domain'] = CustomDomains::current() !== null;
        if ($call['guest'] || $call['own_domain']) {
            $c = $call; $view = $this->view;
            require Main::app_path() . '/app/views/live/guest_frame.php';
            return;
        }
        $this->view->call = $call;
        $this->view->render();
    }
}
