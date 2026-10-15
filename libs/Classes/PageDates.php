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
        '/' => array('2026-10-15', '59c05dd0a292'),
        '/about' => array('2026-10-15', '32f57e1d911a'),
        '/affiliates' => array('2026-10-12', 'c754e16c6e76'),
        '/ai-ofm-tools' => array('2026-10-15', '941b0063012b'),
        '/alternatives' => array('2026-10-15', '8d7a596be45c'),
        '/author/dr-glauber' => array('2026-10-15', '6f21144852b6'),
        '/best-creator-monetization-platforms' => array('2026-10-15', 'f27db383fe6d'),
        '/compare/beacons' => array('2026-10-15', '72dcf9779485'),
        '/compare/eromify' => array('2026-10-15', '82135de7e5b9'),
        '/compare/fansly' => array('2026-10-15', '774f156d2682'),
        '/compare/fanvue' => array('2026-10-15', '9cf5415ea372'),
        '/compare/kofi' => array('2026-10-15', 'dc9c44017330'),
        '/compare/linktree' => array('2026-10-15', '48a588bdff70'),
        '/compare/onlyfans' => array('2026-10-15', '7a5817d6d3de'),
        '/compare/patreon' => array('2026-10-15', 'a2e85354bcef'),
        '/compare/stan' => array('2026-10-15', '98fbc3c91a49'),
        '/consistent-ai-model-face' => array('2026-10-15', '945c6a975acf'),
        '/contact' => array('2026-10-12', 'b4cccedd6f8a'),
        '/fanvue-alternatives' => array('2026-10-15', '3606f425eea9'),
        '/features' => array('2026-10-15', '822ddc647a98'),
        '/features/ai-influencer' => array('2026-10-15', 'd0a70ff47a32'),
        '/features/custom-domains' => array('2026-10-15', 'a79c0b34ddcd'),
        '/features/dm-agent' => array('2026-10-15', 'c4d413e8a7f8'),
        '/features/link-in-bio' => array('2026-10-15', 'd5978257e7d0'),
        '/features/memberships' => array('2026-10-13', '1eb5d76b79fd'),
        '/features/pay-per-view' => array('2026-10-13', '2ef9d6da63aa'),
        '/features/payouts' => array('2026-10-13', 'c8afd1a51a6c'),
        '/features/publishing' => array('2026-10-15', '4e1c4be34044'),
        '/features/services-and-events' => array('2026-10-13', 'b186b54a4bd5'),
        '/founding' => array('2026-10-15', '11bc346b4b59'),
        '/integrations' => array('2026-10-15', 'ef9d59032660'),
        '/integrations/bluesky' => array('2026-10-15', '1825797bb145'),
        '/integrations/facebook' => array('2026-10-15', 'e2cca53f0fa3'),
        '/integrations/fanvue' => array('2026-10-15', 'cb16516eee1f'),
        '/integrations/instagram' => array('2026-10-15', '129f9b5103e8'),
        '/integrations/linkedin' => array('2026-10-15', 'fbd764fd4d4f'),
        '/integrations/pinterest' => array('2026-10-15', '14eadca6198f'),
        '/integrations/threads' => array('2026-10-15', '85883a8b6c70'),
        '/integrations/tiktok' => array('2026-10-15', 'b48e5cca6fc0'),
        '/integrations/x' => array('2026-10-15', '85e48580b987'),
        '/integrations/youtube' => array('2026-10-15', 'c2df1311218e'),
        '/linktree-alternatives' => array('2026-10-15', 'b56d7576b29d'),
        '/lora-character-training' => array('2026-10-15', '43feb33987a3'),
        '/monetize-your-content' => array('2026-10-13', '359b3dc15754'),
        '/onlyfans-alternatives' => array('2026-10-15', '5c432db2a834'),
        '/patreon-alternatives' => array('2026-10-15', 'ac9247914806'),
        '/press' => array('2026-10-15', '3df7850dd5c6'),
        '/pricing' => array('2026-10-15', 'f98b004f4a8d'),
        '/privacy' => array('2026-10-12', '010544fcb1f3'),
        '/terms' => array('2026-10-13', 'bcabc60c9009'),
        '/tools/ai-disclosure-caption-checker' => array('2026-10-15', 'fad1bb7652d7'),
        '/tools/ai-influencer-bio-generator' => array('2026-10-15', 'a9005175fc04'),
        '/tools/ai-influencer-name-generator' => array('2026-10-15', '18c78b6c5680'),
        '/tools/ai-influencer-persona' => array('2026-10-12', '5e26a9a550f3'),
        '/tools/fan-questions' => array('2026-10-15', '7eee29641dd5'),
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
