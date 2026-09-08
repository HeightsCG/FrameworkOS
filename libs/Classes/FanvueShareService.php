<?php
/**
 * Mirror a Creator Link Studio post to the creator's connected Fanvue account.
 *
 * Unlike social cross-posting (teaser only), Fanvue is a peer paid-content platform,
 * so the FULL post goes across: caption, the real media (uploaded to the creator's
 * Fanvue vault), audience, PPV price and the scheduled publish time.
 *
 * Best-effort like SocialShareService: never throws; returns
 * array('ok' => bool, 'uuid' => string, 'error' => string).
 */
class FanvueShareService {

    /** Pseudo account id used in share_accounts / scheduler social_accounts lists. */
    const ACCOUNT_ID = 'fanvue';

    /** Credits → cents for Fanvue's price field ($1 = 10 credits, see PPV model). */
    const CENTS_PER_CREDIT = 10;

    public static function share(array $user, array $post, $scheduled_iso = null){
        try {
            if (!Plan::can_social_post($user)) {
                return self::fail('Your plan does not include cross-posting.');
            }
            $accounts = new FanvueAccountsModel();
            $account  = $accounts->get_connected_for_user((int) $user['user_id']);
            if (!$account) { return self::fail('Fanvue is not connected.'); }

            $token = FanvueService::access_token_for($account);
            if ($token === '') { return self::fail('Fanvue session expired. Reconnect in Settings > Integrations.'); }

            // Media: originals of every ready, non-rejected asset, in post order.
            $uuids = array();
            foreach ((new PostsModel())->get_assets((int) $post['id']) as $a) {
                if (($a['status'] ?? '') !== 'ready' || !empty($a['deleted_at'])) { continue; }
                if (($a['moderation_status'] ?? '') === 'rejected') { continue; }
                $key = (string) ($a['original_key'] ?? '');
                if ($key === '') { $key = (string) ($a['display_key'] ?? ''); }
                if ($key === '') { continue; }
                $src   = S3Service::presigned_get_url($key, 600);
                $bytes = ($src !== '') ? @file_get_contents($src) : false;
                if ($bytes === false || $bytes === '') { continue; }
                $type  = ($a['type'] === 'video') ? 'video' : 'image';
                $uuids[] = FanvueService::upload_media($token, $bytes, basename($key), $type);
            }

            $audience = (($post['audience'] ?? 'free') === 'subscribers') ? 'subscribers' : 'followers-and-subscribers';
            $cents    = (($post['audience'] ?? '') === 'ppv') ? (int) ($post['ppv_price_credits'] ?? 0) * self::CENTS_PER_CREDIT : 0;

            $created = FanvueService::create_post($token, (string) ($post['caption'] ?? ''), $uuids, $audience, $cents, $scheduled_iso);
            $uuid    = (string) $created['uuid'];
            (new PostsModel())->set_fanvue_post_uuid((int) $user['user_id'], (int) $post['id'], $uuid);
            $accounts->set_error((int) $user['user_id'], '');
            return array('ok' => true, 'uuid' => $uuid, 'error' => '');

        } catch (\Throwable $e) {
            error_log('[fanvue share] post ' . (int) ($post['id'] ?? 0) . ': ' . $e->getMessage());
            try { (new FanvueAccountsModel())->set_error((int) $user['user_id'], $e->getMessage()); } catch (\Throwable $ignored) {}
            return self::fail($e->getMessage());
        }
    }

    private static function fail($msg){
        return array('ok' => false, 'uuid' => '', 'error' => (string) $msg);
    }

}
