<?php
/**
 * The upgrade prompt a Free creator sees (need_plan answers, the /setup plan step, the plan links on Influencers):
 * which plan, whether the founding 14-day trial applies, and the checkout link that opens straight into payment.
 */
class UpgradePrompt {

    /** The plan this account signed up for (user_accounts.signup_plan), else Creator. */
    public static function plan(?array $user): string {
        $p = (string) ($user['signup_plan'] ?? '');
        return in_array($p, array('creator', 'studio'), true) ? $p : 'creator';
    }

    /**
     * The founding trial applies: the account came in with the offer (signup_founding, or the cls_founding cookie
     * in this browser), cannot sell yet, and Founding::eligible() (spots left, never claimed, not on a paid plan).
     */
    public static function founding(?array $user): bool {
        if (!$user || Plan::can_sell($user)) { return false; }
        $flag = (int) ($user['signup_founding'] ?? 0) === 1 || (string) ($_COOKIE['cls_founding'] ?? '') === '1';
        if (!$flag) { return false; }
        try { return Founding::eligible((int) $user['user_id']); }
        catch (\Throwable $e) { error_log('[upgrade] founding ' . (int) $user['user_id'] . ': ' . $e->getMessage()); return false; }
    }

    /** Billing with that plan's checkout open (checkout=1); founding=1 applies the trial; return= comes back after. */
    public static function checkout_url(string $plan, bool $founding, string $return = ''): string {
        return '/account/billing?tab=plan&plan=' . rawurlencode($plan) . ($founding ? '&founding=1' : '') . '&checkout=1'
            . ($return !== '' ? '&return=' . rawurlencode($return) : '');
    }

    /** The plan tab only (no checkout), for the plain "Choose a Plan" links. */
    public static function plan_url(string $plan, string $return = ''): string {
        return '/account/billing?tab=plan&plan=' . rawurlencode($plan) . ($return !== '' ? '&return=' . rawurlencode($return) : '');
    }

    /** The extra fields of a need_plan answer (site.js cls_need_plan). */
    public static function payload(?array $user, string $return = ''): array {
        return array('need_plan' => true, 'plan' => 'creator', 'return' => $return, 'founding_eligible' => self::founding($user));
    }

    /** The owner account of the signed-in creator (team members act on it), or null. */
    public static function owner(): ?array {
        if ((int) Session::get('user_id') <= 0) { return null; }
        $rows = (new UsersModel())->get_user_by_id(Permissions::creator_id());
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** need_plan wording for the API actions that refuse with the generic default ('use this', 'turn this on', 'use AI tools'). */
    const ACTIONS = array(
        'launch_campaign_options' => 'run a launch campaign', 'launch_campaign_draft' => 'run a launch campaign', 'launch_campaign_confirm' => 'run a launch campaign',
        'billing_set_slots' => 'add AI influencer slots', 'billing_set_pack' => 'buy AI credits', 'confirm_ai_credit_purchase' => 'buy AI credits',
        'broadcast_send' => 'send broadcasts', 'message_send' => 'send paid messages',
        'edit_project_create' => 'edit clips', 'edit_project_save' => 'edit clips', 'edit_project_export' => 'export clips',
        'generate_brand_identity' => 'generate your brand identity with AI', 'post_caption_auto' => 'write captions with AI',
        'domain_add' => 'use a custom domain', 'domain_verify' => 'use a custom domain', 'domain_set_primary' => 'use a custom domain',
        'toggle_creator_plan' => 'turn on membership tiers', 'toggle_promo_code' => 'turn on promo codes', 'toggle_bundle' => 'turn on bundles',
        'scheduler_save' => 'use automations', 'scheduler_toggle' => 'turn on automations', 'scheduler_run_now' => 'run automations', 'scheduler_run_status' => 'run automations',
        'post_share' => 'cross-post to your socials', 'event_set_live' => 'make an event live', 'service_set_live' => 'make a service live',
        'mcp_token_generate' => 'connect Claude', 'eromify_connect' => 'connect Eromify', 'eromify_disconnect' => 'connect Eromify', 'eromify_characters' => 'connect Eromify',
        'media_generate' => 'generate images with AI', 'media_generate_video' => 'generate videos with AI',
        'media_edit' => 'edit images with AI', 'media_enhance_video' => 'enhance videos with AI', 'media_extract_frame' => 'create videos with AI',
        'influencer_create' => 'create an AI influencer', 'influencer_save_step' => 'create an AI influencer', 'influencer_name_suggest' => 'create an AI influencer',
        'influencer_upload' => 'create an AI influencer', 'influencer_photo_from_gallery' => 'create an AI influencer', 'influencer_image_remove' => 'create an AI influencer',
        'influencer_train' => 'train your AI influencer', 'influencer_generate_image' => 'generate images of your AI influencer',
        'influencer_generate_video' => 'generate videos of your AI influencer', 'influencer_enhance' => 'enhance images with AI',
        'influencer_prompt_auto' => 'write prompts with AI', 'influencer_carousel_start' => 'make carousels with AI', 'influencer_carousel_read' => 'make carousels with AI',
        'influencer_carousel_regenerate' => 'make carousels with AI', 'influencer_carousel_to_post' => 'make carousels with AI',
        'scene_run' => 'run scenes', 'scene_save' => 'save scenes', 'scene_set_active' => 'use scenes', 'scene_delete' => 'use scenes', 'scene_duplicate' => 'use scenes', 'scene_thumb' => 'use scenes',
        'influencer_motion_first_frame' => 'make motion videos', 'influencer_motion_start' => 'make motion videos',
        'influencer_replace_prepare' => 'replace characters in videos', 'influencer_replace_start' => 'replace characters in videos',
        'influencer_scene_start' => 'make scene videos', 'influencer_talking_scene_start' => 'make talking videos', 'influencer_talking_start' => 'make talking videos',
        'influencer_voice_suggest' => 'give your influencer a voice', 'influencer_voice_design' => 'give your influencer a voice', 'influencer_voice_save' => 'give your influencer a voice',
        'influencer_voice_activate' => 'give your influencer a voice', 'influencer_speech_enhance' => 'generate speech', 'influencer_speech_start' => 'generate speech',
    );

    /** Prefix fallbacks for actions not listed above. */
    const PREFIXES = array('influencer_' => 'use AI influencers', 'scene_' => 'use scenes', 'media_' => 'use AI tools', 'scheduler_' => 'use automations', 'domain_' => 'use a custom domain');

    /** The words for an API action, or '' when there is nothing more specific than the default. */
    public static function action_words(string $api_action): string {
        if (isset(self::ACTIONS[$api_action])) { return self::ACTIONS[$api_action]; }
        foreach (self::PREFIXES as $p => $words) { if (strpos($api_action, $p) === 0) { return $words; } }
        return '';
    }
}
