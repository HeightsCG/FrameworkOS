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
        '/' => array('2026-10-15', 'aa0c27f55d02'),
        '/about' => array('2026-10-15', 'e2036bd6a0ad'),
        '/affiliates' => array('2026-10-15', 'fb809c27a491'),
        '/ai-ofm-tools' => array('2026-10-15', '7c282c0ae7e8'),
        '/alternatives' => array('2026-10-15', '8d7a596be45c'),
        '/author/dr-glauber' => array('2026-10-15', '6f21144852b6'),
        '/best-creator-monetization-platforms' => array('2026-10-15', '809c1c089e0c'),
        '/compare/beacons' => array('2026-10-15', '1c4b9f4f21df'),
        '/compare/eromify' => array('2026-10-15', 'ad5d600c7fa1'),
        '/compare/fansly' => array('2026-10-15', '16e637650674'),
        '/compare/fanvue' => array('2026-10-15', '0967fd075a52'),
        '/compare/kofi' => array('2026-10-15', 'd20386feb38d'),
        '/compare/linktree' => array('2026-10-15', '87e3872e4662'),
        '/compare/onlyfans' => array('2026-10-15', '6feff981b7d2'),
        '/compare/patreon' => array('2026-10-15', '41e359ba6a89'),
        '/compare/stan' => array('2026-10-15', 'd988771ae8bf'),
        '/consistent-ai-model-face' => array('2026-10-15', '803ffb8677bc'),
        '/contact' => array('2026-10-12', 'b4cccedd6f8a'),
        '/fanvue-alternatives' => array('2026-10-15', '88738820c5c1'),
        '/features' => array('2026-10-15', 'bd0b47f2d166'),
        '/features/ai-influencer' => array('2026-10-15', 'c0687fcff757'),
        '/features/analytics' => array('2026-10-15', '09c7132c1c5f'),
        '/features/automations' => array('2026-10-15', '50618b10d38f'),
        '/features/custom-domains' => array('2026-10-15', '13c655a09106'),
        '/features/dm-agent' => array('2026-10-15', 'dbe50f5f7663'),
        '/features/link-in-bio' => array('2026-10-15', '7389ad0bce52'),
        '/features/memberships' => array('2026-10-15', '06e3c5f76e14'),
        '/features/pay-per-view' => array('2026-10-15', 'da12a9e1942a'),
        '/features/payouts' => array('2026-10-15', '3a5d3ace82f7'),
        '/features/publishing' => array('2026-10-15', 'cf300c17a391'),
        '/features/services-and-events' => array('2026-10-15', '804e9b51d335'),
        '/integrations' => array('2026-10-15', 'ef9d59032660'),
        '/integrations/bluesky' => array('2026-10-15', '1d1e75b84e4a'),
        '/integrations/facebook' => array('2026-10-15', 'a035345c7976'),
        '/integrations/fanvue' => array('2026-10-15', '47553bf90059'),
        '/integrations/instagram' => array('2026-10-15', 'd2dd3ebd907a'),
        '/integrations/linkedin' => array('2026-10-15', '27f43c48e752'),
        '/integrations/pinterest' => array('2026-10-15', '3082358189f9'),
        '/integrations/threads' => array('2026-10-15', 'c469c420fe4f'),
        '/integrations/tiktok' => array('2026-10-15', '4aec2c5356f5'),
        '/integrations/x' => array('2026-10-15', 'e52dbd590dce'),
        '/integrations/youtube' => array('2026-10-15', '6d5a3cd9c237'),
        '/linktree-alternatives' => array('2026-10-15', 'aebbe5082328'),
        '/lora-character-training' => array('2026-10-15', '4e1e23ca5795'),
        '/monetize-your-content' => array('2026-10-15', '8a7db999f4d6'),
        '/onlyfans-alternatives' => array('2026-10-15', 'a9fe5b6f6640'),
        '/patreon-alternatives' => array('2026-10-15', '59e6e4977bd8'),
        '/press' => array('2026-10-15', '3df7850dd5c6'),
        '/pricing' => array('2026-10-15', '82e023541054'),
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
