<?php
/**
 * Adding paid AI credits for a succeeded PaymentIntent ('ai_credit_purchase', AI credit packs). The page confirm and
 * the Stripe webhook both come here, so credits land even when the buyer closes the tab after paying. Idempotent per
 * PaymentIntent (the ledger has a unique index on it): only the first caller adds the credits and sends the notice.
 * Returns ['ok', 'new', 'credits', 'balance'].
 */
class TopUps {

    const TYPES = array('ai_credit_purchase');

    /** reverse(): another event for the same payment held the lock past the wait; the caller must ask Stripe to retry. */
    const LOCK_BUSY = -2;

    public static function fulfill($intent): array
    {
        $type    = (string) ($intent->metadata['type'] ?? '');
        $user_id = (int) ($intent->metadata['user_id'] ?? 0);
        $credits = (int) ($intent->metadata['credits'] ?? 0);
        $pi      = (string) $intent->id;
        if (!in_array($type, self::TYPES, true) || $user_id <= 0 || $credits <= 0 || (string) $intent->status !== 'succeeded') {
            return array('ok' => false, 'new' => false, 'credits' => 0, 'balance' => 0);
        }
        $model = new AiCreditsModel();
        $desc  = 'Bought ' . $credits . ' AI credits';

        $new = !$model->has_payment_intent($pi) && $model->credit_purchase($user_id, $credits, $pi, $desc) !== false
            && $model->has_payment_intent($pi);
        $balance = (int) $model->get_balance($user_id);
        if (!$new) {   // already added (by the page or the webhook), or lost the race to one of them
            return array('ok' => $model->has_payment_intent($pi), 'new' => false, 'credits' => $credits, 'balance' => $balance);
        }

        Notify::send($user_id, 'credits', 'AI credits added', number_format($credits) . ' AI credits added. Balance: ' . number_format($balance) . ' AI credits.', '/account/billing?tab=credits', 'fa-wand-magic-sparkles');
        return array('ok' => true, 'new' => true, 'credits' => $credits, 'balance' => $balance);
    }

    /**
     * An AI credit pack refunded or disputed in Stripe: take the refunded or disputed share ($part of $total cents;
     * $total 0 = what the card paid for it) of its credits back out of the AI balance. One event at a time per
     * PaymentIntent (named lock), never more than the share in total, never below zero: a shortfall is final and the
     * payment is then settled, so no later event collects from other purchases. Returns credits taken, -1 when the
     * PaymentIntent is not an AI credit purchase, or LOCK_BUSY.
     */
    public static function reverse($payment_intent_id, $part_cents, $total_cents, $why): int
    {
        $pi = (string) $payment_intent_id;
        if ($pi === '') { return -1; }
        $model = new AiCreditsModel();
        $buy   = $model->purchase_by_payment_intent($pi);
        if (!$buy) { return -1; }

        $user_id = (int) $buy['user_id'];
        $credits = (int) $buy['credits'];
        $total   = (int) $total_cents > 0 ? (int) $total_cents : ((int) ($buy['paid_cents'] ?? 0) > 0 ? (int) $buy['paid_cents'] : $credits * 10);
        $share   = (int) $part_cents >= $total ? $credits : (int) round($credits * max(0, (int) $part_cents) / $total);

        $lock = 'topup_rev:' . $pi;
        $got  = $model->named_lock($lock, 10);
        if (!$got) { return self::LOCK_BUSY; }   // never skipped: the webhook answers 500 so Stripe redelivers
        try {
            $done = $model->reversed_for_payment_intent($user_id, $pi);
            $due  = min($credits, $share) - $done;
            if ($due <= 0) { return 0; }   // already settled by an earlier refund or dispute event

            $take    = min($due, max(0, (int) $model->get_balance($user_id)));
            $handled = $done + $due;
            if ($take < $due) {
                $handled = $credits;   // the shortfall is final: nothing more is ever taken for this payment
                error_log('[topups] reverse clamped: pi=' . $pi . ' user=' . $user_id . ' due=' . $due . ' taken=' . $take);
            }
            $key  = $pi . ':rev' . $handled;
            $desc = 'AI credits removed: ' . $why;
            if ($take <= 0) { $model->mark_reversal($user_id, $key, $desc); return 0; }
            $ok = $model->apply_delta($user_id, -$take, 'adjust', $desc, null, $key);
            if ($ok === false) { error_log('[topups] reverse failed: pi=' . $pi . ' user=' . $user_id . ' credits=' . $take); return 0; }
            return $take;
        } finally {
            $model->named_unlock($lock);
        }
    }
}
