<?php
/**
 * What happens to recurring charges when an account is deleted or suspended, both sides of fan memberships
 * (the account as a fan, and as a creator whose fans pay it) plus the creator's own plan billing.
 *
 * Delete: memberships are canceled in Stripe now and the creator plan ends; nothing bills again.
 * Suspend: memberships are paused in Stripe (no invoices while paused) and plan billing is skipped by the
 * billing run, so a reactivated account picks up where it left off. Reactivate resumes the memberships.
 */
class AccountBilling {

    public static function on_delete($user_id): void
    {
        $uid  = (int) $user_id;
        $subs = new CreatorSubscriptionsModel();
        foreach ($subs->active_paid_involving($uid) as $row) {
            $acct = self::connect_account((int) $row['creator_id']);
            if ((string) $row['stripe_subscription_id'] !== '' && $acct !== '') {
                StripeService::cancel_subscription_now($acct, (string) $row['stripe_subscription_id']);
            }
            $subs->close((int) $row['id']);
            if ((int) $row['creator_id'] === $uid) {   // the creator left: tell each fan their membership ended
                Notify::send((int) $row['subscriber_id'], 'subscriptions', 'Membership ended', 'This creator closed their account, so your membership has ended and you will not be charged again.', '/account/settings?section=subscriptions', 'fa-heart-crack');
            }
        }
        BillingService::end_plan_now($uid);
    }

    /**
     * Put the creator's current platform fee on every paid membership their fans hold. Stripe keeps the fee a
     * subscription was created with, so without this a plan change (Free 20%, Creator 10%, Studio 3%) would never
     * reach existing members. Re-reads the fee at the end in case the plan changed again while this ran.
     */
    public static function sync_membership_fees($creator_id): string
    {
        $uid  = (int) $creator_id;
        $acct = self::connect_account($uid);
        if ($acct === '') { return 'no payout account'; }
        $subs = new CreatorSubscriptionsModel();
        for ($pass = 0; $pass < 3; $pass++) {
            $fee = Plan::fee_percent(BillingService::user($uid));
            $ok = 0; $bad = 0;
            foreach ($subs->active_paid_for_creator($uid) as $row) {
                if ((string) $row['stripe_subscription_id'] === '') { continue; }
                StripeService::set_subscription_fee($acct, (string) $row['stripe_subscription_id'], $fee) ? $ok++ : $bad++;
            }
            if (Plan::fee_percent(BillingService::user($uid)) === $fee) { return $fee . '% on ' . $ok . ' memberships' . ($bad ? ', ' . $bad . ' failed' : ''); }
        }
        return 'fee kept changing; will sync on the next plan change';
    }

    public static function on_suspend($user_id): void   { self::pause_all((int) $user_id, true); }
    public static function on_reactivate($user_id): void { self::pause_all((int) $user_id, false); }

    private static function pause_all($uid, $pause): void
    {
        foreach ((new CreatorSubscriptionsModel())->active_paid_involving($uid) as $row) {
            $acct = self::connect_account((int) $row['creator_id']);
            if ((string) $row['stripe_subscription_id'] === '' || $acct === '') { continue; }
            StripeService::pause_subscription($acct, (string) $row['stripe_subscription_id'], $pause);
        }
    }

    private static function connect_account($creator_id): string
    {
        $u = (new UsersModel())->get_user_by_id((int) $creator_id);
        return (is_array($u) && count($u) === 1) ? (string) ($u[0]['stripe_connect_account_id'] ?? '') : '';
    }
}
