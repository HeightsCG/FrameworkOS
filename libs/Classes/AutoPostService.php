<?php
/**
 * Executes one Scheduler automation: generate an on-brand image, write a caption,
 * create a post, publish it, and (best-effort) cross-post to social. Shared by the
 * CLI worker (cron/scheduler.php) and the "Run now" endpoint. Never throws — returns
 * ['ok'=>bool, 'post_id'=>int|null, 'message'=>string].
 */
class AutoPostService {

    public static function run_rule(array $rule, array $user){
        $creator_id = (int) ($user['user_id'] ?? 0);
        $topic      = trim((string) ($rule['topic'] ?? ''));
        if ($creator_id <= 0) { return self::fail(null, 'Missing creator.'); }
        if ($topic === '')    { return self::fail(null, 'This automation has no topic to generate from.'); }

        $cb        = (new CreatorBrandModel())->get_for_user($creator_id);
        $use_brand = !empty($rule['use_brand']);
        $prompt    = $use_brand ? BrandService::image_prompt($topic, $cb) : $topic;
        $size      = in_array(($rule['size'] ?? ''), array('square', 'portrait', 'landscape'), true) ? $rule['size'] : 'square';

        // 1) Generate the image: the creator's Eromify character in the scene, or a brand photo (OpenAI).
        if (($rule['image_source'] ?? 'brand') === 'character') {
            $acct = (new EromifyAccountsModel())->get_connected_for_user($creator_id);
            if (!$acct) { return self::fail(null, 'Eromify is not connected. Connect it in Settings > Integrations.'); }
            if (trim((string) ($rule['character_id'] ?? '')) === '') { return self::fail(null, 'This automation has no character selected.'); }
            $er = EromifyService::generate_character_image($acct['api_key'], (string) $rule['character_id'], $topic, $size);
            if (!$er['ok']) {
                (new EromifyAccountsModel())->set_error($creator_id, $er['error']);
                return self::fail(null, 'Character image failed: ' . $er['error']);
            }
            if ($er['credits_remaining'] !== null) { (new EromifyAccountsModel())->set_credits($creator_id, $er['credits_remaining']); }
            $bytes = self::fetch_bytes($er['url'], 30 * 1024 * 1024);
            if ($bytes === '') { return self::fail(null, 'Could not download the generated character image.'); }
            $info = @getimagesizefromstring($bytes);
            $ext  = ($info && $info[2] === IMAGETYPE_JPEG) ? 'jpg' : (($info && $info[2] === IMAGETYPE_WEBP) ? 'webp' : 'png');
            $gen  = array('ok' => true, 'bytes' => $bytes, 'ext' => $ext);
        } else {
            $gen = ImageGenService::generate($prompt, ImageGenService::dimensions($size));
        }
        if (empty($gen['ok'])) { return self::fail(null, 'Image generation failed: ' . ($gen['error'] ?? 'unknown error')); }

        // 2) Ingest the bytes as a vault asset (mirrors media_generateAction).
        $tmp = tempnam(sys_get_temp_dir(), 'sched');
        if ($tmp === false || file_put_contents($tmp, $gen['bytes']) === false) {
            return self::fail(null, 'Could not write the generated image.');
        }
        $media    = new MediaAssetsModel();
        $label    = 'Scheduled · ' . mb_substr($topic, 0, 40);
        $ext      = in_array($gen['ext'] ?? 'png', array('jpg', 'png', 'webp'), true) ? $gen['ext'] : 'png';
        $mime     = array('jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
        $asset_id = (int) $media->add($creator_id, 'image', $label . '.' . $ext, $mime[$ext], 'processing');
        if ($asset_id <= 0) { @unlink($tmp); return self::fail(null, 'Could not create the media asset.'); }
        $watermark = !empty($user['watermark_enabled']);
        $r = MediaService::process_image($creator_id, $asset_id, $tmp, $ext, $mime[$ext], $user, $watermark);
        @unlink($tmp);
        if (isset($r['error'])) { $media->set_failed($creator_id, $asset_id, $r['error']); return self::fail(null, 'Image processing failed: ' . $r['error']); }
        $media->set_ready($creator_id, $asset_id, $r);

        // 3) Caption (AI; fall back to the topic if the model is unavailable).
        $caption = BrandService::caption_for($topic, $use_brand ? $cb : array());
        if ($caption === '') { $caption = $topic; }

        // 4) Create the post.
        $audience = (($rule['audience'] ?? 'free') === 'subscribers') ? 'subscribers' : 'free';
        $posts    = new PostsModel();
        $post_id  = (int) $posts->create_draft($creator_id, $caption, $audience);
        if ($post_id <= 0) { return self::fail(null, 'Could not create the post.'); }
        $posts->update_fields($creator_id, $post_id, array(
            'caption'          => $caption,
            'audience'         => $audience,
            'tier_id'          => ($audience === 'subscribers' && (int) ($rule['tier_id'] ?? 0) > 0) ? (int) $rule['tier_id'] : 0,
            'comments_enabled' => !empty($rule['comments_enabled']) ? 1 : 0,
        ));
        $posts->set_assets($creator_id, $post_id, array($asset_id), $asset_id);

        // 5) Publish (the generated asset is ready, so validation is satisfied).
        if ($posts->count_missing_assets($post_id) > 0) { return self::fail($post_id, 'Post had missing media; left unpublished.'); }
        $posts->set_state($creator_id, $post_id, 'published');
        $post = $posts->get_by_id($post_id);

        // 6) Cross-post to social (best-effort — never fails the run, but the
        // outcome is surfaced in the message so a silent failure is visible).
        $accounts = json_decode((string) ($rule['social_accounts'] ?? '[]'), true);
        $message  = 'Published a new post.';
        if ($post && is_array($accounts) && !empty($accounts)) {
            $share = SocialShareService::share($user, $post, array_map('strval', $accounts), null);
            if (is_array($share)) {
                if (!empty($share['ok']) && (int) ($share['shared'] ?? 0) > 0) {
                    $n = (int) $share['shared'];
                    $message .= ' Shared to ' . $n . ' social account' . ($n === 1 ? '' : 's') . '.';
                } elseif (empty($share['ok'])) {
                    $message .= ' Social sharing failed: ' . (string) ($share['error'] ?? 'unknown error');
                }
            }
        }

        return array('ok' => true, 'post_id' => $post_id, 'message' => $message);
    }

    /** Download a generated image (https only) into memory, capped. '' on failure. */
    private static function fetch_bytes($url, $max){
        if (strpos((string) $url, 'https://') !== 0) { return ''; }
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf, $max) { $buf .= $chunk; return (strlen($buf) > $max) ? 0 : strlen($chunk); },
        ));
        curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ($code === 200 && strlen($buf) <= $max) ? $buf : '';
    }

    private static function fail($post_id, $message){
        return array('ok' => false, 'post_id' => $post_id, 'message' => $message);
    }
}
