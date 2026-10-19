<?php
/**
 * TikTok signup conversions (2026-10-19). The ad campaign optimises on CompleteRegistration, so it is sent for creators only:
 * an account that meets UsersModel::creator_sql (the Creator role), the same rule as the admin Funnel's Creators column.
 *
 *   creator  -> CompleteRegistration, once: the browser pixel (window.CLSTikTokRegistration, ttq.identify first) and the
 *               Events API copy share the account's tt_reg_event_id, so TikTok counts it once.
 *   other    -> FanSignup, once, Events API only (a custom event: fan volume shows in TikTok, never used to optimise).
 *
 * settle() runs when a new account is created and again the first page after Become a Creator, so an account that turns
 * creator later fires CompleteRegistration at that moment. The atomic claims in UsersModel make each event once-only:
 * a refresh, a second tab or a later sign-in gets nothing. Accounts from before 2026-10-16 have no event id and never fire;
 * accounts from 2026-10-16 to 2026-10-19 were closed by sql/2026-10-19_tiktok_creator_registration.sql.
 */
class TikTokRegistration {

    /**
     * Settle the account's signup conversion now: returns the pixel payload when this request fires the creator's
     * CompleteRegistration (hand it to window.CLSTikTokRegistration), else null (a FanSignup may have been sent instead).
     */
    public static function settle(int $user_id): ?array {
        if ($user_id <= 0) { return null; }
        try {
            $users = new UsersModel();
            $tt = $users->claim_tt_registration($user_id);
            if ($tt) {
                TikTokEventJob::from_event('creator_registration', $user_id, array('event_id' => $tt['event_id']));
                return $tt;
            }
            if ($users->claim_tt_fan_signup($user_id)) { TikTokEventJob::from_event('fan_signup', $user_id, array()); }
        } catch (\Throwable $e) {
            error_log('[tiktok_registration] user_id=' . $user_id . ': ' . $e->getMessage());
        }
        return null;
    }

    /** The next page this browser renders settles the account (libs/Layout/google_analytics.php): a Google signup, Become a Creator. */
    public static function settle_on_next_page(int $user_id): void {
        if ($user_id > 0) { Session::set('tt_reg_pending', $user_id); }
    }
}
