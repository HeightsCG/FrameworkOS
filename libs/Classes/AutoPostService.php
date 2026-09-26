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
        if (!Plan::can_use_creator_features($user)) { return self::fail(null, 'Your plan is inactive. Choose a plan to keep automations running.'); }
        if (Plan::is_locked($user, 'automations', (int) ($rule['id'] ?? 0))) { return self::fail(null, Plan::locked_message($user, 'automations')); }
        if ($topic === '')    { return self::fail(null, 'This automation has no topic to generate from.'); }

        $cb        = (new CreatorBrandModel())->get_for_user($creator_id);
        $use_brand = !empty($rule['use_brand']);
        $ai_assist = !isset($rule['ai_assist']) || (int) $rule['ai_assist'] === 1;
        // The scene this run shows: ONE line of the saved prompt, exactly as typed. The image and the caption both use
        // it, so they describe the same picture. Lines that apply to every photo go with it; caption rules go to the caption.
        $parts     = AutomationPrompt::split($topic);
        list($scene, $history) = AutomationPrompt::pick($parts['scenes'], $rule['scene_history'] ?? '');
        // Scene first: the image model weighs the start of the prompt most, and the pose and expression live there.
        $image_brief = trim(rtrim($scene, " .") . (empty($parts['shared']) ? '' : '. ' . implode(' ', $parts['shared'])));
        if ($image_brief === '') { return self::fail(null, 'This automation has no scene to generate from.'); }
        $size      = in_array(($rule['size'] ?? ''), array('square', 'portrait', 'landscape'), true) ? $rule['size'] : 'square';

        // 1) Generate the image: a trained influencer, or a brand photo (OpenAI).
        $asset_id = 0;
        if (($rule['image_source'] ?? 'brand') === 'influencer') {
            $ir = InfluencerJobService::run_for_rule($rule, $user, $image_brief, $size);
            if (empty($ir['ok'])) { return self::fail(null, 'Influencer image failed: ' . (string) $ir['error']); }
            $asset_id = (int) $ir['asset_id'];
            $gen = array('ok' => true);
        } else {
            $pay = Plan::charge_ai($user, 'image', 'Automation image: ' . mb_substr($topic, 0, 60));
            if (empty($pay['ok'])) { return self::fail(null, $pay['message']); }
            $prompt = $use_brand ? BrandService::image_prompt($image_brief, $cb) : $image_brief;
            error_log('[automation] rule ' . (int) ($rule['id'] ?? 0) . ' image prompt: ' . $prompt);
            $gen = ImageGenService::generate($prompt, $size);
            if (empty($gen['ok'])) { (new AiCreditsModel())->apply_delta($creator_id, (int) $pay['price'], 'refund', 'Refund: automation image failed'); }
        }
        if (empty($gen['ok'])) { return self::fail(null, 'Image generation failed: ' . ($gen['error'] ?? 'unknown error')); }
        if ((int) ($rule['id'] ?? 0) > 0) { (new SchedulerRulesModel())->set_scene_history((int) $rule['id'], $history); }

        // 2) Ingest the bytes as a vault asset (mirrors media_generateAction). An influencer run has already landed its asset.
        if ($asset_id <= 0) {
        // The image was paid for; if it can't be stored, give the credits back (once).
        $refund = function () use ($creator_id, $pay) {
            (new AiCreditsModel())->apply_delta($creator_id, (int) $pay['price'], 'refund', 'Refund: automation image could not be saved');
        };
        $tmp = tempnam(sys_get_temp_dir(), 'sched');
        if ($tmp === false || file_put_contents($tmp, $gen['bytes']) === false) {
            $refund(); return self::fail(null, 'Could not write the generated image.');
        }
        $media    = new MediaAssetsModel();
        $label    = 'Scheduled · ' . mb_substr($topic, 0, 40);
        $ext      = in_array($gen['ext'] ?? 'png', array('jpg', 'png', 'webp'), true) ? $gen['ext'] : 'png';
        $mime     = array('jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
        $asset_id = (int) $media->add($creator_id, 'image', $label . '.' . $ext, $mime[$ext], 'processing');
        if ($asset_id <= 0) { @unlink($tmp); $refund(); return self::fail(null, 'Could not create the media asset.'); }
        $watermark = !empty($user['watermark_enabled']);
        $r = MediaService::process_image($creator_id, $asset_id, $tmp, $ext, $mime[$ext], $user, $watermark);
        @unlink($tmp);
        if (isset($r['error'])) { $media->set_failed($creator_id, $asset_id, $r['error']); $refund(); return self::fail(null, 'Image processing failed: ' . $r['error']); }
        $media->set_ready($creator_id, $asset_id, $r);
        }

        // 3) Caption: AI, written from the scene line and held to the creator's caption rules, then cleaned in code
        // (lowercase, no em dashes, one emoji at most) so the house style never depends on the model obeying.
        $fixed     = trim((string) ($rule['caption_text'] ?? ''));
        $style     = (($rule['image_source'] ?? 'brand') === 'influencer') ? 'tease' : '';
        $caption   = $ai_assist ? AutomationPrompt::clean_caption(BrandService::caption_for($scene !== '' ? $scene : $image_brief, $use_brand ? $cb : array(), $style, $parts['caption']))
                                : $fixed;
        if ($caption === '') { $caption = $fixed; }   // never publish the saved prompt as a caption

        // 4) Create the post.
        $audience = (($rule['audience'] ?? 'free') === 'subscribers') ? 'subscribers' : 'free';
        $posts    = new PostsModel();
        $post_id  = (int) $posts->create_draft($creator_id, $caption, $audience);
        if ($post_id <= 0) { return self::fail(null, 'Could not create the post.'); }
        $posts->update_fields($creator_id, $post_id, array(
            'caption'          => $caption,
            'audience'         => $audience,
            'tier_id'          => 0,
            'tier_ids'         => ($audience === 'subscribers' && (int) ($rule['tier_id'] ?? 0) > 0) ? array((int) $rule['tier_id']) : array(),
            'comments_enabled' => !empty($rule['comments_enabled']) ? 1 : 0,
        ));
        $posts->set_assets($creator_id, $post_id, array($asset_id), $asset_id);

        // 5) Publish (the generated asset is ready, so validation is satisfied).
        if ($posts->count_missing_assets($post_id) > 0) { return self::fail($post_id, 'Post had missing media; left unpublished.'); }
        $posts->set_state($creator_id, $post_id, 'published');
        PostNotifier::published($creator_id, $post_id);
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

    private static function fail($post_id, $message){
        return array('ok' => false, 'post_id' => $post_id, 'message' => $message);
    }
}
