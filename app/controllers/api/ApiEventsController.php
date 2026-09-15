<?php
/** Creator events and registrations. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiEventsController extends BaseApiController {

    /** Create or edit an event (Manager+; times arrive in the creator's tz → stored UTC). */
    public function event_saveAction(){
        $user = $this->require_creator('manage');
        $creator_id = (int) $user['user_id'];
        $tz = (string) ($user['content_timezone'] ?? 'UTC');
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
        $price_credits = ($access === 'paid') ? (int) round(((float) ($this->post['price'] ?? 0)) * 10) : 0;   // $1 = 10 credits
        $tier_id = ($access === 'tier') ? (int) ($this->post['tier_id'] ?? 0) : 0;

        $fields = [
            'title'               => $title,
            'description'         => trim(html_entity_decode((string) ($this->post['description'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'start_at'            => $start_utc,
            'end_at'              => $end_utc,
            'timezone'            => $tz,
            'access_type'         => $access,
            'price_credits'       => $price_credits,
            'tier_id'             => $tier_id,
            'capacity'            => (int) ($this->post['capacity'] ?? 0),
            'location'            => trim(html_entity_decode((string) ($this->post['location'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'external_url'        => trim((string) ($this->post['external_url'] ?? '')),
            'access_instructions' => trim(html_entity_decode((string) ($this->post['access_instructions'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'status'              => (($this->post['status'] ?? 'draft') === 'published') ? 'published' : 'draft',
        ];
        $model = new EventsModel();
        if ($id > 0) {
            if (!$model->get_one($creator_id, $id)) { $this->jsonError('Event not found'); }
            $model->update_event($creator_id, $id, $fields);
        } else {
            $id = (int) $model->create($creator_id, $fields);
        }
        $this->jsonSuccess(['id' => $id, 'message' => 'Event saved']);
    }

    public function event_deleteAction(){
        $user = $this->require_creator('manage');
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Event required'); }
        (new EventsModel())->delete_event((int) $user['user_id'], $id);
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
        $creator_id = (int) $ev['creator_id'];
        if ($creator_id === $me) { $this->jsonError('This is your own event.'); }

        if ($model->is_registered($id, $me)) { $this->jsonSuccess(['already' => true, 'access' => $this->event_access($ev)]); }
        if ((int) $ev['capacity'] > 0 && $model->attendee_count($id) >= (int) $ev['capacity']) {
            $this->jsonError('This event is full.');
        }

        $access = (string) $ev['access_type'];
        $paid = 0;
        if ($access === 'subscribers' || $access === 'tier') {
            $subs = new CreatorSubscriptionsModel();
            $plan_ids = array_map('intval', (array) $subs->active_plan_ids($me, $creator_id));
            $ok = !empty($plan_ids);
            if ($access === 'tier' && (int) $ev['tier_id'] > 0) { $ok = in_array((int) $ev['tier_id'], $plan_ids, true); }
            if (!$ok) { $this->jsonError('This event is for subscribers.', ['need_subscription' => true]); }
        } elseif ($access === 'paid' && (int) $ev['price_credits'] > 0) {
            $price = (int) $ev['price_credits'];
            $credits = new CreditsModel();
            if ($credits->get_balance($me) < $price) {
                $this->jsonError('Not enough credits.', ['need_credits' => true, 'price' => $price, 'balance' => $credits->get_balance($me)]);
            }
            if ($credits->apply_delta($me, -$price, 'event_ticket', 'Event registration') === false) {
                $this->jsonError('Not enough credits.', ['need_credits' => true]);
            }
            $paid = $price;
            $crow = $this->userModel->get_user_by_id($creator_id);
            $crow = (is_array($crow) && count($crow) === 1) ? $crow[0] : null;
            $net = (int) round($price * (100 - Plan::fee_percent($crow)) / 100);
            if ($net > 0) { $credits->apply_delta($creator_id, $net, 'event_earning', 'Event ticket'); }
        }

        $model->register($id, $me, $paid);
        $t = mb_substr((string) $ev['title'], 0, 60);
        $handle = '';
        $h = $this->userModel->get_user_by_id($creator_id);
        if (is_array($h) && count($h) === 1) { $handle = (string) $h[0]['u_name']; }
        $this->notify($creator_id, 'events', 'New event registration', 'Someone registered for "' . $t . '".', '/events', 'fa-calendar-check');
        $this->notify($me, 'events', 'Registration confirmed', 'You\'re registered for "' . $t . '".', $handle !== '' ? '/@' . $handle : '', 'fa-calendar-check');
        $this->jsonSuccess(['access' => $this->event_access($ev)]);
    }

    public function event_cancelAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $id = (int) ($this->post['event_id'] ?? 0);
        (new EventsModel())->cancel_registration($id, $me);
        $this->jsonSuccess();
    }

    /** The delivery details revealed to a registered attendee. */
    private function event_access(array $ev){
        return [
            'url'          => (string) ($ev['external_url'] ?? ''),
            'location'     => (string) ($ev['location'] ?? ''),
            'instructions' => html_entity_decode((string) ($ev['access_instructions'] ?? ''), ENT_QUOTES, 'UTF-8'),
        ];
    }

    /* ---------- Services (PRD §22) ---------- */

}
