<?php
/** Platform plan billing (app-managed, BillingService) and AI credit purchases. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiBillingController extends BaseApiController {

    /* ---------- Platform plan: app-managed billing (BillingService); Stripe only holds the card ---------- */

    /** Plan, add-ons, card and the next charge, for the billing page script. */
    private function billing_state(array $user): array{
        $acct = BillingService::account((int) $user['user_id']);
        $next = BillingService::next_charge($acct);
        return array(
            'plan' => (string) $acct['plan_key'], 'status' => (string) $acct['status'],
            'next_charge_at' => (string) ($acct['next_charge_at'] ?? ''), 'cancel_at_period_end' => (int) $acct['cancel_at_period_end'],
            'card' => (string) ($acct['stripe_payment_method_id'] ?? '') !== '' ? trim($acct['card_brand'] . ((string) $acct['card_last4'] !== '' ? ' •••• ' . $acct['card_last4'] : '')) : '',
            'next' => $next,
        );
    }

    /** The promo code for a plan quote or change: founding=1 (from /founding) takes the internal founding promo (Founding::CODE). */
    private function plan_code(): string{
        $on = (string) ($this->post['founding'] ?? '') === '1' || (string) ($_COOKIE['cls_founding'] ?? '') === '1';
        Founding::present($on);   // FOUNDING is accepted only with the flag, never as a typed promo
        return (string) ($this->post['founding'] ?? '') === '1' ? Founding::CODE : (string) ($this->post['promo_code'] ?? '');
    }

    /** Answer a BillingService charge result; requires_action hands the page a secret to confirm with. */
    private function charge_answer(array $r, string $ok_message): void{
        $st = (string) ($r['status'] ?? 'failed');
        if ($st === 'succeeded' || $st === 'scheduled') { $this->jsonSuccess(['status' => $st, 'message' => $st === 'succeeded' ? $ok_message : (string) $r['message']]); }
        if ($st === 'requires_action') {
            $acct = BillingService::account((int) Session::get('user_id') > 0 ? Permissions::creator_id() : 0);
            $this->jsonSuccess(['status' => $st, 'charge_id' => (int) $r['charge_id'], 'client_secret' => (string) $r['client_secret'],
                'payment_method' => (string) ($acct['stripe_payment_method_id'] ?? ''), 'message' => (string) $r['message']]);
        }
        if ($st === 'processing') { $this->jsonSuccess(['status' => $st, 'message' => (string) $r['message']]); }
        $this->jsonError((string) ($r['message'] ?? 'The payment did not go through.'));
    }

    /**
     * The recurring-charge disclosure before a change: what is charged today, what renews, how
     * often, the next charge date. For plans (plan=<key>), slots (slots=<n>) or a pack (pack=<dollars>).
     */
    public function billing_quoteAction(){
        $user = $this->require_creator('owner', false);
        $uid  = (int) $user['user_id'];
        $acct = BillingService::account($uid);
        $fmt  = function ($lines) { return array_map(function ($l) { return array('label' => $l[0], 'amount' => BillingService::money($l[1])); }, $lines); };
        if (isset($this->post['plan'])) {
            $q = BillingService::quote_plan($uid, (string) $this->post['plan'], $this->plan_code());
            if (empty($q['ok'])) { $this->jsonError((string) $q['message']); }
            Analytics::event('checkout_started', array('user_id' => $uid, 'creator_id' => $uid, 'value_cents' => (int) $q['recurring'], 'meta' => $q['mode'] . ' ' . (string) $this->post['plan'] . (Founding::presented() ? ' founding' : '')));   // a plan checkout opened (LifecycleEmails follows up if it never finishes)
            if ($q['mode'] !== 'downgrade') { CheckoutAttempts::opened($uid, 'plan', (string) $this->post['plan'], $q['mode'], (int) $q['today'], (int) $q['recurring'], (string) ($q['promo']['promo_code'] ?? '') === Founding::CODE); }   // Admin > Billing > Checkout attempts
            $t = PlanTiers::get((string) $this->post['plan']);
            $this->jsonSuccess(['mode' => $q['mode'], 'today' => BillingService::money($q['today']), 'lines' => $fmt($q['lines']),
                'recurring' => BillingService::money($q['recurring']) . ' / month for ' . $t['name'], 'next_at' => date('M j, Y', strtotime($q['next_at'] . ' UTC')),
                'has_card' => (string) ($acct['stripe_payment_method_id'] ?? '') !== '', 'card' => $this->billing_state($user)['card'],
                'promo_ok' => in_array($q['mode'], array('subscribe', 'upgrade'), true), 'promo_label' => (string) ($q['promo_label'] ?? ''),
                'today_zero' => (int) $q['today'] === 0, 'card_needed' => !empty($q['card_needed']),
                'trial_no_card' => BillingService::no_card_trial_quote($q) && (string) ($acct['stripe_payment_method_id'] ?? '') === '', 'trial_credits' => Founding::NO_CARD_CREDITS,
                'plan_credits' => (int) ($t['limits']['ai_credits'] ?? 0),
                'founding' => stripos((string) ($q['promo_label'] ?? ''), Founding::CODE) !== false]);   // the founding trial is what this quote prices
        }
        if (isset($this->post['pack'])) {
            if (!BillingService::is_paid($acct)) { $this->jsonError('Choose a plan to buy AI credits.', ['need_plan' => true]); }   // same gate as billing_set_pack
            $d = (int) $this->post['pack'];
            if (!in_array($d, PlanTiers::AI_PACKS, true)) { $this->jsonError('Choose a valid credit pack.'); }
            $next  = (string) $acct['next_charge_at'];
            if ((int) $acct['pack_dollars'] <= 0) { CheckoutAttempts::opened($uid, 'pack', (string) $d, 'pack', $d * 100, $d * 100); }   // with a pack running, a change is scheduled, not paid
            $this->jsonSuccess(['mode' => 'pack', 'today' => BillingService::money($d * 100), 'lines' => $fmt(array(array(number_format(PlanTiers::pack_credits($d)) . ' AI credits (monthly)', $d * 100))),
                'recurring' => BillingService::money($d * 100) . ' / month for ' . number_format(PlanTiers::pack_credits($d)) . ' AI credits', 'next_at' => date('M j, Y', strtotime($next . ' UTC')),
                'has_card' => (string) ($acct['stripe_payment_method_id'] ?? '') !== '', 'card' => $this->billing_state($user)['card']]);
        }
        if (isset($this->post['slots'])) {
            $add = (int) $this->post['slots'] - (int) $acct['influencer_slots'];
            if ($add <= 0) { $this->jsonError('Nothing to charge.'); }
            $cents = (int) round($add * BillingService::slot_cents() * BillingService::remaining_fraction($acct));
            CheckoutAttempts::opened($uid, 'slots', (string) (int) $this->post['slots'], 'slots', $cents, $add * BillingService::slot_cents());
            $this->jsonSuccess(['mode' => 'slots', 'today' => BillingService::money($cents), 'lines' => $fmt(array(array($add . ' extra AI influencer' . ($add === 1 ? '' : 's') . ' (rest of this period)', $cents))),
                'recurring' => BillingService::money(BillingService::slot_cents()) . ' / month per extra AI influencer', 'next_at' => date('M j, Y', strtotime($acct['next_charge_at'] . ' UTC')),
                'has_card' => (string) ($acct['stripe_payment_method_id'] ?? '') !== '', 'card' => $this->billing_state($user)['card']]);
        }
        $this->jsonError('Nothing to quote.');
    }

    /** Start saving a card: a SetupIntent for off-session charges. */
    public function billing_card_setupAction(){
        $user = $this->require_creator('owner', false);
        $customer = StripeService::ensure_customer($user);
        $si = $customer !== '' ? StripeService::create_setup_intent($customer, array('type' => 'platform_card', 'user_id' => (string) $user['user_id'])) : array();   // the webhook ties a declined card to the checkout
        if (empty($si)) { $this->jsonError('Could not start adding a card. Please try again.'); }
        $this->jsonSuccess(['client_secret' => $si['client_secret']]);
    }

    /** The SetupIntent succeeded in the page: keep its card as the one we charge (retries a past-due account). */
    public function billing_card_saveAction(){
        $user = $this->require_creator('owner', false);
        $r = BillingService::save_card((int) $user['user_id'], (string) ($this->post['setup_intent_id'] ?? ''));
        if (empty($r['ok'])) { $this->jsonError((string) $r['message']); }
        CheckoutAttempts::card_entered((int) $user['user_id'], (string) ($this->post['setup_intent_id'] ?? ''));
        $retry = (array) ($r['retry'] ?? array());
        if (($retry['status'] ?? '') === 'requires_action') {
            $acct = BillingService::account((int) $user['user_id']);
            $this->jsonSuccess(['message' => 'Card saved. Confirm the payment with your bank.', 'status' => 'requires_action', 'charge_id' => (int) $retry['charge_id'],
                'client_secret' => (string) $retry['client_secret'], 'payment_method' => (string) ($acct['stripe_payment_method_id'] ?? '')]);
        }
        $this->jsonSuccess(['message' => (string) $r['message']]);
    }

    /**
     * The card form in a checkout was declined: record it on the checkout attempt. The reason is read from the
     * SetupIntent in Stripe, never taken from the page.
     */
    public function billing_card_failedAction(){
        $user = $this->require_creator('owner', false);
        $si   = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($this->post['setup_intent_id'] ?? ''));
        $customer = (string) ($user['stripe_customer_id'] ?? '');
        $err  = ($si !== '' && $customer !== '') ? StripeService::setup_intent_error($customer, $si) : array();
        if ($err) { CheckoutAttempts::card_failed((int) $user['user_id'], $err['code'], $err['message'], $si); }
        $this->jsonSuccess(['recorded' => $err ? 1 : 0]);
    }

    /** Choose a paid plan (from Free), upgrade now (prorated), or schedule a downgrade. */
    public function billing_change_planAction(){
        $user = $this->require_creator('owner', false);
        $plan = (string) ($this->post['plan'] ?? '');
        if ($plan === PlanTiers::FREE_KEY) { $r = BillingService::set_cancel((int) $user['user_id'], true); if (empty($r['ok'])) { $this->jsonError($r['message']); } $this->jsonSuccess(['status' => 'scheduled', 'message' => $r['message']]); }
        $t = PlanTiers::get($plan);
        // the Creator Agreement is accepted here: a subscribe or upgrade needs the box ticked, stamped when the charge succeeds.
        $agreement = array();
        $q = $t ? BillingService::quote_plan((int) $user['user_id'], $plan, $this->plan_code()) : array();
        if (!empty($q['ok']) && in_array($q['mode'], array('subscribe', 'upgrade'), true)) {
            if ((string) ($this->post['agree_terms'] ?? '') !== '1') { $this->jsonError(CreatorAgreement::MESSAGE, ['need_terms' => true]); }
            $agreement = array('version' => CreatorAgreement::VERSION, 'ip' => $this->get_ip_address());
        }
        $this->charge_answer(BillingService::change_plan((int) $user['user_id'], $plan, $this->plan_code(), $agreement), 'You\'re on ' . ($t ? $t['name'] : 'your new plan') . ' now.');
    }

    /** The founding creator's testimonial (/founding/testimonial): up to 300 characters, plus consent to show name and handle. Account owners with an active founding claim only. */
    public function founding_testimonial_saveAction(){
        $uid = (int) Session::get('user_id');
        if ($uid <= 0) { $this->jsonError('Sign in first.', ['need_login' => true]); }
        $m = new FoundingClaimsModel();
        $c = Permissions::is_owner_creator() ? $m->for_user($uid) : null;
        if (!$c || (string) $c['status'] !== 'active') { $this->jsonError('Only founding creators can send a testimonial.'); }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) ($this->post['text'] ?? ''), ENT_QUOTES, 'UTF-8')));   // POST arrives html-encoded
        if ($text === '') { $this->jsonError('Write a sentence first.'); }
        if (mb_strlen($text) > 300) { $this->jsonError('Keep it to 300 characters.'); }
        $consent = (string) ($this->post['consent'] ?? '') === '1';
        $m->save_testimonial((int) $c['id'], $text, $consent);
        $this->jsonSuccess(['text' => $text, 'consent' => $consent ? 1 : 0, 'message' => 'Testimonial saved']);
    }

    /** One-click cancel: the plan runs to the end of the period, then the account moves to Free. */
    public function billing_cancelAction(){
        $user = $this->require_creator('owner', false);
        $r = BillingService::set_cancel((int) $user['user_id'], true);
        if (empty($r['ok'])) { $this->jsonError($r['message']); }
        $this->jsonSuccess(['message' => $r['message']]);
    }

    public function billing_resumeAction(){
        $user = $this->require_creator('owner', false);
        $r = BillingService::set_cancel((int) $user['user_id'], false);
        if (empty($r['ok'])) { $this->jsonError($r['message']); }
        $this->jsonSuccess(['message' => $r['message']]);
    }

    /** Extra AI influencer slots (Creator): quantity = the total wanted. */
    public function billing_set_slotsAction(){
        $user = $this->require_creator('owner');
        $q = (int) ($this->post['quantity'] ?? -1);
        $this->charge_answer(BillingService::set_slots((int) $user['user_id'], $q), 'You now have ' . $q . ' extra AI influencer' . ($q === 1 ? '' : 's') . '.');
    }

    /** Recurring AI credit pack: dollars = a pack from PlanTiers::AI_PACKS, or 0 to stop it. */
    public function billing_set_packAction(){
        $user = $this->require_creator('owner');
        $d = (int) ($this->post['dollars'] ?? -1);
        $this->charge_answer(BillingService::set_pack((int) $user['user_id'], $d), number_format(PlanTiers::pack_credits($d)) . ' AI credits added. They renew monthly.');
    }

    /** The page finished a bank authentication: record the payment's outcome. */
    public function billing_confirmAction(){
        $user = $this->require_creator('owner', false);
        $this->charge_answer(BillingService::confirm((int) $user['user_id'], (int) ($this->post['charge_id'] ?? 0)), 'Payment confirmed.');
    }

    /** A payment waiting for authentication (the link in the "confirm your payment" email). */
    public function billing_pendingAction(){
        $user = $this->require_creator('owner', false);
        $row  = (new BillingChargesModel())->get_for_user((int) $user['user_id'], (int) ($this->post['charge_id'] ?? 0));
        if (!$row || (string) $row['status'] !== 'requires_action' || (string) $row['stripe_payment_intent_id'] === '') { $this->jsonError('There is no payment waiting for you.'); }
        $r = StripeService::payment_intent_result((string) $row['stripe_payment_intent_id']);
        if ($r['status'] === 'succeeded') { BillingService::settle((int) $row['id'], $r); $this->jsonError('That payment is already complete.'); }
        $acct = BillingService::account((int) $user['user_id']);
        $this->jsonSuccess(['charge_id' => (int) $row['id'], 'client_secret' => $r['client_secret'], 'payment_method' => (string) $acct['stripe_payment_method_id'],
            'amount' => BillingService::money((int) $row['amount_cents'])]);
    }

    /* ---------- AI credits ($1 = 10 credits, PlanTiers::AI_CREDITS_PER_DOLLAR) ---------- */

    public function buy_ai_creditsAction(){
        $user    = $this->require_creator('owner', true, 'buy AI credits');   // one-time AI credit purchases are for Creator and Studio only: a Free account is turned away here (the Billing page shows it no purchase controls either)
        $dollars = (int) ($this->post['dollars'] ?? 0);
        if (!in_array($dollars, PlanTiers::AI_PACKS, true)) {
            $this->jsonError('Choose a valid credit pack');
        }

        try {
            $stripe = StripeService::client();
            list($user, $customer_id) = $this->ensure_stripe_customer($stripe);
            $cents  = $dollars * 100;
            $intent = $stripe->paymentIntents->create([
                'amount'                    => $cents,
                'currency'                  => 'usd',
                'customer'                  => $customer_id,
                'automatic_payment_methods' => ['enabled' => true],
                'description'               => PlanTiers::pack_credits($dollars) . ' AI credits',
                'metadata'                  => [
                    'user_id' => (string) $user['user_id'],
                    'credits' => (string) PlanTiers::pack_credits($dollars),
                    'type'    => 'ai_credit_purchase',
                ],
            ]);
            $this->jsonSuccess(['client_secret' => $intent->client_secret, 'credits' => PlanTiers::pack_credits($dollars), 'total_cents' => $cents, 'message' => 'Payment ready']);
        } catch (\Throwable $e) {
            error_log('[stripe] buy_ai_credits: ' . $e->getMessage());
            $this->jsonError('Could not start the purchase. Please try again.');
        }
    }

    public function confirm_ai_credit_purchaseAction(){
        $user  = $this->require_creator('owner');
        $pi_id = (string) ($this->post['payment_intent_id'] ?? '');
        if ($pi_id === '') {
            $this->jsonError('Payment reference is required');
        }

        try {
            $user_id = (int) $user['user_id'];
            $intent  = StripeService::client()->paymentIntents->retrieve($pi_id);

            if ((string) ($intent->metadata['type'] ?? '') !== 'ai_credit_purchase'
                || (int) ($intent->metadata['user_id'] ?? 0) !== $user_id
                || (string) $intent->customer !== (string) ($user['stripe_customer_id'] ?? '')) {
                $this->jsonError('This payment could not be verified');
            }
            if ($intent->status !== 'succeeded') {
                $this->jsonError('Payment has not completed yet');
            }

            $r = TopUps::fulfill($intent);   // the webhook may have added them already; either way they are added once
            if (!$r['ok']) { $this->jsonError('Could not confirm the purchase'); }
            $this->jsonSuccess(['balance' => $r['balance'], 'message' => number_format($r['credits']) . ' AI credits added']);

        } catch (\Throwable $e) {
            error_log('[stripe] confirm_ai_credit_purchase: ' . $e->getMessage());
            $this->jsonError('Could not confirm the purchase');
        }
    }

    /** Ensure the logged-in user has a Stripe customer; returns [user, customer_id]. */
    private function ensure_stripe_customer($stripe): array{
        $user_id     = (int) Session::get('user_id');
        $user        = $this->userModel->get_user_by_id($user_id)[0];
        $customer_id = StripeService::ensure_customer($user);   // replaces a stored id Stripe no longer has
        if ($customer_id === '') { throw new RuntimeException('Could not create the Stripe customer'); }
        return [$this->userModel->get_user_by_id($user_id)[0], $customer_id];
    }

}
