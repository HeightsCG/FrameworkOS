<?php
/**
 * Credit amounts ($1 = 10 credits, CREDITS_PER_DOLLAR, the same rate as AI credits), the affiliate payout minimum, and
 * the price validators still used by the post and message price fields until those are removed.
 */
class Price {

    const CREDITS_PER_DOLLAR = 10;
    const MIN_CREDITS = 10;     // $1
    const MAX_CREDITS = 5000;   // $500
    const PAYOUT_MIN_CENTS = 2500;   // smallest affiliate payout, $25 (Affiliates; paid by staff)
    const PAYOUT_MIN_LABEL = '$' . (self::PAYOUT_MIN_CENTS / 100);

    /**
     * Parse a dollar amount typed by the creator ("4.90", "$5", "12"). Returns ['ok', 'credits', 'message'].
     * $allow_free: 0 means free (events and services can be free; a paid item can't).
     */
    public static function from_dollars($raw, $allow_free = false): array {
        $s = trim(str_replace(array('$', ',', ' '), '', html_entity_decode((string) $raw, ENT_QUOTES, 'UTF-8')));
        if ($s === '' || !is_numeric($s)) { return self::no('Enter a price in dollars, like 4.90.'); }
        $cents = (int) round(((float) $s) * 100);
        if ($cents === 0 && $allow_free) { return array('ok' => true, 'credits' => 0, 'message' => ''); }
        if ($cents % 10 !== 0) {
            $down = intdiv($cents, 10) * 10; $up = $down + 10;
            return self::no('Prices go in 10¢ steps. Use ' . self::fmt_cents($down) . ' or ' . self::fmt_cents($up) . '.');
        }
        return self::check_credits(intdiv($cents, 10), $allow_free);
    }

    /**
     * Parse a price the creator typed in credits ("49", "1,200"). Whole credits only, 10 to 5,000 ($1 to $500).
     * Returns ['ok', 'credits', 'message']; $allow_free: 0 means free (events and services).
     */
    public static function from_credits($raw, $allow_free = false): array {
        $s = trim(str_replace(array(',', ' '), '', html_entity_decode((string) $raw, ENT_QUOTES, 'UTF-8')));
        $s = preg_replace('/credits?$/i', '', $s);
        if ($s === '' || !preg_match('/^\d+(\.0+)?$/', $s)) {
            return self::no(is_numeric($s) ? 'Prices are whole credits, like 49.' : 'Enter a price in credits, like 49.');
        }
        return self::check_credits((int) $s, $allow_free);
    }

    /** Validate a price already in credits. The same limits and messages everywhere. */
    public static function check_credits($credits, $allow_free = false): array {
        $c = (int) $credits;
        if ($c === 0 && $allow_free) { return array('ok' => true, 'credits' => 0, 'message' => ''); }
        if ($c < self::MIN_CREDITS) { return self::no('The lowest price is ' . self::credits(self::MIN_CREDITS) . '.'); }
        if ($c > self::MAX_CREDITS) { return self::no('The highest price is ' . self::credits(self::MAX_CREDITS) . '.'); }
        return array('ok' => true, 'credits' => $c, 'message' => '');
    }

    /** "49 credits": how a credit amount is shown inside the platform. */
    public static function credits($credits): string {
        $n = (int) $credits;
        return number_format($n) . (abs($n) === 1 ? ' credit' : ' credits');
    }

    /** Dollars for an edit field ("4.90"), so re-saving never changes the price. */
    public static function input($credits): string {
        return number_format((int) $credits / self::CREDITS_PER_DOLLAR, 2, '.', '');
    }

    private static function fmt_cents($cents): string {
        return ($cents < 0 ? '-' : '') . '$' . number_format(abs((int) $cents) / 100, 2);
    }

    private static function no($msg): array {
        return array('ok' => false, 'credits' => 0, 'message' => (string) $msg);
    }
}
