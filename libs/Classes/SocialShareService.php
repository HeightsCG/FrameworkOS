<?php
/**
 * Cross-post the PROMOTIONAL version of a post to selected connected social accounts.
 * Best-effort — never throws. Always sends the public caption + a SAFE preview image
 * (blurred variant for subscriber posts) — never the subscriber media. No link is
 * appended: outbound links suppress reach on X and friends; the profile URL lives in the bio.
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
            $platforms = array();
            foreach ((new SocialAccountsModel())->get_connected_for_user((int) $user['user_id']) as $a) {
                $pfm = (string) $a['post_for_me_social_account_id'];
                if (in_array($pfm, $req, true)) { $valid[] = $pfm; $platforms[(string) $a['platform']] = (string) $a['platform']; }
            }
            if (empty($valid)) { return array('ok' => false, 'shared' => 0, 'error' => 'None of the selected social accounts are connected.'); }

            $promo = trim((string) $post['caption']);

            // Platforms with a hard length limit get a shortened caption so nothing is cut off mid-word.
            $platform_configurations = array();
            foreach ($platforms as $platform) {
                $fit = self::caption_for($platform, $promo);
                if ($fit !== $promo) { $platform_configurations[$platform] = array('caption' => $fit); }
            }

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

            $res = PostForMeService::create_post($valid, $promo, $media_urls, $scheduled_iso, false, $platform_configurations);
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

    /** Hard post-length limits per platform (Post for Me platform keys). Everything else is effectively unlimited. */
    const LIMITS = array('x' => 280, 'bluesky' => 300, 'threads' => 500);

    /** URLs count as a fixed 23 characters on X (t.co wrapping), whatever their real length. */
    const X_URL_WEIGHT = 23;

    /**
     * The caption for one platform: shortened at a word boundary (with an ellipsis) when it
     * exceeds the platform's limit. Platforms without a limit get the text unchanged.
     */
    public static function caption_for($platform, $caption): string {
        $platform = strtolower((string) $platform);
        $caption  = trim((string) $caption);
        if (!isset(self::LIMITS[$platform])) { return $caption; }
        $limit = self::LIMITS[$platform];
        if (self::length_for($platform, $caption) <= $limit) { return $caption; }

        $budget = $limit - self::length_for($platform, '…');

        // Take whole words while they fit, then cut mid-word only if the first word alone is too long.
        $out = '';
        foreach (preg_split('/(\s+)/u', $caption, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            if (self::length_for($platform, $out . $piece) > $budget) { break; }
            $out .= $piece;
        }
        $out = rtrim($out, " \n\t.,;:!?-–—");
        if ($out === '') {
            $chars = preg_split('//u', $caption, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($chars as $ch) {
                if (self::length_for($platform, $out . $ch) > $budget) { break; }
                $out .= $ch;
            }
        }
        return $out . '…';
    }

    /** Length the platform will count: X uses weighted characters with URLs at 23; others count code points. */
    public static function length_for($platform, $text): int {
        $text = (string) $text;
        if ($platform !== 'x') { return mb_strlen($text, 'UTF-8'); }
        $n = 0;
        // Each URL counts as 23 regardless of length.
        $text = preg_replace_callback('~https?://\S+~iu', function ($m) use (&$n) { $n += self::X_URL_WEIGHT; return ''; }, $text);
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            $light = ($cp <= 0x10FF) || ($cp >= 0x2000 && $cp <= 0x200D) || ($cp >= 0x2010 && $cp <= 0x201F) || ($cp >= 0x2032 && $cp <= 0x2037);
            $n += $light ? 1 : 2;
        }
        return $n;
    }
}
