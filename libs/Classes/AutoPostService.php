<?php
/**
 * Executes one Scheduler automation: generate an on-brand image, write a caption,
 * create a post, publish it, and (best-effort) cross-post to social. Shared by the
 * CLI worker (cron/scheduler.php) and the "Run now" endpoint. Never throws — returns
 * ['ok'=>bool, 'post_id'=>int|null, 'message'=>string, 'status'?=>'rendering'].
 * A video automation animates the still: the run ends 'rendering', and finish_video() publishes the post
 * when the video job lands (a video takes minutes, longer than a run should hold the worker).
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
        $size      = Aspect::normalize($rule['size'] ?? '', '1:1');
        $is_inf    = (($rule['image_source'] ?? 'brand') === 'influencer');
        $is_video  = ((string) ($rule['media_type'] ?? 'image') === 'video');

        // No image or video model is ever asked to draw words: they misspell them. The line goes on afterwards in code.
        // Influencer video: the AI step plans this run from the whole brief (a still with no text, the hook line, one action).
        // Image (and brand video): words the scene asks for come out of the prompt and are overlaid on the result.
        $plan = null; $overlay = '';
        $meta = array('image_prompt' => '', 'hook_line' => '', 'motion_action' => '', 'image_model' => '', 'video_model' => '');
        if ($is_inf && $is_video) {
            $cap_inf = (new InfluencersModel())->get_one($creator_id, (int) ($rule['influencer_id'] ?? 0));
            if (!$cap_inf) { return self::fail(null, 'This automation has no influencer selected.'); }
            $plan = AutomationPlan::video($cap_inf, $topic, (string) ($rule['video_prompt'] ?? ''), (new SchedulerRunsModel())->recent_hooks((int) ($rule['id'] ?? 0), AutomationPlan::RECENT));
            if (empty($plan['ok'])) { return self::fail(null, 'Planning failed: ' . $plan['error']); }
            $image_brief = $plan['image_prompt'];
            $overlay     = $plan['hook'];
            $meta['hook_line'] = $plan['hook']; $meta['motion_action'] = $plan['motion'];
        } else {
            $tx = AutomationPlan::text_from_scene($image_brief);
            if ($tx['line'] !== '') { $image_brief = $tx['scene']; $overlay = $tx['line']; $meta['hook_line'] = $tx['line']; }
        }
        if ($overlay !== '' && !TextOverlay::available()) { return self::fail(null, 'On-screen text is not available on this server (no font).'); }
        $meta['image_prompt'] = $image_brief;

        // 1) Generate the image: a trained influencer, or a brand photo (OpenAI).
        $asset_id = 0; $still_price = 0;
        if ($is_inf) {
            // A video automation starts from Nano Banana Pro drawing her from her approved reference pictures.
            $ir = InfluencerJobService::run_for_rule($rule, $user, $image_brief, $size, $plan ? array('model_key' => self::VIDEO_BASE_MODEL) : array());
            if (empty($ir['ok'])) { return self::fail(null, 'Influencer image failed: ' . (string) $ir['error']); }
            $asset_id = (int) $ir['asset_id'];
            $still_price = (int) ($ir['price'] ?? 0);
            $meta['image_model'] = (string) ($ir['model_key'] ?? '');
            $meta['image_prompt'] = (string) ($ir['prompt'] ?? $image_brief);
            $gen = array('ok' => true);
        } else {
            $pay = Plan::charge_ai($user, 'image', 'Automation image: ' . mb_substr($topic, 0, 60));
            if (empty($pay['ok'])) { return self::fail(null, $pay['message']); }
            $prompt = $use_brand ? BrandService::image_prompt($image_brief, $cb) : $image_brief;
            error_log('[automation] rule ' . (int) ($rule['id'] ?? 0) . ' image prompt: ' . $prompt);
            $gen = ImageGenService::generate($prompt, $size);
            if (empty($gen['ok'])) { (new AiCreditsModel())->apply_delta($creator_id, (int) $pay['price'], 'refund', 'Refund: automation image failed'); }
            $still_price = (int) $pay['price'];
            $meta['image_model'] = 'brand'; $meta['image_prompt'] = $prompt;
            // A still that will be animated keeps no text (the video gets the line); a posted image gets it now.
            if (!empty($gen['ok']) && $overlay !== '' && !$is_video) {
                $burned = TextOverlay::burn_image($gen['bytes'], $overlay);
                if ($burned === '') { (new AiCreditsModel())->apply_delta($creator_id, (int) $pay['price'], 'refund', 'Refund: automation text overlay failed'); return self::fail(null, 'The on-screen line could not be added to the image.'); }
                $gen['bytes'] = $burned; $gen['ext'] = 'png';
            }
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
        $asset_id = (int) $media->add($creator_id, 'image', $label . '.' . $ext, $mime[$ext], 'processing', 'generated');
        if ($asset_id <= 0) { @unlink($tmp); $refund(); return self::fail(null, 'Could not create the media asset.'); }
        $watermark = !empty($user['watermark_enabled']);
        $r = MediaService::process_image($creator_id, $asset_id, $tmp, $ext, $mime[$ext], $user, $watermark);
        @unlink($tmp);
        if (isset($r['error'])) { $media->set_failed($creator_id, $asset_id, $r['error']); $refund(); return self::fail(null, 'Image processing failed: ' . $r['error']); }
        $media->set_ready($creator_id, $asset_id, $r);
        }

        // 2b) An influencer image automation whose scene asked for words: they go on in code, as a new Library image.
        if ($is_inf && !$is_video && $overlay !== '') {
            $burned = self::burn_asset($creator_id, $user, $asset_id, $overlay, $topic);
            if ($burned <= 0) { return self::fail(null, 'The on-screen line could not be added to the image.'); }
            $asset_id = $burned;
        }

        // 3) Caption: AI, written from the scene line. When the creator wrote caption instructions, those are the only
        // instructions, and the formatting rules they state are also enforced in code after the model answers.
        $cap_infl  = ((int) ($rule['influencer_id'] ?? 0) > 0) ? (new InfluencersModel())->get_one($creator_id, (int) $rule['influencer_id']) : null;
        $cap_opts  = array('mode' => (($rule['caption_mode'] ?? '') === 'hook_overlay' ? 'standard' : (string) ($rule['caption_mode'] ?? 'standard')),   /* rules saved through the connector before hook_overlay was refused */ 'persona' => InfluencerService::persona_block($cap_infl));
        $fixed     = trim((string) ($rule['caption_text'] ?? ''));
        $style     = (($rule['image_source'] ?? 'brand') === 'influencer') ? 'tease' : '';
        $cap_scene = $plan ? $plan['image_prompt'] : ($scene !== '' ? $scene : $image_brief);   // a planned run's caption follows the picture it shows
        $caption   = $ai_assist ? AutomationPrompt::clean_caption(BrandService::caption_for($cap_scene, $use_brand ? $cb : array(), $style, $parts['caption'], $cap_opts), $parts['caption'])
                                : $fixed;
        if ($caption === '') { $caption = $fixed; }   // never publish the saved prompt as a caption

        // 3b) Video: animate the still (Studio's image-to-video job, either image source). The post is made when the
        // video lands (finish_video), so a failed render leaves no half-made post behind; the still stays in the Library.
        if ($is_video) {
            // Influencer: only the planned action goes to the video model (never the brief), on a model that accepts her face.
            if ($plan) {
                list($vkey, $vdur) = self::influencer_video_model((string) ($rule['video_model_key'] ?? ''), (string) ($rule['video_duration'] ?? ''));
                $motion = $plan['motion'];
            } else {
                $vkey = (string) ($rule['video_model_key'] ?? ''); $vdur = (string) ($rule['video_duration'] ?? '');
                $motion = trim((string) ($rule['video_prompt'] ?? ''));
                if ($motion === '') { $motion = $scene; }
            }
            $vm = InfluencerConfig::resolve_model('video', $vkey);
            $meta['video_model'] = $vm ? (string) $vm['key'] : $vkey;
            if ($meta['motion_action'] === '') { $meta['motion_action'] = $motion; }
            $v = MediaVideoJob::start($user, $asset_id, $vkey, $motion, $vdur,
                array('overlay_text' => $overlay, 'auto_post' => array('rule_id' => (int) ($rule['id'] ?? 0), 'caption' => $caption, 'meta' => $meta)));
            if (empty($v['ok'])) {
                // No video, no post: give back the still's credits too (it stays in the Library).
                (new AiCreditsModel())->refund_once($creator_id, $still_price > 0 ? $still_price : Plan::ai_price('image', array('params' => array('num_images' => 1))), 'automation still #' . (int) $asset_id);
                return self::fail(null, 'Video failed: ' . (string) $v['message'] . ' The image credits were returned.', $meta);
            }
            return array('ok' => true, 'post_id' => null, 'status' => 'rendering', 'message' => 'The video is rendering. The post publishes when it is ready.', 'meta' => $meta);
        }

        return self::publish($user, $rule, $asset_id, $caption) + array('meta' => $meta);
    }

    /**
     * An automation's video finished (MediaVideoJob): publish the post with it ($asset_id > 0), or record why it
     * failed. Recorded as a run of the automation either way.
     */
    public static function finish_video($cid, array $auto, $asset_id, $error){
        $cid     = (int) $cid;
        $rule_id = (int) ($auto['rule_id'] ?? 0);
        $rule    = (new SchedulerRulesModel())->get_one($cid, $rule_id);
        if (!$rule) { return; }   // the automation was deleted while its video rendered; the video stays in the Library
        if ((int) $asset_id > 0) {
            $rows = (new UsersModel())->get_user_by_id($cid);
            $res  = (is_array($rows) && count($rows) === 1)
                ? self::publish($rows[0], $rule, (int) $asset_id, (string) ($auto['caption'] ?? ''))
                : self::fail(null, 'Missing creator.');
        } else {
            $res = self::fail(null, 'Video failed: ' . ((string) $error !== '' ? (string) $error : 'the render did not finish'));
        }
        (new SchedulerRunsModel())->add($rule_id, $cid, $res['ok'] ? 'success' : 'failed', $res['post_id'], $res['message'], (array) ($auto['meta'] ?? array()));
        (new SchedulerRulesModel())->set_last_run($rule_id, $res['ok'] ? 'success' : 'failed');
    }

    /** Video automations of an influencer start from Nano Banana Pro drawing her from her approved references. */
    const VIDEO_BASE_MODEL = 'prompt_match_pro';
    /** And animate on Kling 3.0 (Cinematic), 10 seconds, unless the rule picked another model that accepts a realistic face. */
    const VIDEO_DEFAULT_MODEL = 'kling_v3';
    const VIDEO_DEFAULT_SECONDS = '10';

    /** [model key, seconds] for an influencer video: the rule's choice, unless it is empty or a model that refuses realistic faces (Seedance). */
    public static function influencer_video_model($key, $dur){
        $m = ((string) $key !== '') ? InfluencerConfig::resolve_model('video', (string) $key) : null;
        if (!$m || ((string) $m['key'] !== (string) $key) || !empty($m['no_real_faces'])) { return array(self::VIDEO_DEFAULT_MODEL, self::VIDEO_DEFAULT_SECONDS); }
        return array((string) $m['key'], (string) $dur);
    }

    /** Overlay $line on a ready image asset; the result is a new Library image. Returns its id, 0 on failure. */
    private static function burn_asset($cid, array $user, $asset_id, $line, $topic){
        $a = (new MediaAssetsModel())->get_one($cid, (int) $asset_id);
        if (!$a || (string) $a['status'] !== 'ready') { return 0; }
        $tmp = tempnam(sys_get_temp_dir(), 'burn');
        if ($tmp === false || !S3Service::get_private_to_file((string) ($a['original_key'] ?: $a['display_key']), $tmp)) { @unlink($tmp); return 0; }
        $out = TextOverlay::burn_image((string) file_get_contents($tmp), $line);
        @unlink($tmp);
        if ($out === '') { return 0; }
        try {
            $r = MediaIngestService::ingest_image($cid, $user, $out, 'png', 'image/png', 'Scheduled · ' . mb_substr((string) $topic, 0, 40), false);
        } catch (\Throwable $e) {
            error_log('[automation] text overlay ingest failed: ' . $e->getMessage());
            return 0;
        }
        return (int) $r['asset_id'];
    }

    /** Create the post around $asset_id, publish it and cross-post it (steps 4-6 of a run). */
    private static function publish(array $user, array $rule, $asset_id, $caption){
        $creator_id = (int) $user['user_id'];
        $asset_id   = (int) $asset_id;
        $caption    = (string) $caption;

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

    private static function fail($post_id, $message, array $meta = array()){
        return array('ok' => false, 'post_id' => $post_id, 'message' => $message, 'meta' => $meta);
    }
}
