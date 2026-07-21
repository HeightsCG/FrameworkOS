<?php
/**
 * Canonical definition of the platform plan tiers — the SINGLE source of truth
 * for what each plan includes. Everything (limits, feature flags, and the copy
 * shown on the billing page) lives here in code; NOTHING is read from Stripe
 * metadata. A Stripe plan is matched to a tier purely by its product NAME
 * (see match()), so Stripe only owns pricing, not product semantics.
 *
 * Enforcement reads these via the Plan class: Plan::tier(), Plan::can(),
 * Plan::limit(). The billing page renders the `features` bullets.
 *
 *   limits.socials/sub_tiers/etc: 0 means "unlimited".
 *   limits.fee_percent: the platform's take rate for this tier.
 */
class PlanTiers {

    const TIERS = array(

        'creator' => array(
            'key'         => 'creator',
            'rank'        => 1,
            'name'        => 'Creator',
            'tagline'     => 'Go solo, get paid.',
            'match'       => array('creator'),   // product-name keywords (lowercased, substring)
            'recommended' => false,
            'limits'      => array(
                'seats'      => 1,
                'profiles'   => 1,
                'socials'    => 3,
                'sub_tiers'  => 1,
                'storage_gb' => 50,
                'fee_percent'=> 10,
            ),
            'payouts'    => 'monthly',
            'scheduling' => 'basic',
            'analytics'  => 'basic',
            'support'    => 'standard',
            'flags'      => array(
                'social_posting'      => true,
                'ppv'                 => true,
                'tips'                => true,
                'bundles'             => false,
                'trials'              => false,
                'promo_codes'         => false,
                'advanced_scheduling' => false,
                'ai_tools'            => false,
                'brand_kit'           => false,
                'premium_templates'   => false,
                'advanced_analytics'  => false,
                'agency_analytics'    => false,
                'approval_workflow'   => false,
                'split_payouts'       => false,
                'white_label'         => false,
                'api_access'          => false,
            ),
            'features'   => array(
                '1 seat — just you',
                '1 creator profile',
                '3 connected social accounts',
                'PPV, 1 subscription tier &amp; tips',
                'Basic scheduling &amp; analytics',
                '10% platform fee',
                '50 GB storage',
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
                'seats'      => 3,
                'profiles'   => 1,
                'socials'    => 10,
                'sub_tiers'  => 0,   // unlimited
                'storage_gb' => 500,
                'fee_percent'=> 5,
            ),
            'payouts'    => 'weekly',
            'scheduling' => 'advanced',
            'analytics'  => 'advanced',
            'support'    => 'priority',
            'flags'      => array(
                'social_posting'      => true,
                'ppv'                 => true,
                'tips'                => true,
                'bundles'             => true,
                'trials'              => true,
                'promo_codes'         => true,
                'advanced_scheduling' => true,
                'ai_tools'            => true,
                'brand_kit'           => true,
                'premium_templates'   => true,
                'advanced_analytics'  => true,
                'agency_analytics'    => false,
                'approval_workflow'   => false,
                'split_payouts'       => false,
                'white_label'         => false,
                'api_access'          => false,
            ),
            'features'   => array(
                'Everything in Creator, plus:',
                '3 seats + collaborator roles',
                '10 connected socials',
                'AI tools, premium templates &amp; brand kit',
                'Multiple tiers, bundles, trials &amp; promo codes',
                'Advanced scheduling &amp; analytics',
                '5% platform fee',
                '500 GB storage',
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
                'seats'      => 10,
                'profiles'   => 10,
                'socials'    => 50,
                'sub_tiers'  => 0,   // unlimited
                'storage_gb' => 2048,
                'fee_percent'=> 2,
            ),
            'payouts'    => 'on_demand',
            'scheduling' => 'advanced',
            'analytics'  => 'agency',
            'support'    => 'dedicated',
            'flags'      => array(
                'social_posting'      => true,
                'ppv'                 => true,
                'tips'                => true,
                'bundles'             => true,
                'trials'              => true,
                'promo_codes'         => true,
                'advanced_scheduling' => true,
                'ai_tools'            => true,
                'brand_kit'           => true,
                'premium_templates'   => true,
                'advanced_analytics'  => true,
                'agency_analytics'    => true,
                'approval_workflow'   => true,
                'split_payouts'       => true,
                'white_label'         => true,
                'api_access'          => true,
            ),
            'features'   => array(
                'Everything in Pro, plus:',
                '10 seats with roles &amp; permissions',
                'Up to 10 creator profiles',
                '50+ connected socials',
                'Team approval workflows',
                'Agency analytics + exports',
                'White-label + API access',
                '2% platform fee',
                '2 TB storage',
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
