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
        '/alternatives' => array('2026-10-15', '8d7a596be45c'),
        '/author/dr-glauber' => array('2026-10-15', '6f21144852b6'),
        '/consistent-ai-model-face' => array('2026-10-15', '803ffb8677bc'),
        '/contact' => array('2026-10-12', 'b4cccedd6f8a'),
        '/features' => array('2026-10-15', 'bd0b47f2d166'),
        '/features/ai-influencer' => array('2026-10-15', 'c0687fcff757'),
        '/features/analytics' => array('2026-10-15', '09c7132c1c5f'),
        '/features/automations' => array('2026-10-15', '50618b10d38f'),
        '/features/custom-domains' => array('2026-10-15', '13c655a09106'),
        '/features/dm-agent' => array('2026-10-15', 'dbe50f5f7663'),
        '/features/link-in-bio' => array('2026-10-15', '7389ad0bce52'),
        '/features/publishing' => array('2026-10-15', 'cf300c17a391'),
        '/integrations' => array('2026-10-15', 'ef9d59032660'),
        '/integrations/bluesky' => array('2026-10-15', '1d1e75b84e4a'),
        '/integrations/facebook' => array('2026-10-15', 'a035345c7976'),
        '/integrations/instagram' => array('2026-10-15', 'd2dd3ebd907a'),
        '/integrations/linkedin' => array('2026-10-15', '27f43c48e752'),
        '/integrations/pinterest' => array('2026-10-15', '3082358189f9'),
        '/integrations/threads' => array('2026-10-15', 'c469c420fe4f'),
        '/integrations/tiktok' => array('2026-10-15', '4aec2c5356f5'),
        '/integrations/x' => array('2026-10-15', 'e52dbd590dce'),
        '/integrations/youtube' => array('2026-10-15', '6d5a3cd9c237'),
        '/lora-character-training' => array('2026-10-15', '4e1e23ca5795'),
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
