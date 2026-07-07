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

        // 1) Generate the image.
        $gen = ImageGenService::generate($prompt, ImageGenService::dimensions($size));
        if (empty($gen['ok'])) { return self::fail(null, 'Image generation failed: ' . ($gen['error'] ?? 'unknown error')); }

        // 2) Ingest the bytes as a vault asset (mirrors media_generateAction).
        $tmp = tempnam(sys_get_temp_dir(), 'sched');
        if ($tmp === false || file_put_contents($tmp, $gen['bytes']) === false) {
            return self::fail(null, 'Could not write the generated image.');
        }
        $media    = new MediaAssetsModel();
        $label    = 'Scheduled · ' . mb_substr($topic, 0, 40);
        $asset_id = (int) $media->add($creator_id, 'image', $label . '.png', 'image/png', 'processing');
        if ($asset_id <= 0) { @unlink($tmp); return self::fail(null, 'Could not create the media asset.'); }
        $watermark = !empty($user['watermark_enabled']);
        $r = MediaService::process_image($creator_id, $asset_id, $tmp, 'png', 'image/png', $user, $watermark);
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

        // 6) Cross-post to social (best-effort — never fails the run).
        $accounts = json_decode((string) ($rule['social_accounts'] ?? '[]'), true);
        if ($post && is_array($accounts) && !empty($accounts)) {
            SocialShareService::share($user, $post, array_map('strval', $accounts), null);
        }

        return array('ok' => true, 'post_id' => $post_id, 'message' => 'Published a new post.');
    }

    private static function fail($post_id, $message){
        return array('ok' => false, 'post_id' => $post_id, 'message' => $message);
    }
}
