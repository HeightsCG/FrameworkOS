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
 *   limits.*: 0 means "unlimited".
 *   limits.fee_percent: the platform's take rate for this tier.
 *   limits.ai_credits: AI credits added to the account every billing date.
 */
class PlanTiers {

    /** The comparison rows, in billing-page order. kind drives formatting + usage source. */
    const ROWS = array(
        array('key' => 'fee_percent', 'label' => 'Platform take rate',    'noun' => '',                 'kind' => 'percent'),
        array('key' => 'seats',       'label' => 'Team seats',            'noun' => 'team seats',       'kind' => 'count'),
        array('key' => 'influencers', 'label' => 'AI influencers',        'noun' => 'AI influencers',   'kind' => 'count'),
        array('key' => 'ai_credits',  'label' => 'AI credits / month',    'noun' => 'AI credits',       'kind' => 'credits'),
        array('key' => 'automations', 'label' => 'Scheduled automations', 'noun' => 'automations',      'kind' => 'count'),
        array('key' => 'storage_gb',  'label' => 'Storage',               'noun' => 'GB of storage',    'kind' => 'gb'),
    );

    /** Shown under the plan grid: on every plan, no limits. */
    const INCLUDED = array(
        'Unlimited social connections', 'Unlimited membership tiers', 'Brand images &amp; AI captions', 'Inbox automation &amp; AI replies',
        'Bundles, promo codes &amp; free trials', 'Analytics &amp; exports', 'Claude connector',
    );

    /** What each AI job type costs in AI credits (per output). Training is free: the influencer count gates it. */
    const AI_PRICES = array('image' => 1, 'enhance' => 1, 'video' => 3);

    /** Top-up packs: $1 = 1 AI credit. */
    const AI_PACKS = array(10, 25, 50, 100);

    const TIERS = array(

        'creator' => array(
            'key'         => 'creator',
            'rank'        => 1,
            'name'        => 'Creator',
            'tagline'     => 'Go solo, get paid.',
            'match'       => array('creator'),   // product-name keywords (lowercased, substring)
            'recommended' => false,
            'limits'      => array(
                'fee_percent' => 10,
                'seats'       => 1,
                'influencers' => 1,
                'ai_credits'  => 30,
                'automations' => 5,
                'storage_gb'  => 25,
                'socials'     => 0,
                'sub_tiers'   => 0,
            ),
        ),

        'pro' => array(
            'key'         => 'pro',
            'rank'        => 2,
            'name'        => 'Pro',
            'tagline'     => 'Scale your solo brand.',
            'match'       => array('pro'),
            'recommended' => true,
            'limits'      => array(
                'fee_percent' => 5,
                'seats'       => 3,
                'influencers' => 3,
                'ai_credits'  => 60,
                'automations' => 20,
                'storage_gb'  => 100,
                'socials'     => 0,
                'sub_tiers'   => 0,
            ),
        ),

        'studio' => array(
            'key'         => 'studio',
            'rank'        => 3,
            'name'        => 'Studio',
            'tagline'     => 'Run a team or agency.',
            'match'       => array('studio'),
            'recommended' => false,
            'limits'      => array(
                'fee_percent' => 3,
                'seats'       => 10,
                'influencers' => 10,
                'ai_credits'  => 90,
                'automations' => 0,
                'storage_gb'  => 500,
                'socials'     => 0,
                'sub_tiers'   => 0,
            ),
        ),
    );

    /** All tiers, ordered low → high. */
    public static function all(): array
    {
        $tiers = array_values(self::TIERS);
        usort($tiers, function ($a, $b) { return $a['rank'] - $b['rank']; });
        return $tiers;
    }

    /** A tier definition by key, or null. */
    public static function get($key)
    {
        return self::TIERS[(string) $key] ?? null;
    }

    /** One ROWS entry by key, or null. */
    public static function row($key)
    {
        foreach (self::ROWS as $r) { if ($r['key'] === (string) $key) { return $r; } }
        return null;
    }

    /** A limit value as shown on the billing page: 0 → Unlimited, 25 → "25 GB", 10 → "10%". */
    public static function fmt_limit($key, $v): string
    {
        $row  = self::row($key);
        $kind = $row ? $row['kind'] : 'count';
        $v    = (float) $v;
        if ($kind === 'percent') { return rtrim(rtrim(number_format($v, 1), '0'), '.') . '%'; }
        if ($v <= 0) { return 'Unlimited'; }
        if ($kind === 'gb') { return number_format($v) . ' GB'; }
        return number_format($v);
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
