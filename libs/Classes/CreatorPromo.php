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
 * BILLING (2026-10-18): this class decides the Creator list price. PlanTiers::tiers() sets the creator tier's price from
 * list_price(): PRICE until the offer ends, REGULAR after. Each account keeps the price it started at
 * (billing_accounts.plan_price_cents, BillingService::account_plan_cents), so ending or extending the offer never changes
 * what an existing subscriber pays. The price, regular price and end date are set on /admin (Growth tab, "Founding
 * Creator Price"; app_settings creator_promo_price / creator_promo_regular / creator_promo_ends); PRICE, REGULAR and
 * ENDS below are only the defaults when nothing is saved.
 * Time is always the server's clock, never the browser's.
 */
class CreatorPromo
{
    const PLAN    = 'creator';
    const PRICE   = 49;                       // default promotional monthly price (dollars); admin setting creator_promo_price overrides
    const REGULAR = 99;                       // default regular monthly price after the offer; admin setting creator_promo_regular overrides
    const ENDS    = '2026-10-31 23:59:59';    // default last moment of the offer, in TZ (admin setting creator_promo_ends overrides)
    const TZ      = 'America/New_York';
    const SETTING = 'creator_promo_ends';     // app_settings key: 'YYYY-MM-DD' (offer runs to 11:59:59 PM that day, Eastern)
    const SETTING_PRICE   = 'creator_promo_price';     // app_settings key: whole dollars a month during the offer
    const SETTING_REGULAR = 'creator_promo_regular';   // app_settings key: whole dollars a month after the offer

    /** The promotional monthly price in dollars: the admin's saved price, else PRICE. */
    public static function price(): int { return self::dollars(self::SETTING_PRICE, self::PRICE); }

    /** The regular monthly price in dollars after the offer: the admin's saved price, else REGULAR. */
    public static function regular(): int { return self::dollars(self::SETTING_REGULAR, self::REGULAR); }

    private static function dollars(string $key, int $default): int {
        if (!array_key_exists($key, self::$prices)) {
            $v = '';
            try { $v = trim((string) (new AdminSettingsModel())->get($key, '')); } catch (\Throwable $e) { $v = ''; }   // no DB (a test): the default
            self::$prices[$key] = (ctype_digit($v) && (int) $v > 0) ? (int) $v : $default;
        }
        return self::$prices[$key];
    }
    private static $prices = array();

    /** The offer's last moment as 'Y-m-d H:i:s' in TZ: the admin's saved end date, else ENDS. */
    public static function ends(): string {
        if (self::$ends !== null) { return self::$ends; }
        $d = '';
        try { $d = trim((string) (new AdminSettingsModel())->get(self::SETTING, '')); } catch (\Throwable $e) { $d = ''; }   // no DB (a test): the default
        self::$ends = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d . ' 23:59:59' : self::ENDS;
        return self::$ends;
    }
    private static $ends = null;

    /** Forget the cached end date and prices (after the admin saves a new one in this request). */
    public static function reset(): void { self::$ends = null; self::$prices = array(); }

    /** Unix time of the end of the offer (daylight-saving aware). */
    public static function ends_at(): int {
        return (new DateTime(self::ends(), new DateTimeZone(self::TZ)))->getTimestamp();
    }

    /** The Creator list price new subscribers get right now: PRICE while the offer runs, REGULAR after (dollars). */
    public static function list_price(?int $at = null): int {
        return self::running($at) ? self::price() : self::regular();
    }

    /** Is the offer still open at $at (default now)? */
    public static function running(?int $at = null): bool {
        return ($at ?? self::now()) <= self::ends_at();
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

    /** Is the offer running at $at (default now)? Billing charges list_price(), so what the page shows is what checkout charges. */
    public static function active(?int $at = null): bool {
        return self::running($at);
    }

    /** A public page's cache lifetime, cut so nothing cached before the deadline is served after it (real clock, not the preview). */
    public static function cap_cache(int $seconds): int {
        $left = self::ends_at() - time();
        return ($left > 0 && $left < $seconds) ? max(1, $left + 1) : $seconds;
    }

    public static function saving(): int { return self::regular() - self::price(); }

    /** "October 31, 2026" and "Nov 1" for the copy. */
    public static function ends_label(): string { return (new DateTime(self::ends(), new DateTimeZone(self::TZ)))->format('F j, Y'); }
    /** The first day of the regular price: the day after the offer ends. */
    private static function regular_from(): DateTime { return (new DateTime(self::ends(), new DateTimeZone(self::TZ)))->modify('+1 day'); }
    public static function regular_from_short(): string { return self::regular_from()->format('M j'); }
    public static function regular_from_long(): string { return self::regular_from()->format('F j, Y'); }

    /** The admin panel's one-line status of the offer. */
    public static function admin_note(): string {
        return self::running()
            ? 'New signups pay $' . self::price() . ' a month until ' . self::ends_label() . ', 11:59 PM Eastern, then $' . self::regular() . '. Accounts keep the price they started at.'
            : 'Ended ' . self::ends_label() . '. New signups pay $' . self::regular() . '. Accounts keep the price they started at.';
    }

    /** One sentence for FAQs and plan summaries while the offer runs, or ''. */
    public static function sentence(): string {
        if (!self::active()) { return ''; }
        return 'Founding Creator offer: start Creator by ' . self::ends_label() . ' (11:59 PM Eastern) and pay $' . self::price() . ' a month for as long as you stay subscribed; the regular price from ' . self::regular_from_long() . ' is $' . self::regular() . ' a month.';
    }
}
