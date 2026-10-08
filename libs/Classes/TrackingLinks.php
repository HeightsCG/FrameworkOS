<?php
/**
 * Inbound tracking links. /go/<code> (GoController) sets the cls_tl cookie {code, creator_id, t} for 30 days,
 * last click wins; attribute() logs a conversion against that link when it is for the same creator.
 * Codes starting with "cls" are system links: clssim<creator_id> counts clicks from the "More Creators Like This" block.
 */
class TrackingLinks {

    const COOKIE = 'cls_tl';
    const DAYS = 30;
    const SIMILAR_LABEL = 'More Creators Like This';

    /** Letters and digits, 6 to 32. */
    public static function valid_code($code): bool {
        return (bool) preg_match('/^[A-Za-z0-9]{6,32}$/', (string) $code);
    }

    /** System code for clicks on a creator from the "More Creators Like This" block. */
    public static function similar_code($creator_id): string {
        return 'clssim' . (int) $creator_id;
    }

    /** A deeper public path on the creator's page ('/events/3'), or '' when it isn't one. */
    public static function clean_path($p): string {
        $p = trim((string) $p);
        if ($p === '' || $p === '/') { return ''; }
        if ($p[0] !== '/') { $p = '/' . $p; }
        return (strlen($p) <= 200 && preg_match('#^(/[A-Za-z0-9_-]+)+$#', $p)) ? $p : '';
    }

    /** Remember the click (first party, 30 days, last click wins). */
    public static function set_cookie(array $link): void {
        $val = json_encode(array('code' => (string) $link['code'], 'creator_id' => (int) $link['creator_id'], 't' => time()));
        setcookie(self::COOKIE, $val, array('expires' => time() + self::DAYS * 86400, 'path' => '/', 'secure' => Main::site_protocol() === 'https://', 'httponly' => true, 'samesite' => 'Lax'));
    }

    /**
     * The profile page on any host (platform or the creator's own domain) with ?tl=<code> from /go: when the code is
     * one of this creator's links, set cls_tl here too, so purchases on their domain can read it. Never throws.
     */
    public static function from_query($creator_id): void {
        try {
            $code = (string) ($_GET['tl'] ?? '');
            if ($code === '' || !self::valid_code($code) || headers_sent()) { return; }
            $link = (new TrackingLinksModel())->get_by_code($code);
            if ($link && (int) $link['creator_id'] === (int) $creator_id) { self::set_cookie($link); }
        } catch (\Throwable $e) {
            error_log('[tracking_links] tl: ' . $e->getMessage());
        }
    }

    /**
     * Log a conversion for the link in the visitor's cls_tl cookie. $creator_id is whose follow/sale this is; it must
     * match the cookie's creator (0 = any, for a signup). Never throws: a tracking problem never blocks the action.
     */
    public static function attribute($creator_id, $kind, $user_id, $amount = 0, $ref_table = '', $ref_id = 0): void {
        try {
            $c = json_decode((string) ($_COOKIE[self::COOKIE] ?? ''), true);
            if (!is_array($c) || !self::valid_code($c['code'] ?? '')) { return; }
            if ((int) ($c['t'] ?? 0) < time() - self::DAYS * 86400) { return; }
            $owner = (int) ($c['creator_id'] ?? 0);
            if ($owner <= 0 || ((int) $creator_id > 0 && (int) $creator_id !== $owner) || (int) $user_id === $owner) { return; }
            $m = new TrackingLinksModel();
            $link = $m->get_by_code((string) $c['code']);
            if (!$link || (int) $link['creator_id'] !== $owner) { return; }
            if ($m->has_event((int) $link['id'], (string) $kind, (int) $user_id, (string) $ref_table, (int) $ref_id)) { return; }
            $m->record_event((int) $link['id'], $owner, (string) $kind, (int) $user_id, (int) $amount, (string) $ref_table, (int) $ref_id);
        } catch (\Throwable $e) {
            error_log('[tracking_links] ' . $kind . ': ' . $e->getMessage());
        }
    }
}
