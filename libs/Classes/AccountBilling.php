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
