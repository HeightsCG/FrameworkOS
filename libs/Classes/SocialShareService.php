<?php
/**
 * Cross-post the PROMOTIONAL version of a post to selected connected social accounts.
 * Best-effort — never throws. Always sends the public caption + a SAFE preview image
 * (blurred variant for subscriber posts) — never the subscriber media. Free posts send all
 * their media as a carousel, trimmed per platform (see media_for). No link is
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
        if ($fanvue && class_exists('DatabaseJobQueue') && FanvueShareService::has_video($post)) {
            // Videos upload in the background so publishing doesn't wait on them; a failure arrives as a notification.
            (new DatabaseJobQueue())->dispatch('fanvue_share', array('user_id' => (int) $user['user_id'], 'post_id' => (int) $post['id'], 'scheduled_iso' => $scheduled_iso), 'fanvue_share:' . (int) $post['id']);
            $res['ok']     = empty($account_ids) ? true : $res['ok'];
            $res['shared'] = (int) $res['shared'] + 1;
        } elseif ($fanvue) {
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

            $assets = (new PostsModel())->get_assets((int) $post['id']);
            $cover = null;
            foreach ($assets as $a) { if ((int) $a['is_cover'] === 1) { $cover = $a; break; } }
            if (!$cover && !empty($assets)) { $cover = $assets[0]; }

            // Uploaded items as array('url' => ..., 'video' => bool), cover first.
            $items = array();
            if ($cover) {
                if ($post['audience'] === 'subscribers' || !self::sfw($cover)) {
                    // Teaser only (members-only post, or an adult / not-yet-cleared image on mainstream socials):
                    // the blurred still, never the media itself.
                    $items = self::push_item((string) ($cover['blurred_key'] ?? ''), 'image/jpeg', false, $items);
                } else {
                    $items = self::push_asset($cover, $items);
                }
            }
            // Free posts go out as a carousel: the rest of the post's media after the cover.
            // Adult-flagged or blocked extras are left off.
            if ($cover && $post['audience'] === 'free') {
                foreach ($assets as $a) {
                    if (count($items) >= self::MAX_MEDIA) { break; }
                    if ((int) $a['asset_id'] === (int) $cover['asset_id'] || !empty($a['deleted_at'])) { continue; }
                    if (!self::sfw($a)) { continue; }
                    $items = self::push_asset($a, $items);
                }
            }

            // Each platform gets only as much of the carousel as it accepts.
            $media_urls = array_column($items, 'url');
            foreach ($platforms as $platform) {
                $fit = array_column(self::media_for($platform, $items), 'url');
                if ($fit !== $media_urls) {
                    $platform_configurations[$platform]['media'] = array_map(function ($u) { return array('url' => $u); }, $fit);
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

    /** Most media items sent with one cross-post (Instagram's carousel limit). */
    const MAX_MEDIA = 10;

    /** Per-platform item limits; platforms not listed take one item. */
    const MEDIA_LIMITS = array(
        'instagram' => 10, 'facebook' => 10, 'threads' => 10,
        'x' => 4, 'bluesky' => 4, 'linkedin' => 9, 'tiktok' => 10, 'tiktok_business' => 10,
    );

    /** Platforms whose carousels can mix photos and videos; the rest take several photos or one video. */
    const MIXED_MEDIA = array('instagram', 'facebook', 'threads');

    /** The slice of the uploaded items one platform accepts (the cover is always first). */
    public static function media_for($platform, array $items): array {
        $platform = strtolower((string) $platform);
        $limit    = self::MEDIA_LIMITS[$platform] ?? 1;
        if (count($items) > 1 && !in_array($platform, self::MIXED_MEDIA, true) && in_array(true, array_column($items, 'video'), true)) {
            $limit = 1;
        }
        return array_slice($items, 0, $limit);
    }

    /** Upload one asset: images as the display rendition, videos as the clip (falling back to the poster). */
    private static function push_asset(array $asset, array $items){
        if ((string) $asset['type'] !== 'video') {
            return self::push_item((string) ($asset['display_key'] ?? ''), 'image/jpeg', false, $items);
        }
        $before = count($items);
        $items  = self::push_item((string) ($asset['original_key'] ?? ''), (string) ($asset['mime'] ?: 'video/mp4'), true, $items);
        if (count($items) === $before) {
            error_log('[social share] video upload failed for asset ' . (int) $asset['asset_id'] . ', sending the poster instead');
            $items = self::push_item((string) ($asset['poster_key'] ?? ''), 'image/jpeg', false, $items);
        }
        return $items;
    }

    /** push_media, keeping track of whether the item is a video. */
    private static function push_item($key, $mime, $is_video, array $items){
        $urls = self::push_media($key, $mime, array());
        if (!empty($urls)) { $items[] = array('url' => $urls[0], 'video' => (bool) $is_video); }
        return $items;
    }

    /** Upload one S3 object to Post for Me's media store; returns $media_urls with the new media_url appended on success. */
    private static function push_media($key, $mime, array $media_urls){
        if ($key === '') { return $media_urls; }
        $tmp = tempnam(sys_get_temp_dir(), 'share');
        if ($tmp === false || !S3Service::get_private_to_file($key, $tmp)) { @unlink($tmp); return $media_urls; }
        $size = (int) filesize($tmp);
        if ($size <= 0) { @unlink($tmp); return $media_urls; }
        $up = PostForMeService::create_upload_url();
        if (!is_array($up) || count($up) !== 2) { @unlink($tmp); return $media_urls; }
        list($media_url, $upload_url) = $up;
        $fh = fopen($tmp, 'rb');
        $ch = curl_init($upload_url);
        curl_setopt_array($ch, array(
            CURLOPT_PUT => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => $size,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => array('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream')),
        ));
        curl_exec($ch); $ucode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        fclose($fh); @unlink($tmp);
        if ($ucode >= 200 && $ucode < 300) { $media_urls[] = $media_url; }
        else { error_log('[social share] media upload HTTP ' . $ucode . ' ' . $err); }
        return $media_urls;
    }

    /** Safe for mainstream socials: media (image, or a video's poster frame) cleared as non-adult. */
    private static function sfw(array $a): bool{
        $st = (string) ($a['moderation_status'] ?? '');
        if ($st === 'n_a') { return true; }   // video uploaded before videos were moderated
        return $st === 'approved' && empty($a['is_adult']);
    }

}
