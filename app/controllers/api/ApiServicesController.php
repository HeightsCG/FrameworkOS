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

        $fields = [
            'name'             => $name,
            'description'      => trim(html_entity_decode((string) ($this->post['description'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'price_credits'    => (int) round(((float) ($this->post['price'] ?? 0)) * 10),   // $1 = 10 credits
            'duration_min'     => (int) ($this->post['duration_min'] ?? 0),
            'delivery_method'  => $method,
            'scheduling_url'   => trim((string) ($this->post['scheduling_url'] ?? '')),
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

    public function service_deleteAction(){
        $user = $this->require_creator('manage');
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Service required'); }
        (new ServicesModel())->delete_service((int) $user['user_id'], $id);
        $this->jsonSuccess();
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
                $this->jsonError('Not enough credits.', ['need_credits' => true, 'price' => $price, 'balance' => $credits->get_balance($me)]);
            }
        }
        // Record first: UNIQUE(service_id, buyer_id) is the mutex against a double charge. Then debit.
        $purchase_id = $model->record_purchase($id, $me, max(0, $price));
        if ($purchase_id <= 0) { $this->jsonSuccess(['already' => true, 'access' => $this->service_access($sv)]); }
        if ($price > 0) {
            if ($credits->apply_delta($me, -$price, 'service_purchase', 'Service purchase') === false) {
                $model->remove_purchase($purchase_id);
                $this->jsonError('Not enough credits.', ['need_credits' => true]);
            }
            $crow = $this->userModel->get_user_by_id($creator_id);
            $crow = (is_array($crow) && count($crow) === 1) ? $crow[0] : null;
            $net = (int) round($price * (100 - Plan::fee_percent($crow)) / 100);
            if ($net > 0) { $credits->apply_delta($creator_id, $net, 'service_earning', 'Service sale'); }
        }

        $t = mb_substr((string) $sv['name'], 0, 60);
        $handle = '';
        $h = $this->userModel->get_user_by_id($creator_id);
        if (is_array($h) && count($h) === 1) { $handle = (string) $h[0]['u_name']; }
        $this->notify($creator_id, 'services', 'New service booking', 'Someone booked "' . $t . '".', '/services', 'fa-briefcase');
        $this->notify($me, 'services', 'Booking confirmed', 'You booked "' . $t . '". Schedule your session next.', $handle !== '' ? '/@' . $handle : '', 'fa-briefcase');
        $this->jsonSuccess(['access' => $this->service_access($sv)]);
    }

    /** The booking + delivery details revealed to a buyer. */
    private function service_access(array $sv){
        return [
            'method'         => (string) ($sv['delivery_method'] ?? 'custom'),
            'scheduling_url' => (string) ($sv['scheduling_url'] ?? ''),
            'details'        => html_entity_decode((string) ($sv['delivery_details'] ?? ''), ENT_QUOTES, 'UTF-8'),
        ];
    }

    /* ---------- Reports / trust & safety (PRD §35–37) ---------- */

}
