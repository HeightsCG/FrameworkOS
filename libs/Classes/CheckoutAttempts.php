<?php
/**
 * Checkout attempts: who opened a plan or add-on checkout and what happened, so Admin never needs the Stripe dashboard.
 *
 *   opened        billing_quote for a plan (subscribe / upgrade), an AI credit pack or extra AI influencers
 *   card_entered  a card saved in the checkout, or a charge started on the card on file
 *   succeeded     the charge went through (a $0 trial start counts)
 *   failed        the card was declined (in the card form or on the charge), with Stripe's decline code and a plain reason
 *   abandoned     no card within an hour of opening, or a card but no payment within an hour (expire(), run by cron/billing.php and the admin pages)
 *
 * A failed card can be retried in the same checkout: the row moves on and keeps the decline count and the last reason.
 * Every call is wrapped so tracking can never break a payment.
 */
class CheckoutAttempts {

    const WINDOW = 3600;        // no card within an hour of opening = abandoned
    const RETURN_WINDOW = 21600; // a card or a payment within 6 hours still belongs to that checkout (an abandoned one comes back)
    const LIVE = array('opened', 'card_entered', 'failed', 'abandoned');

    /** Stripe decline / error codes in plain language. */
    const REASONS = array(
        'insufficient_funds'        => 'Card declined: insufficient funds',
        'generic_decline'           => 'Card declined',
        'card_declined'             => 'Card declined',
        'do_not_honor'              => 'Card declined by the bank',
        'call_issuer'               => 'Card declined: the bank wants the cardholder to call',
        'lost_card'                 => 'Card declined: reported lost',
        'stolen_card'               => 'Card declined: reported stolen',
        'pickup_card'               => 'Card declined: card restricted',
        'restricted_card'           => 'Card declined: card restricted',
        'fraudulent'                => 'Card declined: suspected fraud',
        'merchant_blacklist'        => 'Card declined: blocked',
        'transaction_not_allowed'   => 'Card declined: the bank does not allow this payment',
        'not_permitted'             => 'Card declined: the bank does not allow this payment',
        'card_velocity_exceeded'    => 'Card declined: over the card\'s limit',
        'withdrawal_count_limit_exceeded' => 'Card declined: over the card\'s limit',
        'try_again_later'           => 'Card declined: the bank said try again later',
        'expired_card'              => 'Card expired',
        'incorrect_cvc'             => 'Wrong security code (CVC)',
        'invalid_cvc'               => 'Wrong security code (CVC)',
        'incorrect_number'          => 'Wrong card number',
        'invalid_number'            => 'Wrong card number',
        'invalid_expiry_month'      => 'Wrong expiry date',
        'invalid_expiry_year'       => 'Wrong expiry date',
        'incorrect_zip'             => 'ZIP code did not match',
        'processing_error'          => 'The card network had an error',
        'card_not_supported'        => 'This card type is not supported',
        'currency_not_supported'    => 'The card does not support US dollars',
        'authentication_required'   => 'Bank authentication required',
        'payment_intent_authentication_failure' => 'Bank authentication failed',
        'setup_intent_authentication_failure'   => 'Bank authentication failed',
    );

    /** A plain reason for a Stripe code, falling back to Stripe's own message. */
    public static function reason($code, $message = ''): string
    {
        $code = strtolower(trim((string) $code));
        if (isset(self::REASONS[$code])) { return self::REASONS[$code]; }
        $message = trim((string) $message);
        if ($message !== '') { return mb_substr($message, 0, 255); }
        return $code !== '' ? 'Card declined (' . str_replace('_', ' ', $code) . ')' : 'Card declined';
    }

    /** The checkout a charge belongs to: subscribe/upgrade = plan; renewals are not checkouts. */
    public static function kind_for_charge($charge_kind): string
    {
        $k = (string) $charge_kind;
        if ($k === 'subscribe' || $k === 'upgrade') { return 'plan'; }
        return in_array($k, array('pack', 'slots'), true) ? $k : '';
    }

    private static function run(callable $fn, $label)
    {
        try { return $fn(new CheckoutAttemptsModel()); }
        catch (\Throwable $e) { error_log('[checkout_attempts] ' . $label . ': ' . $e->getMessage()); return 0; }
    }

    /** A checkout opened (or re-quoted, e.g. a promo applied). Returns the attempt id. */
    public static function opened($user_id, $kind, $item, $mode, $today_cents, $recurring_cents, $founding = false): int
    {
        return (int) self::run(function (CheckoutAttemptsModel $m) use ($user_id, $kind, $item, $mode, $today_cents, $recurring_cents, $founding) {
            $f = array('kind' => (string) $kind, 'item' => (string) $item, 'mode' => (string) $mode, 'founding' => $founding ? 1 : 0,
                       'amount_cents' => (int) $today_cents, 'recurring_cents' => (int) $recurring_cents);
            $cur = $m->current($user_id, (string) $kind, self::WINDOW, array('opened', 'card_entered'));
            if ($cur && (string) $cur['item'] === (string) $item && in_array((string) $cur['status'], array('opened', 'card_entered'), true)) {
                $m->set((int) $cur['id'], $f);   // the same checkout, quoted again
                return (int) $cur['id'];
            }
            return $m->create($f + array('user_id' => (int) $user_id, 'status' => 'opened'));
        }, 'opened');
    }

    /** A card was saved in the checkout. */
    public static function card_entered($user_id, $setup_intent_id = ''): void
    {
        self::run(function (CheckoutAttemptsModel $m) use ($user_id, $setup_intent_id) {
            $cur = $m->current($user_id, '', self::RETURN_WINDOW, self::LIVE);
            if (!$cur || (string) $cur['status'] === 'card_entered') { return 0; }
            $f = array('status' => 'card_entered', 'card_entered_at' => gmdate('Y-m-d H:i:s'), 'finished_at' => null);
            if ((string) $cur['status'] === 'abandoned') { $f['failure_reason'] = null; }   // came back to it
            if ((string) $setup_intent_id !== '') { $f['stripe_setup_intent_id'] = mb_substr((string) $setup_intent_id, 0, 64); }
            return $m->set((int) $cur['id'], $f);
        }, 'card_entered');
    }

    /** The card form was declined (a SetupIntent failed). */
    public static function card_failed($user_id, $code, $message = '', $setup_intent_id = ''): void
    {
        self::run(function (CheckoutAttemptsModel $m) use ($user_id, $code, $message, $setup_intent_id) {
            $cur = $m->current($user_id, '', self::RETURN_WINDOW, self::LIVE);
            if (!$cur) { return 0; }
            $si = mb_substr((string) $setup_intent_id, 0, 64);
            // the page and the webhook both report the same decline: count it once
            if ((string) $cur['status'] === 'failed' && $si !== '' && (string) $cur['stripe_setup_intent_id'] === $si) { return 0; }
            $f = array('status' => 'failed', 'failure_code' => mb_substr((string) $code, 0, 64) ?: null, 'failure_reason' => self::reason($code, $message),
                       'declines' => (int) $cur['declines'] + 1, 'finished_at' => gmdate('Y-m-d H:i:s'));
            if ($si !== '') { $f['stripe_setup_intent_id'] = $si; }
            return $m->set((int) $cur['id'], $f);
        }, 'card_failed');
    }

    /** BillingService::charge() created a charge: link it to the user's open checkout (a charge with no checkout open gets one). */
    public static function charging($user_id, $charge_kind, $charge_id, $amount_cents, $item = ''): void
    {
        $kind = self::kind_for_charge($charge_kind);
        if ($kind === '') { return; }
        self::run(function (CheckoutAttemptsModel $m) use ($user_id, $kind, $charge_kind, $charge_id, $amount_cents, $item) {
            $now = gmdate('Y-m-d H:i:s');
            $cur = $m->current($user_id, $kind, self::RETURN_WINDOW, self::LIVE);
            $f = array('status' => 'card_entered', 'charge_id' => (int) $charge_id, 'amount_cents' => (int) $amount_cents, 'finished_at' => null);
            if (!$cur) {
                return $m->create($f + array('user_id' => (int) $user_id, 'kind' => $kind, 'item' => (string) $item, 'mode' => (string) $charge_kind, 'card_entered_at' => $now));
            }
            if ((string) $cur['status'] === 'abandoned') { $f['failure_reason'] = null; }
            if ((string) ($cur['card_entered_at'] ?? '') === '') { $f['card_entered_at'] = $now; }
            return $m->set((int) $cur['id'], $f);
        }, 'charging');
    }

    /** BillingService::settle() learned a charge's outcome. $status: succeeded | failed | requires_action | processing. */
    public static function settled($charge_id, $status, $reason = '', $code = '', $payment_intent_id = ''): void
    {
        self::run(function (CheckoutAttemptsModel $m) use ($charge_id, $status, $reason, $code, $payment_intent_id) {
            $a = $m->by_charge($charge_id);
            if (!$a || (string) $a['status'] === 'succeeded') { return 0; }   // a success is final (webhooks can arrive late or twice)
            $now = gmdate('Y-m-d H:i:s');
            $f = array();
            if ((string) $payment_intent_id !== '') { $f['stripe_payment_intent_id'] = mb_substr((string) $payment_intent_id, 0, 64); }
            if ($status === 'succeeded') {
                $f += array('status' => 'succeeded', 'finished_at' => $now);
            } elseif ($status === 'failed') {
                if ((string) $a['status'] === 'failed') { return $m->set((int) $a['id'], $f); }   // already counted
                $f += array('status' => 'failed', 'failure_code' => mb_substr((string) $code, 0, 64) ?: null, 'failure_reason' => self::reason($code, $reason),
                            'declines' => (int) $a['declines'] + 1, 'finished_at' => $now);
            } else {
                $f += array('status' => 'card_entered', 'failure_reason' => $status === 'requires_action' ? 'Waiting for bank authentication' : 'Payment processing');
            }
            return $m->set((int) $a['id'], $f);
        }, 'settled');
    }

    /** Abandon checkouts past the hour. */
    public static function expire(): int
    {
        return (int) self::run(function (CheckoutAttemptsModel $m) { return $m->expire(self::WINDOW); }, 'expire');
    }

    /** Admin label for a status. */
    public static function label($status): string
    {
        $s = (string) $status;
        return $s === 'card_entered' ? 'Card entered' : ucfirst($s);
    }

    /** Admin label for what was bought: "Creator", "Studio (upgrade)", "$20 AI credit pack", "2 extra AI influencers". */
    public static function item_label(array $a): string
    {
        $item = (string) $a['item'];
        if ((string) $a['kind'] === 'pack') { return $item !== '' ? '$' . (int) $item . ' AI credit pack' : 'AI credit pack'; }
        if ((string) $a['kind'] === 'slots') { return $item !== '' ? (int) $item . ' extra AI influencer' . ((int) $item === 1 ? '' : 's') : 'Extra AI influencers'; }
        $t = $item !== '' ? PlanTiers::get($item) : null;
        $name = $t ? (string) $t['name'] : ($item !== '' ? ucfirst($item) : 'Plan');
        return $name . ((string) $a['mode'] === 'upgrade' ? ' (upgrade)' : '') . (!empty($a['founding']) ? ' (trial)' : '');
    }
}
