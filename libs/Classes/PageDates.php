<?php
/**
 * The date each hand-written public page last changed, for the sitemap's <lastmod> and the WebPage dateModified
 * (SEO audit 2026-10-12: 29 of 54 sitemap URLs had no date).
 *
 * A file's mtime would stamp every page on every deploy, so the dates are kept here instead: cron/build_page_dates.php
 * (a dev step, like cron/build_charts.php) renders each page, hashes its visible text, and moves a page's date only when
 * that text changed. Production reads this list and never writes it. Run the script on dev before a deploy that changes
 * public copy, and ship this file with it.
 *
 * DATES: path => array(date 'YYYY-MM-DD', hash of the page text). GENERATED: edit through the script, not by hand.
 */
class PageDates {

    const DATES = array(
        '/' => array('2026-10-12', 'e82245901c56'),
        '/about' => array('2026-10-12', 'a62d73f57362'),
        '/affiliates' => array('2026-10-12', 'c754e16c6e76'),
        '/ai-ofm-tools' => array('2026-10-12', '3e30622cac91'),
        '/author/dr-glauber' => array('2026-10-12', 'dff32d3a6d29'),
        '/best-creator-monetization-platforms' => array('2026-10-12', 'd1522e190fcf'),
        '/compare/beacons' => array('2026-10-12', '0aadca9076b1'),
        '/compare/eromify' => array('2026-10-12', '7528af238c6c'),
        '/compare/fansly' => array('2026-10-12', 'e22ae5efd5ee'),
        '/compare/fanvue' => array('2026-10-12', '7bdc854fbf6a'),
        '/compare/kofi' => array('2026-10-12', '339e3f9784da'),
        '/compare/linktree' => array('2026-10-12', 'a5326b4ca3a0'),
        '/compare/onlyfans' => array('2026-10-12', '8621c50d666f'),
        '/compare/patreon' => array('2026-10-12', '0682c49f2dab'),
        '/compare/stan' => array('2026-10-12', 'be9600035a65'),
        '/consistent-ai-model-face' => array('2026-10-12', 'df40b0532a58'),
        '/contact' => array('2026-10-12', 'b4cccedd6f8a'),
        '/fanvue-alternatives' => array('2026-10-12', 'b304b7f14061'),
        '/features' => array('2026-10-12', 'e302f7070921'),
        '/features/ai-influencer' => array('2026-10-12', '0f7177e953fd'),
        '/features/custom-domains' => array('2026-10-12', '573aadf6674a'),
        '/features/dm-agent' => array('2026-10-12', '4b6a00b0a61c'),
        '/features/link-in-bio' => array('2026-10-12', '964ad8b0572c'),
        '/features/memberships' => array('2026-10-12', '344ca2cf9d4f'),
        '/features/pay-per-view' => array('2026-10-12', '18de8bc7f09f'),
        '/features/payouts' => array('2026-10-12', '4f3aab72daa1'),
        '/features/publishing' => array('2026-10-12', '65088ffcd977'),
        '/features/services-and-events' => array('2026-10-12', 'deb5ec666197'),
        '/founding' => array('2026-10-12', 'c96a696fe97e'),
        '/lora-character-training' => array('2026-10-12', 'bc3f685346f5'),
        '/monetize-your-content' => array('2026-10-12', '648f1fc8367b'),
        '/onlyfans-alternatives' => array('2026-10-12', '85413e059309'),
        '/pricing' => array('2026-10-12', '12f9f5fd2e5d'),
        '/privacy' => array('2026-10-12', '010544fcb1f3'),
        '/terms' => array('2026-10-12', '7daa2e504aee'),
        '/tools/ai-influencer-persona' => array('2026-10-12', 'a12f955f64dd'),
        '/tools/fan-questions' => array('2026-10-12', 'e33b961bf1d7'),
    );

    /** 'YYYY-MM-DD' for a public path, or '' when the page has not been through the script yet. */
    public static function get(string $path): string {
        return (string) (self::DATES[$path][0] ?? '');
    }

    /** ISO 8601 (midnight UTC) for JSON-LD, or ''. */
    public static function iso(string $path): string {
        $d = self::get($path);
        return $d === '' ? '' : $d . 'T00:00:00+00:00';
    }
}
