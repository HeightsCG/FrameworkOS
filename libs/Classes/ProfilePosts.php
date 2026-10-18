<?php
/**
 * The post grid on a creator's profile (/@handle), one page at a time. Used by ProfileController for the first page and by
 * /api/profile_posts for "Load More", so both run the same gate and entitlement code.
 *
 * Entitlement is decided HERE (server-side): the real media URL is signed only for entitled viewers; everyone else gets only
 * the blurred locked preview, so the clear rendition never reaches the browser.
 */
class ProfilePosts {

    const PAGE = 24;

    /**
     * Can this viewer see the creator's page? Same rules as the page itself: an active Creator account on a paid plan,
     * and no block either way (the creator viewing their own page is never blocked).
     */
    public static function creator_visible(array $user, int $viewer_id): bool {
        if ((string) ($user['user_status'] ?? 'Active') === 'Disabled') { return false; }
        $users = new UsersModel();
        if ((int) $user['role_id'] !== (int) $users->get_role_id_by_name('Creator')) { return false; }
        if (!Plan::has_paid_plan($user)) { return false; }
        if ($viewer_id > 0 && $viewer_id !== (int) $user['user_id'] && (new BlocksModel())->either_blocked($viewer_id, (int) $user['user_id'])) { return false; }
        return true;
    }

    /**
     * Up to $limit visible cards starting at raw post $offset (newest first). Posts the gate hides are skipped, so a page can
     * read further than $limit rows; next_offset is where the following page starts.
     * Returns array('cards' => [...], 'has_more' => bool, 'next_offset' => int).
     */
    public static function page(array $user, string $display_name, int $viewer_id, int $offset = 0, int $limit = self::PAGE, string $q = ''): array {
        $creator_id = (int) $user['user_id'];
        $limit  = max(1, min(60, $limit));
        $offset = max(0, $offset);
        $viewer_logged_in = $viewer_id > 0;
        $posts_model = new PostsModel();

        // Viewer context, once per page.
        $subs = new CreatorSubscriptionsModel();
        $subscribed_plan_ids = $viewer_logged_in ? (array) $subs->active_plan_ids($viewer_id, $creator_id) : array();
        $max_tier_price      = $viewer_logged_in ? $subs->max_active_tier_price($viewer_id, $creator_id) : null;
        $plan_prices = array();
        foreach ((new CreatorPlansModel())->get_for_user($creator_id) as $pl) {
            $plan_prices[(int) $pl['id']] = (int) $pl['price_cents'];
        }
        // Moderation gate: content must be scanned before it's available to viewers.
        //  - 'pending' (an image not yet cleared) → hidden from everyone but the creator.
        //  - 'flagged' (adult) → hidden from viewers with "show adult content" off.
        // No owner exemption: the creator sees exactly what a visitor with their settings sees.
        // Logged-out viewers default to adult OFF.
        $show_adult = false;
        if ($viewer_logged_in) {
            $viewer_rows = (new UsersModel())->get_user_by_id($viewer_id);
            $viewer_row  = (is_array($viewer_rows) && count($viewer_rows) === 1) ? $viewer_rows[0] : null;
            $show_adult  = AgeVerification::adult_allowed($viewer_row);   // toggle on AND age verified
        }

        $cards = array();
        $raw   = $offset;          // next raw row to read
        $done  = false;            // no more published posts
        $chunk_size = min(60, $limit * 2);
        while (count($cards) < $limit && !$done) {
            $published = (array) $posts_model->get_published_for_creator($creator_id, $chunk_size, $raw, $q);
            if (count($published) < $chunk_size) { $done = true; }
            if (!$published) { break; }

            $post_ids  = array_map(function ($p) { return (int) $p['id']; }, $published);
            $liked_map    = $viewer_logged_in ? (new PostLikesModel())->liked_map($viewer_id, $post_ids) : array();
            $unlocked_map = $viewer_logged_in ? (new PpvUnlocksModel())->unlocked_map($viewer_id, $post_ids) : array();
            // Always compute the gate (even for the creator) so 'blocked' content is hidden
            // from EVERYONE, including the creator's own public profile.
            $moderation_map = $posts_model->moderation_map($post_ids);
            $tier_map       = $posts_model->tiers_for_posts($post_ids);
            $assets_map     = $posts_model->assets_for_posts($post_ids);

            foreach ($published as $i => $p) {
                if (count($cards) >= $limit) { $done = false; break; }
                $raw++;
                $card = self::card($p, $assets_map[(int) $p['id']] ?? array(), $moderation_map, $show_adult, $unlocked_map, $liked_map,
                    $tier_map, $subscribed_plan_ids, $max_tier_price, $plan_prices, $display_name);
                if ($card !== null) { $cards[] = $card; }
            }
        }
        // Stopped on a full page: is there anything after it?
        $has_more = !$done && count($cards) >= $limit && !empty($posts_model->get_published_for_creator($creator_id, 1, $raw));
        return array('cards' => $cards, 'has_more' => $has_more, 'next_offset' => $raw);
    }

    /** One grid card, or null when the gate hides the post from this viewer. */
    private static function card(array $p, array $assets, array $moderation_map, bool $show_adult, array $unlocked_map, array $liked_map,
                                 array $tier_map, array $subscribed_plan_ids, $max_tier_price, array $plan_prices, string $display_name): ?array {
        $mod = $moderation_map[(int) $p['id']] ?? '';
        if ($mod === 'blocked') { return null; }                       // quarantined — never shown to anyone
        if ($mod === 'pending') { return null; }                       // not yet scanned — not available to anyone
        if ($mod === 'adult' && !$show_adult) { return null; }         // approved adult — viewer opted out
        $audience = $p['audience'];
        if ($audience === 'free') {
            $entitled = true;
        } elseif ($audience === 'ppv') {
            // PPV is locked for everyone (incl. subscribers) until purchased.
            $entitled = isset($unlocked_map[(int) $p['id']]);
        } else { // subscribers-only: any of the post's tiers (or any active plan when none set)
            $entitled = PostsModel::tier_entitled($p, $tier_map[(int) $p['id']] ?? array(), $subscribed_plan_ids, $max_tier_price, $plan_prices);
        }

        $cover = null;
        foreach ($assets as $a) { if ((int) $a['is_cover'] === 1) { $cover = $a; break; } }
        if (!$cover && !empty($assets)) { $cover = $assets[0]; }
        $cap = trim((string) $p['caption']);

        $card = array(
            'id'           => (int) $p['id'],
            'caption'      => $cap,
            'excerpt'      => mb_substr($cap, 0, 120),
            'audience'     => $audience,
            'entitled'     => $entitled,
            'published_at' => !empty($p['published_at']) ? date('M j, Y', strtotime((string) $p['published_at'])) : '',
            'media_count'  => count($assets),
            'has_video'    => false,
            'cover'        => '',   // uniform grid thumbnail
            'likes'        => (int) $p['likes'],
            'liked'        => isset($liked_map[(int) $p['id']]),
            'comments'     => (int) $p['comments'],
            'views'        => (int) $p['views'],
            'comments_enabled' => (int) $p['comments_enabled'],
        );
        if ($audience === 'ppv') {
            $card['ppv_price_credits'] = (int) $p['ppv_price_credits'];
            $card['ppv_price_dollars'] = Price::input((int) $p['ppv_price_credits']);
            $card['unlocked']          = isset($unlocked_map[(int) $p['id']]);
        }

        if ($entitled) {
            // PPV is bought per-post — an entitled viewer paid (or owns it), so serve it
            // unwatermarked. Free/subscriber content keeps the watermarked display variant.
            $img_variant = ($audience === 'ppv') ? 'original' : 'display';
            $card['assets'] = array();
            foreach ($assets as $a) {
                if (!empty($a['deleted_at']) || $a['status'] !== 'ready') { continue; }
                if ($a['type'] === 'video') {
                    $card['has_video'] = true;
                    $card['assets'][] = array('type' => 'video',
                        'url' => MediaService::signed_variant($a, 'original', 900),
                        'poster' => MediaService::signed_variant($a, 'poster', 900));
                } else {
                    $card['assets'][] = array('type' => 'image',
                        'url' => MediaService::signed_variant($a, $img_variant, 900), 'poster' => '');
                }
            }
            // Grid card uses a small, uniform thumbnail (poster for a video cover). Free posts use the public,
            // cacheable webp copy when it exists (PublicThumbService); anything else stays a signed URL.
            $pub = ($cover && $audience === 'free') ? PublicThumbService::url($cover, 'grid') : '';
            $card['cover'] = $pub !== '' ? $pub : ($cover ? MediaService::signed_variant($cover, ($cover['type'] === 'video' ? 'poster' : 'thumb'), 900) : '');
            $card['cover_alt'] = PublicThumbService::alt($cap, $display_name);
        } else {
            // Non-entitled: only the blurred variant is ever signed.
            $card['cover'] = $cover ? MediaService::signed_variant($cover, 'blurred', 900) : '';
            $card['locked_url'] = $card['cover'];
        }
        return $card;
    }

    /** The grid buttons for these cards (the same partial the page renders). */
    public static function render(array $cards): string {
        ob_start();
        foreach ($cards as $c) { require Main::app_path() . '/app/views/profile/_post_card.php'; }
        return (string) ob_get_clean();
    }
}
