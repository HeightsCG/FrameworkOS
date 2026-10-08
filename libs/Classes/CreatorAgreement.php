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

    // MCP tools that sell. Some only count when the item is paid (see mcp_sells); the rest are always selling.
    const MCP_GATED = array(
        'create_plan', 'update_plan', 'set_plan_active', 'create_bundle', 'update_bundle', 'set_bundle_active',
        'create_post', 'publish_post', 'schedule_post', 'create_service', 'update_service', 'create_event', 'update_event',
        'create_promo_code', 'update_promo_code', 'set_promo_active',
    );
    const MCP_MESSAGE = 'Accept the Creator Agreement in Settings to continue';

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

    /** A pay-per-view post with a price, or one gated to a paid membership tier (any tier: the creator sells one). */
    public static function post_is_paid(array $post): bool {
        $aud = (string) ($post['audience'] ?? '');
        if ($aud === 'ppv') { return (int) ($post['ppv_price_credits'] ?? 0) > 0; }
        if ($aud !== 'subscribers') { return false; }
        $model = new CreatorPlansModel();
        if ((int) ($post['tier_id'] ?? 0) > 0) {
            $tier = $model->get_one((int) $post['creator_id'], (int) $post['tier_id']);
            return $tier ? (int) $tier['price_cents'] > 0 : false;
        }
        foreach ((array) $model->get_for_user((int) $post['creator_id']) as $p) { if ((int) $p['price_cents'] > 0) { return true; } }
        return false;
    }

    /** Would this MCP tool call sell something? Free and unpaid variants pass. */
    public static function mcp_sells(string $name, int $creator_id, array $a): bool {
        $id = (int) ($a['id'] ?? 0);
        switch ($name) {
            case 'create_plan':
                return (float) ($a['price'] ?? 0) > 0;
            case 'update_plan':
                if (array_key_exists('price', $a)) { return (float) $a['price'] > 0; }
                $row = (new CreatorPlansModel())->get_one($creator_id, $id);
                return $row ? (int) $row['price_cents'] > 0 : false;
            case 'set_plan_active':
                if (empty($a['active'])) { return false; }
                $row = (new CreatorPlansModel())->get_one($creator_id, $id);
                return $row ? (int) $row['price_cents'] > 0 : false;
            case 'create_service': case 'update_service': case 'create_event': case 'update_event':
                return (int) ($a['price_credits'] ?? 0) > 0;
            case 'create_post': {
                $aud = (string) ($a['audience'] ?? 'free');
                return self::post_is_paid(array('creator_id' => $creator_id, 'audience' => $aud, 'ppv_price_credits' => (int) ($a['ppv_price_credits'] ?? 0), 'tier_id' => (int) ($a['tier_id'] ?? 0)));
            }
            case 'publish_post': case 'schedule_post': {
                $post = (new PostsModel())->get_one($creator_id, $id);
                return $post ? self::post_is_paid($post) : false;
            }
            case 'set_bundle_active':
                return !empty($a['active']);   // a bundle is always priced
            default:
                return true;   // bundles, promo codes: selling tools
        }
    }

    /** MCP gate: throws the refusal (the dispatcher reports it as a tool error) when a selling tool is called before the agreement is accepted. */
    public static function mcp_require(string $name, int $creator_id, array $a): void {
        if (!in_array($name, self::MCP_GATED, true)) { return; }
        if (self::accepted($creator_id)) { return; }
        if (self::mcp_sells($name, $creator_id, $a)) { throw new RuntimeException(self::MCP_MESSAGE . ' (' . self::URL . ')'); }
    }
}
