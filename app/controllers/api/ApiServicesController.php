<?php
/** Creator services and purchases. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiServicesController extends BaseApiController {

    public function service_saveAction(){
        $user = $this->require_creator('manage');
        $creator_id = (int) $user['user_id'];
        $id = (int) ($this->post['id'] ?? 0);

        $name = trim(html_entity_decode((string) ($this->post['name'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($name === '') { $this->jsonError('A name is required'); }

        $method = (string) ($this->post['delivery_method'] ?? 'custom');
        if (!in_array($method, ServicesModel::delivery_methods(), true)) { $method = 'custom'; }
        if ($method === 'cls_video' && !LiveKit::enabled()) { $this->jsonError('CLS Video is not available yet.'); }

        $fields = [
            'name'             => $name,
            'description'      => trim(html_entity_decode((string) ($this->post['description'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'price_credits'    => $this->price_given($this->post['price'] ?? '') ? $this->price_credits($this->post['price']) : 0,   // empty = free
            'duration_min'     => (int) ($this->post['duration_min'] ?? 0),
            'delivery_method'  => $method,
            'delivery_details' => trim(html_entity_decode((string) ($this->post['delivery_details'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'capacity'         => (int) ($this->post['capacity'] ?? 0),
            'category'         => trim(html_entity_decode((string) ($this->post['category'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'refund_policy'    => trim(html_entity_decode((string) ($this->post['refund_policy'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'status'           => (($this->post['status'] ?? 'draft') === 'published') ? 'published' : 'draft',
        ];
        $model = new ServicesModel();
        if ($id > 0) {
            if (!$model->get_one($creator_id, $id)) { $this->jsonError('Service not found'); }
            $model->update_service($creator_id, $id, $fields);
        } else {
            $id = (int) $model->create($creator_id, $fields);
        }
        $this->jsonSuccess(['id' => $id, 'message' => 'Service saved']);
    }

    /** Delete a service nobody has booked. Once booked, buyers keep their details: turn Live off instead. */
    public function service_deleteAction(){
        $user = $this->require_creator('manage', false);
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Service required'); }
        $model = new ServicesModel();
        if (!$model->get_one((int) $user['user_id'], $id)) { $this->jsonError('Service not found'); }
        if ($model->stats($id)['rows'] > 0) { $this->jsonError('This service has bookings, so it can\'t be deleted. Turn Live off to stop new bookings.'); }
        $model->delete_service((int) $user['user_id'], $id);
        $this->jsonSuccess(['message' => 'Service deleted']);
    }

    /** The creator's own service for the manage-page actions (owner check + the account's timezone). */
    private function owned_service(): array{
        $user = $this->require_creator('manage', false)  /* delivering, refunds, buyers: still theirs to handle on Free */;
        $sv = (new ServicesModel())->get_one((int) $user['user_id'], (int) ($this->post['service_id'] ?? 0));
        if (!$sv) { $this->jsonError('Service not found'); }
        return [(int) $user['user_id'], $sv, $user];
    }

    private function local_date(string $utc, string $tz): string{
        try { $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format('M j, Y'); }
        catch (\Throwable $e) { return ''; }
    }

    private function buyer_json(array $b, string $tz): array{
        $label = ['paid' => !empty($b['delivered_at']) ? 'Delivered' : 'Booked', 'refunded' => 'Refunded'];
        return [
            'id' => (int) $b['id'], 'user_id' => (int) $b['buyer_id'], 'name' => html_entity_decode((string) $b['name'], ENT_QUOTES, 'UTF-8'),
            'handle' => (string) $b['handle'], 'avatar' => (string) ($b['avatar_url'] ?? ''), 'booked' => $this->local_date((string) $b['created_at'], $tz),
            'paid' => (int) $b['price_credits'] > 0 ? Price::credits((int) $b['price_credits']) : 'Free',
            'paid_credits' => (int) $b['price_credits'], 'status' => (string) $b['status'], 'delivered' => !empty($b['delivered_at']),
            'status_label' => $label[(string) $b['status']] ?? ucfirst((string) $b['status']),
        ];
    }

    /** The creator marks a booking delivered: it's stamped Delivered and their share is paid to their balance. */
    public function service_mark_deliveredAction(){
        [$owner, $sv] = $this->owned_service();
        $pid = (int) ($this->post['purchase_id'] ?? 0);
        if (!(new ServicesModel())->purchase((int) $sv['id'], $pid)) { $this->jsonError('Booking not found'); }
        $paid = (new CreditsModel())->release_service_earning($pid, (int) $sv['creator_id']);
        if ($paid === false) { $this->jsonError('That booking is already delivered or was refunded.'); }
        $this->jsonSuccess(['message' => $paid > 0 ? 'Marked delivered. ' . Price::credits($paid) . ' were added to your balance.' : 'Marked delivered.']);
    }

    /** One page of buyers (search + page); never the whole list. */
    public function service_buyersAction(){
        [$owner, $sv, $user] = $this->owned_service();
        $tz = (string) ($user['content_timezone'] ?? 'UTC');
        $model = new ServicesModel();
        $per  = max(1, min(50, (int) ($this->post['per'] ?? 10)));
        $page = max(1, (int) ($this->post['page'] ?? 1));
        $q    = trim(mb_substr(html_entity_decode((string) ($this->post['q'] ?? ''), ENT_QUOTES, 'UTF-8'), 0, 100));
        $res  = $model->buyers_page((int) $sv['id'], $q, $per, ($page - 1) * $per);
        $pages = max(1, (int) ceil($res['total'] / $per));
        if ($page > $pages) { $page = $pages; $res = $model->buyers_page((int) $sv['id'], $q, $per, ($page - 1) * $per); }
        $list = array_map(function ($b) use ($tz) { return $this->buyer_json($b, $tz); }, $res['rows']);
        $this->jsonSuccess(['buyers' => $list, 'total' => $res['total'], 'page' => $page, 'pages' => $pages, 'per' => $per]);
    }

    /** CSV of everyone who booked (a form POST, so CSRF still applies). */
    public function service_buyers_csvAction(){
        [$owner, $sv, $user] = $this->owned_service();
        $tz = (string) ($user['content_timezone'] ?? 'UTC');
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(html_entity_decode((string) $sv['name'], ENT_QUOTES, 'UTF-8'))), '-') ?: 'service';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $slug . '-buyers.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Handle', 'Email', 'Booked', 'Paid', 'Status'], ',', '"', '');
        foreach ((new ServicesModel())->all_buyers((int) $sv['id']) as $b) {
            $j = $this->buyer_json($b, $tz);
            fputcsv($out, [$j['name'], $j['handle'] !== '' ? '@' . $j['handle'] : '', (string) $b['email'], $j['booked'], $j['paid'], $j['status_label']], ',', '"', '');
        }
        fclose($out);
        exit;
    }

    public function service_refund_buyerAction(){
        [$owner, $sv] = $this->owned_service();
        $r = ServiceRefunds::refund_one($sv, (int) ($this->post['purchase_id'] ?? 0));
        if (empty($r['ok'])) { $this->jsonError($r['message']); }
        $this->jsonSuccess(['message' => $r['message']]);
    }

    /** Live = on the profile and bookable; not live = hidden. */
    public function service_set_liveAction(){
        [$owner, $sv, $user] = $this->owned_service();
        $live = ((string) ($this->post['live'] ?? '0')) === '1';
        $this->plan_to_turn_on($user, $live);
        (new ServicesModel())->set_status($owner, (int) $sv['id'], $live ? 'published' : 'draft');
        $this->jsonSuccess(['live' => $live]);
    }

    /** Buy a service (one-time, credits). Reveals the booking + delivery details on success. */
    public function service_purchaseAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Sign in to book.', ['need_login' => true]); }
        $id = (int) ($this->post['service_id'] ?? 0);
        $model = new ServicesModel();
        $sv = $model->get_public($id);
        if (!$sv) { $this->jsonError('Service not found'); }
        $creator_id = (int) $sv['creator_id'];
        if ($creator_id === $me) { $this->jsonError('This is your own service.'); }
        if ((new BlocksModel())->either_blocked($me, $creator_id)) { $this->jsonError('Service not found'); }
        if ($this->seller_suspended($creator_id)) { $this->jsonError('Service not found'); }

        // Already purchased — just hand back the booking details.
        if ($model->has_purchased($id, $me)) { $this->jsonSuccess(['already' => true, 'access' => $this->service_access($sv)]); }
        // Group service with a seat cap.
        if ((int) $sv['capacity'] > 0 && $model->purchase_count($id) >= (int) $sv['capacity']) {
            $this->jsonError('This service is fully booked.');
        }

        $price = (int) $sv['price_credits'];
        if ($price > 0) {
            $credits = new CreditsModel();
            if ($credits->get_balance($me) < $price) {
                $this->jsonError('Not enough credits in your wallet.', ['need_credits' => true, 'price' => $price, 'balance' => $credits->get_balance($me)]);
            }
        }
        // Record first: UNIQUE(service_id, buyer_id) is the mutex against a double charge. Then debit.
        $purchase_id = $model->record_purchase($id, $me, max(0, $price));
        if ($purchase_id <= 0) { $this->jsonSuccess(['already' => true, 'access' => $this->service_access($sv)]); }
        if ($price > 0) {
            // The fan pays now; the creator's share is stored on the booking and paid to them when they mark it
            // delivered (service_mark_delivered), so refunding before that never takes anything back from them.
            $model->set_net($purchase_id, $this->creator_net($creator_id, $price));
            if ($credits->apply_delta($me, -$price, 'service_purchase', 'Service purchase') === false) {
                $model->remove_purchase($purchase_id);
                $this->jsonError('Not enough credits in your wallet.', ['need_credits' => true]);
            }
        }

        $t = mb_substr(html_entity_decode((string) $sv['name'], ENT_QUOTES, 'UTF-8'), 0, 60);
        $handle = '';
        $h = $this->userModel->get_user_by_id($creator_id);
        if (is_array($h) && count($h) === 1) { $handle = (string) $h[0]['u_name']; }
        $fan_name = Notify::name_of($me) ?: 'Someone';
        $this->notify($creator_id, 'services', 'New service booking', $fan_name . ' booked "' . $t . '"' . ($price > 0 ? ' and paid ' . Notify::credits($price) . '.' : '.'), '/services/manage/' . (int) $sv['id'], 'fa-briefcase');
        $this->notify($me, 'services', 'Booking confirmed', 'You booked "' . $t . '". Your booking details are on the service page.', $handle !== '' ? '/@' . $handle . '/services/' . (int) $sv['id'] : '', 'fa-briefcase');
        $this->jsonSuccess(['access' => $this->service_access($sv)]);
    }

    /** The booking + delivery details revealed to a buyer. */
    private function service_access(array $sv){
        return [
            'method'  => (string) ($sv['delivery_method'] ?? 'custom'),
            'details' => html_entity_decode((string) ($sv['delivery_details'] ?? ''), ENT_QUOTES, 'UTF-8'),
        ];
    }

    /* ---------- Reports / trust & safety (PRD §35–37) ---------- */

}
