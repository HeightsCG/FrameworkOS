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
        $t[] = array('name' => 'generate_image', 'description' => 'Generate an AI image (Pro/Studio) and add it to the media library. Returns the new asset id.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('prompt'),
            'properties' => array('prompt' => array('type' => 'string'),
                'size' => array('type' => 'string', 'enum' => array('square', 'portrait', 'landscape')),
                'use_brand' => array('type' => 'boolean', 'description' => 'Apply brand style (default true).'))));
        $t[] = array('name' => 'upload_image_from_url', 'description' => 'Fetch a PUBLIC image URL and add it to the media library. Images only.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('url'),
            'properties' => array('url' => array('type' => 'string', 'description' => 'Public https URL of a JPG/PNG/WebP/GIF image.'),
                'name' => array('type' => 'string'))));

        // ---- Messaging ----
        $t[] = array('name' => 'send_message', 'description' => 'Send a direct message to a user (must follow/subscribe to you or vice-versa).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('to_user_id', 'body'),
            'properties' => array('to_user_id' => array('type' => 'integer'), 'body' => array('type' => 'string'))));
        $t[] = array('name' => 'send_broadcast', 'description' => 'Broadcast a message to an audience segment.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('body'),
            'properties' => array('body' => array('type' => 'string'),
                'segment' => array('type' => 'string', 'enum' => array('all', 'followers', 'subscribers')))));

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
                $res = ImageGenService::generate($final, ImageGenService::dimensions($size));
                if (empty($res['ok'])) { throw new RuntimeException('Generation failed: ' . ($res['error'] ?? 'unknown')); }
                return self::ingestImage($cid, $user, $res['bytes'], 'png', 'image/png', 'Generated · ' . mb_substr($prompt, 0, 40));
            }
            case 'upload_image_from_url': {
                $user = self::user($cid);
                if (!S3Service::configured()) { throw new RuntimeException('Uploads unavailable (storage not configured)'); }
                $img = self::safeFetchImage((string) ($a['url'] ?? ''));
                $label = trim((string) ($a['name'] ?? '')) !== '' ? (string) $a['name'] : 'Uploaded image';
                return self::ingestImage($cid, $user, $img['bytes'], $img['ext'], $img['mime'], $label);
            }

            // Messaging
            case 'send_message': {
                $to = (int) ($a['to_user_id'] ?? 0);
                $body = trim((string) ($a['body'] ?? ''));
                if ($to <= 0 || $to === $cid) { throw new InvalidArgumentException('Invalid recipient'); }
                if ($body === '') { throw new InvalidArgumentException('body is required'); }
                $mm = new MessagesModel();
                $conv = (int) $mm->open_between($cid, $to);
                if ($conv <= 0) { throw new RuntimeException('You can only message people who follow you, subscribe to you, or whom you follow'); }
                $mid = $mm->send($conv, $cid, mb_substr($body, 0, 2000));
                return array('conversation_id' => $conv, 'message_id' => (int) $mid);
            }
            case 'send_broadcast': {
                $body = trim((string) ($a['body'] ?? ''));
                if ($body === '') { throw new InvalidArgumentException('body is required'); }
                $seg = in_array($a['segment'] ?? 'all', array('all', 'followers', 'subscribers'), true) ? $a['segment'] : 'all';
                list($bid, $count) = (new BroadcastsModel())->create_and_send($cid, $seg, mb_substr($body, 0, 2000));
                if ((int) $count <= 0) { throw new RuntimeException('No one is in that audience segment yet'); }
                return array('broadcast_id' => (int) $bid, 'recipients' => (int) $count);
            }

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

    private static function requirePlan($cid, $capability, $message){
        if (!Plan::can(self::user($cid), $capability)) { throw new RuntimeException($message); }
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

    /** Write generated/fetched image bytes into the media library via the normal pipeline. */
    private static function ingestImage($cid, $user, $bytes, $ext, $mime, $label){
        $tmp = tempnam(sys_get_temp_dir(), 'mcpimg');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) { throw new RuntimeException('Could not buffer the image'); }
        $mm  = new MediaAssetsModel();
        $aid = (int) $mm->add($cid, 'image', mb_substr($label, 0, 60) . '.' . $ext, $mime, 'processing');
        if ($aid <= 0) { @unlink($tmp); throw new RuntimeException('Could not create the media asset'); }
        $r = MediaService::process_image($cid, $aid, $tmp, $ext, $mime, $user, !empty($user['watermark_enabled']));
        @unlink($tmp);
        if (isset($r['error'])) { $mm->set_failed($cid, $aid, $r['error']); throw new RuntimeException($r['error']); }
        $mm->set_ready($cid, $aid, $r);
        return array('asset_id' => $aid, 'type' => 'image');
    }

    /** Fetch a PUBLIC image URL with SSRF protection; validate it is a real image. */
    private static function safeFetchImage($url){
        $url = trim((string) $url);
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), array('http', 'https'), true)) {
            throw new InvalidArgumentException('Provide a valid http(s) image URL');
        }
        // Resolve + reject private/reserved addresses (SSRF guard).
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : (array) @gethostbynamel($host);
        if (empty($ips)) { throw new RuntimeException('Could not resolve that host'); }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('That URL resolves to a private/blocked address');
            }
        }
        $max = 20 * 1024 * 1024;
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => array($host . ':' . (isset($p['port']) ? (int) $p['port'] : (strtolower($p['scheme']) === 'https' ? 443 : 80)) . ':' . $ips[0]),
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf, $max) { $buf .= $chunk; return (strlen($buf) > $max) ? 0 : strlen($chunk); },
        ));
        $okc = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (($okc === false && $buf === '') || $code >= 400 || $buf === '') { throw new RuntimeException('Could not fetch that image URL'); }

        $info = @getimagesizefromstring($buf);
        $map = array(IMAGETYPE_JPEG => array('jpg', 'image/jpeg'), IMAGETYPE_PNG => array('png', 'image/png'),
                     IMAGETYPE_WEBP => array('webp', 'image/webp'), IMAGETYPE_GIF => array('gif', 'image/gif'));
        if (!$info || !isset($map[$info[2]])) { throw new RuntimeException('That URL is not a supported image (JPG/PNG/WebP/GIF)'); }
        return array('bytes' => $buf, 'ext' => $map[$info[2]][0], 'mime' => $map[$info[2]][1]);
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
