<?php
/**
 * Auto-replenishment: when a debit leaves a wallet under the user's threshold, charge
 * their saved card off-session for the package they chose and add the credits.
 * Runs right after CreditsModel::apply_delta commits a debit. Never throws.
 * One attempt per 10 minutes per user; a failed attempt is reported once every 6 hours.
 */
class AutoReplenishService {

    public static function after_debit($user_id, $new_balance): void {
        try {
            $user_id = (int) $user_id;
            $credits = new CreditsModel();
            $cfg = $credits->get_autoreplenishment($user_id);
            if (empty($cfg['enabled']) || (int) $new_balance >= (int) $cfg['threshold'] || (string) ($cfg['pm_id'] ?? '') === '') { return; }
            $pkg = CreditsModel::package_for_dollars((int) round(((int) $cfg['amount_cents']) / 100));
            if (!$pkg) { return; }

            $rows = (new UsersModel())->get_user_by_id($user_id);
            $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            $customer = $user ? (string) ($user['stripe_customer_id'] ?? '') : '';
            if (!$user || $customer === '') { return; }

            if (!$credits->claim_autoreplenish_attempt($user_id, 10)) { return; }   // one attempt per 10 minutes, even with concurrent debits
            // Same price as Buy Credits: the package plus the processing fee.
            $base_cents  = (int) $pkg['dollars'] * 100;
            $fee_cents   = (int) round($base_cents * Main::credit_fee_percent() / 100);
            $total_cents = $base_cents + $fee_cents;
            $stripe = StripeService::client();
            try {
                $intent = $stripe->paymentIntents->create(array(
                    'amount'         => $total_cents,
                    'currency'       => 'usd',
                    'customer'       => $customer,
                    'payment_method' => (string) $cfg['pm_id'],
                    'off_session'    => true,
                    'confirm'        => true,
                    'description'    => 'Auto-replenishment: ' . (int) $pkg['credits'] . ' credits',
                    'metadata'       => array('type' => 'credit_purchase', 'user_id' => $user_id, 'credits' => (int) $pkg['credits'], 'auto' => 1,
                                              'base_cents' => $base_cents, 'fee_cents' => $fee_cents),
                ), array('idempotency_key' => 'autoreplenish-' . $user_id . '-' . (int) floor(time() / 600)));
            } catch (\Throwable $e) {
                error_log('[autoreplenish] user ' . $user_id . ': ' . $e->getMessage());
                self::report_failure($user_id, $pkg, $e->getMessage());
                return;
            }
            if ((string) $intent->status !== 'succeeded') { self::report_failure($user_id, $pkg, 'Payment did not complete (' . $intent->status . ')'); return; }
            TopUps::fulfill($intent);   // adds the credits and sends the notice once (the webhook is the backup)
        } catch (\Throwable $e) {
            error_log('[autoreplenish] ' . $e->getMessage());
        }
    }

    private static function report_failure($user_id, array $pkg, $why): void {
        $recent = (new UserNotificationsModel())->recent_with_title((int) $user_id, 'Auto-replenishment failed', 6);
        if ($recent) { return; }
        Notify::send($user_id, 'auto_replenishment', 'Auto-replenishment failed',
            'We could not charge your saved card for ' . Notify::credits((int) $pkg['credits']) . ' (' . Price::fmt((int) $pkg['credits']) . '). Update your payment method to keep auto top-ups working.',
            '/account/settings?section=wallet', 'fa-triangle-exclamation');
    }
}
