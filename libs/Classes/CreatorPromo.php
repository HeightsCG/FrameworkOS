<?php
/**
 * Founding Creator price promotion (Daniel, 2026-10-15). One place for the dates and prices every public surface shows:
 * the Creator plan is $49 a month until Oct 31, 2026 11:59 PM Eastern; the announced regular price from Nov 1, 2026 is $99.
 *
 * Policy (decided 2026-10-15):
 *   - Eligibility: starting the Creator plan (the 14-day trial, or a paid start once trial spots are gone) before the deadline.
 *   - Lock: an eligible creator keeps $49 for as long as the subscription stays active; cancelling or moving to Free ends it.
 *   - The $49 price is open to everyone until the deadline; the 14-day trial stays limited to Founding::SPOTS.
 *
 * DISPLAY ONLY. Billing still charges PlanTiers::price('creator') on every charge and renewal (BillingService::plan_cents).
 * The promo shows only while that billing price equals PRICE, so the page never promises a price checkout does not charge.
 * The date switch and the $49 lock in billing are a separate, approved change (see the report of 2026-10-15).
 * Time is always the server's clock, never the browser's.
 */
class CreatorPromo
{
    const PLAN    = 'creator';
    const PRICE   = 49;                       // promotional monthly price (dollars)
    const REGULAR = 99;                       // announced regular monthly price from Nov 1, 2026
    const ENDS    = '2026-10-31 23:59:59';    // last moment of the offer, in TZ
    const STARTS_REGULAR = '2026-11-01';      // first day of the regular price, in TZ
    const TZ      = 'America/New_York';

    /** Unix time of the end of the offer (Oct 31, 2026 23:59:59 Eastern, daylight-saving aware). */
    public static function ends_at(): int {
        return (new DateTime(self::ENDS, new DateTimeZone(self::TZ)))->getTimestamp();
    }

    /** The clock the offer is judged by. On dev only, ?promo_at=<unix or date> previews another moment (never in production). */
    public static function now(): int {
        $env = (string) getenv('APPLICATION_ENV');
        if ($env !== '' && $env !== 'production' && isset($_GET['promo_at'])) {
            $v = (string) $_GET['promo_at'];
            $t = ctype_digit($v) ? (int) $v : strtotime($v . ' ' . self::TZ);
            if ($t) { return $t; }
        }
        return time();
    }

    /** Is the offer running at $at (default now), and does billing actually charge the promotional price? */
    public static function active(?int $at = null): bool {
        $at = $at ?? self::now();
        if ($at > self::ends_at()) { return false; }
        return (int) PlanTiers::price(self::PLAN) === self::PRICE;   // never advertise a price billing does not charge
    }

    /** A public page's cache lifetime, cut so nothing cached before the deadline is served after it (real clock, not the preview). */
    public static function cap_cache(int $seconds): int {
        $left = self::ends_at() - time();
        return ($left > 0 && $left < $seconds) ? max(1, $left + 1) : $seconds;
    }

    public static function saving(): int { return self::REGULAR - self::PRICE; }

    /** "October 31, 2026" and "Nov 1" for the copy. */
    public static function ends_label(): string { return (new DateTime(self::ENDS, new DateTimeZone(self::TZ)))->format('F j, Y'); }
    public static function regular_from_short(): string { return (new DateTime(self::STARTS_REGULAR, new DateTimeZone(self::TZ)))->format('M j'); }
    public static function regular_from_long(): string { return (new DateTime(self::STARTS_REGULAR, new DateTimeZone(self::TZ)))->format('F j, Y'); }

    /** One sentence for FAQs and plan summaries while the offer runs, or ''. */
    public static function sentence(): string {
        if (!self::active()) { return ''; }
        return 'Founding Creator offer: start Creator by ' . self::ends_label() . ' (11:59 PM Eastern) and pay $' . self::PRICE . ' a month for as long as you stay subscribed; the regular price from ' . self::regular_from_long() . ' is $' . self::REGULAR . ' a month.';
    }
}
