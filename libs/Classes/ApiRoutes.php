<?php
/**
 * Routing table for /api/<action>. The monolithic ApiController was split into
 * app/controllers/api/Api*Controller.php; URLs and action names are unchanged.
 * Bootstrap::start_app() looks the action up here. Keep in sync when adding an action.
 */
class ApiRoutes {

    const MAP = [
        'ApiAuthController' => [
            'register', 'login', 'logout', 'impersonate_stop', 'forgot', 'reset', 'verify_email',
            'resend_verification', 'change_password', 'mfa_totp_begin', 'mfa_totp_confirm', 'mfa_totp_disable', 'mfa_email_send_enroll',
            'mfa_email_confirm', 'mfa_email_disable', 'mfa_regenerate_backup_codes', 'mfa_verify', 'mfa_send_login_code',
        ],
        'ApiProfileController' => [
            'update_profile', 'change_username', 'save_notification_prefs', 'save_adult_content_pref', 'block_user', 'unblock_user',
            'become_creator', 'leave_creator', 'delete_my_account', 'data_export_request', 'data_export_status', 'data_export_download', 'follow_creator', 'unfollow_creator', 'upload_my_avatar', 'remove_my_avatar',
        ],
        'ApiBillingController' => [
            'billing_quote', 'billing_card_setup', 'billing_card_save', 'billing_change_plan', 'billing_cancel', 'billing_resume',
            'billing_set_slots', 'billing_set_pack', 'billing_confirm', 'billing_pending', 'buy_ai_credits', 'confirm_ai_credit_purchase',
            'buy_credits', 'confirm_credit_purchase', 'save_autoreplenishment', 'start_payout_onboarding', 'payout_login_link', 'request_payout',
            'disconnect_payout_account',
        ],
        'ApiPostsController' => [
            'feed', 'feed_new', 'post_detail', 'post_like', 'post_comments', 'post_comment_add',
            'post_comment_delete', 'post_view', 'ppv_unlock', 'bundle_unlock', 'join_free_plan', 'subscribe_plan',
            'cancel_creator_subscription', 'reactivate_creator_subscription',
        ],
        'ApiMediaController' => [
            'media_upload', 'media_generate', 'media_generate_video', 'media_upload_init', 'media_upload_chunk', 'media_upload_status', 'media_upload_complete',
            'media_list', 'media_get', 'media_update', 'media_sign', 'media_download', 'media_watermark', 'media_delete',
            'media_bulk', 'collections_list', 'collection_save', 'collection_delete', 'collection_add_assets', 'collection_remove_assets',
        ],
        'ApiCreatorStudioController' => [
            'heartbeat', 'set_timezone', 'save_creator_profile', 'save_directory_listing', 'generate_brand_identity', 'save_brand_identity', 'upload_creator_image',
            'remove_creator_image', 'save_creator_link', 'delete_creator_link', 'toggle_creator_link', 'reorder_creator_links', 'save_creator_plan',
            'delete_creator_plan', 'toggle_creator_plan', 'reorder_creator_plans', 'save_promo_code', 'toggle_promo_code', 'delete_promo_code',
            'promo_preview', 'save_bundle', 'toggle_bundle', 'delete_bundle', 'scheduler_list', 'scheduler_save',
            'scheduler_toggle', 'scheduler_delete', 'scheduler_run_now', 'scheduler_run_status', 'post_save', 'post_get', 'post_caption_auto',
            'post_open_draft', 'post_publish', 'post_schedule', 'post_save_draft', 'post_archive', 'post_duplicate',
            'post_delete', 'post_share', 'posts_list', 'posts_calendar', 'posts_bulk',
            'domain_add', 'domain_verify', 'domain_set_primary', 'domain_remove',
        ],
        'ApiSocialIntegrationsController' => [
            'connect_account', 'disconnect_account', 'fanvue_connect',
            'fanvue_disconnect',
        ],
        'ApiSupportController' => [
            'support_create', 'support_reply', 'support_close', 'support_assist',
        ],
        'ApiSetupController' => [
            'setup_progress', 'setup_dismiss', 'setup_skip_step',
        ],
        'ApiInboxController' => [
            'inbox_settings_save', 'inbox_queue_list', 'inbox_reply_send', 'inbox_reply_dismiss', 'inbox_test_draft',
            'auto_messages_list', 'auto_message_save', 'auto_message_delete',
        ],
        'ApiMessagesController' => [
            'message_send', 'message_inbox', 'message_thread', 'message_people', 'message_open', 'message_unread_count', 'message_delete', 'conversation_delete',
            'message_unlock', 'message_media_list', 'message_peer_info',
        ],
        'ApiBroadcastController' => [
            'broadcast_info', 'broadcast_send',
        ],
        'ApiNotificationsController' => [
            'notifications_list', 'notifications_unread_count', 'notifications_mark_read',
        ],
        'ApiAudienceController' => [
            'audience_tag_add', 'audience_tag_remove', 'audience_note_save',
        ],
        'ApiSearchController' => [
            'search',
        ],
        'ApiAdminController' => [
            'admin_impersonate', 'admin_set_user_status', 'admin_moderate', 'admin_refund', 'report_submit', 'report_resolve', 'verification_request',
            'verification_resolve', 'admin_adjust_credits', 'admin_send_password_reset', 'admin_reset_mfa', 'admin_set_mfa_email',
            'admin_verify_email', 'admin_resend_verification', 'admin_cancel_membership', 'admin_billing_retry', 'admin_set_plan_cancel',
        ],
        'ApiTeamController' => [
            'team_invite', 'team_set_role', 'team_set_status', 'team_remove',
        ],
        'ApiSeoContentController' => [
            'seo_keyword_add', 'seo_keyword_update', 'seo_draft_now', 'seo_article_save', 'seo_article_publish',
            'seo_article_unpublish', 'seo_article_rewrite', 'seo_article_discard', 'seo_article_cover',
        ],
        'ApiEventsController' => [
            'event_save', 'event_delete', 'event_register', 'event_cancel', 'event_attendees', 'event_attendees_csv', 'event_refund_attendee', 'event_remove_attendee', 'event_message_send', 'event_messages', 'event_cancel_all', 'event_set_live',
        ],
        'ApiLiveController' => [
            'live_join', 'live_remove', 'live_mute_all', 'live_status', 'live_settings', 'live_waiting', 'live_admit', 'live_deny', 'live_person', 'live_hand',
        ],
        'ApiServicesController' => [
            'service_save', 'service_delete', 'service_purchase', 'service_buyers', 'service_buyers_csv', 'service_refund_buyer', 'service_set_live', 'service_mark_delivered',
        ],
        'ApiMcpController' => [
            'mcp_token_generate', 'mcp_token_revoke',
        ],
        'ApiInfluencersController' => [
            'influencer_list', 'influencer_get', 'influencer_create', 'influencer_save_step', 'influencer_delete', 'influencer_name_suggest',
            'influencer_images', 'influencer_upload', 'influencer_photo_from_gallery', 'influencer_image_remove', 'influencer_train', 'influencer_models',
            'influencer_reference_generate', 'influencer_reference_pick', 'influencer_training_set_start', 'influencer_training_set_status',
            'influencer_training_set_retry', 'influencer_job_get', 'influencer_generate_image', 'influencer_jobs_list', 'influencer_job_retry',
            'influencer_prompt_auto', 'influencer_asset_url', 'influencer_asset_delete', 'influencer_generate_video', 'influencer_enhance',
        ],
    ];

    private static $index = null;

    public static function controller_for(string $action): ?string {
        if (self::$index === null) {
            self::$index = [];
            foreach (self::MAP as $class => $actions) {
                foreach ($actions as $a) { self::$index[$a] = $class; }
            }
        }
        return self::$index[$action] ?? null;
    }
}
