<?php
/**
 * Third-party proof on the public pages (heycatch D3.5, Daniel 2026-10-12): links to the listings that exist, and nothing we cannot
 * show truthfully. No rating, badge, count or date is rendered unless the switch for it is set here after the fact exists.
 */
class Proof {

    /** The G2 listing has published reviews: set true once at least one review is live, then the "Read reviews on G2" link shows. */
    const G2_REVIEWS = false;

    /** Product Hunt launch date (YYYY-MM-DD) once the launch is live; '' hides the badge and keeps the plain link. */
    const PRODUCT_HUNT_LAUNCH = '';

    /** Fewest creators with a public page before the count and avatar grid render on the home page (D3.1). */
    const CREATOR_COUNT_MIN = 25;

    /** Fewest approved testimonials before the testimonial row renders (D3.2). */
    const TESTIMONIALS_MIN = 3;

    public static function product_hunt_url(): string { return (string) (SeoMeta::SOCIAL_PROFILES['Product Hunt'] ?? ''); }
    public static function g2_url(): string { return (string) (SeoMeta::SOCIAL_PROFILES['G2'] ?? ''); }

    /** The proof links as a short inline list: Product Hunt always (the listing exists), G2 only once it has reviews. */
    public static function links(string $class = 'sx-proof'): string {
        $e = function ($s) { return Sections::e($s); }; $items = array();
        if (self::product_hunt_url() !== '') {
            $badge = self::PRODUCT_HUNT_LAUNCH !== '' ? ' <span class="' . $class . '__badge">Launched ' . $e(date('M j, Y', strtotime(self::PRODUCT_HUNT_LAUNCH))) . '</span>' : '';
            $items[] = '<a class="' . $class . '__a" href="' . $e(self::product_hunt_url()) . '" rel="noopener" target="_blank">See us on Product Hunt</a>' . $badge;
        }
        if (self::G2_REVIEWS && self::g2_url() !== '') { $items[] = '<a class="' . $class . '__a" href="' . $e(self::g2_url()) . '" rel="noopener" target="_blank">Read reviews on G2</a>'; }
        if (empty($items)) { return ''; }
        return '<p class="' . $class . '">' . implode('<span class="' . $class . '__sep" aria-hidden="true">·</span>', $items) . '</p>';
    }
}
