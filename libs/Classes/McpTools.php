<?php
/**
 * MCP tool registry for the remote connector. Each tool is a thin wrapper over
 * an EXISTING model method, always scoped to the authenticated creator's id —
 * no new business logic and no cross-creator access.
 *
 * Deliberately NOT exposed (need an external flow or move money/are irreversible;
 * add later on request): media upload (binary/S3), plan/promo/bundle CREATE &
 * UPDATE (need Stripe price/coupon), payouts & credit movement, messaging /
 * broadcast send, account deletion, and "run automation now" (immediate publish).
 *
 * definitions() -> tools/list payload.  call() -> run one tool for a creator.
 */
class McpTools {

    public static function definitions(){
        $none = array('type' => 'object', 'properties' => new stdClass(), 'required' => array());
        $id   = array('type' => 'object', 'properties' => array('id' => array('type' => 'integer')), 'required' => array('id'));
        $limit = array('type' => 'object', 'properties' => array('limit' => array('type' => 'integer', 'description' => '1-100, default 25')), 'required' => array());

        $t = array();

        // ---- Analytics (read) ----
        $t[] = array('name' => 'get_analytics',      'description' => 'Overview KPIs: posts, revenue, views, followers, subscribers.', 'inputSchema' => $none);
        $t[] = array('name' => 'revenue_breakdown',  'description' => 'Revenue broken down by source (all-time).', 'inputSchema' => $none);
        $t[] = array('name' => 'top_posts',          'description' => 'Best-performing posts.', 'inputSchema' => $limit);
        $t[] = array('name' => 'recent_sales',       'description' => 'Most recent sales.', 'inputSchema' => $limit);
        $t[] = array('name' => 'customer_stats',     'description' => 'Customer/buyer statistics.', 'inputSchema' => $none);
        $t[] = array('name' => 'content_mix',        'description' => 'Breakdown of content by type/audience.', 'inputSchema' => $none);
        $t[] = array('name' => 'follower_count',     'description' => 'Total followers.', 'inputSchema' => $none);
        $t[] = array('name' => 'subscriber_count',   'description' => 'Total active subscribers.', 'inputSchema' => $none);

        // ---- Posts ----
        $t[] = array('name' => 'list_posts',  'description' => 'List posts, newest first.', 'inputSchema' => $limit);
        $t[] = array('name' => 'get_post',    'description' => 'Get one post by id.', 'inputSchema' => $id);
        $t[] = array('name' => 'post_counts', 'description' => 'Counts of posts by state (draft/scheduled/published/archived).', 'inputSchema' => $none);
        $t[] = array('name' => 'create_post', 'description' => 'Create a DRAFT post.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('caption'),
            'properties' => array(
                'caption'  => array('type' => 'string'),
                'audience' => array('type' => 'string', 'enum' => array('free', 'subscribers', 'ppv')),
                'ppv_price_credits' => array('type' => 'integer', 'description' => 'Required credits when audience=ppv.'),
            )));
        $t[] = array('name' => 'update_post', 'description' => 'Update a post\'s caption/audience/pricing/comments.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id'),
            'properties' => array(
                'id' => array('type' => 'integer'),
                'caption' => array('type' => 'string'),
                'audience' => array('type' => 'string', 'enum' => array('free', 'subscribers', 'ppv')),
                'ppv_price_credits' => array('type' => 'integer'),
                'tier_id' => array('type' => 'integer'),
                'comments_enabled' => array('type' => 'boolean'),
            )));
        $share = array('type' => 'array', 'items' => array('type' => 'string'),
            'description' => 'Optional cross-post targets: connected social account ids (see list_share_targets) and/or "fanvue" to mirror the full post to the connected Fanvue account.');
        $t[] = array('name' => 'list_share_targets', 'description' => 'Connected cross-post targets (social accounts + Fanvue) usable as share_accounts.', 'inputSchema' => $none);
        $t[] = array('name' => 'publish_post',  'description' => 'Publish a post now (fails if it has no ready media). Optionally cross-post.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id'),
            'properties' => array('id' => array('type' => 'integer'), 'share_accounts' => $share)));
        $t[] = array('name' => 'schedule_post', 'description' => 'Schedule a post for a future UTC datetime. Optionally cross-post at that time.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id', 'scheduled_at'),
            'properties' => array('id' => array('type' => 'integer'), 'scheduled_at' => array('type' => 'string', 'description' => 'UTC "YYYY-MM-DD HH:MM:SS"'), 'share_accounts' => $share)));
        $t[] = array('name' => 'archive_post',  'description' => 'Archive a post.', 'inputSchema' => $id);
        $t[] = array('name' => 'delete_post',   'description' => 'Delete a post.', 'inputSchema' => $id);
        $t[] = array('name' => 'duplicate_post','description' => 'Duplicate a post as a new draft.', 'inputSchema' => $id);

        // ---- Media ----
        $t[] = array('name' => 'list_media',    'description' => 'List media library assets.', 'inputSchema' => $limit);
        $t[] = array('name' => 'get_media',     'description' => 'Get one media asset.', 'inputSchema' => $id);
        $t[] = array('name' => 'storage_usage', 'description' => 'Total media storage bytes and asset count.', 'inputSchema' => $none);
        $t[] = array('name' => 'get_plan_usage', 'description' => 'The account\'s plan tier, billing period, AI credit balance and what is in use against each plan limit (seats, influencers, automations, storage).', 'inputSchema' => $none);
        $t[] = array('name' => 'set_media_description', 'description' => 'Set a media asset\'s description.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id', 'description'),
            'properties' => array('id' => array('type' => 'integer'), 'description' => array('type' => 'string'))));
        $t[] = array('name' => 'delete_media',  'description' => 'Delete (soft) a media asset.', 'inputSchema' => $id);

        // ---- Collections ----
        $t[] = array('name' => 'list_collections',   'description' => 'List media collections.', 'inputSchema' => $none);
        $t[] = array('name' => 'create_collection',  'description' => 'Create a collection.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('name'), 'properties' => array('name' => array('type' => 'string'))));
        $t[] = array('name' => 'rename_collection',  'description' => 'Rename a collection.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id', 'name'), 'properties' => array('id' => array('type' => 'integer'), 'name' => array('type' => 'string'))));
        $t[] = array('name' => 'delete_collection',  'description' => 'Delete a collection.', 'inputSchema' => $id);
        $t[] = array('name' => 'collection_add_assets',    'description' => 'Add owned media assets to a collection.', 'inputSchema' => self::idAndAssets());
        $t[] = array('name' => 'collection_remove_assets', 'description' => 'Remove media assets from a collection.', 'inputSchema' => self::idAndAssets());

        // ---- Links ----
        $t[] = array('name' => 'list_links',     'description' => 'List profile links.', 'inputSchema' => $none);
        $t[] = array('name' => 'create_link',    'description' => 'Add a profile link.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('title', 'url'),
            'properties' => array('title' => array('type' => 'string'), 'url' => array('type' => 'string'))));
        $t[] = array('name' => 'update_link',    'description' => 'Update a link\'s title/url.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id', 'title', 'url'),
            'properties' => array('id' => array('type' => 'integer'), 'title' => array('type' => 'string'), 'url' => array('type' => 'string'))));
        $t[] = array('name' => 'set_link_enabled', 'description' => 'Enable/disable a link.', 'inputSchema' => self::idAndActive('enabled'));
        $t[] = array('name' => 'delete_link',    'description' => 'Delete a link.', 'inputSchema' => $id);

        // ---- Monetization objects (read + activate/deactivate + delete) ----
        $t[] = array('name' => 'list_plans',      'description' => 'List subscription plans.', 'inputSchema' => $none);
        $t[] = array('name' => 'set_plan_active', 'description' => 'Activate/deactivate a plan.', 'inputSchema' => self::idAndActive());
        $t[] = array('name' => 'delete_plan',     'description' => 'Delete a plan.', 'inputSchema' => $id);
        $t[] = array('name' => 'list_promo_codes','description' => 'List promo/discount codes.', 'inputSchema' => $none);
        $t[] = array('name' => 'set_promo_active','description' => 'Activate/deactivate a promo code.', 'inputSchema' => self::idAndActive());
        $t[] = array('name' => 'delete_promo_code','description' => 'Delete a promo code.', 'inputSchema' => $id);
        $t[] = array('name' => 'list_bundles',    'description' => 'List content bundles.', 'inputSchema' => $none);
        $t[] = array('name' => 'set_bundle_active','description' => 'Activate/deactivate a bundle.', 'inputSchema' => self::idAndActive());
        $t[] = array('name' => 'delete_bundle',   'description' => 'Delete a bundle.', 'inputSchema' => $id);

        // ---- Events (full CRUD; credit-priced, self-contained) ----
        $t[] = array('name' => 'list_events',  'description' => 'List events.', 'inputSchema' => $none);
        $t[] = array('name' => 'get_event',    'description' => 'Get one event.', 'inputSchema' => $id);
        $t[] = array('name' => 'create_event', 'description' => 'Create an event.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('title', 'start_at'),
            'properties' => array(
                'title' => array('type' => 'string'), 'description' => array('type' => 'string'),
                'start_at' => array('type' => 'string', 'description' => 'UTC datetime'), 'end_at' => array('type' => 'string'),
                'timezone' => array('type' => 'string'), 'access_type' => array('type' => 'string'),
                'price_credits' => array('type' => 'integer'), 'capacity' => array('type' => 'integer'),
                'location' => array('type' => 'string'), 'status' => array('type' => 'string', 'enum' => array('draft', 'published', 'canceled')),
            )));
        $t[] = array('name' => 'update_event', 'description' => 'Update an event (send id plus only the fields to change).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id'),
            'properties' => array(
                'id' => array('type' => 'integer'),
                'title' => array('type' => 'string'), 'description' => array('type' => 'string'),
                'start_at' => array('type' => 'string', 'description' => 'UTC datetime'), 'end_at' => array('type' => 'string'),
                'timezone' => array('type' => 'string'),
                'access_type' => array('type' => 'string'), 'price_credits' => array('type' => 'integer'),
                'tier_id' => array('type' => 'integer'), 'capacity' => array('type' => 'integer'),
                'location' => array('type' => 'string'), 'external_url' => array('type' => 'string'),
                'access_instructions' => array('type' => 'string'),
                'status' => array('type' => 'string', 'enum' => array('draft', 'published', 'canceled')),
            )));
        $t[] = array('name' => 'delete_event', 'description' => 'Delete an event.', 'inputSchema' => $id);

        // ---- Services (full CRUD) ----
        $t[] = array('name' => 'list_services',  'description' => 'List services.', 'inputSchema' => $none);
        $t[] = array('name' => 'get_service',    'description' => 'Get one service.', 'inputSchema' => $id);
        $t[] = array('name' => 'create_service', 'description' => 'Create a service.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('name'),
            'properties' => array(
                'name' => array('type' => 'string'), 'description' => array('type' => 'string'),
                'price_credits' => array('type' => 'integer'), 'duration_min' => array('type' => 'integer'),
                'delivery_method' => array('type' => 'string'), 'capacity' => array('type' => 'integer'),
                'status' => array('type' => 'string', 'enum' => array('draft', 'published')),
            )));
        $t[] = array('name' => 'update_service', 'description' => 'Update a service (send id plus only the fields to change).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id'),
            'properties' => array(
                'id' => array('type' => 'integer'),
                'name' => array('type' => 'string'), 'description' => array('type' => 'string'),
                'price_credits' => array('type' => 'integer'), 'duration_min' => array('type' => 'integer'),
                'delivery_method' => array('type' => 'string'), 'scheduling_url' => array('type' => 'string'),
                'delivery_details' => array('type' => 'string'), 'capacity' => array('type' => 'integer'),
                'category' => array('type' => 'string'), 'refund_policy' => array('type' => 'string'),
                'status' => array('type' => 'string', 'enum' => array('draft', 'published')),
            )));
        $t[] = array('name' => 'delete_service', 'description' => 'Delete a service.', 'inputSchema' => $id);

        // ---- Automations (Scheduler) ----
        $t[] = array('name' => 'list_automations', 'description' => 'List Scheduler automations.', 'inputSchema' => $none);
        $t[] = array('name' => 'get_automation',   'description' => 'Get one automation.', 'inputSchema' => $id);
        $t[] = array('name' => 'create_automation','description' => 'Create a Scheduler automation (auto-generates & publishes posts).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('name', 'topic'),
            'properties' => array(
                'name' => array('type' => 'string'), 'topic' => array('type' => 'string'),
                'cadence' => array('type' => 'string', 'enum' => array('daily', 'weekly')),
                'run_time' => array('type' => 'string', 'description' => 'HH:MM'), 'timezone' => array('type' => 'string'),
                'audience' => array('type' => 'string', 'enum' => array('free', 'subscribers')),
                'active' => array('type' => 'boolean'),
                'size' => array('type' => 'string', 'enum' => array('square', 'portrait', 'landscape')),
                'social_accounts' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Cross-post targets, e.g. ["fanvue"]. See list_share_targets.'),
                'image_source' => array('type' => 'string', 'enum' => array('brand', 'influencer'), 'description' => '"influencer" renders one of the creator\'s trained AI influencers (influencer_id required); the topic is then the scene only. Default "brand".'),
                'influencer_id' => array('type' => 'integer', 'description' => 'Trained influencer id (see list_influencers). Required when image_source is "influencer".'),
                'ai_assist' => array('type' => 'boolean', 'description' => 'Default true: Claude distils the scene and writes the caption. false: the topic goes to the image model verbatim and caption_text is posted as written (for content Claude would soften).'),
                'caption_text' => array('type' => 'string', 'description' => 'Caption to post when ai_assist is false.'),
            )));
        $t[] = array('name' => 'update_automation',    'description' => 'Update an automation. Send the FULL config (unset fields reset to defaults).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id', 'name', 'topic'),
            'properties' => array(
                'id' => array('type' => 'integer'),
                'name' => array('type' => 'string'), 'topic' => array('type' => 'string'),
                'cadence' => array('type' => 'string', 'enum' => array('daily', 'weekly')),
                'run_time' => array('type' => 'string', 'description' => 'HH:MM'), 'timezone' => array('type' => 'string'),
                'audience' => array('type' => 'string', 'enum' => array('free', 'subscribers')),
                'tier_id' => array('type' => 'integer'), 'comments_enabled' => array('type' => 'boolean'),
                'use_brand' => array('type' => 'boolean'), 'active' => array('type' => 'boolean'),
                'image_source' => array('type' => 'string', 'enum' => array('brand', 'influencer'), 'description' => '"influencer" renders one of the creator\'s trained AI influencers (influencer_id required); the topic is then the scene only. Default "brand".'),
                'influencer_id' => array('type' => 'integer', 'description' => 'Trained influencer id (see list_influencers). Required when image_source is "influencer".'),
                'ai_assist' => array('type' => 'boolean', 'description' => 'Default true: Claude distils the scene and writes the caption. false: the topic goes to the image model verbatim and caption_text is posted as written (for content Claude would soften).'),
                'caption_text' => array('type' => 'string', 'description' => 'Caption to post when ai_assist is false.'),
                'days_of_week' => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => '0-6 (Sun-Sat), for weekly cadence'),
            )));
        $t[] = array('name' => 'set_automation_active','description' => 'Activate/deactivate an automation.', 'inputSchema' => self::idAndActive());
        $t[] = array('name' => 'delete_automation',    'description' => 'Delete an automation.', 'inputSchema' => $id);

        // ---- Audience ----
        $t[] = array('name' => 'list_audience',      'description' => 'List audience (followers/subscribers/buyers).', 'inputSchema' => $none);
        $t[] = array('name' => 'audience_add_tag',   'description' => 'Tag an audience member.', 'inputSchema' => self::fanAnd('tag'));
        $t[] = array('name' => 'audience_remove_tag','description' => 'Remove a tag from an audience member.', 'inputSchema' => self::fanAnd('tag'));
        $t[] = array('name' => 'audience_save_note', 'description' => 'Save a note on an audience member.', 'inputSchema' => self::fanAnd('note'));

        // ---- Profile & Brand ----
        $t[] = array('name' => 'get_profile',  'description' => 'Get the public creator profile.', 'inputSchema' => $none);
        $t[] = array('name' => 'save_profile', 'description' => 'Update display name, bio, location.', 'inputSchema' => array(
            'type' => 'object', 'properties' => array(
                'display_name' => array('type' => 'string'), 'bio' => array('type' => 'string'), 'location' => array('type' => 'string')), 'required' => array()));
        $t[] = array('name' => 'get_brand',    'description' => 'Get the brand kit.', 'inputSchema' => $none);
        $t[] = array('name' => 'save_brand',   'description' => 'Update the brand kit.', 'inputSchema' => array(
            'type' => 'object', 'properties' => array(
                'brand_name' => array('type' => 'string'), 'tagline' => array('type' => 'string'),
                'description' => array('type' => 'string'), 'voice' => array('type' => 'string'),
                'colors' => array('type' => 'array', 'items' => array('type' => 'string')),
                'keywords' => array('type' => 'array', 'items' => array('type' => 'string'))), 'required' => array()));

        // ---- Monetization: create/update (no eager Stripe; artifacts made lazily at checkout) ----
        $t[] = array('name' => 'create_plan', 'description' => 'Create a subscription plan.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('name'),
            'properties' => array(
                'name' => array('type' => 'string'),
                'price' => array('type' => 'number', 'description' => 'Monthly price in dollars (0 or omit for a free tier; paid tiers must be >= $1).'),
                'billing_interval' => array('type' => 'string', 'enum' => array('week', 'month', 'year')),
                'description' => array('type' => 'string'), 'perks' => array('type' => 'string'),
            )));
        $t[] = array('name' => 'update_plan', 'description' => 'Update a subscription plan (send id + fields to change).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id'),
            'properties' => array('id' => array('type' => 'integer'), 'name' => array('type' => 'string'),
                'price' => array('type' => 'number'), 'billing_interval' => array('type' => 'string', 'enum' => array('week', 'month', 'year')),
                'description' => array('type' => 'string'), 'perks' => array('type' => 'string'))));
        $t[] = array('name' => 'create_promo_code', 'description' => 'Create a discount code (Pro/Studio plans).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('code', 'percent_off'),
            'properties' => array(
                'code' => array('type' => 'string', 'description' => '3-40 letters/numbers'),
                'percent_off' => array('type' => 'integer', 'description' => '1-100'),
                'applies_to' => array('type' => 'string', 'enum' => array('all', 'subscription', 'ppv')),
                'max_redemptions' => array('type' => 'integer'), 'expires_at' => array('type' => 'string', 'description' => 'date/datetime'),
            )));
        $t[] = array('name' => 'update_promo_code', 'description' => 'Update a discount code.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id'),
            'properties' => array('id' => array('type' => 'integer'), 'code' => array('type' => 'string'),
                'percent_off' => array('type' => 'integer'), 'applies_to' => array('type' => 'string', 'enum' => array('all', 'subscription', 'ppv')),
                'max_redemptions' => array('type' => 'integer'), 'expires_at' => array('type' => 'string'))));
        $t[] = array('name' => 'create_bundle', 'description' => 'Create a content bundle from owned media (Pro/Studio plans).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('name', 'price_credits', 'asset_ids'),
            'properties' => array('name' => array('type' => 'string'), 'description' => array('type' => 'string'),
                'price_credits' => array('type' => 'integer', 'description' => '>= 1'),
                'asset_ids' => array('type' => 'array', 'items' => array('type' => 'integer')))));
        $t[] = array('name' => 'update_bundle', 'description' => 'Update a content bundle.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id'),
            'properties' => array('id' => array('type' => 'integer'), 'name' => array('type' => 'string'),
                'description' => array('type' => 'string'), 'price_credits' => array('type' => 'integer'),
                'asset_ids' => array('type' => 'array', 'items' => array('type' => 'integer')))));

        // ---- Media creation ----
        $t[] = array('name' => 'generate_image', 'description' => 'Generate a brand AI image (Flux on fal.ai, no AI credits used) and add it to the media library. Returns the new asset id.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('prompt'),
            'properties' => array('prompt' => array('type' => 'string'),
                'size' => array('type' => 'string', 'enum' => array('square', 'portrait', 'landscape')),
                'use_brand' => array('type' => 'boolean', 'description' => 'Apply brand style (default true).'))));
        $t[] = array('name' => 'upload_image_from_url', 'description' => 'Fetch a PUBLIC image URL and add it to the media library. Images only.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('url'),
            'properties' => array('url' => array('type' => 'string', 'description' => 'Public https URL of a JPG/PNG/WebP/GIF image.'),
                'name' => array('type' => 'string'))));
        $t[] = array('name' => 'upload_video_from_url', 'description' => 'Fetch a PUBLIC video URL (MP4/MOV/WebM, up to 1 GB) and add it to the media library. Pass poster_url (a JPG/PNG thumbnail of the video) whenever you have one — it becomes the preview image.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('url'),
            'properties' => array('url' => array('type' => 'string', 'description' => 'Public https URL of an MP4, MOV or WebM file.'),
                'poster_url' => array('type' => 'string', 'description' => 'Optional public https URL of a still image to use as the preview/thumbnail.'),
                'name' => array('type' => 'string'))));

        // ---- Messaging ----
        $t[] = array('name' => 'send_message', 'description' => 'Send a direct message to a user (must follow/subscribe to you or vice-versa). Optionally attach library media (asset_ids, up to 10) and set a price in dollars ($3-$500) the fan pays to unlock it.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('to_user_id'),
            'properties' => array('to_user_id' => array('type' => 'integer'), 'body' => array('type' => 'string'),
                'asset_ids' => array('type' => 'array', 'items' => array('type' => 'integer')), 'price' => array('type' => 'integer', 'description' => 'Dollars; 0 or omitted = free'))));
        $t[] = array('name' => 'send_broadcast', 'description' => 'Send one message to every fan in one or more audience segments (each gets it as a private DM). Optionally attach media and a price.', 'inputSchema' => array(
            'type' => 'object', 'required' => array(),
            'properties' => array('body' => array('type' => 'string'),
                'segments' => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => BroadcastsModel::segments())),
                'asset_ids' => array('type' => 'array', 'items' => array('type' => 'integer')), 'price' => array('type' => 'integer'))));
        $t[] = array('name' => 'list_auto_messages', 'description' => 'Welcome / trigger messages sent automatically on Creator Link Studio events (new_follower, new_subscriber, first_message, new_purchase).', 'inputSchema' => array('type' => 'object', 'properties' => new stdClass()));
        $t[] = array('name' => 'save_auto_message', 'description' => 'Set (and enable) the automatic message for a trigger. Optional media and price like send_message. enabled=false turns it off.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('trigger'),
            'properties' => array('trigger' => array('type' => 'string', 'enum' => AutoMessagesModel::TRIGGERS), 'text' => array('type' => 'string'),
                'asset_ids' => array('type' => 'array', 'items' => array('type' => 'integer')), 'price' => array('type' => 'integer'), 'enabled' => array('type' => 'boolean'))));


        // ---- AI influencers (create, train once, generate on demand) ----
        $infl = array('type' => 'object', 'required' => array('influencer_id'), 'properties' => array('influencer_id' => array('type' => 'integer')));
        $t[] = array('name' => 'list_influencers', 'description' => 'List the creator\'s AI influencers with status, trigger word and counts. Status ready = trained and usable.', 'inputSchema' => $none);
        $t[] = array('name' => 'get_influencer', 'description' => 'One influencer: status, path, wizard step, trigger word, prompt defaults, counts, cover image.', 'inputSchema' => $infl);
        $t[] = array('name' => 'influencer_model_options', 'description' => 'Model choices per purpose (reference, image, video, enhance), each with a label, what it is good at, price and durations. Pass model_key values from here to the generate tools.', 'inputSchema' => $none);
        $t[] = array('name' => 'create_influencer', 'description' => 'Create an influencer. path "photos" = train from 10-50 uploaded photos (add_influencer_photo); path "reference" = describe her or upload one face photo, generate a reference, then a 10-image training set.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('name'),
            'properties' => array('name' => array('type' => 'string', 'description' => 'Unique per account'),
                'path' => array('type' => 'string', 'enum' => array('photos', 'reference'), 'description' => 'Default "photos".'))));
        $t[] = array('name' => 'update_influencer', 'description' => 'Update her settings. Any subset: name, source_description (face description for the reference path), reference_model_key, steer_text (training-set steering), prompt_defaults (prepended to every prompt), negative_prompt, is_public, share_accounts (default share targets for her automations; see list_share_targets).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'name' => array('type' => 'string'), 'source_description' => array('type' => 'string'),
                'reference_model_key' => array('type' => 'string'), 'steer_text' => array('type' => 'string'), 'prompt_defaults' => array('type' => 'string'),
                'negative_prompt' => array('type' => 'string'), 'is_public' => array('type' => 'boolean'),
                'share_accounts' => array('type' => 'array', 'items' => array('type' => 'string')))));
        $t[] = array('name' => 'delete_influencer', 'description' => 'Remove an influencer (her media stays in the library).', 'inputSchema' => $infl);
        $t[] = array('name' => 'add_influencer_photo', 'description' => 'Fetch a PUBLIC image URL and attach it to her. role "upload" = a training photo (photos path, 10-50 needed); role "face" = the single face photo that becomes her reference (reference path).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id', 'url'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'url' => array('type' => 'string', 'description' => 'Public https URL of a JPG/PNG/WebP'),
                'role' => array('type' => 'string', 'enum' => array('upload', 'face')))));
        $t[] = array('name' => 'remove_influencer_image', 'description' => 'Detach one of her uploaded/training images.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id', 'asset_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'asset_id' => array('type' => 'integer'))));
        $t[] = array('name' => 'delete_influencer_asset', 'description' => 'Delete one of her generated or uploaded files from the media library (removes it from posts/collections too).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id', 'asset_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'asset_id' => array('type' => 'integer'))));
        $t[] = array('name' => 'list_influencer_images', 'description' => 'Her images by role (upload, face, reference, training, generated, video, enhanced) with signed preview URLs.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'role' => array('type' => 'string', 'enum' => array('upload', 'face', 'reference', 'training', 'generated', 'video', 'enhanced')))));
        $t[] = array('name' => 'generate_influencer_reference', 'description' => 'Reference path: generate one reference image from her face description (text input). Returns a job id; poll get_influencer_job, then approve_influencer_reference with the landed asset id.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'source_description' => array('type' => 'string', 'description' => 'Face description; saved on her when given.'),
                'reference_model_key' => array('type' => 'string', 'description' => 'From influencer_model_options.reference; affects only the reference image.'))));
        $t[] = array('name' => 'approve_influencer_reference', 'description' => 'Approve a reference image (a generated candidate or her face photo) so the training set can be built from it.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id', 'asset_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'asset_id' => array('type' => 'integer'))));
        $t[] = array('name' => 'generate_influencer_training_set', 'description' => 'Reference path: generate the 10 square training images from the approved reference (one job each). Poll get_influencer_training_set until complete, then train_influencer.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'steer_text' => array('type' => 'string', 'description' => 'Optional steering appended to every training image prompt.'))));
        $t[] = array('name' => 'get_influencer_training_set', 'description' => 'Progress of her training set: done/failed/active counts and every slot with its image or error.', 'inputSchema' => $infl);
        $t[] = array('name' => 'retry_influencer_training_slot', 'description' => 'Retry a failed training-set slot (same seed) or regenerate a finished one (new seed).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id', 'job_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'job_id' => array('type' => 'integer'))));
        $t[] = array('name' => 'train_influencer', 'description' => 'Train (or retrain) her model: photos path uses her 10-50 uploads, reference path her complete 10-image set. About $2 and a few minutes; poll get_influencer until status is ready. A retrain keeps the current model until the new one succeeds.', 'inputSchema' => $infl);
        $t[] = array('name' => 'list_influencer_models', 'description' => 'Her trained models (history): status, active flag, trigger word, errors.', 'inputSchema' => $infl);
        $t[] = array('name' => 'generate_influencer_image', 'description' => 'Generate images of a TRAINED influencer with her weights. Her trigger word is added automatically; just name the subject, e.g. "photo of a woman at a rooftop cafe at golden hour". Returns a job id; poll get_influencer_job until done for the asset ids. Same seed + prompt reproduces the image.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id', 'prompt'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'prompt' => array('type' => 'string'),
                'model_key' => array('type' => 'string', 'description' => 'From influencer_model_options.image'),
                'image_size' => array('type' => 'string', 'enum' => array('square', 'portrait', 'landscape')),
                'num_images' => array('type' => 'integer', 'description' => '1-4'), 'seed' => array('type' => 'integer', 'description' => 'Pin to reproduce'),
                'guidance' => array('type' => 'number'), 'steps' => array('type' => 'integer'), 'lora_scale' => array('type' => 'number', 'description' => 'Likeness strength 0.1-2, default 1'))));
        $t[] = array('name' => 'generate_influencer_video', 'description' => 'Image-to-video from one of her stills (a generated/enhanced/reference asset id). Returns a job id; poll get_influencer_job.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id', 'asset_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'asset_id' => array('type' => 'integer'), 'prompt' => array('type' => 'string', 'description' => 'The motion'),
                'model_key' => array('type' => 'string', 'description' => 'From influencer_model_options.video'), 'duration' => array('type' => 'string', 'description' => 'Seconds; must be one the model offers'),
                )));
        $t[] = array('name' => 'enhance_influencer_image', 'description' => 'Upscale one of her images into a new asset. Returns a job id; poll get_influencer_job.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id', 'asset_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'asset_id' => array('type' => 'integer'))));
        $t[] = array('name' => 'write_influencer_prompt', 'description' => 'Have the studio write a scene prompt for an image of her, or a motion prompt for a video (returned as text, nothing is rendered).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'hint' => array('type' => 'string'), 'kind' => array('type' => 'string', 'enum' => array('image', 'video'), 'description' => 'Default image'))));
        $t[] = array('name' => 'get_influencer_job', 'description' => 'One generation/training job: status (queued, submitting, running, landing, done, failed), error, seed, cost and the landed assets with signed URLs.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('job_id'), 'properties' => array('job_id' => array('type' => 'integer'))));
        $t[] = array('name' => 'list_influencer_jobs', 'description' => 'Her recent jobs, newest first, optionally by type.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('influencer_id'),
            'properties' => array('influencer_id' => array('type' => 'integer'), 'type' => array('type' => 'string', 'description' => 'Comma list of reference,training_set,training,image,video,enhance'),
                'limit' => array('type' => 'integer', 'description' => '1-100, default 24'))));

        // ---- Automation run (generates + publishes) ----
        $t[] = array('name' => 'run_automation_now', 'description' => 'Run a Scheduler automation immediately: generates an image, writes a caption, and PUBLISHES a post.', 'inputSchema' => $id);

        return $t;
    }

    private static function idAndAssets(){
        return array('type' => 'object', 'required' => array('id', 'asset_ids'),
            'properties' => array('id' => array('type' => 'integer'),
                'asset_ids' => array('type' => 'array', 'items' => array('type' => 'integer'))));
    }
    private static function idAndActive($word = 'active'){
        return array('type' => 'object', 'required' => array('id', $word),
            'properties' => array('id' => array('type' => 'integer'), $word => array('type' => 'boolean')));
    }
    private static function fanAnd($field){
        return array('type' => 'object', 'required' => array('fan_id', $field),
            'properties' => array('fan_id' => array('type' => 'integer'), $field => array('type' => 'string')));
    }

    /** Execute a tool for a creator. Returns a JSON-able value; throws on bad input. */
    public static function call($name, $creator_id, array $a){
        $cid = (int) $creator_id;
        $iid = (int) ($a['id'] ?? 0);
        $ok  = function ($b) { return array('ok' => (bool) $b); };

        switch ($name) {

            // Analytics
            case 'get_analytics':     return (new AnalyticsModel())->overview($cid);
            case 'revenue_breakdown': return (new AnalyticsModel())->revenue_breakdown($cid);
            case 'top_posts':         return (new AnalyticsModel())->top_posts($cid, self::lim($a, 5));
            case 'recent_sales':      return (new AnalyticsModel())->recent_sales($cid, self::lim($a, 8));
            case 'customer_stats':    return (new AnalyticsModel())->customer_stats($cid);
            case 'content_mix':       return (new AnalyticsModel())->content_mix($cid);
            case 'follower_count':    return array('followers' => (new FollowsModel())->count_followers($cid));
            case 'subscriber_count':  return array('subscribers' => (new CreatorSubscriptionsModel())->count_members($cid));

            // Posts
            case 'list_posts':   return array('posts' => array_slice((array) (new PostsModel())->list_for_creator($cid, array()), 0, self::lim($a, 25)));
            case 'get_post':     return self::need((new PostsModel())->get_one($cid, $iid), 'Post not found');
            case 'post_counts':  return (new PostsModel())->counts_by_state($cid);
            case 'create_post': {
                $caption = trim((string) ($a['caption'] ?? ''));
                if ($caption === '') { throw new InvalidArgumentException('caption is required'); }
                $aud = in_array($a['audience'] ?? 'free', array('free', 'subscribers', 'ppv'), true) ? $a['audience'] : 'free';
                $p = new PostsModel();
                $pid = (int) $p->create_draft($cid, $caption, $aud);
                if ($pid <= 0) { throw new RuntimeException('Could not create the post'); }
                $p->update_fields($cid, $pid, array('caption' => $caption, 'audience' => $aud, 'ppv_price_credits' => (int) ($a['ppv_price_credits'] ?? 0)));
                return array('post_id' => $pid, 'state' => 'draft');
            }
            case 'update_post': {
                $f = array_intersect_key($a, array_flip(array('caption', 'audience', 'ppv_price_credits', 'tier_id', 'comments_enabled')));
                return $ok((new PostsModel())->update_fields($cid, $iid, $f));
            }
            case 'list_share_targets': {
                $targets = array();
                $fv = (new FanvueAccountsModel())->get_connected_for_user($cid);
                if ($fv) { $targets[] = array('id' => FanvueShareService::ACCOUNT_ID, 'platform' => 'fanvue', 'username' => (string) ($fv['handle'] ?? '')); }
                foreach ((new SocialAccountsModel())->get_connected_for_user($cid) as $acc) {
                    $targets[] = array('id' => (string) $acc['post_for_me_social_account_id'], 'platform' => (string) $acc['platform'], 'username' => (string) ($acc['username'] ?? ''));
                }
                return array('targets' => $targets);
            }
            case 'publish_post': {
                $p = new PostsModel();
                $post = $p->get_one($cid, $iid);
                if (!$post) { throw new InvalidArgumentException('Post not found'); }
                if ($p->count_missing_assets($iid) > 0) { throw new RuntimeException('Post has no ready media; add media before publishing'); }
                $res = $ok($p->set_state($cid, $iid, 'published', null, date('Y-m-d H:i:s')));
                return self::with_share($res, $cid, $post, $a, null);
            }
            case 'schedule_post': {
                $when = trim((string) ($a['scheduled_at'] ?? ''));
                if ($when === '') { throw new InvalidArgumentException('scheduled_at is required (UTC)'); }
                $p = new PostsModel();
                $post = $p->get_one($cid, $iid);
                if (!$post) { throw new InvalidArgumentException('Post not found'); }
                $res = $ok($p->set_state($cid, $iid, 'scheduled', $when));
                return self::with_share($res, $cid, $post, $a, str_replace(' ', 'T', $when) . 'Z');
            }
            case 'archive_post':   return $ok((new PostsModel())->set_state($cid, $iid, 'archived'));
            case 'delete_post':    return $ok((new PostsModel())->delete_post($cid, $iid));
            case 'duplicate_post': return array('post_id' => (int) (new PostsModel())->duplicate($cid, $iid));

            // Media
            case 'list_media':   return array('media' => array_slice((array) (new MediaAssetsModel())->get_for_creator($cid, array()), 0, self::lim($a, 25)));
            case 'get_media':    return self::need((new MediaAssetsModel())->get_one($cid, $iid), 'Media not found');
            case 'storage_usage':return array('bytes' => (int) (new MediaAssetsModel())->total_bytes($cid), 'count' => (int) (new MediaAssetsModel())->count_for_creator($cid));
            case 'get_plan_usage': return Plan::usage(self::user($cid));
            case 'set_media_description': return $ok((new MediaAssetsModel())->set_description($cid, $iid, (string) ($a['description'] ?? '')));
            case 'delete_media': return $ok((new MediaAssetsModel())->soft_delete($cid, $iid));

            // Collections
            case 'list_collections':  return array('collections' => (array) (new CollectionsModel())->get_for_creator($cid));
            case 'create_collection': return array('id' => (int) (new CollectionsModel())->add($cid, (string) ($a['name'] ?? '')));
            case 'rename_collection': return $ok((new CollectionsModel())->rename($cid, $iid, (string) ($a['name'] ?? '')));
            case 'delete_collection': return $ok((new CollectionsModel())->delete_collection($cid, $iid));
            case 'collection_add_assets':
            case 'collection_remove_assets': {
                $col = new CollectionsModel();
                if (!$col->get_one($cid, $iid)) { throw new InvalidArgumentException('Collection not found'); }
                $ids = self::ownedAssetIds($cid, $a['asset_ids'] ?? array());
                if ($name === 'collection_add_assets') { $col->add_assets($iid, $ids); } else { $col->remove_assets($iid, $ids); }
                return array('ok' => true, 'affected' => count($ids));
            }

            // Links
            case 'list_links':       return array('links' => (array) (new CreatorLinksModel())->get_for_user($cid));
            case 'create_link':      return array('id' => (int) (new CreatorLinksModel())->add($cid, (string) ($a['title'] ?? ''), (string) ($a['url'] ?? '')));
            case 'update_link':      return $ok((new CreatorLinksModel())->update_link($cid, $iid, (string) ($a['title'] ?? ''), (string) ($a['url'] ?? '')));
            case 'set_link_enabled': return $ok((new CreatorLinksModel())->set_enabled($cid, $iid, !empty($a['enabled']) ? 1 : 0));
            case 'delete_link':      return $ok((new CreatorLinksModel())->delete_link($cid, $iid));

            // Plans / promo / bundles (read + toggle + delete)
            case 'list_plans':       return array('plans' => (array) (new CreatorPlansModel())->get_for_user($cid));
            case 'set_plan_active':  return $ok((new CreatorPlansModel())->set_active($cid, $iid, !empty($a['active']) ? 1 : 0));
            case 'delete_plan':      return $ok((new CreatorPlansModel())->delete_plan($cid, $iid));
            case 'list_promo_codes': return array('promo_codes' => (array) (new CreatorPromoCodesModel())->get_for_user($cid));
            case 'set_promo_active': return $ok((new CreatorPromoCodesModel())->set_active($cid, $iid, !empty($a['active']) ? 1 : 0));
            case 'delete_promo_code':return $ok((new CreatorPromoCodesModel())->delete_code($cid, $iid));
            case 'list_bundles':     return array('bundles' => (array) (new ContentBundlesModel())->get_for_creator($cid));
            case 'set_bundle_active':return $ok((new ContentBundlesModel())->set_active($cid, $iid, !empty($a['active']) ? 1 : 0));
            case 'delete_bundle':    return $ok((new ContentBundlesModel())->delete_bundle($cid, $iid));

            // Events
            case 'list_events':  return array('events' => (array) (new EventsModel())->list_for_creator($cid));
            case 'get_event':    return self::need((new EventsModel())->get_one($cid, $iid), 'Event not found');
            case 'create_event': {
                if (trim((string) ($a['title'] ?? '')) === '' || trim((string) ($a['start_at'] ?? '')) === '') {
                    throw new InvalidArgumentException('title and start_at are required');
                }
                $a += array('access_type' => 'free', 'status' => 'draft'); // fields the model reads unguarded
                return array('id' => (int) (new EventsModel())->create($cid, $a));
            }
            case 'update_event': return $ok((new EventsModel())->update_event($cid, $iid, $a));
            case 'delete_event': return $ok((new EventsModel())->delete_event($cid, $iid));

            // Services
            case 'list_services':  return array('services' => (array) (new ServicesModel())->list_for_creator($cid));
            case 'get_service':    return self::need((new ServicesModel())->get_one($cid, $iid), 'Service not found');
            case 'create_service': {
                if (trim((string) ($a['name'] ?? '')) === '') { throw new InvalidArgumentException('name is required'); }
                $a += array('delivery_method' => 'custom', 'status' => 'draft'); // fields the model reads unguarded
                return array('id' => (int) (new ServicesModel())->create($cid, $a));
            }
            case 'update_service': return $ok((new ServicesModel())->update_service($cid, $iid, $a));
            case 'delete_service': return $ok((new ServicesModel())->delete_service($cid, $iid));

            // Automations
            case 'list_automations':    return array('automations' => (array) (new SchedulerRulesModel())->list_for_creator($cid));
            case 'get_automation':      return self::need((new SchedulerRulesModel())->get_one($cid, $iid), 'Automation not found');
            case 'create_automation': {
                if (trim((string) ($a['name'] ?? '')) === '' || trim((string) ($a['topic'] ?? '')) === '') {
                    throw new InvalidArgumentException('name and topic are required');
                }
                $cap = Plan::check_count(self::user($cid), 'automations', (new SchedulerRulesModel())->count_for_creator($cid));
                if (empty($cap['ok'])) { throw new RuntimeException($cap['message'] . ' Upgrade at /account/billing.'); }
                return array('id' => (int) (new SchedulerRulesModel())->create($cid, $a));
            }
            case 'update_automation':     return $ok((new SchedulerRulesModel())->update_rule($cid, $iid, $a));
            case 'set_automation_active': return $ok((new SchedulerRulesModel())->set_active($cid, $iid, !empty($a['active']) ? 1 : 0));
            case 'delete_automation':     return $ok((new SchedulerRulesModel())->delete_rule($cid, $iid));

            // Audience
            case 'list_audience':       return array('audience' => array_slice((array) (new AudienceModel())->list_for_creator($cid), 0, 200));
            case 'audience_add_tag':    return $ok((new AudienceModel())->add_tag($cid, (int) ($a['fan_id'] ?? 0), (string) ($a['tag'] ?? '')));
            case 'audience_remove_tag': return $ok((new AudienceModel())->remove_tag($cid, (int) ($a['fan_id'] ?? 0), (string) ($a['tag'] ?? '')));
            case 'audience_save_note':  return $ok((new AudienceModel())->save_note($cid, (int) ($a['fan_id'] ?? 0), (string) ($a['note'] ?? '')));

            // Profile / brand
            case 'get_profile':  return (array) (new CreatorProfileModel())->get_for_user($cid);
            case 'save_profile': return $ok((new CreatorProfileModel())->save($cid, $a));
            case 'get_brand':    return (array) (new CreatorBrandModel())->get_for_user($cid);
            case 'save_brand':   return $ok((new CreatorBrandModel())->save($cid, $a));

            // Plans (create/update — no eager Stripe; price/coupon made lazily at checkout)
            case 'create_plan': {
                if (trim((string) ($a['name'] ?? '')) === '') { throw new InvalidArgumentException('name is required'); }
                return array('id' => (int) (new CreatorPlansModel())->add($cid, self::planFields($a, null)));
            }
            case 'update_plan': {
                $m = new CreatorPlansModel(); $row = $m->get_one($cid, $iid);
                if (!$row) { throw new InvalidArgumentException('Plan not found'); }
                return $ok($m->update_plan($cid, $iid, self::planFields($a, $row)));
            }

            // Promo codes
            case 'create_promo_code': {
                self::requirePlan($cid, 'promo_codes', 'Discount codes require a Pro or Studio plan');
                return array('id' => (int) (new CreatorPromoCodesModel())->add($cid, self::promoFields($a, null)));
            }
            case 'update_promo_code': {
                $m = new CreatorPromoCodesModel(); $row = $m->get_owned($cid, $iid);
                if (!$row) { throw new InvalidArgumentException('Promo code not found'); }
                return $ok($m->update_code($cid, $iid, self::promoFields($a, $row)));
            }

            // Bundles
            case 'create_bundle': {
                self::requirePlan($cid, 'bundles', 'Content bundles require a Pro or Studio plan');
                $name = trim((string) ($a['name'] ?? '')); $price = (int) ($a['price_credits'] ?? 0);
                if ($name === '') { throw new InvalidArgumentException('name is required'); }
                if ($price < 1) { throw new InvalidArgumentException('price_credits must be >= 1'); }
                $ids = self::ownedReadyAssetIds($cid, $a['asset_ids'] ?? array());
                if (!$ids) { throw new InvalidArgumentException('Provide at least one owned, ready media asset_id'); }
                $m = new ContentBundlesModel();
                $bid = (int) $m->add($cid, $name, (string) ($a['description'] ?? ''), $price);
                $m->set_items($bid, $ids);
                return array('id' => $bid);
            }
            case 'update_bundle': {
                $m = new ContentBundlesModel(); $row = $m->get_owned($cid, $iid);
                if (!$row) { throw new InvalidArgumentException('Bundle not found'); }
                $f = array(
                    'name'          => array_key_exists('name', $a) ? (string) $a['name'] : (string) $row['name'],
                    'description'   => array_key_exists('description', $a) ? (string) $a['description'] : (string) ($row['description'] ?? ''),
                    'price_credits' => array_key_exists('price_credits', $a) ? max(1, (int) $a['price_credits']) : (int) $row['price_credits'],
                );
                $m->update_bundle($cid, $iid, $f);
                if (array_key_exists('asset_ids', $a)) { $m->set_items($iid, self::ownedReadyAssetIds($cid, $a['asset_ids'])); }
                return array('ok' => true);
            }

            // Media creation
            case 'generate_image': {
                $user = self::user($cid);
                self::requirePlan($cid, 'ai_tools', 'AI image generation requires a Pro or Studio plan');
                if (!S3Service::configured()) { throw new RuntimeException('Image generation unavailable (storage not configured)'); }
                $prompt = trim((string) ($a['prompt'] ?? ''));
                if ($prompt === '') { throw new InvalidArgumentException('prompt is required'); }
                $size = in_array($a['size'] ?? 'square', array('square', 'portrait', 'landscape'), true) ? $a['size'] : 'square';
                $final = $prompt;
                if (($a['use_brand'] ?? true)) {
                    $cb = (new CreatorBrandModel())->get_for_user($cid);
                    if (!empty($cb['brand_name']) || !empty($cb['colors']) || !empty($cb['voice']) || !empty($cb['keywords'])) {
                        $final = BrandService::image_prompt($prompt, (array) $cb);
                    }
                }
                $res = ImageGenService::generate($final, $size);
                if (empty($res['ok'])) { throw new RuntimeException('Generation failed: ' . ($res['error'] ?? 'unknown')); }
                return self::ingestImage($cid, $user, $res['bytes'], (string) ($res['ext'] ?? 'png'), (string) ($res['mime'] ?? 'image/png'), 'Generated · ' . mb_substr($prompt, 0, 40));
            }
            case 'upload_image_from_url': {
                $user = self::user($cid);
                if (!S3Service::configured()) { throw new RuntimeException('Uploads unavailable (storage not configured)'); }
                $img = self::safeFetchImage((string) ($a['url'] ?? ''));
                $label = trim((string) ($a['name'] ?? '')) !== '' ? (string) $a['name'] : 'Uploaded image';
                return self::ingestImage($cid, $user, $img['bytes'], $img['ext'], $img['mime'], $label);
            }

            case 'upload_video_from_url': {
                $user = self::user($cid);
                if (!S3Service::configured()) { throw new RuntimeException('Uploads unavailable (storage not configured)'); }
                @set_time_limit(600);
                $label = trim((string) ($a['name'] ?? '')) !== '' ? (string) $a['name'] : 'Uploaded video';
                return self::ingestVideoFromUrl($cid, $user, (string) ($a['url'] ?? ''), (string) ($a['poster_url'] ?? ''), $label);
            }

            // Messaging
            case 'send_message': {
                $to = (int) ($a['to_user_id'] ?? 0);
                $body = trim((string) ($a['body'] ?? ''));
                if ($to <= 0 || $to === $cid) { throw new InvalidArgumentException('Invalid recipient'); }
                list($asset_ids, $price) = self::message_media($cid, $a);
                if ($body === '' && empty($asset_ids)) { throw new InvalidArgumentException('body or asset_ids is required'); }
                $mm = new MessagesModel();
                $conv = (int) $mm->open_between($cid, $to);
                if ($conv <= 0) { throw new RuntimeException('You can only message people who follow you, subscribe to you, or whom you follow'); }
                $mid = $mm->send($conv, $cid, mb_substr($body, 0, 2000), null, $asset_ids, $price);
                InboxAutomationService::notify_new_message($to, $cid, MessagesModel::preview_text($body, count($asset_ids), $price), $conv);
                return array('conversation_id' => $conv, 'message_id' => (int) $mid, 'price_credits' => $price);
            }
            case 'send_broadcast': {
                $body = trim((string) ($a['body'] ?? ''));
                list($asset_ids, $price) = self::message_media($cid, $a);
                if ($body === '' && empty($asset_ids)) { throw new InvalidArgumentException('body or asset_ids is required'); }
                $segs = BroadcastsModel::clean_segments($a['segments'] ?? ($a['segment'] ?? array('all')));
                if (empty($segs)) { $segs = array('all'); }
                list($bid, $count, $queued) = (new BroadcastsModel())->create_and_send($cid, $segs, mb_substr($body, 0, 2000), $asset_ids, $price);
                if ((int) $count <= 0) { throw new RuntimeException('No one is in that audience yet'); }
                return array('broadcast_id' => (int) $bid, 'recipients' => (int) $count, 'queued' => (bool) $queued);
            }
            case 'list_auto_messages': {
                $rows = (new AutoMessagesModel())->get_for_creator($cid);
                $out = array();
                foreach (InboxAutomationService::trigger_meta() as $k => $meta) {
                    $r = $rows[$k] ?? null;
                    $out[] = array('trigger' => $k, 'label' => $meta[0], 'when' => $meta[1], 'enabled' => $r ? !empty($r['enabled']) : false,
                        'text' => $r ? (string) $r['text'] : '', 'asset_ids' => $r ? (array) $r['asset_ids'] : array(),
                        'price' => $r ? (int) round(((int) $r['price_credits']) / 10) : 0);
                }
                return array('auto_messages' => $out);
            }
            case 'save_auto_message': {
                $trigger = (string) ($a['trigger'] ?? '');
                if (!AutoMessagesModel::is_trigger($trigger)) { throw new InvalidArgumentException('Unknown trigger'); }
                $model = new AutoMessagesModel();
                if (array_key_exists('enabled', $a) && !$a['enabled']) {
                    $model->delete_one($cid, $trigger);
                    return array('trigger' => $trigger, 'enabled' => false);
                }
                $text = trim((string) ($a['text'] ?? ''));
                list($asset_ids, $price) = self::message_media($cid, $a);
                if ($text === '' && empty($asset_ids)) {
                    $cur = $model->get_one($cid, $trigger);
                    if (!$cur) { throw new InvalidArgumentException('text or asset_ids is required'); }
                    $text = (string) $cur['text']; $asset_ids = (array) $cur['asset_ids']; $price = (int) $cur['price_credits'];
                }
                $model->save($cid, $trigger, $text, true, $asset_ids, $price);
                return array('trigger' => $trigger, 'enabled' => true);
            }

            // AI influencers
            case 'list_influencers': {
                $out = array();
                foreach ((new InfluencersModel())->list_for_creator($cid) as $row) { $out[] = InfluencerService::influencer_json($cid, $row); }
                return array('influencers' => $out);
            }
            case 'get_influencer':            return InfluencerService::influencer_json($cid, self::influencer($cid, $a));
            case 'influencer_model_options':  return array('enabled' => InfluencerConfig::enabled(), 'reference' => InfluencerConfig::picker_options('reference'), 'image' => InfluencerConfig::picker_options('image'), 'video' => InfluencerConfig::picker_options('video'), 'enhance' => InfluencerConfig::picker_options('enhance'));
            case 'create_influencer': {
                self::requirePlan($cid, 'ai_tools', 'AI influencers require a Pro or Studio plan');
                return self::result(InfluencerActions::create($cid, (string) ($a['name'] ?? ''), (string) ($a['path'] ?? 'photos')));
            }
            case 'update_influencer': {
                $in = array_intersect_key($a, array_flip(array('name', 'source_description', 'reference_model_key', 'steer_text', 'prompt_defaults', 'negative_prompt', 'is_public', 'share_accounts')));
                return self::result(InfluencerActions::update($cid, self::influencer($cid, $a), $in));
            }
            case 'delete_influencer':          return self::result(InfluencerActions::delete($cid, self::influencer($cid, $a)));
            case 'add_influencer_photo': {
                $user = self::user($cid);
                self::requirePlan($cid, 'ai_tools', 'AI influencers require a Pro or Studio plan');
                $img  = MediaIngestService::fetch_image((string) ($a['url'] ?? ''));
                return self::result(InfluencerActions::attach_photo($cid, $user, self::influencer($cid, $a), $img['bytes'], $img['ext'], $img['mime'], (string) ($a['role'] ?? 'upload')));
            }
            case 'remove_influencer_image':    return self::result(InfluencerActions::remove_image($cid, self::influencer($cid, $a), (int) ($a['asset_id'] ?? 0)));
            case 'delete_influencer_asset':    return self::result(InfluencerActions::delete_asset($cid, self::influencer($cid, $a), (int) ($a['asset_id'] ?? 0)));
            case 'list_influencer_images': {
                $infl = self::influencer($cid, $a);
                $role = in_array($a['role'] ?? '', InfluencerImagesModel::ROLES, true) ? $a['role'] : '';
                return array('images' => InfluencerService::images_json($cid, (int) $infl['id'], $role));
            }
            case 'generate_influencer_reference': {
                self::requirePlan($cid, 'ai_tools', 'AI influencers require a Pro or Studio plan');
                return self::result(InfluencerActions::reference_generate($cid, self::influencer($cid, $a), array_intersect_key($a, array_flip(array('source_description', 'reference_model_key')))));
            }
            case 'approve_influencer_reference': return self::result(InfluencerActions::reference_pick($cid, self::influencer($cid, $a), (int) ($a['asset_id'] ?? 0)));
            case 'generate_influencer_training_set': {
                self::requirePlan($cid, 'ai_tools', 'AI influencers require a Pro or Studio plan');
                return self::result(InfluencerActions::training_set_start($cid, self::influencer($cid, $a), array_key_exists('steer_text', $a) ? (string) $a['steer_text'] : null));
            }
            case 'get_influencer_training_set':   return self::result(InfluencerActions::training_set_status($cid, self::influencer($cid, $a)));
            case 'retry_influencer_training_slot': return self::result(InfluencerActions::training_set_retry($cid, self::influencer($cid, $a), (int) ($a['job_id'] ?? 0)));
            case 'train_influencer': {
                self::requirePlan($cid, 'ai_tools', 'AI influencers require a Pro or Studio plan');
                return self::result(InfluencerActions::train($cid, self::influencer($cid, $a)));
            }
            case 'list_influencer_models':     return self::result(InfluencerActions::models($cid, self::influencer($cid, $a)));
            case 'generate_influencer_image': {
                self::requirePlan($cid, 'ai_tools', 'AI influencers require a Pro or Studio plan');
                return self::result(InfluencerActions::generate_image($cid, self::influencer($cid, $a), $a, 'studio'));
            }
            case 'generate_influencer_video': {
                self::requirePlan($cid, 'ai_tools', 'AI influencers require a Pro or Studio plan');
                return self::result(InfluencerActions::generate_video($cid, self::influencer($cid, $a), $a, 'studio'));
            }
            case 'enhance_influencer_image': {
                self::requirePlan($cid, 'ai_tools', 'AI influencers require a Pro or Studio plan');
                return self::result(InfluencerActions::enhance($cid, self::influencer($cid, $a), (int) ($a['asset_id'] ?? 0), (string) ($a['model_key'] ?? ''), (string) ($a['level'] ?? 'safe'), 'studio'));
            }
            case 'write_influencer_prompt':    return self::result(InfluencerActions::prompt_auto($cid, self::influencer($cid, $a), (string) ($a['hint'] ?? ''), (($a['kind'] ?? 'image') === 'video') ? 'video' : 'image'));
            case 'get_influencer_job': {
                $job = (new InfluencerJobsModel())->get_one($cid, (int) ($a['job_id'] ?? 0));
                return array('job' => InfluencerJobService::job_json($cid, self::need($job, 'Job not found')));
            }
            case 'list_influencer_jobs':       return self::result(InfluencerActions::jobs($cid, self::influencer($cid, $a), (string) ($a['type'] ?? ''), self::lim($a, 24)));

            // Run automation now (generates + publishes)
            case 'run_automation_now': {
                $user = self::user($cid);
                $rule = (new SchedulerRulesModel())->get_one($cid, $iid);
                if (!$rule) { throw new InvalidArgumentException('Automation not found'); }
                $res = AutoPostService::run_rule($rule, $user);
                (new SchedulerRunsModel())->add((int) $rule['id'], $cid, $res['ok'] ? 'success' : 'failed', $res['post_id'], $res['message']);
                (new SchedulerRulesModel())->set_last_run((int) $rule['id'], $res['ok'] ? 'success' : 'failed');
                if (empty($res['ok'])) { throw new RuntimeException($res['message']); }
                return array('post_id' => (int) $res['post_id'], 'message' => $res['message']);
            }

            default:
                throw new InvalidArgumentException('Unknown tool: ' . $name);
        }
    }

    // ---- helpers for the high-impact tools ----

    private static function user($cid){
        $r = (new UsersModel())->get_user_by_id((int) $cid);
        if (!is_array($r) || count($r) !== 1) { throw new RuntimeException('Account not found'); }
        return $r[0];
    }

    /** Every feature is on every plan; the only gate is having an active plan. */
    private static function requirePlan($cid, $capability, $message){
        if (!Plan::can_use_creator_features(self::user($cid))) { throw new RuntimeException('This needs an active plan. Choose one at /account/billing.'); }
    }

    private static function planFields($a, $row){
        $g = function ($k, $d) use ($a, $row) { return array_key_exists($k, $a) ? $a[$k] : ($row[$k] ?? $d); };
        $bi = $g('billing_interval', 'month');
        if (!in_array($bi, array('week', 'month', 'year'), true)) { $bi = 'month'; }
        $cents = array_key_exists('price', $a) ? (int) round(((float) $a['price']) * 100) : (int) ($row['price_cents'] ?? 0);
        if ($cents !== 0 && $cents < 100) { throw new InvalidArgumentException('A paid tier must be at least $1.00 (use 0 for a free tier)'); }
        return array(
            'name' => trim((string) $g('name', '')), 'price_cents' => $cents, 'billing_interval' => $bi,
            'trial_enabled' => (int) ($row['trial_enabled'] ?? 0), 'trial_value' => (int) ($row['trial_value'] ?? 0),
            'trial_unit' => (string) ($row['trial_unit'] ?? 'day'),
            'description' => (string) $g('description', ''), 'perks' => (string) $g('perks', ''),
        );
    }

    private static function promoFields($a, $row){
        $g = function ($k, $d) use ($a, $row) { return array_key_exists($k, $a) ? $a[$k] : ($row[$k] ?? $d); };
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $g('code', '')));
        if (strlen($code) < 3 || strlen($code) > 40) { throw new InvalidArgumentException('code must be 3-40 letters/numbers'); }
        $pct = (int) $g('percent_off', 0);
        if ($pct < 1 || $pct > 100) { throw new InvalidArgumentException('percent_off must be 1-100'); }
        $applies = $g('applies_to', 'all');
        if (!in_array($applies, array('all', 'subscription', 'ppv'), true)) { $applies = 'all'; }
        $max = $g('max_redemptions', null);
        $max = ($max === null || $max === '' || (int) $max <= 0) ? null : (int) $max;
        $exp = $g('expires_at', null);
        $exp = (!empty($exp) && ($ts = strtotime((string) $exp))) ? date('Y-m-d H:i:s', $ts) : null;
        return array('code' => $code, 'percent_off' => $pct, 'applies_to' => $applies, 'max_redemptions' => $max, 'expires_at' => $exp);
    }

    /** Owned assets that are 'ready' and not deleted (bundle/item rules). */
    private static function ownedReadyAssetIds($cid, $ids){
        $ready = array();
        foreach ((array) (new MediaAssetsModel())->get_for_creator($cid, array()) as $x) {
            if (($x['status'] ?? '') === 'ready' && empty($x['deleted_at'])) { $ready[(int) $x['id']] = true; }
        }
        $out = array();
        foreach ((array) $ids as $id) { $id = (int) $id; if (isset($ready[$id])) { $out[$id] = $id; } }
        return array_values($out);
    }

    /* ---- ingest helpers: thin delegates to MediaIngestService (shared with influencer generations) ---- */

    /** Write generated/fetched image bytes into the media library via the normal pipeline. */
    private static function ingestImage($cid, $user, $bytes, $ext, $mime, $label){
        return MediaIngestService::ingest_image($cid, $user, $bytes, $ext, $mime, $label);
    }

    /** Video from a URL: store the original, build poster/thumb (ffmpeg, else poster_url), mark ready. */
    private static function ingestVideoFromUrl($cid, $user, $url, $poster_url, $label){
        return MediaIngestService::ingest_video_from_url($cid, $user, $url, $poster_url, $label);
    }

    /** Fetch a PUBLIC image URL with SSRF protection; validate it is a real image. */
    private static function safeFetchImage($url){
        return MediaIngestService::fetch_image($url);
    }

    /** Cross-post after publish/schedule when share_accounts was given; the outcome rides on the result. */
    private static function with_share(array $res, $cid, array $post, array $a, $scheduled_iso){
        $ids = array_values(array_filter(array_map('strval', (array) ($a['share_accounts'] ?? array()))));
        if (empty($ids)) { return $res; }
        $rows = (new UsersModel())->get_user_by_id((int) $cid);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$user) { return $res; }
        $share = SocialShareService::share($user, $post, $ids, $scheduled_iso);
        $res['shared']      = (int) ($share['shared'] ?? 0);
        $res['share_error'] = (string) ($share['error'] ?? '');
        return $res;
    }


    /** The creator's influencer from influencer_id (or id), or an error. */
    /** Owned, ready asset ids + credit price from a tool call's asset_ids / price (dollars). */
    private static function message_media($cid, array $a): array {
        $ids = array_slice(array_map('intval', (array) ($a['asset_ids'] ?? array())), 0, 10);
        $asset_ids = array();
        if (!empty($ids)) {
            foreach ((new MediaAssetsModel())->get_owned_ready($cid, $ids) as $row) { $asset_ids[] = (int) $row['id']; }
            if (empty($asset_ids)) { throw new InvalidArgumentException('asset_ids must be ready media in your library'); }
        }
        $price = 0;
        if (!empty($asset_ids) && (int) ($a['price'] ?? 0) > 0) { $price = max(3, min(500, (int) $a['price'])) * 10; }
        return array($asset_ids, $price);
    }

    private static function influencer($cid, array $a){
        $id = (int) ($a['influencer_id'] ?? ($a['id'] ?? 0));
        return self::need((new InfluencersModel())->get_one($cid, $id), 'Influencer not found');
    }
    /** Shared-service result -> tool payload (errors become tool errors). */
    private static function result(array $r){
        if (empty($r['ok'])) { throw new RuntimeException((string) ($r['error'] ?? 'Request failed')); }
        unset($r['ok'], $r['error']);
        return $r;
    }
    private static function lim($a, $default){
        $n = (int) ($a['limit'] ?? $default);
        return ($n < 1) ? $default : (($n > 100) ? 100 : $n);
    }
    private static function need($row, $msg){
        if (!$row) { throw new InvalidArgumentException($msg); }
        return $row;
    }
    /** Keep only asset ids that belong to this creator (prevents referencing others' media). */
    private static function ownedAssetIds($cid, $ids){
        $m = new MediaAssetsModel();
        $out = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id > 0 && $m->get_one($cid, $id)) { $out[] = $id; }
        }
        return $out;
    }

}
