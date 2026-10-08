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

        // A creator's platform plan is billed by the app (BillingService): reconcile its PaymentIntents.
        // settle() is idempotent with the job and the page, so nothing is charged or granted twice.
        if (empty($event->account) && in_array($event->type, array('payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.requires_action'), true)
            && (string) ($obj->metadata['type'] ?? '') === 'platform_billing') {
            $bc  = new BillingChargesModel();
            $row = $bc->by_payment_intent((string) $obj->id);
            if (!$row && (int) ($obj->metadata['charge_id'] ?? 0) > 0) {   // the create call's reply was lost: match by charge id
                $row = $bc->get((int) $obj->metadata['charge_id']);
                if ($row && (string) $row['stripe_payment_intent_id'] === '' && (int) $row['user_id'] === (int) ($obj->metadata['user_id'] ?? 0)) {
                    $bc->set((int) $row['id'], array('stripe_payment_intent_id' => (string) $obj->id));
                } else { $row = null; }
            }
            if ($row) {
                $st = $event->type === 'payment_intent.succeeded' ? 'succeeded' : ($event->type === 'payment_intent.requires_action' ? 'requires_action' : 'failed');
                BillingService::settle((int) $row['id'], array('status' => $st, 'payment_intent_id' => (string) $obj->id, 'client_secret' => '',
                    'reason' => (string) ($obj->last_payment_error->message ?? '')));
            }
            http_response_code(200);
            echo 'ok';
            exit;
        }

        // Wallet top-ups and AI credit packs: the backup for a buyer who paid and closed the tab before the page
        // confirmed. Same idempotent path as the page, so the credits are added exactly once.
        if (empty($event->account) && $event->type === 'payment_intent.succeeded'
            && in_array((string) ($obj->metadata['type'] ?? ''), TopUps::TYPES, true)) {
            TopUps::fulfill($obj);
            http_response_code(200);
            echo 'ok';
            exit;
        }

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
     * Stripe Connect webhook: payout.* events from creators' connected accounts, so the
     * cash-out history shows each bank payout and its outcome. A separate endpoint in the
     * Stripe dashboard ("listen to events on connected accounts"), so it has its own
     * signing secret (stripe_connect_webhook_secret). See docs/ops.md.
     */
    public function stripe_connectAction(){
        $payload = file_get_contents('php://input');
        $sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        $secret = '';
        try { $secret = (string) Main::config(Main::get_environment(), 'stripe_connect_webhook_secret'); } catch (\Throwable $e) {}
        if ($secret === '') {
            error_log('[stripe connect webhook] stripe_connect_webhook_secret not configured');
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

        $types = array('payout.created', 'payout.updated', 'payout.paid', 'payout.failed', 'payout.canceled');
        if (in_array($event->type, $types, true)) {
            $obj     = $event->data->object;
            $account = (string) ($event->account ?? '');
            $model   = new PayoutsModel();
            $creator = $model->creator_for_account($account);
            if ($creator <= 0) {
                // an account we no longer know (disconnected or another environment): acknowledge so Stripe stops retrying.
                error_log('[stripe connect webhook] ' . $event->type . ' for unknown account ' . $account);
            } else {
                // true only for the first delivery (of any payout.* type) that records the failure.
                if ($model->upsert_from_event($creator, $obj)) {
                    $row = $model->get_by_payout_id((string) $obj->id);
                    $why = trim((string) ($row['failure_message'] ?? ($obj->failure_message ?? '')));
                    $cur = strtoupper((string) ($row['currency'] ?? ($obj->currency ?? 'usd')));
                    $amt = ($cur === 'USD' ? '$' : $cur . ' ') . number_format(((int) ($row['amount_cents'] ?? $obj->amount)) / 100, 2);
                    Notify::send($creator, 'credits', 'Bank payout failed',
                        'Your ' . $amt . ' payout to your bank did not go through.' . ($why !== '' ? ' ' . $why : '') . ' Check your bank details and contact support if it keeps happening.',
                        '/account/settings?section=wallet&tab=cashout', 'fa-triangle-exclamation');
                }
            }
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

        // A second paid membership with the same creator: cancel it in Stripe, never record it over the first.
        if ($subs->has_other_active_paid($subscriber, $creator, (string) $obj->id)) {
            $cu = (new UsersModel())->get_user_by_id($creator);
            $acct = (is_array($cu) && count($cu) === 1) ? (string) ($cu[0]['stripe_connect_account_id'] ?? '') : '';
            if ($acct !== '') { StripeService::cancel_subscription_now($acct, (string) $obj->id); }
            error_log('[membership] duplicate canceled: fan=' . $subscriber . ' creator=' . $creator . ' sub=' . (string) $obj->id);
            return;
        }
        $customer = is_string($obj->customer) ? $obj->customer : (isset($obj->customer->id) ? (string) $obj->customer->id : '');
        $subs->record_paid($subscriber, $creator, $plan, array(
            'subscription_id'    => (string) $obj->id,
            'customer_id'        => $customer,
            'current_period_end' => $period,
            'sub_status'         => (string) $obj->status,   // 'trialing' only when Stripe really gave a trial
        ));
        // The fan closed the tab before the success page: tell both sides here instead. The success page and this webhook
        // share one claim, so whichever runs first sends the notices (and counts the discount code) and the other doesn't.
        if ($subs->claim_checkout_recorded((string) $obj->id)) {
            $chandle = Notify::handle_of($creator);
            Notify::send($subscriber, 'subscriptions', 'You\'re subscribed to ' . (Notify::name_of($creator) ?: $plan['name']), $plan['name'] . ' is active. Manage it in Settings › My Subscriptions.', $chandle !== '' ? '/@' . $chandle : '/', 'fa-heart');
            Notify::send($creator, 'subscriptions', 'New subscriber', (Notify::name_of($subscriber) ?: 'Someone') . ' subscribed to ' . $plan['name'] . '.', '/audience', 'fa-user-plus');
            InboxAutomationService::trigger($creator, $subscriber, 'new_subscriber');
            if (!empty($meta['promo_id'])) { (new CreatorPromoCodesModel())->redeem((int) $meta['promo_id']); }
        }
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
