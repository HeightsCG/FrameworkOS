<?php
/**
 * Public, unauthenticated endpoints for inbound provider webhooks.
 * NOT extended from BaseApiController, so it carries no CSRF check; $protected = 0
 * so the layout never swaps in the login form.
 *
 * NOTE: live delivery needs a publicly reachable URL configured in the Post for
 * Me dashboard. The POC works without it (connection via redirect, status via
 * polling); this handler is here so wiring it later is a config-only change.
 */
class WebhookController extends Controller {

    public $protected = 0;

    public function __construct(){
        parent::__construct();
    }

    /**
     * Fanvue creator.* webhooks. The inbox automation now runs on Creator Link Studio's own
     * DMs, so every event is verified and acknowledged (2xx) so Fanvue stops retrying.
     */
    public function fanvueAction(){
        $raw = (string) file_get_contents('php://input');
        $sig = (string) ($_SERVER['HTTP_X_FANVUE_SIGNATURE'] ?? '');
        $secret = FanvueService::webhook_secret();
        if ($secret !== '' && !FanvueService::verify_webhook_signature($raw, $sig, $secret)) {
            http_response_code(401);
            echo 'unauthorized';
            exit;
        }
        http_response_code(200);
        echo 'ignored';
        exit;
    }

    public function postformeAction(){
        // Post for Me sends a shared secret on every delivery in the
        // "Post-For-Me-Webhook-Secret" header. Verify it before processing anything.
        // Fail-closed: with no configured secret, reject rather than trust the payload.
        $secret = '';
        try { $secret = (string) Main::config(Main::get_environment(), 'post_for_me_webhook_secret'); } catch (\Throwable $e) {}
        if ($secret === '') {
            error_log('[postforme webhook] post_for_me_webhook_secret not configured');
            http_response_code(503);
            echo 'not configured';
            exit;
        }
        $sent = (string) ($_SERVER['HTTP_POST_FOR_ME_WEBHOOK_SECRET'] ?? '');
        if ($sent === '' || !hash_equals($secret, $sent)) {
            http_response_code(401);
            echo 'unauthorized';
            exit;
        }

        $event = json_decode(file_get_contents('php://input'), true);
        if (!is_array($event)) {
            http_response_code(400);
            echo 'bad request';
            exit;
        }

        $type = $event['type'] ?? '';
        $data = $event['data'] ?? array();

        if ($type === 'social.account.created' && !empty($data['id']) && isset($data['external_id'])) {
            (new SocialAccountsModel())->upsert_from_pfm((int) $data['external_id'], $data);
        } elseif (strpos($type, 'social.post') === 0 && !empty($data['id']) && isset($data['status'])) {
            (new SocialPostsModel())->update_status($data['id'], $data['status']);
        }

        http_response_code(200);
        echo 'ok';
        exit;
    }

    /**
     * Stripe webhook for subscription lifecycle (renewals, cancellations, failures).
     * Configure the endpoint URL + signing secret (stripe_webhook_secret) in the
     * Stripe dashboard. Can't be tested from localhost (Stripe can't reach it), so
     * the checkout success redirect is the primary record path; this keeps state in
     * sync afterward.
     */
    public function stripeAction(){
        $payload = file_get_contents('php://input');
        $sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        $secret = '';
        try { $secret = (string) Main::config(Main::get_environment(), 'stripe_webhook_secret'); } catch (\Throwable $e) {}

        if ($secret === '') {
            error_log('[stripe webhook] stripe_webhook_secret not configured');
            http_response_code(500);
            echo 'not configured';
            exit;
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig, $secret);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo 'invalid signature';
            exit;
        }

        $obj  = $event->data->object;
        $subs = new CreatorSubscriptionsModel();

        switch ($event->type) {
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                $this->sync_subscription($subs, $obj);
                break;

            case 'customer.subscription.deleted':
                $row = $subs->get_by_stripe_id((string) $obj->id);
                $subs->update_by_stripe_id($obj->id, 'canceled', $obj->current_period_end ?? null, 0);
                if ($row) {
                    $this->sub_notice($row, 'ended', 'Your membership to ' . (Notify::name_of((int) $row['creator_id']) ?: 'this creator') . ' has ended. You can subscribe again anytime.', 'fa-heart-crack');
                    Notify::send((int) $row['creator_id'], 'subscriptions', 'Subscriber left', (Notify::name_of((int) $row['subscriber_id']) ?: 'A subscriber') . '\'s ' . (string) ($row['plan_name'] ?? 'membership') . ' ended.', '/audience', 'fa-user-minus');
                }
                break;

            case 'invoice.payment_failed':
                // A failed renewal must stop granting access until it's resolved.
                if (!empty($obj->subscription)) {
                    $row = $subs->get_by_stripe_id((string) $obj->subscription);
                    $subs->update_by_stripe_id((string) $obj->subscription, 'past_due', null, 0);
                    if ($row) { $this->sub_notice($row, 'payment failed', 'The renewal for your membership to ' . (Notify::name_of((int) $row['creator_id']) ?: 'this creator') . ' did not go through. Update your card to keep access.', 'fa-triangle-exclamation'); }
                }
                break;

            case 'invoice.payment_succeeded':
                // Renewal cleared a past_due; the paired subscription.updated carries the new period.
                if (!empty($obj->subscription) && $subs->exists_by_stripe_id((string) $obj->subscription)) {
                    $row = $subs->get_by_stripe_id((string) $obj->subscription);
                    $subs->update_by_stripe_id((string) $obj->subscription, 'active', null, 0);
                    if ($row && (string) ($obj->billing_reason ?? '') === 'subscription_cycle') {
                        $this->sub_notice($row, 'renewed', 'Your membership to ' . (Notify::name_of((int) $row['creator_id']) ?: 'this creator') . ' renewed' . (!empty($obj->amount_paid) ? ' for $' . number_format(((int) $obj->amount_paid) / 100, 2) : '') . '.', 'fa-heart');
                    }
                }
                break;

            case 'charge.dispute.created':
                // A chargeback (PRD §21): log it and suspend the disputing account pending review.
                $suspended = (new RefundsModel())->record_chargeback($obj);
                if ($suspended > 0) {
                    Notify::send($suspended, 'system', 'Account paused pending review', 'A payment on your account was disputed with your bank. Your account is paused while we review it. Reply to this email if you think this is a mistake.', '/', 'fa-shield-halved', false, true);
                }
                break;
        }

        http_response_code(200);
        echo 'ok';
        exit;
    }

    /**
     * Record or update a subscription from its Stripe object. Creating (not just
     * updating) matters when the fan closed the tab before the success redirect —
     * the checkout still succeeded, so the membership must be captured here.
     */
    private function sync_subscription($subs, $obj){
        $status = $this->normalize_status((string) $obj->status);
        $period = $obj->current_period_end ?? null;
        $cape   = !empty($obj->cancel_at_period_end) ? 1 : 0;

        if ($subs->exists_by_stripe_id((string) $obj->id)) {
            $subs->update_by_stripe_id((string) $obj->id, $status, $period, $cape);
            return;
        }

        $meta       = ($obj->metadata && method_exists($obj->metadata, 'toArray')) ? $obj->metadata->toArray() : (array) $obj->metadata;
        $subscriber = (int) ($meta['subscriber_id'] ?? 0);
        $creator    = (int) ($meta['creator_id'] ?? 0);
        $plan_id    = (int) ($meta['plan_id'] ?? 0);
        if (!$subscriber || !$creator || !$plan_id) {
            return;
        }

        $plan = (new CreatorPlansModel())->get_by_id($plan_id);
        if (!$plan) {
            return;
        }

        $customer = is_string($obj->customer) ? $obj->customer : (isset($obj->customer->id) ? (string) $obj->customer->id : '');
        $subs->record_paid($subscriber, $creator, $plan, array(
            'subscription_id'    => (string) $obj->id,
            'customer_id'        => $customer,
            'current_period_end' => $period,
        ));
        // The fan closed the tab before the success page: tell both sides here instead.
        $chandle = Notify::handle_of($creator);
        Notify::send($subscriber, 'subscriptions', 'You\'re subscribed to ' . (Notify::name_of($creator) ?: $plan['name']), $plan['name'] . ' is active. Manage it in Settings › My Subscriptions.', $chandle !== '' ? '/@' . $chandle : '/', 'fa-heart');
        Notify::send($creator, 'subscriptions', 'New subscriber', (Notify::name_of($subscriber) ?: 'Someone') . ' subscribed to ' . $plan['name'] . '.', '/audience', 'fa-user-plus');
        InboxAutomationService::trigger($creator, $subscriber, 'new_subscriber');
        if ($status !== 'active' || $cape) {
            $subs->update_by_stripe_id((string) $obj->id, $status, $period, $cape);
        }
    }

    private function sub_notice(array $row, $what, $body, $icon): void {
        $plan = (string) ($row['plan_name'] ?? 'Membership');
        Notify::send((int) $row['subscriber_id'], 'subscriptions', $plan . ' ' . $what, $body, '/account/settings?section=subscriptions', $icon);
    }

    /** Collapse Stripe's status set into the three our access checks understand. */
    private function normalize_status($status){
        if ($status === 'active' || $status === 'trialing') {
            return 'active';
        }
        if ($status === 'past_due' || $status === 'unpaid') {
            return 'past_due';
        }
        if ($status === 'canceled' || $status === 'incomplete_expired') {
            return 'canceled';
        }
        return $status;
    }
}
