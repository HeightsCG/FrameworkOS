<?php
/**
 * Adding paid credits for a succeeded PaymentIntent: wallet top-ups ('credit_purchase', including automatic ones)
 * and AI credit packs ('ai_credit_purchase'). The page confirm, auto top-up and the Stripe webhook all come here,
 * so credits land even when the buyer closes the tab after paying. Idempotent per PaymentIntent (the ledgers have a
 * unique index on it): only the first caller adds the credits and sends the notice.
 * Returns ['ok', 'new', 'credits', 'balance'].
 */
class TopUps {

    const TYPES = array('credit_purchase', 'ai_credit_purchase');

    public static function fulfill($intent): array
    {
        $type    = (string) ($intent->metadata['type'] ?? '');
        $user_id = (int) ($intent->metadata['user_id'] ?? 0);
        $credits = (int) ($intent->metadata['credits'] ?? 0);
        $pi      = (string) $intent->id;
        if (!in_array($type, self::TYPES, true) || $user_id <= 0 || $credits <= 0 || (string) $intent->status !== 'succeeded') {
            return array('ok' => false, 'new' => false, 'credits' => 0, 'balance' => 0);
        }
        $ai    = ($type === 'ai_credit_purchase');
        $model = $ai ? new AiCreditsModel() : new CreditsModel();
        $auto  = !$ai && !empty($intent->metadata['auto']);
        $desc  = $ai ? 'Bought ' . $credits . ' AI credits' : ($auto ? 'Auto-replenishment: ' . $credits . ' credits' : 'Added ' . Price::fmt($credits) . ' to your wallet');

        $new = !$model->has_payment_intent($pi) && $model->credit_purchase($user_id, $credits, $pi, $desc) !== false
            && $model->has_payment_intent($pi);
        $balance = (int) $model->get_balance($user_id);
        if (!$new) {   // already added (by the page, the webhook or auto top-up), or lost the race to one of them
            return array('ok' => $model->has_payment_intent($pi), 'new' => false, 'credits' => $credits, 'balance' => $balance);
        }

        if ($ai) {
            Notify::send($user_id, 'credits', 'AI credits added', number_format($credits) . ' AI credits added. Balance: ' . number_format($balance) . ' AI credits.', '/account/billing?tab=credits', 'fa-wand-magic-sparkles');
        } elseif ($auto) {
            Notify::send($user_id, 'auto_replenishment', 'Wallet topped up automatically',
                Notify::credits($credits) . ' added to your wallet. Balance: ' . Notify::credits($balance) . '.', '/account/settings?section=wallet', 'fa-rotate');
        } else {
            Notify::send($user_id, 'credits', 'Funds added', Notify::credits($credits) . ' added to your wallet. Balance: ' . Notify::credits($balance) . '.', '/account/settings?section=wallet', 'fa-coins');
        }
        return array('ok' => true, 'new' => true, 'credits' => $credits, 'balance' => $balance);
    }
}
