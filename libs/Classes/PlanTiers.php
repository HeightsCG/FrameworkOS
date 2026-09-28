<?php
/**
 * Canonical definition of the platform plan tiers — the SINGLE source of truth
 * for what each plan includes (docs/pricing-model.md). Everything lives here in
 * code; NOTHING is read from Stripe metadata. A Stripe plan is matched to a tier
 * purely by its product NAME (see match()), so Stripe only owns pricing.
 *
 * Six things differ between tiers (ROWS); everything else the platform does is
 * included on every plan (INCLUDED). Enforcement reads these via Plan::limit(),
 * Plan::check_count() and the AI-credit charge in InfluencerJobService.
 *
 *   limits.*: 0 means "unlimited"; below 0 means "not included" (shown as 0).
 *   limits.fee_percent: the platform's take rate for this tier.
 *   limits.ai_credits: AI credits added to the account every billing date.
 */
class PlanTiers {

    /** The comparison rows, in billing-page order. kind drives formatting + usage source. */
    const ROWS = array(
        array('key' => 'fee_percent', 'label' => 'Platform take rate',    'noun' => '',                 'kind' => 'percent'),
        array('key' => 'seats',       'label' => 'Team seats',            'noun' => 'team seats',       'kind' => 'count'),
        array('key' => 'influencers', 'label' => 'AI influencers',        'noun' => 'AI influencers',   'kind' => 'count'),
        array('key' => 'ai_credits',  'label' => 'AI credits',            'noun' => 'AI credits',       'kind' => 'credits'),
        array('key' => 'automations', 'label' => 'Scheduled automations', 'noun' => 'automations',      'kind' => 'count'),
        array('key' => 'storage_gb',  'label' => 'Storage',               'noun' => 'GB of storage',    'kind' => 'gb'),
    );

    /** Shown under the plan grid: on every plan, no limits. Per-plan switches are FEATURES. */
    const INCLUDED = array(
        'Unlimited social connections', 'Unlimited membership tiers', 'AI captions',
        'Bundles, promo codes &amp; free trials', 'Analytics &amp; exports', 'Claude connector',
    );

    /** Switches that differ by plan (TIERS 'features'), with the label shown on plan cards. */
    const FEATURES = array(
        'inbox_ai'      => 'Inbox automation &amp; AI replies',
        'custom_domain' => 'Custom domains',
    );

    /** What each AI job type costs in AI credits (per output). Training is free: the influencer count gates it. */
    const AI_PRICES = array('image' => 50, 'enhance' => 10, 'video' => 200);

    /** AI credits per dollar. Same rate as the fan wallet, so credits never read as dollars. */
    const AI_CREDITS_PER_DOLLAR = 10;

    /** AI credit packs by price in dollars: bought once, or added as a recurring monthly pack. */
    const AI_PACKS = array(10, 25, 50, 100);

    /** Credits in a pack ($10 -> 100). */
    public static function pack_credits($dollars): int
    {
        return (int) $dollars * self::AI_CREDITS_PER_DOLLAR;
    }

    /**
     * App-managed billing (BillingService). Stripe only stores the card and runs each charge.
     *   interval_months   length of a billing period
     *   retry_after_days  after a failed renewal, retry this many days after the first failure
     *   grace_days        paid features stay on while past due; after this (or the last retry) the account moves to Free
     *   pack_carry_over   recurring pack credits roll over (true) or are replaced each period (false)
     */
    const BILLING = array(
        'interval_months'  => 1,
        'retry_after_days' => array(1, 3, 5),
        'grace_days'       => 7,
        'pack_carry_over'  => true,
        'currency'         => 'usd',
    );

    /**
     * The plans: price, limits, per-plan switches, and the Stripe product name keywords ('match').
     * Stripe price ids differ between test and live, so they come from app.ini
     * (plan_price_<tier>, addon_price_<addon>) per environment.
     *
     *   limits: 0 = unlimited; below 0 = not included (shown as 0, anything already made is locked).
     *           seats count the owner, so 1 = no collaborators.
     *   ai_credits_grant: 'monthly' (every billing date) or 'once' (first time on a plan, never refilled).
     *   retired: closed to new subscribers; existing subscribers keep it.
     */
    const TIERS = array(
        'free'    => array('key' => 'free',    'rank' => 0, 'name' => 'Free',    'tagline' => 'Start earning, no card needed.', 'price' => 0,   'stripe_price_id' => '', 'match' => array(),          'recommended' => false,
            'ai_credits_grant' => 'once', 'features' => array('inbox_ai' => false),
            'limits' => array('fee_percent' => 20, 'seats' => 1,  'influencers' => -1, 'ai_credits' => 200, 'automations' => -1,  'storage_gb' => 5,   'socials' => 0, 'sub_tiers' => 0)),
        'creator' => array('key' => 'creator', 'rank' => 1, 'name' => 'Creator', 'tagline' => 'Go solo, get paid.',             'price' => 49,  'stripe_price_id' => '', 'match' => array('creator'), 'recommended' => true,
            'ai_credits_grant' => 'monthly', 'features' => array('inbox_ai' => true),
            'limits' => array('fee_percent' => 10, 'seats' => 1,  'influencers' => 1,  'ai_credits' => 500, 'automations' => 5,  'storage_gb' => 25,  'socials' => 0, 'sub_tiers' => 0)),
        'pro'     => array('key' => 'pro',     'rank' => 2, 'name' => 'Pro',     'tagline' => 'Scale your solo brand.',         'price' => 149, 'stripe_price_id' => '', 'match' => array('pro'),     'recommended' => false, 'retired' => true,
            'ai_credits_grant' => 'monthly', 'features' => array('inbox_ai' => true),
            'limits' => array('fee_percent' => 5,  'seats' => 3,  'influencers' => 3,  'ai_credits' => 1500, 'automations' => 20, 'storage_gb' => 100, 'socials' => 0, 'sub_tiers' => 0)),
        'studio'  => array('key' => 'studio',  'rank' => 3, 'name' => 'Studio',  'tagline' => 'Run a team or agency.',          'price' => 199, 'stripe_price_id' => '', 'match' => array('studio'),  'recommended' => false,
            'ai_credits_grant' => 'monthly', 'features' => array('inbox_ai' => true, 'custom_domain' => true),
            'limits' => array('fee_percent' => 3,  'seats' => 10, 'influencers' => 10, 'ai_credits' => 3000, 'automations' => 0,  'storage_gb' => 500, 'socials' => 0, 'sub_tiers' => 0)),
    );

    /** Add-ons: extra capacity billed as a quantity line item on a plan's subscription. 'max' = most slots per account. */
    const ADDONS = array(
        'influencer_slot' => array('key' => 'influencer_slot', 'name' => 'Extra AI influencer', 'note' => 'One more AI influencer slot on the Creator plan.',
            'price' => 15, 'stripe_price_id' => '', 'plans' => array('creator'), 'limit' => 'influencers', 'max' => 5),
    );

    /** The plan every creator starts on, with no Stripe subscription. */
    const FREE_KEY = 'free';

    /** TIERS with this environment's Stripe price ids from app.ini (cached per request). */
    public static function tiers(): array
    {
        static $tiers = null;
        if ($tiers !== null) { return $tiers; }
        $out = self::TIERS;
        // Stripe price ids differ between test and live:
        //   [development] / [production]  plan_price_creator = 'price_...'
        $cfg_ini = Main::get_config();
        $env     = Main::get_environment();
        foreach ($out as $key => $row) {
            foreach (array($cfg_ini[$env] ?? array(), $cfg_ini['global'] ?? array()) as $section) {
                $id = trim((string) ($section['plan_price_' . $key] ?? ''));
                if ($id !== '') { $out[$key]['stripe_price_id'] = $id; break; }
            }
        }
        $tiers = $out;
        return $tiers;
    }

    /**
     * Tiers offered to new subscribers, low → high: everything not retired, plus $current when the
     * account is already on a retired plan (so their own plan still shows as current on Billing).
     */
    public static function offered($current = ''): array
    {
        $out = array();
        foreach (self::all() as $t) {
            if (empty($t['retired']) || $t['key'] === (string) $current) { $out[] = $t; }
        }
        return $out;
    }

    /** Is this tier closed to new subscribers? */
    public static function retired($key): bool
    {
        $t = self::get($key);
        return $t ? !empty($t['retired']) : false;
    }

    /** ADDONS with this environment's Stripe price ids from app.ini (addon_price_<key>). */
    public static function addons(): array
    {
        static $addons = null;
        if ($addons !== null) { return $addons; }
        $out  = self::ADDONS;
        $ini  = Main::get_config();
        $env  = Main::get_environment();
        foreach ($out as $key => $row) {
            foreach (array($ini[$env] ?? array(), $ini['global'] ?? array()) as $section) {
                $id = trim((string) ($section['addon_price_' . $key] ?? ''));
                if ($id !== '') { $out[$key]['stripe_price_id'] = $id; break; }
            }
            $out[$key]['key'] = $key;
        }
        $addons = $out;
        return $addons;
    }

    /** One add-on by key, or null. */
    public static function addon($key)
    {
        $a = self::addons();
        return $a[(string) $key] ?? null;
    }

    /** Add-ons offered on a tier (empty for tiers that take none). */
    public static function addons_for($tier_key): array
    {
        $out = array();
        foreach (self::addons() as $key => $a) {
            if (in_array((string) $tier_key, (array) ($a['plans'] ?? array()), true)) { $out[$key] = $a; }
        }
        return $out;
    }

    /** Tier key for a legacy Stripe subscription price id (configured ids first, then a product-name match). Migration only. */
    public static function tier_for_price($price_id): string
    {
        $price_id = trim((string) $price_id);
        if ($price_id === '') { return ''; }
        foreach (self::tiers() as $key => $t) {
            if (trim((string) ($t['stripe_price_id'] ?? '')) === $price_id) { return $key; }
        }
        try { return StripeService::plan_tier_slug($price_id); } catch (\Throwable $e) { return ''; }
    }

    /** The monthly price shown on the site, in dollars. */
    public static function price($key): int
    {
        $t = self::get($key);
        return $t ? (int) ($t['price'] ?? 0) : 0;
    }

    /** All tiers, ordered low → high. */
    public static function all(): array
    {
        $tiers = array_values(self::tiers());
        usort($tiers, function ($a, $b) { return $a['rank'] - $b['rank']; });
        return $tiers;
    }

    /** A tier definition by key, or null. */
    public static function get($key)
    {
        $t = self::tiers();
        return $t[(string) $key] ?? null;
    }

    /** One ROWS entry by key, or null. */
    public static function row($key)
    {
        foreach (self::ROWS as $r) { if ($r['key'] === (string) $key) { return $r; } }
        return null;
    }

    /** A limit value as shown on the billing page: 0 → Unlimited, below 0 → 0 (not included), 25 → "25 GB", 10 → "10%". */
    public static function fmt_limit($key, $v): string
    {
        $row  = self::row($key);
        $kind = $row ? $row['kind'] : 'count';
        $v    = (float) $v;
        if ($kind === 'percent') { return rtrim(rtrim(number_format($v, 1), '0'), '.') . '%'; }
        if ($v < 0) { return '0'; }
        if ($v <= 0) { return 'Unlimited'; }
        if ($kind === 'gb') { return number_format($v) . ' GB'; }
        return number_format($v);
    }

    /** A tier's limit as shown on a plan card; AI credits say whether they refill ("50 / month", "20 to start"). */
    public static function fmt_tier_limit(array $tier, $key): string
    {
        $v = $tier['limits'][(string) $key] ?? 0;
        if ((string) $key === 'ai_credits' && (int) $v > 0) {
            return number_format((int) $v) . (self::grants_once($tier) ? ' to start' : ' / month');
        }
        return self::fmt_limit($key, $v);
    }

    /** Does this tier grant its AI credits one time only (Free) rather than every billing date? */
    public static function grants_once(array $tier): bool
    {
        return (string) ($tier['ai_credits_grant'] ?? 'monthly') === 'once';
    }

    /** Is a per-plan switch (FEATURES) on for this tier? */
    public static function has_feature(array $tier, $feature): bool
    {
        return !empty($tier['features'][(string) $feature]);
    }

    /** The cheapest tier still offered that has this feature (for "Upgrade to Creator" prompts), or null. */
    public static function lowest_with_feature($feature)
    {
        foreach (self::offered() as $t) { if (self::has_feature($t, $feature)) { return $t; } }
        return null;
    }

    /** The cheapest paid tier still offered whose $key limit is included (not below 0), or null. */
    public static function lowest_including($key)
    {
        foreach (self::offered() as $t) {
            if ($t['key'] !== self::FREE_KEY && (int) ($t['limits'][(string) $key] ?? 0) >= 0) { return $t; }
        }
        return null;
    }

    /**
     * Map a Stripe product NAME to a tier key by keyword match, highest tier
     * first (so "Creator Pro" resolves to pro, not creator). Returns '' if none.
     */
    public static function match($name): string
    {
        $n = strtolower(trim((string) $name));
        if ($n === '') { return ''; }
        $tiers = self::all();
        for ($i = count($tiers) - 1; $i >= 0; $i--) {   // highest rank first
            foreach ($tiers[$i]['match'] as $kw) {
                if (strpos($n, $kw) !== false) { return $tiers[$i]['key']; }
            }
        }
        return '';
    }
}
