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
        $guest = (int) Session::get('user_id') <= 0;
        if ($guest && !EventsModel::open_call($ev)) { $this->view->login_form(); return; }
        $handle = Notify::handle_of((int) $ev['creator_id']);
        list($opens, $closes) = LiveAccess::window($ev);
        $host = LiveAccess::is_host((int) $ev['creator_id']);
        $this->show(array(
            'kind' => 'event', 'id' => (int) $ev['id'],
            'title' => html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8'),
            'when' => EventRefunds::when($ev, (int) Session::get('user_id')),
            'host_name' => Notify::name_of((int) $ev['creator_id']),
            'back' => $host ? '/events/manage/' . (int) $ev['id'] : ($handle !== '' ? '/@' . rawurlencode($handle) . '/events/' . (int) $ev['id'] : '/'),
            'opens_at' => $opens, 'closes_at' => $closes, 'is_host' => $host, 'guest' => $guest,
            'open' => EventsModel::open_call($ev),
            'password' => $host ? trim((string) ($ev['call_password'] ?? '')) : '',   // the host sees it so they can share it
            'needs_password' => !$host && trim((string) ($ev['call_password'] ?? '')) !== '',
        ));
    }

    public function bookingAction(){
        if ((int) Session::get('user_id') <= 0) { $this->view->login_form(); return; }
        $id = (int) (Main::get_url()[2] ?? 0);
        $services = new ServicesModel();
        $p  = $services->purchase_by_id($id);
        $sv = $p ? $services->get_by_id((int) $p['service_id']) : null;
        if (!$sv || (string) ($sv['delivery_method'] ?? '') !== 'cls_video' || !LiveKit::enabled()) { Errors::page_not_found(); return; }
        $host = LiveAccess::is_host((int) $sv['creator_id']);
        $handle = Notify::handle_of((int) $sv['creator_id']);
        $this->show(array(
            'kind' => 'booking', 'id' => (int) $p['id'],
            'title' => html_entity_decode((string) $sv['name'], ENT_QUOTES, 'UTF-8'),
            'when' => $host ? 'Booked by ' . (Notify::name_of((int) $p['buyer_id']) ?: 'a fan') : 'Your booking',
            'host_name' => Notify::name_of((int) $sv['creator_id']),
            'back' => $host ? '/services/manage/' . (int) $sv['id'] : ($handle !== '' ? '/@' . rawurlencode($handle) . '/services/' . (int) $sv['id'] : '/'),
            'opens_at' => 0, 'closes_at' => 0, 'is_host' => $host, 'guest' => false, 'open' => false, 'password' => '', 'needs_password' => false,
        ));
    }

    private function show(array $call){
        $call['me_name'] = $call['guest'] ? '' : Notify::name_of((int) Session::get('user_id'));
        if ($call['guest']) {   // no app shell for visitors without an account: the site banner + the call
            $c = $call; $view = $this->view;
            require Main::app_path() . '/app/views/live/guest_frame.php';
            return;
        }
        $this->view->call = $call;
        $this->view->render();
    }
}
