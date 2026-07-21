<?php
/**
 * Plan / feature gating. What a user's plan includes is defined entirely in code
 * (PlanTiers) — never in Stripe metadata. A user's tier is resolved from their
 * Stripe plan by product NAME at subscribe time and cached on user_accounts.plan_tier,
 * so these checks are plain reads. Enforce features with Plan::can()/limit().
 */
class Plan {

    /**
     * Using ANY creator feature (Studio, publishing, scheduling, analytics,
     * profile/branding) requires an active platform plan. 'trialing' counts as
     * active so a plan's free-trial window still grants access.
     */
    public static function can_use_creator_features($user): bool
    {
        if (!is_array($user)) {
            return false;
        }
        $status = (string) ($user['subscription_status'] ?? '');
        return $status === 'active' || $status === 'trialing';
    }

    /**
     * The user's tier key ('creator'|'pro'|'studio'), or '' when they have no
     * active plan. Reads the cached user_accounts.plan_tier; falls back to a
     * one-time Stripe lookup for subscriptions saved before the column existed.
     */
    public static function tier($user): string
    {
        if (!self::can_use_creator_features($user)) {
            return '';
        }
        $tier = (string) ($user['plan_tier'] ?? '');
        if ($tier === '' && !empty($user['stripe_price_id'])) {
            $tier = StripeService::plan_tier_slug((string) $user['stripe_price_id']);
        }
        return $tier;
    }

    /** The full tier definition (PlanTiers) for the user's plan, or array(). */
    public static function features($user): array
    {
        $t = PlanTiers::get(self::tier($user));
        return is_array($t) ? $t : array();
    }

    /** Whether the user's tier includes a boolean feature flag (e.g. 'bundles'). */
    public static function can($user, $flag): bool
    {
        $t = PlanTiers::get(self::tier($user));
        return $t ? !empty($t['flags'][(string) $flag]) : false;
    }

    /**
     * A numeric limit for the user's tier (e.g. 'seats', 'socials', 'storage_gb',
     * 'fee_percent'). Returns null when the user has no plan; 0 means "unlimited".
     */
    public static function limit($user, $key)
    {
        $t = PlanTiers::get(self::tier($user));
        if (!$t) { return null; }
        return $t['limits'][(string) $key] ?? null;
    }

    /**
     * The platform's take-rate % for a creator's earnings (paid subscriptions +
     * PPV), from their plan tier. Falls back to the global default when the creator
     * has no active plan. This is what replaces the flat Main::platform_fee_percent().
     */
    public static function fee_percent($creator): float
    {
        $fee = self::limit($creator, 'fee_percent');
        return ($fee === null) ? (float) Main::platform_fee_percent() : (float) $fee;
    }

    /**
     * Social posting (connect accounts + publish) is included in every tier, so
     * any active plan qualifies. Per-tier connected-account CAPS are enforced
     * separately via limit($user, 'socials').
     */
    public static function can_social_post($user): bool
    {
        return self::can_use_creator_features($user);
    }
}
