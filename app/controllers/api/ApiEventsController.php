<?php
/** Creator events and registrations. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiEventsController extends BaseApiController {

    /** Create or edit an event (Manager+; times arrive in the creator's tz → stored UTC). */
    public function event_saveAction(){
        $user = $this->require_creator('manage', false);   // Free builds events as drafts
        $creator_id = (int) $user['user_id'];
        $tz = EventsModel::clean_timezone($this->post['timezone'] ?? '', (string) ($user['content_timezone'] ?? 'UTC'));   // the event's own zone; the account's by default
        $id = (int) ($this->post['id'] ?? 0);

        $title = trim(html_entity_decode((string) ($this->post['title'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($title === '') { $this->jsonError('A title is required'); }
        $start_local = trim((string) ($this->post['start_at'] ?? ''));
        if ($start_local === '') { $this->jsonError('A start date & time is required'); }
        $start_utc = $this->to_utc($start_local, $tz);
        $end_local = trim((string) ($this->post['end_at'] ?? ''));
        $end_utc   = $end_local !== '' ? $this->to_utc($end_local, $tz) : '';

        $access = (string) ($this->post['access_type'] ?? 'free');
        if (!in_array($access, EventsModel::access_types(), true)) { $access = 'free'; }
        // A ticket price can apply to any audience (a subscribers-only event can still be paid); 'free' is anyone, no charge.
        // Free events have no price; a paid event needs one; a members event may be free or paid (Price rules).
        $price_credits = ($access === 'free') ? 0 : $this->price_credits($this->post['price'] ?? '', $access !== 'paid');
        $tier_id = ($access === 'tier') ? (int) ($this->post['tier_id'] ?? 0) : 0;
        if ($price_credits > 0 && Plan::can_sell($user)) { CreatorAgreement::require($creator_id); }   // selling needs the Creator Agreement (Free accepts it at checkout)

        $format   = EventsModel::format($this->post['format'] ?? 'virtual');
        if ($format === 'cls_video' && !LiveKit::enabled()) { $this->jsonError('CLS Video is not available yet.'); }
        $link     = trim((string) ($this->post['external_url'] ?? ''));
        $dec = function ($k) { return trim(html_entity_decode((string) ($this->post[$k] ?? ''), ENT_QUOTES, 'UTF-8')); };
        $addr = array('venue_name' => $dec('venue_name'), 'street' => $dec('street'), 'city' => $dec('city'), 'region' => $dec('region'), 'postal_code' => $dec('postal_code'));
        if ($format === 'virtual' && !preg_match('#^https?://#i', $link)) { $this->jsonError('Add the meeting link (it starts with https://).'); }
        if ($format === 'in_person' && ($addr['street'] === '' || $addr['city'] === '')) { $this->jsonError('Add the street address and city.'); }
        $address = EventsModel::address_line($addr);

        $fields = [
            'format'              => $format,
            'title'               => $title,
            'description'         => trim(html_entity_decode((string) ($this->post['description'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'start_at'            => $start_utc,
            'end_at'              => $end_utc,
            'timezone'            => $tz,
            'access_type'         => $access,
            'price_credits'       => $price_credits,
            'tier_id'             => $tier_id,
            'capacity'            => (int) ($this->post['capacity'] ?? 0),
            'location'            => $format === 'in_person' ? $address : '',
            'venue_name'          => $format === 'in_person' ? $addr['venue_name'] : '',
            'street'              => $format === 'in_person' ? $addr['street'] : '',
            'city'                => $format === 'in_person' ? $addr['city'] : '',
            'region'              => $format === 'in_person' ? $addr['region'] : '',
            'postal_code'         => $format === 'in_person' ? $addr['postal_code'] : '',
            'external_url'        => $format === 'virtual' ? $link : '',
            'access_instructions' => trim(html_entity_decode((string) ($this->post['access_instructions'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'call_password'       => $format === 'cls_video' ? html_entity_decode((string) ($this->post['call_password'] ?? ''), ENT_QUOTES, 'UTF-8') : '',
            'call_waiting_room'   => (string) ($this->post['call_waiting_room'] ?? '0') === '1' ? 1 : 0,
            'call_screen_share'   => (string) ($this->post['call_screen_share'] ?? 'host'),
            'call_attendees'      => (string) ($this->post['call_attendees'] ?? 'talk'),
            'call_chat'           => (string) ($this->post['call_chat'] ?? '1') === '0' ? 0 : 1,
            'status'              => (($this->post['status'] ?? 'draft') === 'published') ? 'published' : 'draft',
        ];
        if ($fields['status'] === 'published' && !Plan::can_sell($user)) { $this->need_plan('make an event live'); }
        if (array_key_exists('reminders', $this->post)) { $fields['reminders'] = EventsModel::clean_reminders($this->post['reminders']); }   // e.g. '1440,60'; '' = none
        $model = new EventsModel();
        if ($id > 0) {
            $cur = $model->get_one($creator_id, $id);
            if (!$cur) { $this->jsonError('Event not found'); }
            if ((string) $cur['status'] === 'canceled') { $this->jsonError('This event was canceled, so it can no longer be edited.'); }
            $model->update_event($creator_id, $id, $fields);
            (new LiveRoomsModel())->reset(LiveKit::room_for_event($id));   // the next call starts from the saved call settings
        } else {
            $id = (int) $model->create($creator_id, $fields);
        }
        $this->jsonSuccess(['id' => $id, 'message' => 'Event saved']);
    }

    public function event_deleteAction(){
        $user = $this->require_creator('manage', false);
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Event required'); }
        $model = new EventsModel();
        $ev = $model->get_one((int) $user['user_id'], $id);
        if (!$ev) { $this->jsonError('Event not found'); }
        if ((string) $ev['status'] !== 'canceled') { $this->jsonError('Cancel the event before deleting it.'); }   // cancel refunds and tells attendees first
        $model->delete_event((int) $user['user_id'], $id);
        $this->jsonSuccess();
    }

    /** Register the signed-in user for an event (free / paid-with-credits / subscriber / tier). */
    public function event_registerAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Sign in to register.', ['need_login' => true]); }
        $id = (int) ($this->post['event_id'] ?? 0);
        $model = new EventsModel();
        $ev = $model->get_public($id);
        if (!$ev) { $this->jsonError('Event not found'); }
        // registration closes once the event has ended (the profile page's is_past rule): a late sign-up must not unlock the replay
        $ended_at = strtotime((string) (!empty($ev['end_at']) ? $ev['end_at'] : $ev['start_at']) . ' UTC');
        if ($ended_at !== false && $ended_at < time()) { $this->jsonError('This event has ended.'); }
        $creator_id = (int) $ev['creator_id'];
        if ($creator_id === $me) { $this->jsonError('This is your own event.'); }
        if ((new BlocksModel())->either_blocked($me, $creator_id)) { $this->jsonError('Event not found'); }
        if ($this->seller_suspended($creator_id)) { $this->jsonError('Event not found'); }

        if ($model->is_registered($id, $me)) { $this->jsonSuccess(['already' => true, 'access' => $this->event_access($ev)]); }
        if ((int) $ev['capacity'] > 0 && $model->attendee_count($id) >= (int) $ev['capacity']) {
            $this->jsonError('This event is full.');
        }

        $access = (string) $ev['access_type'];
        if ($access === 'subscribers' || $access === 'tier') {
            $subs = new CreatorSubscriptionsModel();
            $plan_ids = array_map('intval', (array) $subs->active_plan_ids($me, $creator_id));
            $ok = !empty($plan_ids);
            if ($access === 'tier' && (int) $ev['tier_id'] > 0) { $ok = in_array((int) $ev['tier_id'], $plan_ids, true); }
            if (!$ok) { $this->jsonError('This event is for subscribers.', ['need_subscription' => true]); }
        }
        $price = ($access === 'free') ? 0 : (int) $ev['price_credits'];   // subscriber events can carry a ticket price too
        if ($price > 0) {
            $credits = new CreditsModel();
            if ($credits->get_balance($me) < $price) {
                $this->jsonError('Not enough credits in your wallet.', ['need_credits' => true, 'price' => $price, 'balance' => $credits->get_balance($me)]);
            }
        }
        // Take the seat first (UNIQUE event_id+user_id is the mutex against a double charge), then charge.
        $claim = $model->claim_registration($id, $me);
        if ($claim === null) { $this->jsonSuccess(['already' => true, 'access' => $this->event_access($ev)]); }
        // Coming back after canceling a ticket already paid for: no second charge.
        if ($price > 0 && (int) $claim['prior_paid'] <= 0) {
            // The fan pays now; the creator's share is stored on the ticket and paid to them after the event
            // (EventEarnings), so canceling before the event never has to take anything back from the creator.
            if ($credits->apply_delta($me, -$price, 'event_ticket', 'Event registration') === false) {
                $model->release_registration($claim);
                $this->jsonError('Not enough credits in your wallet.', ['need_credits' => true]);
            }
            $model->set_paid((int) $claim['id'], $price, $this->creator_net($creator_id, $price));
        }

        $t = mb_substr(html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8'), 0, 60);
        $charged  = ($price > 0 && (int) $claim['prior_paid'] <= 0) ? $price : 0;
        if ($charged > 0) { TrackingLinks::attribute($creator_id, 'purchase', $me, $charged, 'events', $id); }   // a free registration isn't a purchase
        $fan_name = Notify::name_of($me) ?: 'Someone';
        $this->notify($creator_id, 'events', 'New event registration',
            $fan_name . ' registered for "' . $t . '" (' . $this->event_when($ev, $creator_id) . ')'
            . ($charged > 0 ? ' and paid ' . Notify::credits($charged) . '.' : '.'), '/events/' . (int) $ev['id'], 'fa-calendar-check');
        EventMail::confirmation($ev, $me, $charged);   // always emailed: it's their ticket, and the meeting link only travels by email
        $this->jsonSuccess(['access' => $this->event_access($ev)]);
    }

    /** The fan cancels their own spot: before the start a paid ticket is refunded in full; after it, the seat is just freed. */
    public function event_cancelAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $model = new EventsModel();
        $ev = $model->get_by_id((int) ($this->post['event_id'] ?? 0));   // hidden (draft) too: a ticket holder keeps their refund
        if (!$ev || !in_array((string) $ev['status'], array('published', 'draft'), true)) { $this->jsonError('Event not found'); }
        $reg = $model->going_registration((int) $ev['id'], $me);
        if (!$reg) { $this->jsonSuccess(['refunded' => 0]); }
        $before_start = strtotime((string) $ev['start_at'] . ' UTC') > time();
        if ($before_start && (int) $reg['price_credits'] > 0) {
            $r = EventRefunds::refund_one($ev, (int) $reg['id'], 'fan');
            $this->jsonSuccess(['refunded' => (int) ($r['refunded'] ?? 0)]);
        }
        $model->set_registration_status((int) $reg['id'], 'registered', 'canceled');
        $this->jsonSuccess(['refunded' => 0]);
    }

    /** The delivery details revealed to a registered attendee. */
    private function event_when(array $ev, int $reader_id): string{
        return EventRefunds::when($ev, $reader_id);
    }

    /**
     * What the page may show a new attendee. Never an outside meeting link: that only travels by email (confirmation +
     * reminder). A CLS Video event is joined on the event page itself, gated by the ticket.
     */
    private function event_access(array $ev){
        return [
            'instructions' => html_entity_decode((string) ($ev['access_instructions'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'video'        => (string) ($ev['format'] ?? '') === 'cls_video',
        ];
    }

    /* ---------- Managing one event (creator, Manager+) ---------- */

    /** One of this event's call recordings, ready to watch: short-lived links to the video and its poster. {event_id, recording_id, download?} */
    public function event_recordingAction(){
        [$owner, $ev] = $this->owned_event();
        [$rec, $asset] = $this->event_recording_row($owner, $ev);
        $name = preg_replace('/[^A-Za-z0-9 _.-]+/', '', html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8')) ?: 'Recording';
        $file = trim($name) . ' ' . gmdate('Y-m-d', strtotime((string) $rec['started_at'] . ' UTC')) . '.mp4';
        $this->jsonSuccess([
            'url'      => S3Service::presigned_get_url((string) $asset['original_key'], 3600, !empty($this->post['download']) ? $file : ''),
            'poster'   => (string) $asset['poster_key'] !== '' ? S3Service::presigned_get_url((string) $asset['poster_key'], 3600) : '',
            'title'    => html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8'),
            'duration' => (int) $rec['duration_sec'],
        ]);
    }

    /** Delete one of this event's call recordings (the video is removed; it no longer counts toward storage). {event_id, recording_id} */
    public function event_recording_deleteAction(){
        [$owner, $ev] = $this->owned_event();
        [$rec, $asset] = $this->event_recording_row($owner, $ev);
        (new MediaAssetsModel())->soft_delete($owner, (int) $asset['id']);
        (new LiveRecordingsModel())->set((int) $rec['id'], array('status' => 'deleted'));
        if ((int) ($ev['replay_recording_id'] ?? 0) === (int) $rec['id']) { (new EventsModel())->set_replay($owner, (int) $ev['id'], 0, 0, true); }   // no longer on sale
        $this->jsonSuccess(['message' => 'Recording deleted']);
    }

    /** Sell one of this event's recordings as its replay, on the public event page. {event_id, recording_id, price, free_attendees} */
    public function event_replay_setAction(){
        [$owner, $ev] = $this->owned_event();
        if ($this->seller_suspended($owner)) { $this->jsonError('Selling replays is included on the Creator and Studio plans.', ['need_plan' => true]); }
        [$rec] = $this->event_recording_row($owner, $ev);
        $price = $this->price_credits($this->post['price'] ?? '');   // 10 to 5,000 whole credits
        $free  = (string) ($this->post['free_attendees'] ?? '1') !== '0';
        $was_on_sale = (int) ($ev['replay_price_credits'] ?? 0) > 0 && (int) ($ev['replay_recording_id'] ?? 0) === (int) $rec['id'];
        $events = new EventsModel();
        $events->set_replay($owner, (int) $ev['id'], (int) $rec['id'], $price, $free);
        // A replay on sale must be visible: a hidden (draft) event would sell to nobody, so selling makes it public.
        if ((string) $ev['status'] === 'draft') { $events->set_status($owner, (int) $ev['id'], 'published'); }
        if (!$was_on_sale) { ReplayAnnounceJob::queue((int) $ev['id'], (int) $rec['id']); }   // tell registered people and the creator's audience
        $this->jsonSuccess(['message' => $was_on_sale ? 'Replay price saved' : 'The replay is on sale. Everyone who registered and your followers are being told.']);
    }

    /** Stop selling the replay (people who bought it keep watching it). {event_id} */
    public function event_replay_offAction(){
        [$owner, $ev] = $this->owned_event();
        // Keep which recording it is (price 0 = off sale): people who bought it keep watching.
        (new EventsModel())->set_replay($owner, (int) $ev['id'], (int) ($ev['replay_recording_id'] ?? 0), 0, (int) ($ev['replay_free_attendees'] ?? 1) === 1);
        $this->jsonSuccess(['message' => 'The replay is no longer for sale']);
    }

    /** A fan buys an event's replay with wallet credits. {event_id} */
    public function event_replay_buyAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Sign in to buy the replay.', ['need_login' => true]); }
        $ev = (new EventsModel())->get_public((int) ($this->post['event_id'] ?? 0));
        $info = $ev ? EventReplay::info($ev) : null;
        if (!$ev || !$info || !$info['on_sale'] || (string) $ev['status'] !== 'published') { $this->jsonError('This replay isn’t for sale.'); }
        $creator = (int) $ev['creator_id'];
        if ((new BlocksModel())->either_blocked($me, $creator) || $this->seller_suspended($creator)) { $this->jsonError('This replay isn’t available.'); }
        if (EventReplay::access($ev, $info, $me) !== '') { $this->jsonSuccess(['already' => true, 'message' => 'You can already watch this replay.']); }
        // Adult (or not yet checked) video is only sold to fans who have adult content on, like bundles.
        $st = (string) ($info['asset']['moderation_status'] ?? '');
        if ($st !== 'n_a' && ($st !== 'approved' || !empty($info['asset']['is_adult']))) {
            $vrow = $this->userModel->get_user_by_id($me);
            if (!(is_array($vrow) && count($vrow) === 1 && !empty($vrow[0]['adult_content_enabled']))) {
                $this->jsonError('This replay has adult content. Turn on adult content in Settings to buy it.');
            }
        }
        $price = (int) $info['price'];
        $credits = new CreditsModel();
        $balance = (int) $credits->get_balance($me);
        if ($balance < $price) { $this->jsonError('You need ' . Price::credits($price - $balance) . ' more in your wallet to buy this replay.', ['need_credits' => true, 'balance' => $balance, 'price' => $price]); }
        $unlocks = new ReplayUnlocksModel();
        if (!$unlocks->record((int) $ev['id'], $creator, $me, $price)) { $this->jsonSuccess(['already' => true, 'message' => 'You already own this replay.']); }
        $title = html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8');
        $net = $this->creator_net($creator, $price);
        if ($credits->pay($me, $price, 'replay_unlock', 'Replay of "' . mb_substr($title, 0, 80) . '"', $creator, $net, 'replay_earning', 'Replay sale: "' . mb_substr($title, 0, 80) . '"') === false) {
            $unlocks->remove((int) $ev['id'], $me);
            $this->jsonError('Not enough credits in your wallet.', ['need_credits' => true, 'balance' => (int) $credits->get_balance($me), 'price' => $price]);
        }
        if ($net > 0) { $unlocks->set_net((int) $ev['id'], $me, $net); }
        $this->notify($creator, 'purchases', 'New replay sale', 'Someone bought the replay of "' . mb_substr($title, 0, 60) . '" for ' . Notify::credits($price) . '.', '/events/manage/' . (int) $ev['id'], 'fa-circle-play');
        $this->notify($me, 'purchases', 'Replay purchased', Notify::credits($price) . ' spent · ' . Notify::credits($credits->get_balance($me)) . ' left. Watch it on the event page.', '/purchases', 'fa-circle-play');
        InboxAutomationService::trigger($creator, $me, 'new_purchase', 'replay' . (int) $ev['id']);
        $this->jsonSuccess(['message' => 'Replay purchased. Enjoy!', 'balance' => (int) $credits->get_balance($me)]);
    }

    /** Watch an event's replay (anyone allowed to): a short-lived link to the video. {event_id} */
    public function event_replay_watchAction(){
        $ev = (new EventsModel())->get_public((int) ($this->post['event_id'] ?? 0));
        $info = $ev ? EventReplay::info($ev) : null;
        if (!$ev || !$info) { $this->jsonError('This replay isn’t available.'); }
        if (EventReplay::access($ev, $info, (int) Session::get('user_id')) === '') { $this->jsonError('Buy the replay to watch it.', ['need_buy' => true]); }
        $a = $info['asset'];
        $this->jsonSuccess(['url' => S3Service::presigned_get_url((string) $a['original_key'], 4 * 3600),
                            'poster' => (string) $a['poster_key'] !== '' ? S3Service::presigned_get_url((string) $a['poster_key'], 4 * 3600) : '']);
    }

    /** [recording, its video] for this event, or a JSON error. */
    private function event_recording_row(int $owner, array $ev): array{
        $rec = (new LiveRecordingsModel())->get((int) ($this->post['recording_id'] ?? 0));
        if (!$rec || (int) $rec['event_id'] !== (int) $ev['id'] || (string) $rec['status'] !== 'ready' || (int) $rec['asset_id'] <= 0) { $this->jsonError('That recording isn’t available.'); }
        $asset = (new MediaAssetsModel())->get_one($owner, (int) $rec['asset_id']);
        if (!$asset || (string) $asset['original_key'] === '') { $this->jsonError('That recording isn’t available.'); }
        return [$rec, $asset];
    }

    /** [owner id, the owner's event, owner row] — or a JSON 'Event not found'. */
    private function owned_event(): array{
        $user = $this->require_creator('manage', false)  /* refunds, attendees, cancelling: still theirs to handle on Free */;
        $ev = (new EventsModel())->get_one((int) $user['user_id'], (int) ($this->post['event_id'] ?? 0));
        if (!$ev) { $this->jsonError('Event not found'); }
        return [(int) $user['user_id'], $ev, $user];
    }

    private function attendee_json(array $a, string $tz): array{
        $label = ['registered' => 'Going', 'canceled' => 'Canceled', 'refunded' => 'Refunded', 'removed' => 'Removed'];
        return [
            'id' => (int) $a['id'], 'name' => html_entity_decode((string) $a['name'], ENT_QUOTES, 'UTF-8'), 'handle' => (string) $a['handle'],
            'avatar' => (string) ($a['avatar_url'] ?? ''), 'registered' => $this->local_date((string) $a['created_at'], $tz),
            'paid' => (int) $a['price_credits'] > 0 ? Price::credits((int) $a['price_credits']) : 'Free',
            'paid_credits' => (int) $a['price_credits'], 'status' => (string) $a['status'],
            'status_label' => $label[(string) $a['status']] ?? ucfirst((string) $a['status']),
        ];
    }

    private function local_date(string $utc, string $tz, string $format = 'M j, Y'): string{
        try { $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format($format); }
        catch (\Throwable $e) { return ''; }
    }

    /** One page of the people going (search + page); never the whole list. */
    public function event_attendeesAction(){
        [$owner, $ev, $user] = $this->owned_event();
        $tz = (string) ($user['content_timezone'] ?? 'UTC');
        $model = new EventsModel();
        $per  = max(1, min(50, (int) ($this->post['per'] ?? 10)));
        $page = max(1, (int) ($this->post['page'] ?? 1));
        $q    = trim(mb_substr(html_entity_decode((string) ($this->post['q'] ?? ''), ENT_QUOTES, 'UTF-8'), 0, 100));
        $res  = $model->going_page((int) $ev['id'], $q, $per, ($page - 1) * $per);
        $pages = max(1, (int) ceil($res['total'] / $per));
        if ($page > $pages) { $page = $pages; $res = $model->going_page((int) $ev['id'], $q, $per, ($page - 1) * $per); }
        $list = array_map(function ($a) use ($tz) { return $this->attendee_json($a, $tz); }, $res['rows']);
        $this->jsonSuccess(['attendees' => $list, 'total' => $res['total'], 'page' => $page, 'pages' => $pages, 'per' => $per]);
    }

    /** CSV of everyone who registered (a form POST, so CSRF still applies). */
    public function event_attendees_csvAction(){
        [$owner, $ev, $user] = $this->owned_event();
        $tz = (string) ($user['content_timezone'] ?? 'UTC');
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(EventRefunds::title($ev))), '-') ?: 'event';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $slug . '-attendees.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Handle', 'Email', 'Registered', 'Paid', 'Status'], ',', '"', '');
        // a cell starting like a formula (= + - @ tab cr) is quoted so a spreadsheet shows it as text
        $cell = function ($v) { $v = (string) $v; return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v; };
        foreach ((new EventsModel())->attendees((int) $ev['id']) as $a) {
            $j = $this->attendee_json($a, $tz);
            fputcsv($out, [$cell($j['name']), $j['handle'], $cell((string) $a['email']), $j['registered'], $j['paid'], $j['status_label']], ',', '"', '');
        }
        fclose($out);
        exit;
    }

    public function event_refund_attendeeAction(){
        [$owner, $ev] = $this->owned_event();
        $r = EventRefunds::refund_one($ev, (int) ($this->post['registration_id'] ?? 0), 'creator');
        if (empty($r['ok'])) { $this->jsonError($r['message']); }
        $this->jsonSuccess(['message' => $r['message']]);
    }

    public function event_remove_attendeeAction(){
        [$owner, $ev] = $this->owned_event();
        $r = EventRefunds::remove_one($ev, (int) ($this->post['registration_id'] ?? 0));
        if (empty($r['ok'])) { $this->jsonError($r['message']); }
        $this->jsonSuccess(['message' => $r['message']]);
    }

    /** One message to everyone going: it lands in each attendee's Inbox (email if they're offline). */
    public function event_message_sendAction(){
        [$owner, $ev] = $this->owned_event();
        $body = trim(html_entity_decode((string) ($this->post['body'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($body === '') { $this->jsonError('Write a message first.'); }
        if (mb_strlen($body) > 2000) { $this->jsonError('Keep the message under 2,000 characters.'); }
        $model = new EventsModel();
        $blocked = (new BlocksModel())->related_ids($owner);
        $ids = array_values(array_filter($model->going_user_ids((int) $ev['id']), function ($u) use ($blocked) { return !isset($blocked[$u]); }));
        if (empty($ids)) { $this->jsonError('No one is registered yet.'); }
        $model->add_message((int) $ev['id'], $owner, $body, count($ids));
        if (count($ids) > 50 && class_exists('DatabaseJobQueue')) {
            (new DatabaseJobQueue())->dispatch('event_message', ['event_id' => (int) $ev['id'], 'creator_id' => $owner, 'body' => $body, 'ids' => $ids], null);
        } else {
            EventMessageJob::deliver($ev, $owner, $body, $ids);
        }
        $this->jsonSuccess(['recipients' => count($ids), 'message' => 'Sent to ' . count($ids) . (count($ids) === 1 ? ' attendee' : ' attendees')]);
    }

    /** One page of sent messages, newest first, with the real send time. */
    public function event_messagesAction(){
        [$owner, $ev, $user] = $this->owned_event();
        $tz = (string) ($user['content_timezone'] ?? 'UTC');
        $per  = max(1, min(50, (int) ($this->post['per'] ?? 10)));
        $page = max(1, (int) ($this->post['page'] ?? 1));
        $res  = (new EventsModel())->messages_page((int) $ev['id'], $per, ($page - 1) * $per);
        $out = array_map(function ($m) use ($tz) {
            return ['id' => (int) $m['id'], 'sent_at' => $this->local_date((string) $m['created_at'], $tz, 'M j, Y · g:i A'), 'body' => (string) $m['body'], 'recipients' => (int) $m['recipients']];
        }, $res['rows']);
        $this->jsonSuccess(['messages' => $out, 'total' => $res['total'], 'page' => $page, 'pages' => max(1, (int) ceil($res['total'] / $per))]);
    }

    /** Live = fans can see it on the profile and register; not live = hidden. */
    public function event_set_liveAction(){
        [$owner, $ev, $user] = $this->owned_event();
        if ((string) $ev['status'] === 'canceled') { $this->jsonError('This event was canceled.'); }
        $live = ((string) ($this->post['live'] ?? '0')) === '1';
        $this->plan_to_turn_on($user, $live);
        // hiding a sold event would strand its ticket holders while the creator is still paid: cancel refunds them instead
        if (!$live && (string) $ev['status'] === 'published' && (new EventsModel())->has_paid_going((int) $ev['id'])) {
            $this->jsonError('This event has paid attendees. Cancel it instead so they are refunded.');
        }
        (new EventsModel())->set_status($owner, (int) $ev['id'], $live ? 'published' : 'draft');
        $this->jsonSuccess(['live' => $live, 'message' => $live ? 'Event is live' : 'Event is hidden']);
    }

    /** Cancel the whole event: registration closes, every paid ticket is refunded, every attendee is told. */
    public function event_cancel_allAction(){
        [$owner, $ev] = $this->owned_event();
        if ((string) $ev['status'] === 'canceled') { $this->jsonError('This event is already canceled.'); }
        $r = EventRefunds::cancel_all($ev);
        $this->jsonSuccess(['refunded' => $r['refunded_n'], 'notified' => $r['notified'], 'message' => 'Event canceled']);
    }

    /* ---------- Services (PRD §22) ---------- */

}
