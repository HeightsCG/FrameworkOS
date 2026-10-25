<?php
/**
 * Creator referral reward. A signup from a creator's page (?ref=<handle>) stores user_accounts.referred_by_creator_id.
 * When that account's FIRST paid plan charge succeeds (BillingService::apply), the referring creator gets CREDITS
 * AI credits, once per charge. A refund or dispute on that charge takes them back (WebhookController), as far as
 * the balance covers. AI credits are never cashed out. Nothing here throws into the billing or webhook path.
 */
class ReferralRewards {

    const CREDITS = 200;   // $20 of AI credits per paying referral (Daniel, 2026-10-24)
    const KINDS = array('subscribe', 'upgrade', 'renewal');

    public static function key($charge_id): string {
        return 'ref:' . (int) $charge_id;
    }

    /** A succeeded billing_charges row (already marked applied). */
    public static function on_charge(array $row): void {
        try {
            $cid = (int) ($row['id'] ?? 0);
            $uid = (int) ($row['user_id'] ?? 0);
            if ($cid <= 0 || $uid <= 0 || (int) ($row['amount_cents'] ?? 0) <= 0 || !in_array((string) ($row['kind'] ?? ''), self::KINDS, true)) { return; }
            if (!(new BillingChargesModel())->first_paid_plan_charge($uid, $cid)) { return; }

            $users = new UsersModel();
            $me = $users->get_user_by_id($uid);
            $ref = (is_array($me) && count($me) === 1) ? (int) ($me[0]['referred_by_creator_id'] ?? 0) : 0;
            if ($ref <= 0 || $ref === $uid) { return; }
            $them = $users->get_user_by_id($ref);
            if (!is_array($them) || count($them) !== 1 || (int) ($them[0]['deleted'] ?? 0) === 1) { return; }

            $handle = (string) ($me[0]['u_name'] ?? '');
            $ok = (new AiCreditsModel())->grant_once($ref, self::CREDITS, 'referral_reward', self::key($cid), 'Referral reward: @' . $handle . ' started a paid plan');
            if ($ok) {
                Notify::send($ref, 'system', 'You earned ' . self::CREDITS . ' AI credits',
                    '@' . $handle . ' signed up from your page and started a paid plan.', '/account/billing', 'fa-gift');
            }
        } catch (\Throwable $e) {
            error_log('[referral] charge ' . (int) ($row['id'] ?? 0) . ': ' . $e->getMessage());
        }
    }

    /** Refund or dispute on a charge: take the reward back once. */
    public static function on_reverse($payment_intent_id): void {
        try {
            if ((string) $payment_intent_id === '') { return; }
            $charge = (new BillingChargesModel())->by_payment_intent($payment_intent_id);
            if (!$charge) { return; }
            $credits = new AiCreditsModel();
            $reward = $credits->row_by_key(self::key($charge['id']));
            if (!$reward) { return; }
            $credits->take_back_once((int) $reward['user_id'], (int) $reward['credits'], 'referral_reversal',
                self::key($charge['id']) . ':rev', 'Referral reward reversed: the payment was refunded or disputed');
        } catch (\Throwable $e) {
            error_log('[referral] reverse ' . (string) $payment_intent_id . ': ' . $e->getMessage());
        }
    }
}
