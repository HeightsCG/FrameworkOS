<?php
/**
 * Cross-post the PROMOTIONAL version of a post to selected connected social accounts.
 * Best-effort — never throws. Always sends the public caption + a SAFE preview image
 * (blurred variant for subscriber posts) + a link back — never the subscriber media.
 *
 * Extracted from ApiCreatorStudioController so both the manual publish/schedule flow and the
 * Scheduler worker can share one implementation.
 */
class SocialShareService {

    /**
     * Returns array('ok'=>bool, 'shared'=>int, 'error'=>string) so callers can surface the outcome.
     * The pseudo id 'fanvue' in $account_ids routes to FanvueShareService (full-post mirror);
     * everything else is a Post for Me social account id.
     */
    public static function share(array $user, array $post, array $account_ids, $scheduled_iso = null){
        $account_ids = array_values(array_map('strval', $account_ids));
        $fanvue      = in_array(FanvueShareService::ACCOUNT_ID, $account_ids, true);
        $account_ids = array_values(array_diff($account_ids, array(FanvueShareService::ACCOUNT_ID)));

        $res = self::share_social($user, $post, $account_ids, $scheduled_iso);
        if ($fanvue) {
            $fv = FanvueShareService::share($user, $post, $scheduled_iso);
            if (!empty($fv['ok'])) {
                $res['ok']     = empty($account_ids) ? true : $res['ok'];
                $res['shared'] = (int) $res['shared'] + 1;
            } else {
                $res['ok']    = false;
                $res['error'] = trim((string) $res['error'] . ' ' . (string) $fv['error']);
            }
        }
        return $res;
    }

    private static function share_social(array $user, array $post, array $account_ids, $scheduled_iso = null){
        try {
            if (empty($account_ids)) { return array('ok' => true, 'shared' => 0, 'error' => ''); }
            if (!Plan::can_social_post($user)) { return array('ok' => false, 'shared' => 0, 'error' => 'Your plan does not include social posting.'); }
            $valid = array(); $req = array_map('strval', $account_ids);
            foreach ((new SocialAccountsModel())->get_connected_for_user((int) $user['user_id']) as $a) {
                $pfm = (string) $a['post_for_me_social_account_id'];
                if (in_array($pfm, $req, true)) { $valid[] = $pfm; }
            }
            if (empty($valid)) { return array('ok' => false, 'shared' => 0, 'error' => 'None of the selected social accounts are connected.'); }

            $link  = 'https://' . Main::public_domain() . '/@' . (string) ($user['u_name'] ?? '');
            $cap   = trim((string) $post['caption']);
            $promo = ($cap !== '' ? $cap . "\n\n" : '') . 'See more: ' . $link;

            $media_urls = array();
            $assets = (new PostsModel())->get_assets((int) $post['id']);
            $cover = null;
            foreach ($assets as $a) { if ((int) $a['is_cover'] === 1) { $cover = $a; break; } }
            if (!$cover && !empty($assets)) { $cover = $assets[0]; }
            if ($cover) {
                $variant = ($post['audience'] === 'subscribers') ? 'blurred' : (($cover['type'] === 'video') ? 'poster' : 'display');
                $col = array('blurred' => 'blurred_key', 'poster' => 'poster_key', 'display' => 'display_key');
                $key = (string) ($cover[$col[$variant]] ?? ($cover['blurred_key'] ?? ''));
                if ($key !== '') {
                    $src = S3Service::presigned_get_url($key, 300);
                    $bytes = ($src !== '') ? @file_get_contents($src) : false;
                    if ($bytes !== false && $bytes !== '') {
                        $up = PostForMeService::create_upload_url();
                        if (is_array($up) && count($up) === 2) {
                            list($media_url, $upload_url) = $up;
                            $ch = curl_init($upload_url);
                            curl_setopt_array($ch, array(
                                CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $bytes,
                                CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array('Content-Type: image/jpeg'),
                            ));
                            curl_exec($ch); $ucode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                            if ($ucode >= 200 && $ucode < 300) { $media_urls[] = $media_url; }
                        }
                    }
                }
            }

            $res = PostForMeService::create_post($valid, $promo, $media_urls, $scheduled_iso, false);
            if (is_array($res) && isset($res['id'])) {
                (new SocialPostsModel())->create(
                    (int) $user['user_id'], (string) $res['id'], $promo,
                    (string) ($res['status'] ?? 'scheduled'), $scheduled_iso, $valid, (int) $post['id']
                );
                return array('ok' => true, 'shared' => count($valid), 'error' => '');
            }
            $err = is_array($res) ? (string) ($res['_error'] ?? 'unknown error') : 'no response from Post for Me';
            error_log('[social share] create_post failed: ' . $err);
            return array('ok' => false, 'shared' => 0, 'error' => $err);
        } catch (\Throwable $e) {
            error_log('[social share] failed: ' . $e->getMessage());
            return array('ok' => false, 'shared' => 0, 'error' => $e->getMessage());
        }
    }
}
