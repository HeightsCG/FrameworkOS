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
     * Using ANY creator feature (Studio, publishing, selling, AI, scheduling, analytics, payouts) requires a
     * creator account on a paid plan (Creator or Studio). Free is the account everyone signs up with: it can
     * follow, subscribe, unlock, buy and message, but never gets creator tools (Daniel, 2026-09-28).
     */
    public static function can_use_creator_features($user): bool
    {
        if (!is_array($user)) {
            return false;
        }
        // Everyone signs up on Free; creator tools (Studio, selling, AI, payouts) need a paid plan, Creator or Studio.
        return self::is_creator_row($user) && self::has_paid_plan($user);
    }

    /**
     * A paid plan (not Free), from the BillingService mirror on user_accounts. Past due counts
     * during the grace period (BillingService moves the account to Free when it ends). A plan set
     * to cancel at period end is Free once that period is over, even if the job hasn't run yet.
     */
    public static function has_paid_plan($user): bool
    {
        if (!is_array($user)) { return false; }
        $status = (string) ($user['subscription_status'] ?? '');
        if (!in_array($status, array('active', 'trialing', 'past_due'), true)) { return false; }
        $end = (string) ($user['subscription_current_period_end'] ?? '');
        if (!empty($user['subscription_cancel_at_period_end']) && $end !== '' && strtotime($end . ' UTC') <= time()) { return false; }
        return true;
    }

    /** Is this user row a Creator account? (Free is a creator plan, so this decides who gets it.) */
    public static function is_creator_row($user): bool
    {
        if (!is_array($user)) { return false; }
        $name = strtolower(trim((string) ($user['role_name'] ?? '')));
        if ($name !== '') { return $name === 'creator'; }
        $rid = (int) ($user['role_id'] ?? 0);
        if ($rid <= 0) { return false; }
        static $creator_role = null;
        if ($creator_role === null) {
            try { $creator_role = (int) (new UsersModel())->get_role_id_by_name('Creator'); }
            catch (\Throwable $e) { $creator_role = 0; }
        }
        return $creator_role > 0 && $rid === $creator_role;
    }

    /**
     * The user's tier key ('free'|'creator'|'pro'|'studio'), or '' when the account is not a
     * creator at all. A creator without a Stripe subscription is on 'free'. Reads the cached user_accounts.plan_tier; falls back to a
     * one-time Stripe lookup for subscriptions saved before the column existed.
     */
    public static function tier($user): string
    {
        if (!self::can_use_creator_features($user)) {
            return '';
        }
        if (!self::has_paid_plan($user)) {
            return PlanTiers::FREE_KEY;   // creator, no subscription: the free plan
        }
        $tier = (string) ($user['plan_tier'] ?? '');
        return ($tier !== '' && PlanTiers::get($tier)) ? $tier : PlanTiers::FREE_KEY;
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
     * A numeric limit for the user's tier (e.g. 'seats', 'storage_gb', 'fee_percent'), plus any
     * add-on slots that raise it (extra AI influencers on Creator).
     * Returns null when the user has no plan; 0 means "unlimited"; below 0 means "not included".
     */
    public static function limit($user, $key)
    {
        $t = PlanTiers::get(self::tier($user));
        if (!$t) { return null; }
        $v = $t['limits'][(string) $key] ?? null;
        if ($v !== null && (int) $v > 0) {
            foreach (PlanTiers::addons_for($t['key']) as $a) {
                if ((string) ($a['limit'] ?? '') === (string) $key) { $v = (int) $v + self::addon_quantity($user, $a['key']); }
            }
        }
        return $v;
    }

    /**
     * How many of an add-on the account holds right now: 0 unless the account is on a paid plan
     * that offers it. A removal scheduled for period end is applied here once that date passes.
     */
    private static $addon_cache = array();

    /** Drop cached add-on quantities after they change in this request. */
    public static function forget_addons(): void
    {
        self::$addon_cache = array();
    }

    public static function addon_quantity($user, $addon_key): int
    {
        $cache = &self::$addon_cache;
        $uid = (int) (is_array($user) ? ($user['user_id'] ?? 0) : 0);
        $a   = PlanTiers::addon($addon_key);
        if ($uid <= 0 || !$a || !self::has_paid_plan($user) || !in_array(self::tier($user), (array) ($a['plans'] ?? array()), true)) { return 0; }
        $ck = $uid . ':' . $addon_key;
        if (isset($cache[$ck])) { return $cache[$ck]; }
        // Slots the account holds right now; a removal waits in influencer_slots_next until the billing date.
        $row = (string) $addon_key === 'influencer_slot' ? (new BillingAccountsModel())->get($uid) : null;
        $q = $row ? (int) $row['influencer_slots'] : 0;
        return $cache[$ck] = max(0, min($q, (int) ($a['max'] ?? 0)));
    }

    /**
     * Ids of what is over the plan's limit for $key ('influencers' | 'automations' | 'seats'):
     * kept, never deleted, but not usable until the account is back under its limit. The oldest
     * stay unlocked. Seats count the owner, so members beyond (limit - 1) are locked.
     */
    public static function locked_ids($user, $key): array
    {
        static $cache = array();
        $uid = (int) (is_array($user) ? ($user['user_id'] ?? 0) : 0);
        if ($uid <= 0) { return array(); }
        $limit = self::limit($user, $key);
        if ($limit === null || (int) $limit === 0) { return array(); }
        $ck = $uid . ':' . $key . ':' . (int) $limit;
        if (isset($cache[$ck])) { return $cache[$ck]; }
        switch ((string) $key) {
            case 'influencers': $ids = (new InfluencersModel())->ids_oldest_first($uid); break;
            case 'automations': $ids = (new SchedulerRulesModel())->ids_oldest_first($uid); break;
            case 'seats':       $ids = (new TeamModel())->member_ids_oldest_first($uid); break;
            default:            $ids = array();
        }
        $keep = ((int) $limit < 0) ? 0 : (((string) $key === 'seats') ? (int) $limit - 1 : (int) $limit);
        return $cache[$ck] = array_map('intval', array_slice($ids, max(0, $keep)));
    }

    /** Is this influencer / automation / team member locked by the plan's limit? */
    public static function is_locked($user, $key, $id): bool
    {
        return in_array((int) $id, self::locked_ids($user, $key), true);
    }

    /** Is this collaborator's seat over the owner's plan limit? (Their account is kept; they can't sign in.) */
    public static function team_member_locked($member): bool
    {
        if (!is_array($member) || empty($member['team_role']) || (int) ($member['created_by'] ?? 0) <= 0) { return false; }
        $rows = (new UsersModel())->get_user_by_id((int) $member['created_by']);
        if (!is_array($rows) || count($rows) !== 1) { return false; }
        return self::is_locked($rows[0], 'seats', (int) $member['user_id']);
    }

    const SEAT_LOCKED_MESSAGE = 'Your seat on this team is paused because the account owner\'s plan has fewer team seats. Ask them to upgrade to get back in.';

    /** The message shown when something locked is used. */
    public static function locked_message($user, $key): string
    {
        $row  = PlanTiers::row($key);
        $noun = $row ? $row['noun'] : (string) $key;
        return 'This is locked because your ' . self::tier_name($user) . ' plan includes fewer ' . $noun . ' than you have. It\'s saved, not deleted. Upgrade to use it again.';
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
        if ($limit < 0) {
            return array('ok' => false, 'limit' => $limit, 'used' => $used, 'need_upgrade' => true,
                'message' => ucfirst($noun) . ' aren\'t included on ' . self::tier_name($user) . '. Upgrade to ' . (($up = PlanTiers::lowest_including($key)) ? $up['name'] . ' ' : '') . 'to add them.',
                'upgrade_to' => ($up = PlanTiers::lowest_including($key)) ? $up['key'] : '');
        }
        if ($limit === 0 || $used < $limit) {
            return array('ok' => true, 'limit' => $limit, 'used' => $used, 'message' => '');
        }
        $out = array('ok' => false, 'limit' => $limit, 'used' => $used, 'need_upgrade' => true,
            'message' => self::tier_name($user) . ' includes ' . $limit . ' ' . self::noun($noun, $limit) . '. You\'re using ' . $used . '. Upgrade to add more.');
        // An add-on can raise this limit on the current plan: say so, and let the UI offer it.
        foreach (PlanTiers::addons_for(self::tier($user)) as $a) {
            if ((string) ($a['limit'] ?? '') !== (string) $key || !self::has_paid_plan($user)) { continue; }
            if (self::addon_quantity($user, $a['key']) < (int) ($a['max'] ?? 0)) {
                $out['addon'] = $a['key'];
                $out['addon_price'] = (int) $a['price'];
                $held = self::addon_quantity($user, $a['key']);
                $out['message'] = 'Your plan covers ' . $limit . ' ' . self::noun($noun, $limit) . ($held > 0 ? ' (' . self::tier_name($user) . ' plus ' . $held . ' extra)' : '')
                    . '. Add a slot for $' . (int) $a['price'] . '/month, or upgrade for more.';
            }
        }
        return $out;
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
        // A model can set its own price (the cinematic video model costs more; a video's price follows its length).
        $model = !empty($f['model_key']) ? InfluencerConfig::model((string) $f['model_key']) : null;
        $own   = $model ? InfluencerConfig::credits_for($model, (string) ($f['duration'] ?? ($f['params']['duration'] ?? ''))) : null;
        if ($own !== null && $own > 0) { $unit = $own; }
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
        $tier = self::features($user);
        if ($tier && !PlanTiers::grants_once($tier)) { return false; }   // paid plans: BillingService grants on each successful charge
        if ($tier && PlanTiers::grants_once($tier)) {
            // One-time starter credits (Free): only for an account that has never had a plan grant.
            if ((string) ($user['ai_credit_grant_period'] ?? '') !== '') { return false; }
            return (new AiCreditsModel())->grant_for_period((int) $user['user_id'], 'once', $n, self::tier_name($user) . ' plan: ' . $n . ' starter AI credits') > 0;
        }
        $key = self::period_key($user);
        if ((string) ($user['ai_credit_grant_period'] ?? '') === $key && (int) ($user['ai_credit_grant_amount'] ?? 0) >= $n) { return false; }
        $added = (new AiCreditsModel())->grant_for_period((int) $user['user_id'], $key, $n,
            self::tier_name($user) . ' plan: AI credits for ' . date('M j', strtotime($key)) . ' to ' . date('M j', strtotime(self::period_bounds($user)[1])));
        return $added > 0;
    }

    /** Is a per-plan switch (PlanTiers::FEATURES, e.g. 'inbox_ai') on for the user's plan? */
    public static function has_feature($user, $feature): bool
    {
        $t = self::features($user);
        return !empty($t) && PlanTiers::has_feature($t, $feature);
    }

    /** "Inbox automation & AI replies start on the Creator plan. Upgrade to turn them on." */
    public static function feature_message($feature): string
    {
        $label = html_entity_decode((string) (PlanTiers::FEATURES[(string) $feature] ?? 'This'), ENT_QUOTES, 'UTF-8');
        $t = PlanTiers::lowest_with_feature($feature);
        return $label . ($t ? ' start on the ' . $t['name'] . ' plan. Upgrade to turn them on.' : ' are not on your plan.');
    }

    /**
     * What $gross_cents of sales would have cost on each plan (monthly price + add-ons + take
     * rate), compared with the user's current plan. Returns the offered higher plan that would
     * have saved the most, as ['tier' => def, 'savings_cents' => int, 'current_cents', 'other_cents'],
     * or null when no higher plan is cheaper.
     */
    public static function upgrade_savings($user, $gross_cents)
    {
        $cur = self::features($user);
        if (!$cur) { return null; }
        $gross = max(0, (int) $gross_cents);
        $cost  = function (array $t, $addon_cents) use ($gross) {
            return (int) $t['price'] * 100 + (int) $addon_cents + (int) round($gross * (float) $t['limits']['fee_percent'] / 100);
        };
        $addons = 0;
        foreach (PlanTiers::addons_for($cur['key']) as $a) { $addons += self::addon_quantity($user, $a['key']) * (int) $a['price'] * 100; }
        $now  = $cost($cur, self::has_paid_plan($user) ? $addons : 0);
        $best = null;
        foreach (PlanTiers::offered() as $t) {
            if ((int) $t['rank'] <= (int) $cur['rank']) { continue; }
            $other = $cost($t, 0);
            $save  = $now - $other;
            if ($save > 0 && ($best === null || $save > $best['savings_cents'])) {
                $best = array('tier' => $t, 'savings_cents' => $save, 'current_cents' => $now, 'other_cents' => $other);
            }
        }
        return $best;
    }

    /**
     * Take the AI credits a run costs (PlanTiers::AI_PRICES) before it starts. Returns
     * ['ok' => true, 'price'] or ['ok' => false, 'price', 'balance', 'message'] when the account
     * cannot pay (the caller shows the Buy credits prompt). Free's starter credits land first.
     */
    public static function charge_ai($user, $type, $description, array $f = array()): array
    {
        $uid = (int) ($user['user_id'] ?? 0);
        self::grant_monthly($user);
        $price = self::ai_price($type, $f);
        $m = new AiCreditsModel();
        if ($price > 0 && $m->apply_delta($uid, -$price, 'spend', (string) $description) === false) {
            $bal = (int) $m->get_balance($uid);
            return array('ok' => false, 'price' => $price, 'balance' => $bal, 'message' => self::credits_message($type, $price, $bal));
        }
        return array('ok' => true, 'price' => $price);
    }

    /**
     * What an automation costs in AI credits: per run (a post makes one AI image; a scheduled
     * message only writes text, which is free) and per month at its cadence (daily = 30 runs,
     * weekly = chosen days x 52/12).
     */
    public static function automation_credits(array $rule): array
    {
        $per = ((string) ($rule['kind'] ?? 'post') === 'message') ? 0 : self::ai_price('image', array('params' => array('num_images' => 1)));
        if ($per > 0 && (string) ($rule['media_type'] ?? 'image') === 'video') {   // the still, then animating it
            $per += self::ai_price('video', array('model_key' => (string) ($rule['video_model_key'] ?? ''), 'duration' => (string) ($rule['video_duration'] ?? '')));
        }
        if ((string) ($rule['cadence'] ?? 'daily') === 'weekly') {
            $days = array_filter(array_map('intval', explode(',', (string) ($rule['days_of_week'] ?? ''))), function ($d) { return $d >= 0 && $d <= 6; });
            $runs = (int) round(count($days) * 52 / 12);
        } else {
            $runs = 30;
        }
        return array('per_run' => $per, 'per_month' => $per * $runs);
    }

    /** The message shown when a job cannot be paid for. */
    public static function credits_message($type, $price, $balance): string
    {
        $unit = max(1, (int) (PlanTiers::AI_PRICES[(string) $type] ?? 1));
        $n    = max(1, intdiv((int) $price, $unit));
        $what = ($type === 'video') ? 'a video' : (($type === 'enhance') ? 'an enhancement' : ($n > 1 ? $n . ' images' : 'an image'));
        return 'You need ' . number_format((int) $price) . ' AI credit' . ((int) $price === 1 ? '' : 's') . ' for ' . $what . ' and have ' . number_format((int) $balance) . '. Buy more AI credits to keep going.';
    }

    /**
     * What AI credits buy, for Billing and the pricing page: [['label' => 'Image', 'credits' => 50], ...]. Built from
     * ai_price, so it always matches what is charged. Captions, prompts and training are included (free).
     */
    public static function ai_price_list(): array
    {
        $rows = array(
            array('label' => 'Image', 'credits' => self::ai_price('image')),
            array('label' => 'Enhance an image', 'credits' => self::ai_price('enhance')),
        );
        foreach (InfluencerConfig::picker_options('video') as $m) {
            foreach ((array) $m['durations'] as $d) {
                $rows[] = array('label' => 'Video, ' . strtolower((string) $m['label']) . ', ' . $d . ' seconds',
                    'credits' => self::ai_price('video', array('model_key' => $m['key'], 'duration' => (string) $d)));
            }
        }
        return $rows;
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
                $cap = ((int) $limit < 0) ? ($r['key'] === 'seats' ? 1 : 0) : $limit;   // "not included": seats still hold the owner
                $remaining = max(0, $cap - $used);
                $over = $used > $cap;
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
