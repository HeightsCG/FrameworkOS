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
        $t[] = array('name' => 'publish_post',  'description' => 'Publish a post now (fails if it has no ready media).', 'inputSchema' => $id);
        $t[] = array('name' => 'schedule_post', 'description' => 'Schedule a post for a future UTC datetime.', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id', 'scheduled_at'),
            'properties' => array('id' => array('type' => 'integer'), 'scheduled_at' => array('type' => 'string', 'description' => 'UTC "YYYY-MM-DD HH:MM:SS"'))));
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
        $t[] = array('name' => 'update_event', 'description' => 'Update an event (send only fields to change).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id'), 'properties' => array('id' => array('type' => 'integer'))));
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
        $t[] = array('name' => 'update_service', 'description' => 'Update a service (send only fields to change).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id'), 'properties' => array('id' => array('type' => 'integer'))));
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
        $t[] = array('name' => 'update_automation',    'description' => 'Update an automation (send full config).', 'inputSchema' => array(
            'type' => 'object', 'required' => array('id'), 'properties' => array('id' => array('type' => 'integer'))));
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
            case 'publish_post': {
                $p = new PostsModel();
                if (!$p->get_one($cid, $iid)) { throw new InvalidArgumentException('Post not found'); }
                if ($p->count_missing_assets($iid) > 0) { throw new RuntimeException('Post has no ready media; add media before publishing'); }
                return $ok($p->set_state($cid, $iid, 'published', null, date('Y-m-d H:i:s')));
            }
            case 'schedule_post': {
                $when = trim((string) ($a['scheduled_at'] ?? ''));
                if ($when === '') { throw new InvalidArgumentException('scheduled_at is required (UTC)'); }
                return $ok((new PostsModel())->set_state($cid, $iid, 'scheduled', $when));
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

            default:
                throw new InvalidArgumentException('Unknown tool: ' . $name);
        }
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
