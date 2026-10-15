<?php
/**
 * TikTok Events API (server side, beside the browser pixel in libs/Layout/google_analytics.php). The conversions TikTok
 * ads optimise on are sent from the server, where ad blockers cannot drop them:
 *
 *   signup           -> CompleteRegistration
 *   credits_bought   -> Purchase   (value = dollars paid)
 *   plan_paid        -> Purchase   (a creator plan bought or upgraded; renewals are not ad conversions and are skipped)
 *   membership_paid  -> Purchase   (a fan's paid membership)
 *   follow           -> AddToWishlist (following a creator; FollowsModel::follow calls from_event directly)
 *
 * ViewContent (a creator's page) is sent by the browser pixel from profile/view.php: signed-out profile reads are
 * cached pages with no server work to hook.
 *
 * Hooked into Analytics::event(), the one funnel every business event already goes through, so admin/staff activity
 * is left out the same way. Only web requests are sent (a cron renewal or auto top-up was not caused by an ad).
 * The request's IP, user agent, TikTok cookies (_ttp, and the ttclid the pixel block keeps in cls_ttclid) and page are
 * captured at the moment of the event; the call to TikTok runs from the queue so nobody waits on it.
 *
 * Settings, app.ini [global]:
 *   tiktok_events_token     the Events API access token (Events Manager > the pixel > Settings > Generate access token).
 *                           Empty = nothing is sent.
 *   tiktok_test_event_code  optional: events go to the pixel's Test Events tab instead of counting (use on dev).
 */
class TikTokEventJob {

    const ENABLED  = true;
    const PIXEL_ID = 'DB8ESIBC77U937USUOBG';
    const ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    private static function setting(string $key): string {
        $cfg = Main::get_config();
        return trim((string) ($cfg['global'][$key] ?? ''));
    }

    public static function configured(): bool {
        return self::ENABLED && self::setting('tiktok_events_token') !== '';
    }

    /** Called from Analytics::event() after the row is recorded. Never throws. */
    public static function from_event(string $name, int $user_id, array $opts): void {
        try {
            if (!self::configured() || php_sapi_name() === 'cli') { return; }
            if ($name === 'follow' && Analytics::excluded('', $user_id)) { return; }   // the other events arrive already filtered by Analytics::event
            $value = round(((int) ($opts['value_cents'] ?? 0)) / 100, 2);
            $meta  = (string) ($opts['meta'] ?? '');
            switch ($name) {
                case 'signup':          $ev = 'CompleteRegistration'; $props = array(); break;
                case 'credits_bought':  $ev = 'Purchase';  $props = array('currency' => 'USD', 'value' => $value, 'content_type' => 'product', 'content_name' => 'Credits'); break;
                case 'plan_paid':
                    $kind = strtok($meta, ' ');
                    if (!in_array($kind, array('subscribe', 'upgrade'), true)) { return; }
                    $ev = 'Purchase'; $props = array('currency' => 'USD', 'value' => $value, 'content_type' => 'product', 'content_name' => 'Creator plan'); break;
                case 'membership_paid': $ev = 'Purchase'; $props = array('currency' => 'USD', 'value' => $value, 'content_type' => 'product', 'content_name' => 'Membership'); break;
                case 'follow':          $ev = 'AddToWishlist'; $props = array('content_type' => 'product', 'content_id' => (string) (int) ($opts['creator_id'] ?? 0), 'content_name' => 'Creator'); break;
                default: return;
            }

            self::queue($ev, $name, $user_id, $props);
        } catch (\Throwable $e) {
            error_log('[tiktok_event] ' . $name . ': ' . $e->getMessage());
        }
    }

    /** Capture the request's person (IP, agent, TikTok cookies, page) now; the queue sends it. */
    private static function queue(string $ev, string $name, int $user_id, array $props): void {
        $path    = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        $webhook = (bool) preg_match('#^/webhook#', $path);   // Stripe's request: its IP, agent and cookies are not the person's
        $user = array();
        if (!$webhook) {
            $ip = GeoIp::client_ip();
            if ($ip !== '') { $user['ip'] = $ip; }
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            if ($ua !== '') { $user['user_agent'] = mb_substr($ua, 0, 500); }
            $ttp = (string) ($_COOKIE['_ttp'] ?? '');
            if ($ttp !== '' && preg_match('/^[A-Za-z0-9._-]{1,200}$/', $ttp)) { $user['ttp'] = $ttp; }
            $ttclid = (string) ($_COOKIE['cls_ttclid'] ?? '');
            if ($ttclid !== '' && preg_match('/^[A-Za-z0-9._-]{1,500}$/', $ttclid)) { $user['ttclid'] = $ttclid; }
        }
        $page = array();
        if (!$webhook) {
            $page['url'] = mb_substr(rtrim(Main::get_base_domain(), '/') . (string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 1000);
            $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            if ($ref !== '') { $page['referrer'] = mb_substr($ref, 0, 1000); }
        }

        $data = array('event' => $ev, 'event_time' => time(), 'event_id' => 'cls_' . $name . '_' . bin2hex(random_bytes(8)), 'user' => $user);
        if (!empty($page)) { $data['page'] = $page; }
        if (!empty($props)) { $data['properties'] = $props; }
        (new DatabaseJobQueue())->dispatch('tiktok_event', array('user_id' => $user_id, 'data' => $data));
    }

    /** Fan purchases that answer need_credits when the wallet is short: the person wanted it (AddToCart). */
    const PAYWALL_ACTIONS = array('ppv_unlock' => 'Post', 'bundle_unlock' => 'Bundle', 'message_unlock' => 'Message', 'service_purchase' => 'Service',
                                  'event_register' => 'Event ticket', 'event_replay_buy' => 'Event replay', 'live_tip' => 'Tip');

    /**
     * Called from BaseApiController::jsonSuccess/jsonError with the /api/<action> name and the response:
     *   buy_credits, buy_ai_credits, a paid subscribe_plan, billing_quote for a plan -> InitiateCheckout
     *   billing_card_save                                                           -> AddPaymentInfo
     *   a fan purchase short of credits (PAYWALL_ACTIONS + need_credits)            -> AddToCart
     */
    public static function from_api(string $action, bool $ok, array $data, array $post): void {
        try {
            if (!self::configured() || $action === '') { return; }
            $credits_usd = function ($credits) { return round(((int) $credits) / Price::CREDITS_PER_DOLLAR, 2); };
            $ev = ''; $props = array();
            if ($ok) {
                if ($action === 'buy_credits' || $action === 'buy_ai_credits') {
                    $ev = 'InitiateCheckout';
                    $props = array('currency' => 'USD', 'value' => round(((int) ($data['total_cents'] ?? 0)) / 100, 2), 'content_type' => 'product', 'content_name' => $action === 'buy_credits' ? 'Credits' : 'AI credits');
                } elseif ($action === 'subscribe_plan' && (string) ($data['url'] ?? '') !== '') {
                    $ev = 'InitiateCheckout'; $props = array('content_type' => 'product', 'content_name' => 'Membership');
                } elseif ($action === 'billing_quote' && isset($post['plan']) && (string) ($post['promo_code'] ?? '') === '' && in_array((string) ($data['mode'] ?? ''), array('subscribe', 'upgrade'), true)) {
                    $ev = 'InitiateCheckout';
                    $props = array('currency' => 'USD', 'value' => (float) preg_replace('/[^0-9.]/', '', (string) ($data['today'] ?? '0')), 'content_type' => 'product', 'content_name' => 'Creator plan');
                } elseif ($action === 'billing_card_save') {
                    $ev = 'AddPaymentInfo';
                }
            } elseif (!empty($data['need_credits']) && isset(self::PAYWALL_ACTIONS[$action])) {
                $ev = 'AddToCart';
                $props = array('content_type' => 'product', 'content_name' => self::PAYWALL_ACTIONS[$action]);
                if (isset($data['price'])) { $props += array('currency' => 'USD', 'value' => $credits_usd($data['price'])); }
            }
            if ($ev === '') { return; }
            $uid = (int) Session::get('user_id');
            if (Analytics::excluded('', $uid)) { return; }
            self::queue($ev, $action, $uid, $props);
        } catch (\Throwable $e) {
            error_log('[tiktok_event] api ' . $action . ': ' . $e->getMessage());
        }
    }

    /** Queue handler: add the hashed email + account id, then post to TikTok. */
    public static function handle(array $payload): string {
        if (!self::configured()) { return 'not configured'; }
        $data = (array) ($payload['data'] ?? array());
        if (($data['event'] ?? '') === '') { throw new InvalidArgumentException('event required'); }
        $uid = (int) ($payload['user_id'] ?? 0);
        $data['user'] = (array) ($data['user'] ?? array());
        if ($uid > 0) {
            $data['user']['external_id'] = hash('sha256', (string) $uid);
            $rows = (new UsersModel())->get_user_by_id($uid);
            $email = (is_array($rows) && count($rows) === 1) ? strtolower(trim((string) ($rows[0]['user_email'] ?? ''))) : '';
            if ($email !== '') { $data['user']['email'] = hash('sha256', $email); }
        }
        if (empty($data['user'])) { unset($data['user']); }

        $body = array('event_source' => 'web', 'event_source_id' => self::PIXEL_ID, 'data' => array($data));
        $test = self::setting('tiktok_test_event_code');
        if ($test !== '') { $body['test_event_code'] = $test; }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Access-Token: ' . self::setting('tiktok_events_token')),
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
        ));
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $res = json_decode((string) $raw, true);
        if ($raw === false || $http >= 500) { throw new RuntimeException('TikTok unreachable: ' . ($err !== '' ? $err : 'HTTP ' . $http)); }   // retried with back-off
        if (!is_array($res) || (int) ($res['code'] ?? -1) !== 0) {
            // A rejected event (bad token, bad field) will not succeed on retry: log it and finish.
            error_log('[tiktok_event] ' . $data['event'] . ' rejected: HTTP ' . $http . ' ' . mb_substr((string) $raw, 0, 300));
            return 'rejected: ' . mb_substr((string) ($res['message'] ?? $raw), 0, 200);
        }
        return 'sent ' . $data['event'];
    }
}
