<?php
/**
 * Plan / feature gating. What a user's plan includes is defined entirely in code
 * (PlanTiers) — never in Stripe metadata. A user's tier is resolved from their
 * Stripe plan by product NAME at subscribe time and cached on user_accounts.plan_tier,
 * so these checks are plain reads.
 *
 * Every feature is included on every tier; what differs is the six numbers in
 * PlanTiers::ROWS. Enforce counts with check_count(), storage with limit(), and
 * AI generation with the AI-credit charge (InfluencerJobService::create_job).
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

    /** The tier's display name ("Creator"), or '' without a plan. */
    public static function tier_name($user): string
    {
        $t = self::features($user);
        return (string) ($t['name'] ?? '');
    }

    /**
     * Every feature is on every tier, so "can X" is exactly "has an active plan".
     * Kept so older call sites keep working; new code should use can_use_creator_features().
     */
    public static function can($user, $flag): bool
    {
        return self::tier($user) !== '';
    }

    /**
     * A numeric limit for the user's tier (e.g. 'seats', 'storage_gb', 'fee_percent').
     * Returns null when the user has no plan; 0 means "unlimited".
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
     * any active plan qualifies.
     */
    public static function can_social_post($user): bool
    {
        return self::can_use_creator_features($user);
    }

    /* =====================================================================
     * Count limits (influencers, automations, seats)
     * =================================================================== */

    /**
     * Whether one more $key may be added given $current in use.
     * Returns ['ok', 'limit' (null = no plan, 0 = unlimited), 'used', 'message', 'need_plan'|'need_upgrade'].
     */
    public static function check_count($user, $key, $current): array
    {
        $limit = self::limit($user, $key);
        $row   = PlanTiers::row($key);
        $noun  = $row ? $row['noun'] : (string) $key;
        $used  = max(0, (int) $current);
        if ($limit === null) {
            return array('ok' => false, 'limit' => null, 'used' => $used, 'need_plan' => true,
                'message' => 'Choose a plan to add ' . $noun . '.');
        }
        $limit = (int) $limit;
        if ($limit === 0 || $used < $limit) {
            return array('ok' => true, 'limit' => $limit, 'used' => $used, 'message' => '');
        }
        return array('ok' => false, 'limit' => $limit, 'used' => $used, 'need_upgrade' => true,
            'message' => self::tier_name($user) . ' includes ' . $limit . ' ' . self::noun($noun, $limit) . '. You\'re using ' . $used . '. Upgrade to add more.');
    }

    private static function noun($noun, $n): string
    {
        if ((int) $n === 1) {
            if ($noun === 'team seats') { return 'team seat'; }
            if ($noun === 'AI influencers') { return 'AI influencer'; }
            if ($noun === 'automations') { return 'automation'; }
        }
        return $noun;
    }

    /* =====================================================================
     * Billing period + AI credits
     * =================================================================== */

    /**
     * [start, end] of the user's current billing period in UTC 'Y-m-d H:i:s', anchored
     * on subscription_current_period_end and rolled forward in whole months when that
     * column is stale (renewals don't reach us without a platform webhook). Monthly-only
     * plans make this exact. Calendar month when there is no plan.
     */
    public static function period_bounds($user): array
    {
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);
        $end = (string) (is_array($user) ? ($user['subscription_current_period_end'] ?? '') : '');
        if ($end === '' || !self::can_use_creator_features($user)) {
            return array($now->format('Y-m-01 00:00:00'), $now->modify('first day of next month')->format('Y-m-01 00:00:00'));
        }
        try { $e = new DateTimeImmutable($end, $utc); } catch (\Throwable $x) {
            return array($now->format('Y-m-01 00:00:00'), $now->modify('first day of next month')->format('Y-m-01 00:00:00'));
        }
        $anchor = (int) $e->format('j');
        $k = 0;
        while ($e <= $now && $k < 240) { $e = self::add_months($e, 1, $anchor); $k++; }
        return array(self::add_months($e, -1, $anchor)->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s'));
    }

    /** 'Y-m-d' of the current period's start: the key the monthly grant is stamped with. */
    public static function period_key($user): string
    {
        return substr(self::period_bounds($user)[0], 0, 10);
    }

    /** Move whole months keeping the anchor day-of-month (clamped to the target month's length, like Stripe). */
    private static function add_months(DateTimeImmutable $d, $months, $anchor): DateTimeImmutable
    {
        $first = $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1)->modify(($months >= 0 ? '+' : '') . (int) $months . ' months');
        $day   = min((int) $anchor, (int) $first->format('t'));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);
    }

    /** What a job type costs in AI credits, per the request ($f['params']['num_images'] for images). */
    public static function ai_price($type, array $f = array()): int
    {
        $unit = (int) (PlanTiers::AI_PRICES[(string) $type] ?? 0);
        if ($unit <= 0) { return 0; }
        $model = !empty($f['model_key']) ? InfluencerConfig::model((string) $f['model_key']) : null;   // e.g. the cinematic video model costs more
        if ($model && isset($model['credits']) && (int) $model['credits'] > 0) { $unit = (int) $model['credits']; }
        $n = ((string) $type === 'image') ? max(1, min(4, (int) (($f['params']['num_images'] ?? 1)))) : 1;
        return $unit * $n;
    }

    /**
     * Bring this period's plan credits up to the tier's amount: the full amount on a new
     * period, the difference when the amount rose mid-period (upgrade or a raised
     * allowance). Safe to call on every read; the model makes it idempotent. Returns true
     * when credits were added.
     */
    public static function grant_monthly($user): bool
    {
        if (!is_array($user) || !self::can_use_creator_features($user)) { return false; }
        $n = (int) self::limit($user, 'ai_credits');
        if ($n <= 0) { return false; }
        $key = self::period_key($user);
        if ((string) ($user['ai_credit_grant_period'] ?? '') === $key && (int) ($user['ai_credit_grant_amount'] ?? 0) >= $n) { return false; }
        $added = (new AiCreditsModel())->grant_for_period((int) $user['user_id'], $key, $n,
            self::tier_name($user) . ' plan: AI credits for ' . date('M j', strtotime($key)) . ' to ' . date('M j', strtotime(self::period_bounds($user)[1])));
        return $added > 0;
    }

    /** The message shown when a job cannot be paid for. */
    public static function credits_message($type, $price, $balance): string
    {
        $what = ($type === 'video') ? 'a video' : (($type === 'enhance') ? 'an enhancement' : (($price > 1) ? $price . ' images' : 'an image'));
        return 'You need ' . (int) $price . ' AI credit' . ((int) $price === 1 ? '' : 's') . ' for ' . $what . ' and have ' . (int) $balance
            . '. Buy credits or wait for your next monthly allowance.';
    }

    /* =====================================================================
     * Usage summary (billing page + MCP get_plan_usage)
     * =================================================================== */

    /**
     * The six comparison rows for the OWNER with what is in use. Each row:
     * ['key','label','kind','used','limit','unlimited','over','remaining'].
     * The ai_credits row reports the balance and the monthly grant.
     */
    public static function usage($user): array
    {
        $uid   = (int) ($user['user_id'] ?? 0);
        $tier  = self::tier($user);
        self::grant_monthly($user);
        list($start, $end) = self::period_bounds($user);
        $rows = array();
        foreach (PlanTiers::ROWS as $r) {
            $limit = self::limit($user, $r['key']);
            $used  = null;
            switch ($r['key']) {
                case 'seats':       $used = (int) (new TeamModel())->seats_used($uid); break;
                case 'influencers': $used = (int) (new InfluencersModel())->count_for_creator($uid); break;
                case 'ai_credits':  $used = (int) (new AiCreditsModel())->get_balance($uid); break;
                case 'automations': $used = (int) (new SchedulerRulesModel())->count_for_creator($uid); break;
                case 'storage_gb':  $used = round((new MediaAssetsModel())->total_bytes($uid) / 1073741824, 2); break;
                default:            $used = null;
            }
            $unlimited = ($limit !== null && (int) $limit === 0 && $r['kind'] !== 'percent');
            $over = false; $remaining = null;
            if ($used !== null && $limit !== null && !$unlimited && in_array($r['kind'], array('count', 'gb'), true)) {
                $remaining = max(0, $limit - $used);
                $over = $used > $limit;
            }
            $rows[] = array('key' => $r['key'], 'label' => $r['label'], 'kind' => $r['kind'], 'used' => $used,
                'limit' => $limit, 'limit_text' => $limit === null ? '' : PlanTiers::fmt_limit($r['key'], $limit),
                'unlimited' => $unlimited, 'over' => $over, 'remaining' => $remaining);
        }
        return array('tier' => $tier, 'tier_name' => self::tier_name($user), 'period_start' => $start, 'period_end' => $end,
            'period_key' => substr($start, 0, 10), 'ai_credit_balance' => (int) (new AiCreditsModel())->get_balance($uid),
            'ai_credit_spent' => (int) (new AiCreditsModel())->spent_since($uid, $start),
            'ai_prices' => PlanTiers::AI_PRICES, 'rows' => $rows);
    }
}
