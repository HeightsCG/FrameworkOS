<?php
/**
 * The Creator Agreement gate. A creator who has not accepted it can't sell: no paid plans, paid posts,
 * bundles, paid services or events, no selling plan and no payout onboarding. Free content stays open.
 * One check (accepted) for every caller; API controllers stop with require().
 */
class CreatorAgreement {

    const VERSION = '2026-10-08';   // bump when the agreement text (AccountController::creator_terms) changes
    const URL     = '/account/settings?section=creator';
    const MESSAGE = 'Accept the Creator Agreement to continue';

    private static $cache = array();   // per request: user_id => bool

    /** Has this account (the owner creator) accepted the Creator Agreement? */
    public static function accepted(int $user_id): bool {
        if ($user_id <= 0) { return false; }
        if (!array_key_exists($user_id, self::$cache)) {
            $rows = (new UsersModel())->get_user_by_id($user_id);
            $at = (is_array($rows) && count($rows) === 1) ? (string) ($rows[0]['creator_agreement_accepted_at'] ?? '') : '';
            self::$cache[$user_id] = $at !== '';
        }
        return self::$cache[$user_id];
    }

    /** Forget the cached answer (after the agreement is accepted in this request). */
    public static function forget(int $user_id): void {
        unset(self::$cache[$user_id]);
    }

    /** API gate: answers like Controller::jsonError (need_agreement + where to accept it) and stops, unless accepted. */
    public static function require(int $user_id): void {
        if (self::accepted($user_id)) { return; }
        http_response_code(200);
        echo json_encode(array('success' => false, 'message' => self::MESSAGE, 'need_agreement' => true, 'url' => self::URL));
        exit;
    }
}
