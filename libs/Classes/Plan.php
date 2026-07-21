<?php
/**
 * Plan / feature gating. Whether a user's current plan includes a feature is
 * driven by Stripe product metadata (e.g. social_posting = "true"), so plans
 * stay dynamic — no hardcoded price/tier mapping.
 */
class Plan {

    /** Social posting (connect accounts + publish) requires an active plan whose product allows it. */
    public static function can_social_post($user): bool
    {
        if (!is_array($user)) {
            return false;
        }
        if (($user['subscription_status'] ?? '') !== 'active') {
            return false;
        }
        return StripeService::plan_allows_social_posting($user['stripe_price_id'] ?? '');
    }

    /**
     * Using ANY creator feature (Studio, publishing, scheduling, analytics,
     * profile/branding) requires an active platform plan. Any current tier
     * unlocks the creator surface; per-tier limits (seats, storage, fee rate,
     * etc.) layer on top of this in a later phase. 'trialing' counts as active
     * so a plan's free-trial window still grants access.
     */
    public static function can_use_creator_features($user): bool
    {
        if (!is_array($user)) {
            return false;
        }
        $status = (string) ($user['subscription_status'] ?? '');
        return $status === 'active' || $status === 'trialing';
    }
}
