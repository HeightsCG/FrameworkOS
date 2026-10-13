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
        '/' => array('2026-10-13', '6496ddfa9859'),
        '/about' => array('2026-10-13', '1bb5408eb6c3'),
        '/affiliates' => array('2026-10-12', 'c754e16c6e76'),
        '/ai-ofm-tools' => array('2026-10-13', 'ccb5ce115589'),
        '/author/dr-glauber' => array('2026-10-12', 'dff32d3a6d29'),
        '/best-creator-monetization-platforms' => array('2026-10-13', '3dc69c77adf2'),
        '/compare/beacons' => array('2026-10-13', 'fb1b4757f40f'),
        '/compare/eromify' => array('2026-10-13', '504827811a28'),
        '/compare/fansly' => array('2026-10-13', 'eb1e65564b4e'),
        '/compare/fanvue' => array('2026-10-13', '92f21431c62a'),
        '/compare/kofi' => array('2026-10-13', '3b401e67c8ae'),
        '/compare/linktree' => array('2026-10-13', '5ffeca1358a6'),
        '/compare/onlyfans' => array('2026-10-13', 'c25417b730dd'),
        '/compare/patreon' => array('2026-10-13', '710a49e7079d'),
        '/compare/stan' => array('2026-10-13', 'af8a964e0f6b'),
        '/consistent-ai-model-face' => array('2026-10-12', '7d2de9136b89'),
        '/contact' => array('2026-10-12', 'b4cccedd6f8a'),
        '/fanvue-alternatives' => array('2026-10-13', 'e460103aeedb'),
        '/features' => array('2026-10-13', 'd7559d5683a4'),
        '/features/ai-influencer' => array('2026-10-13', '797244d48309'),
        '/features/custom-domains' => array('2026-10-13', '8f2e3a3b6c3d'),
        '/features/dm-agent' => array('2026-10-12', 'cb0fe58e76e7'),
        '/features/link-in-bio' => array('2026-10-13', '935679a4b93d'),
        '/features/memberships' => array('2026-10-13', '1eb5d76b79fd'),
        '/features/pay-per-view' => array('2026-10-13', '2ef9d6da63aa'),
        '/features/payouts' => array('2026-10-13', 'c8afd1a51a6c'),
        '/features/publishing' => array('2026-10-12', 'af07604c2538'),
        '/features/services-and-events' => array('2026-10-13', 'b186b54a4bd5'),
        '/founding' => array('2026-10-13', '57370dae0ac0'),
        '/linktree-alternatives' => array('2026-10-13', '430c85161f84'),
        '/lora-character-training' => array('2026-10-13', '0837aa77b437'),
        '/monetize-your-content' => array('2026-10-13', '359b3dc15754'),
        '/onlyfans-alternatives' => array('2026-10-13', '5988bc401fce'),
        '/patreon-alternatives' => array('2026-10-13', '9c419c66fb3c'),
        '/pricing' => array('2026-10-13', '1dc17e8c09b2'),
        '/privacy' => array('2026-10-12', '010544fcb1f3'),
        '/terms' => array('2026-10-13', 'bcabc60c9009'),
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
