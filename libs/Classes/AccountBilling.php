<?php
/**
 * What happens to the creator's own plan billing when an account is deleted, stops being a creator, or is suspended.
 *
 * Delete / leave: the creator plan ends now; nothing bills again.
 * Suspend: plan billing is skipped by the billing run. Reactivate: a plan whose period ended while suspended
 * starts a new period that day.
 */
class AccountBilling {

    public static function on_delete($user_id): void
    {
        BillingService::end_plan_now((int) $user_id);
    }

    /** The creator stops being a creator but keeps their account: their own plan ends. */
    public static function on_leave_creator($user_id): void
    {
        BillingService::end_plan_now((int) $user_id);
    }

    /** Nothing to pause: the billing run skips suspended accounts. Kept so callers have one hook. */
    public static function on_suspend($user_id): void
    {
    }

    public static function on_reactivate($user_id): void
    {
        BillingService::restart_period((int) $user_id);   // plan billing restarts from today; missed months are never charged
    }
}
