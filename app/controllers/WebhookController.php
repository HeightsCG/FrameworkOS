<?php
/**
 * Public, unauthenticated endpoints for inbound provider webhooks.
 * NOT extended from ApiController, so it carries no CSRF check; $protected = 0
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
     * Fanvue creator.* webhooks (Developer Area > app > Events). Signed with the
     * per-app secret (X-Fanvue-Signature: t=<unix>,v0=<hmac>). Fanvue retries anything
     * that isn't a 2xx within 10 s, so an inbound message is persisted, acknowledged,
     * and only THEN handed to the inbox automation in the same request.
     */
    public function fanvueAction(){
        $raw = (string) file_get_contents('php://input');
        $sig = (string) ($_SERVER['HTTP_X_FANVUE_SIGNATURE'] ?? '');

        $secret = FanvueService::webhook_secret();
        if ($secret === '') {
            error_log('[fanvue webhook] fanvue_webhook_secret not configured');
            http_response_code(503);
            echo 'not configured';
            exit;
        }
        if (!FanvueService::verify_webhook_signature($raw, $sig, $secret)) {
            error_log('[fanvue webhook] refused: bad signature');
            http_response_code(401);
            echo 'unauthorized';
            exit;
        }

        $ev = json_decode($raw, true);
        if (!is_array($ev) || empty($ev['id']) || empty($ev['type'])) {
            http_response_code(400);
            echo 'bad request';
            exit;
        }
        $type = (string) $ev['type'];
        $data = (array) ($ev['data'] ?? array());

        if ($type === 'creator.message.sent') {
            // Echo of our own send, or the creator answering by hand → drop waiting drafts.
            $account = (new FanvueAccountsModel())->get_by_fanvue_uuid((string) ($data['creator']['uuid'] ?? ''));
            $fan     = (string) ($data['fan']['uuid'] ?? '');
            $mid     = (string) ($data['uuid'] ?? '');
            if ($account && $fan !== '') {
                $replies = new InboxRepliesModel();
                if ($mid === '' || !$replies->is_our_message((int) $account['user_id'], 'fanvue', $mid)) {
                    $replies->dismiss_pending_for_peer((int) $account['user_id'], 'fanvue', $fan, 'creator_replied');
                }
            }
            http_response_code(200);
            echo 'ok';
            exit;
        }

        if ($type !== 'creator.message.received') {
            http_response_code(200);
            echo 'ignored';
            exit;
        }

        $account = (new FanvueAccountsModel())->get_by_fanvue_uuid((string) ($data['creator']['uuid'] ?? ''));
        if (!$account) {
            // Not one of our creators (or disconnected): acknowledge so Fanvue stops retrying.
            http_response_code(200);
            echo 'ok';
            exit;
        }

        $event_id = (new InboxEventsModel())->record('fanvue', (string) $ev['id'], (int) $account['user_id'], $type, $raw);
        if ($event_id === 0) {
            http_response_code(200);
            echo 'ok';   // duplicate delivery
            exit;
        }

        InboxAutomationService::respond_early('ok');
        $out = InboxAutomationService::process_event($event_id);
        error_log('[fanvue webhook] event ' . (string) $ev['id'] . ' → ' . $out['status'] . ' (' . $out['result'] . ')');
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
                $subs->update_by_stripe_id($obj->id, 'canceled', $obj->current_period_end ?? null, 0);
                break;

            case 'invoice.payment_failed':
                // A failed renewal must stop granting access until it's resolved.
                if (!empty($obj->subscription)) {
                    $subs->update_by_stripe_id((string) $obj->subscription, 'past_due', null, 0);
                }
                break;

            case 'invoice.payment_succeeded':
                // Renewal cleared a past_due; the paired subscription.updated carries the new period.
                if (!empty($obj->subscription) && $subs->exists_by_stripe_id((string) $obj->subscription)) {
                    $subs->update_by_stripe_id((string) $obj->subscription, 'active', null, 0);
                }
                break;

            case 'charge.dispute.created':
                // A chargeback (PRD §21): log it and suspend the disputing account pending review.
                (new RefundsModel())->record_chargeback($obj);
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
        if ($status !== 'active' || $cape) {
            $subs->update_by_stripe_id((string) $obj->id, $status, $period, $cape);
        }
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
