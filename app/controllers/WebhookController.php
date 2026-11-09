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
     * Stripe webhook for platform payments: plan card setups, plan charges (BillingService), AI credit packs, and
     * refunds/disputes on them. Configure the endpoint URL + signing secret (stripe_webhook_secret) in the
     * Stripe dashboard.
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

        // A card saved (or declined) in a plan / add-on checkout: Admin > Billing > Checkout attempts. The page reports it too; counted once.
        if (empty($event->account) && in_array($event->type, array('setup_intent.succeeded', 'setup_intent.setup_failed'), true)
            && (string) ($obj->metadata['type'] ?? '') === 'platform_card' && (int) ($obj->metadata['user_id'] ?? 0) > 0) {
            $uid = (int) $obj->metadata['user_id'];
            if ($event->type === 'setup_intent.succeeded') {
                CheckoutAttempts::card_entered($uid, (string) $obj->id);
            } else {
                $err = $obj->last_setup_error ?? null;
                $code = $err ? ((string) ($err->decline_code ?? '') !== '' ? (string) $err->decline_code : (string) ($err->code ?? '')) : '';
                CheckoutAttempts::card_failed($uid, $code, $err ? (string) ($err->message ?? '') : '', (string) $obj->id);
            }
            http_response_code(200);
            echo 'ok';
            exit;
        }

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
                $lpe = $obj->last_payment_error ?? null;
                BillingService::settle((int) $row['id'], array('status' => $st, 'payment_intent_id' => (string) $obj->id, 'client_secret' => '',
                    'reason' => (string) ($lpe->message ?? ''), 'code' => $lpe ? ((string) ($lpe->decline_code ?? '') !== '' ? (string) $lpe->decline_code : (string) ($lpe->code ?? '')) : ''));
            }
            http_response_code(200);
            echo 'ok';
            exit;
        }

        // AI credit packs: the backup for a buyer who paid and closed the tab before the page
        // confirmed. Same idempotent path as the page, so the credits are added exactly once.
        if (empty($event->account) && $event->type === 'payment_intent.succeeded'
            && in_array((string) ($obj->metadata['type'] ?? ''), TopUps::TYPES, true)) {
            TopUps::fulfill($obj);
            http_response_code(200);
            echo 'ok';
            exit;
        }

        // An AI credit pack refunded or disputed in Stripe: take the refunded or disputed share of its credits
        // back out (never below zero, never twice). A dispute then goes on to record_chargeback below.
        if (empty($event->account) && in_array($event->type, array('charge.refunded', 'charge.dispute.created'), true)) {
            try {
                $pi      = is_object($obj->payment_intent ?? null) ? (string) $obj->payment_intent->id : (string) ($obj->payment_intent ?? '');
                $dispute = $event->type === 'charge.dispute.created';
                // a dispute carries its own amount but not the charge's: 0 = measure it against what the card paid
                $taken   = $dispute ? TopUps::reverse($pi, (int) ($obj->amount ?? 0), 0, 'card payment disputed')
                                    : TopUps::reverse($pi, (int) ($obj->amount_refunded ?? 0), (int) ($obj->amount ?? 0), 'card payment refunded');
                if ($taken === TopUps::LOCK_BUSY) {   // another event for this payment held the lock too long: never skip, let Stripe redeliver
                    error_log('[stripe webhook] ' . $event->type . ' reverse: lock busy for ' . $pi . ', asking for a retry');
                    http_response_code(500);
                    echo 'retry';
                    exit;
                }
                if ($taken >= 0 && !$dispute) {
                    http_response_code(200);
                    echo 'ok';
                    exit;
                }
            } catch (\Throwable $e) {
                error_log('[stripe webhook] ' . $event->type . ' reverse: ' . $e->getMessage());
            }
        }

        $this->answer_account_event($event);
    }

    /** Run handle_account_event and answer: 200, also after an error (logged), or 500 only when it asks Stripe to retry. */
    private function answer_account_event($event): void {
        $ok = true;
        try {
            $ok = $this->handle_account_event($event);
        } catch (\Throwable $e) {
            error_log('[stripe webhook] ' . $event->type . ': ' . $e->getMessage());
        }
        http_response_code($ok ? 200 : 500);
        echo $ok ? 'ok' : 'retry';
        exit;
    }

    /** Refunds and disputes on platform plan charges. False only when Stripe must retry the event. */
    private function handle_account_event($event): bool {
        if (!empty($event->account)) { return true; }   // connected-account events: nothing on the platform to act on
        $obj = $event->data->object;
        switch ($event->type) {
            case 'charge.refunded':
                $this->affiliate_reverse($obj);   // a refunded plan charge takes back its affiliate commission
                break;

            case 'charge.dispute.closed':
                // the bank sided with us: a plan charge's affiliate commission comes back where it can
                if ((string) ($obj->status ?? '') === 'won') {
                    $pi = is_object($obj->payment_intent ?? null) ? (string) $obj->payment_intent->id : (string) ($obj->payment_intent ?? '');
                    if (!Affiliates::on_restore($pi)) { return false; }
                }
                break;

            case 'charge.dispute.created':
                // A chargeback (PRD §21): log it and suspend the disputing account pending review.
                $suspended = (new RefundsModel())->record_chargeback($obj);
                $this->affiliate_reverse($obj);   // a disputed plan charge takes back its affiliate commission
                if ($suspended > 0) {
                    Notify::send($suspended, 'system', 'Account paused pending review', 'A payment on your account was disputed with your bank. Your account is paused while we review it. Reply to this email if you think this is a mistake.', '/', 'fa-shield-halved', false, true);
                }
                break;
        }
        return true;
    }

    /** Refund or dispute: take back the affiliate commission; a busy payout lock answers 500 so Stripe redelivers (never skip). */
    private function affiliate_reverse($obj): void {
        $pi = is_object($obj->payment_intent ?? null) ? (string) $obj->payment_intent->id : (string) ($obj->payment_intent ?? '');
        // a refund takes back its refunded share (amount_refunded is the running total); a dispute its disputed amount
        $taken = ((string) ($obj->object ?? '') === 'dispute') ? (int) ($obj->amount ?? 0) : (isset($obj->amount_refunded) ? (int) $obj->amount_refunded : null);
        ReferralRewards::on_reverse($pi);   // the referring creator's AI credit reward goes back too (once)
        if (!Affiliates::on_reverse($pi, $taken ?: null)) {
            error_log('[stripe webhook] affiliate reverse: lock busy for ' . $pi . ', asking for a retry');
            http_response_code(500);
            echo 'retry';
            exit;
        }
    }

    /**
     * Creator ID check results from Didit (IdentityVerification). Didit signs every delivery; an unsigned or stale one
     * is refused. A genuine one is always answered 2xx so Didit stops retrying, including an unknown session reference
     * (acknowledged, nothing changes).
     */
    public function diditAction(){
        $raw = (string) file_get_contents('php://input');
        $headers = function_exists('getallheaders') ? (getallheaders() ?: array()) : array();
        if (!DiditProvider::verify_webhook($raw, $headers)) {
            http_response_code(401);
            echo 'unauthorized';
            exit;
        }
        $payload = json_decode($raw, true);
        if (is_array($payload)) { IdentityVerification::apply_payload($payload); }
        http_response_code(200);
        echo 'ok';
        exit;
    }

    /**
     * Postmark bounce + spam-complaint webhook: POST /webhook/postmark/<secret> (or HTTP basic auth with the secret as
     * the password). The secret is app.ini postmark_webhook_secret. Hard bounces and spam complaints mark the address
     * (LifecycleEmails::postmark_webhook); soft bounces are acknowledged and ignored.
     */
    public function postmarkAction(){
        $url   = Main::get_url();
        $given = (string) ($url[2] ?? '');
        if ($given === '') { $given = (string) ($_SERVER['PHP_AUTH_PW'] ?? ''); }
        $res = LifecycleEmails::postmark_webhook((string) file_get_contents('php://input'), $given);
        http_response_code((int) $res[0]);
        echo $res[1];
        exit;
    }
}
