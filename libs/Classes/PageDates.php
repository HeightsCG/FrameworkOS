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
        '/' => array('2026-10-12', '3283c2921b37'),
        '/about' => array('2026-10-12', 'a62d73f57362'),
        '/affiliates' => array('2026-10-12', 'c754e16c6e76'),
        '/ai-ofm-tools' => array('2026-10-12', 'a92f51ba5910'),
        '/author/dr-glauber' => array('2026-10-12', 'dff32d3a6d29'),
        '/best-creator-monetization-platforms' => array('2026-10-13', '3c97f01d00ee'),
        '/compare/beacons' => array('2026-10-13', '80a245524040'),
        '/compare/eromify' => array('2026-10-13', '127b9cccbbaa'),
        '/compare/fansly' => array('2026-10-13', '9f9907aea409'),
        '/compare/fanvue' => array('2026-10-13', '169bfa5d3039'),
        '/compare/kofi' => array('2026-10-13', 'fdb9311177ac'),
        '/compare/linktree' => array('2026-10-13', '29a061d89ea4'),
        '/compare/onlyfans' => array('2026-10-13', '3de311f6cee6'),
        '/compare/patreon' => array('2026-10-13', 'ba7790014676'),
        '/compare/stan' => array('2026-10-13', '525d739f7124'),
        '/consistent-ai-model-face' => array('2026-10-12', '7d2de9136b89'),
        '/contact' => array('2026-10-12', 'b4cccedd6f8a'),
        '/fanvue-alternatives' => array('2026-10-13', '653499d588ab'),
        '/features' => array('2026-10-12', 'e302f7070921'),
        '/features/ai-influencer' => array('2026-10-12', '31d1ce940440'),
        '/features/custom-domains' => array('2026-10-12', '21e4582fb141'),
        '/features/dm-agent' => array('2026-10-12', 'cb0fe58e76e7'),
        '/features/link-in-bio' => array('2026-10-12', '08a36c76b509'),
        '/features/memberships' => array('2026-10-12', '797b848dfd42'),
        '/features/pay-per-view' => array('2026-10-12', 'c37f37d17465'),
        '/features/payouts' => array('2026-10-12', 'c3d228d5a574'),
        '/features/publishing' => array('2026-10-12', 'af07604c2538'),
        '/features/services-and-events' => array('2026-10-12', 'b6da6ef1d57c'),
        '/founding' => array('2026-10-12', 'c96a696fe97e'),
        '/linktree-alternatives' => array('2026-10-13', '5650ede6918b'),
        '/lora-character-training' => array('2026-10-12', 'ed5ce3df5b74'),
        '/monetize-your-content' => array('2026-10-12', 'dcc01f6c8adc'),
        '/onlyfans-alternatives' => array('2026-10-13', '0bd709e744f3'),
        '/patreon-alternatives' => array('2026-10-13', 'bab5ef18c200'),
        '/pricing' => array('2026-10-12', 'ddd4443c89aa'),
        '/privacy' => array('2026-10-12', '010544fcb1f3'),
        '/terms' => array('2026-10-12', '7daa2e504aee'),
        '/tools/ai-influencer-persona' => array('2026-10-12', '5e26a9a550f3'),
        '/tools/fan-questions' => array('2026-10-12', 'e247eb2d7414'),
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
