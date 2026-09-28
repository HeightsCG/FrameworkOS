<?php
/**
 * Fan prices, one rule everywhere (PPV posts, paid messages, broadcasts, auto-messages, bundles, events, services,
 * and the same through MCP): the creator sets dollars, from $1 to $500, in 10-cent steps; it is stored as wallet
 * credits ($1 = 10 credits, so 1 credit = 10 cents and any whole credit count is a valid step). A price that
 * breaks the rule is rejected with a message, never quietly changed. Fans only ever see dollars (fmt).
 */
class Price {

    const CREDITS_PER_DOLLAR = 10;
    const MIN_CREDITS = 10;     // $1
    const MAX_CREDITS = 5000;   // $500

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

    /** Validate a price already in credits (MCP tools, the bundle API). Same limits, same messages. */
    public static function check_credits($credits, $allow_free = false): array {
        $c = (int) $credits;
        if ($c === 0 && $allow_free) { return array('ok' => true, 'credits' => 0, 'message' => ''); }
        if ($c < self::MIN_CREDITS) { return self::no('The lowest price is ' . self::fmt(self::MIN_CREDITS) . '.'); }
        if ($c > self::MAX_CREDITS) { return self::no('The highest price is ' . self::fmt(self::MAX_CREDITS) . '.'); }
        return array('ok' => true, 'credits' => $c, 'message' => '');
    }

    /** "$4.90": how every price and balance is shown to fans. */
    public static function fmt($credits): string {
        return self::fmt_cents((int) $credits * (100 / self::CREDITS_PER_DOLLAR));
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
