<?php
/** Fan-facing feed, post detail, engagement, PPV/bundle unlocks and creator-plan subscriptions. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiPostsController extends BaseApiController {

    /**
     * Home discovery feed — cross-creator published content, newest first, paginated.
     * All gating is decided HERE (server-side): the clear cover is signed only for an
     * entitled viewer; everyone else gets the blurred teaser. Mirrors the safety gate
     * used on the profile grid (blocked hidden from all, pending withheld, adult opt-in).
     */
    public function feedAction(){
        $viewer = (int) Session::get('user_id');
        $logged = $viewer > 0;

        $show_adult = false;
        if ($logged) {
            $rows = (new UsersModel())->get_user_by_id($viewer);
            $row  = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            $show_adult = !empty($row['adult_content_enabled']);
        }

        $limit  = 4;
        $offset = max(0, (int) ($this->post['offset'] ?? 0));

        $posts_model = new PostsModel();
        // Pull one extra row to learn whether another page exists.
        $rows     = (array) (new FeedModel())->recent($limit + 1, $offset, $viewer);
        $has_more = count($rows) > $limit;
        if ($has_more) { $rows = array_slice($rows, 0, $limit); }

        $post_ids       = array_map(function ($p) { return (int) $p['id']; }, $rows);
        $moderation_map = !empty($post_ids) ? $posts_model->moderation_map($post_ids) : [];
        $unlocked_map   = ($logged && !empty($post_ids)) ? (new PpvUnlocksModel())->unlocked_map($viewer, $post_ids) : [];
        $liked_map      = ($logged && !empty($post_ids)) ? (new PostLikesModel())->liked_map($viewer, $post_ids) : [];

        $cards = [];
        // Discover is the shared surface: the creator is treated exactly like any other
        // viewer here (no owner exemptions). The Studio is where they see their own work.
        foreach ($rows as $p) {
            $id  = (int) $p['id'];
            $mod = $moderation_map[$id] ?? '';
            if ($mod === 'blocked') { continue; }
            if ($mod === 'pending') { continue; }
            if ($mod === 'adult' && !$show_adult) { continue; }

            $audience = (string) $p['audience'];
            if ($audience === 'free') {
                $entitled = true;
            } elseif ($audience === 'ppv') {
                $entitled = isset($unlocked_map[$id]);
            } else {
                $entitled = false; // subscribers-only teaser on the card; unlocked in the lightbox
            }

            $display = trim((string) ($p['display_name'] ?? ''));
            if ($display === '') { $display = '@' . (string) $p['u_name']; }
            $cover_asset = [
                'type'        => (string) ($p['cover_type'] ?? 'image'),
                'thumb_key'   => (string) ($p['cover_thumb_key'] ?? ''),
                'poster_key'  => (string) ($p['cover_poster_key'] ?? ''),
                'blurred_key' => (string) ($p['cover_blurred_key'] ?? ''),
            ];
            $has_cover = ($cover_asset['thumb_key'] !== '' || $cover_asset['poster_key'] !== '' || $cover_asset['blurred_key'] !== '');
            $cap = trim((string) $p['caption']);

            $card = [
                'id'          => $id,
                'profile_url' => '/@' . rawurlencode((string) $p['u_name']),
                'author'      => $display,
                'handle'      => (string) $p['u_name'],
                'avatar'      => (string) ($p['avatar_url'] ?? ''),
                'caption'     => mb_substr($cap, 0, 140),
                'audience'    => $audience,
                'entitled'    => $entitled,
                'is_video'    => ($cover_asset['type'] === 'video'),
                'media_count' => (int) $p['asset_count'],
                'views'       => (int) $p['views'],
                'likes'       => (int) $p['likes'],
                'liked'       => isset($liked_map[$id]),
                'comments'    => (int) $p['comments'],
            ];
            if ($audience === 'ppv') {
                $card['ppv_price_credits'] = (int) $p['ppv_price_credits'];
                $card['unlocked']          = isset($unlocked_map[$id]);
            }
            if (!$has_cover) {
                $card['cover'] = '';
            } elseif ($entitled) {
                $card['cover'] = MediaService::signed_variant($cover_asset, ($cover_asset['type'] === 'video' ? 'poster' : 'thumb'), 900);
            } else {
                $card['cover'] = MediaService::signed_variant($cover_asset, 'blurred', 900);
            }
            $cards[] = $card;
        }

        $this->jsonSuccess(['viewer_logged' => $logged, 'viewer_credits' => $logged ? (int) (new CreditsModel())->get_balance($viewer) : 0, 'items' => $cards, 'has_more' => $has_more, 'next_offset' => $offset + $limit]);
    }

    /**
     * Count of posts published since a watermark id — the Home feed polls this to
     * raise its "N new posts" alert. Approximate by design (doesn't re-run the full
     * moderation/adult gate); it's a nudge to refresh, not an exact figure.
     */
    public function feed_newAction(){
        $since = (int) ($this->post['since_id'] ?? 0);
        $this->jsonSuccess(['count' => (new FeedModel())->count_since($since, 50, (int) Session::get('user_id'))]);
    }

    /**
     * Full detail for a single post — feeds the Home lightbox. Entitlement is decided
     * here: an entitled viewer (owner, free, active subscriber, or PPV-unlocked) gets
     * signed asset URLs; everyone else gets only the blurred teaser + the unlock/subscribe
     * path. Author identity is carried by the feed card, so it isn't repeated here.
     */
    public function post_detailAction(){
        $viewer = (int) Session::get('user_id');
        $post   = (new PostsModel())->get_by_id((int) ($this->post['id'] ?? 0));
        if (!$post || ($post['state'] ?? '') !== 'published') {
            $this->jsonError('Post not found');
        }
        if (empty($post['on_cls'])) { $this->jsonError('Post not found'); }   // socials-only post
        if ($viewer > 0 && (new BlocksModel())->either_blocked($viewer, (int) $post['creator_id'])) { $this->jsonError('Post not found'); }
        $id = (int) $post['id'];

        // Same moderation gate the feed applies, re-checked so a post can't be
        // reached by guessing its id. Like the feed, the creator gets no owner exemption.
        if (!$this->moderation_ok($id, $viewer)) {
            $this->jsonError('Post not available');
        }

        $audience = (string) $post['audience'];
        $unlocked = ($viewer > 0) ? isset((new PpvUnlocksModel())->unlocked_map($viewer, [$id])[$id]) : false;
        // Free / active-subscriber; PPV needs an unlock. Ownership does not count on Discover.
        $entitled = $this->post_engagement_ok($post, $viewer, false);
        if (!$entitled && $audience === 'ppv' && $unlocked) { $entitled = true; }
        $liked = ($viewer > 0) ? isset((new PostLikesModel())->liked_map($viewer, [$id])[$id]) : false;

        $out = [
            'id'               => $id,
            'caption'          => trim((string) $post['caption']),
            'audience'         => $audience,
            'entitled'         => $entitled,
            'published_at'     => !empty($post['published_at']) ? date('M j, Y', strtotime((string) $post['published_at'])) : '',
            'likes'            => (int) $post['likes'],
            'liked'            => $liked,
            'comments'         => (int) $post['comments'],
            'views'            => (int) $post['views'],
            'comments_enabled' => (int) $post['comments_enabled'],
        ];
        if ($audience === 'ppv') {
            $out['ppv_price_credits'] = (int) $post['ppv_price_credits'];
            $out['ppv_price_dollars'] = (int) round(((int) $post['ppv_price_credits']) / 10);
            $out['unlocked']          = $unlocked;
        }
        if ($entitled) {
            $out['assets'] = $this->ppv_reveal_assets($post);
        } else {
            $assets = (new PostsModel())->get_assets($id);
            $cover  = null;
            foreach ($assets as $a) { if ((int) $a['is_cover'] === 1) { $cover = $a; break; } }
            if (!$cover && !empty($assets)) { $cover = $assets[0]; }
            $out['locked_url'] = $cover ? MediaService::signed_variant($cover, 'blurred', 900) : '';
        }

        $this->jsonSuccess(['post' => $out]);
    }

    /** Toggle the viewer's like on a published post. */
    public function post_likeAction(){
        if (empty(Session::get('user_id'))) { $this->jsonError('Sign in to like posts', ['need_login' => true]); }
        $viewer = (int) Session::get('user_id');
        $post   = (new PostsModel())->get_by_id((int) ($this->post['id'] ?? 0));
        if (!$post) { $this->jsonError('Post not found'); }
        if (!$this->post_engagement_ok($post, $viewer)) { $this->jsonError('You cannot like this post'); }
        $likes = new PostLikesModel();
        $liked = $likes->toggle((int) $post['id'], $viewer);
        $count = $likes->count((int) $post['id']);
        (new PostsModel())->set_counter((int) $post['id'], 'likes', $count);
        $this->jsonSuccess(['liked' => $liked, 'likes' => $count]);
    }

    /** List a post's comments (only for entitled viewers). */
    public function post_commentsAction(){
        $viewer = (int) Session::get('user_id');
        $post   = (new PostsModel())->get_by_id((int) ($this->post['id'] ?? 0));
        if (!$post) { $this->jsonError('Post not found'); }
        if (!$this->post_engagement_ok($post, $viewer)) { $this->jsonSuccess(['comments' => [], 'can_comment' => false, 'comments_enabled' => (int) $post['comments_enabled']]); }
        $out = [];
        foreach ((new PostCommentsModel())->list_for_post((int) $post['id']) as $r) {
            $name = $this->commenter_name($r);
            $out[] = [
                'id'         => (int) $r['id'],
                'name'       => $name,
                'initial'    => strtoupper(mb_substr(ltrim($name, '@'), 0, 1)),
                'body'       => (string) $r['body'],
                'when'       => $this->time_ago($r['created_at']),
                'can_delete' => ($viewer > 0 && ($viewer === (int) $r['user_id'] || $viewer === (int) $post['creator_id'])),
            ];
        }
        $this->jsonSuccess(['comments' => $out, 'can_comment' => ($viewer > 0 && (int) $post['comments_enabled'] === 1), 'comments_enabled' => (int) $post['comments_enabled']]);
    }

    /** Add a comment to a post. */
    public function post_comment_addAction(){
        if (empty(Session::get('user_id'))) { $this->jsonError('Sign in to comment', ['need_login' => true]); }
        $viewer = (int) Session::get('user_id');
        $post   = (new PostsModel())->get_by_id((int) ($this->post['id'] ?? 0));
        if (!$post) { $this->jsonError('Post not found'); }
        if ((int) $post['comments_enabled'] !== 1) { $this->jsonError('Comments are turned off for this post'); }
        if (!$this->post_engagement_ok($post, $viewer)) { $this->jsonError('You cannot comment on this post'); }
        $body = html_entity_decode(trim((string) ($this->post['body'] ?? '')), ENT_QUOTES);
        if ($body === '') { $this->jsonError('Write something first'); }
        $comments = new PostCommentsModel();
        $comments->add((int) $post['id'], $viewer, mb_substr($body, 0, 2000));
        $count = $comments->count((int) $post['id']);
        (new PostsModel())->set_counter((int) $post['id'], 'comments', $count);
        $this->jsonSuccess(['count' => $count]);
    }

    /** Delete a comment (its author, or the post's creator). */
    public function post_comment_deleteAction(){
        if (empty(Session::get('user_id'))) { $this->jsonError('Sign in first', ['need_login' => true]); }
        $viewer   = (int) Session::get('user_id');
        $comments = new PostCommentsModel();
        $c        = $comments->get_one((int) ($this->post['comment_id'] ?? 0));
        if (!$c) { $this->jsonError('Comment not found'); }
        $post = (new PostsModel())->get_by_id((int) $c['post_id']);
        if ($viewer !== (int) $c['user_id'] && !($post && $viewer === (int) $post['creator_id'])) {
            $this->jsonError('Not allowed');
        }
        $comments->soft_delete((int) $c['id']);
        $count = $comments->count((int) $c['post_id']);
        (new PostsModel())->set_counter((int) $c['post_id'], 'comments', $count);
        $this->jsonSuccess(['count' => $count]);
    }

    /** Record a view, deduped per unique viewer (each viewer counts once). */
    public function post_viewAction(){
        $viewer = (int) Session::get('user_id');
        $post   = (new PostsModel())->get_by_id((int) ($this->post['id'] ?? 0));
        if (!$post) { echo json_encode(['success' => false]); exit; }
        // Entitled to see it? Owner/free/subscriber via post_engagement_ok; PPV needs an unlock.
        $can_view = $this->post_engagement_ok($post, $viewer);
        if (!$can_view && ($post['audience'] ?? '') === 'ppv' && $viewer > 0
            && (new PpvUnlocksModel())->has_unlocked((int) $post['id'], $viewer)) {
            $can_view = true;
        }
        if (!$can_view) {
            $this->jsonSuccess(['views' => (int) $post['views']]);
        }
        $key = $viewer > 0
            ? ('u:' . $viewer)
            : ('ip:' . substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 40));
        $views = new PostViewsModel();
        if ($views->record((int) $post['id'], $key)) {
            $count = $views->count((int) $post['id']);
            (new PostsModel())->set_counter((int) $post['id'], 'views', $count);
            $this->jsonSuccess(['views' => $count]);
        }
        $this->jsonSuccess(['views' => (int) $post['views']]);
    }

    /** Spend credits to unlock a pay-per-view post. Records the unlock and pays the creator. */
    public function ppv_unlockAction(){
        $viewer = (int) Session::get('user_id');
        if ($viewer <= 0) {
            $this->jsonError('Sign in to unlock this post.', ['need_login' => true]);
        }
        $post_id = (int) ($this->post['post_id'] ?? 0);
        $post    = (new PostsModel())->get_by_id($post_id);
        if (!$post || $post['state'] !== 'published' || $post['audience'] !== 'ppv' || empty($post['on_cls'])) {
            $this->jsonError('That post is not available.');
        }
        // Never sell (or reveal) held content by id: blocked, unscanned, or adult for a viewer who opted out.
        if (!$this->moderation_ok((int) $post['id'], $viewer)) {
            $this->jsonError('That post is not available.');
        }
        $creator_id = (int) $post['creator_id'];
        $price      = (int) $post['ppv_price_credits'];

        // The creator sees their own PPV posts unlocked, for free.
        if ($creator_id === $viewer) {
            $this->jsonSuccess(['assets' => $this->ppv_reveal_assets($post)]);
        }
        if ((new BlocksModel())->either_blocked($viewer, $creator_id)) { $this->jsonError('That post is not available.'); }
        if ($this->seller_suspended($creator_id)) { $this->jsonError('That post is not available.'); }
        if ($price <= 0) { $this->jsonError('This post is not for sale.'); }

        // Optional discount code — applied to the credits charged; the creator's
        // earning and the platform fee are computed off the discounted amount.
        $promo  = null;
        $charge = $price;
        $promo_code = (string) ($this->post['code'] ?? '');
        if ($promo_code !== '') {
            $this->promo_guard();
            $promo = (new CreatorPromoCodesModel())->get_redeemable($creator_id, $promo_code, 'ppv');
            if (!$promo) {
                $this->promo_miss();
                $this->jsonError("That discount code isn't valid.");
            }
            $charge = (int) max(1, ceil($price * (100 - (int) $promo['percent_off']) / 100));
        }

        $unlocks = new PpvUnlocksModel();
        $credits = new CreditsModel();

        if ($unlocks->has_unlocked($post_id, $viewer)) {
            $this->jsonSuccess(['already' => true, 'assets' => $this->ppv_reveal_assets($post)]);
        }
        $balance = $credits->get_balance($viewer);
        if ($balance < $charge) {
            $this->jsonError('You need ' . ($charge - $balance) . ' more credits to unlock this.', ['need_credits' => true, 'balance' => $balance, 'price' => $charge, 'shortfall' => $charge - $balance]);
        }

        // A code's last redemption goes to one buyer: take the slot first, give it back if the unlock doesn't happen.
        $promos = new CreatorPromoCodesModel();
        if ($promo && !$promos->redeem((int) $promo['id'])) {
            $this->jsonError('That discount code has been fully used.');
        }
        // Record first: the UNIQUE(post_id, fan_id) key is the mutex that prevents a
        // double charge from concurrent clicks. Then debit; roll the row back if it fails.
        if (!$unlocks->record($post_id, $creator_id, $viewer, $charge)) {
            if ($promo) { $promos->unredeem((int) $promo['id']); }
            $this->jsonSuccess(['already' => true, 'assets' => $this->ppv_reveal_assets($post)]);
        }
        if ($credits->apply_delta($viewer, -$charge, 'ppv_unlock', 'Unlocked a post') === false) {
            $unlocks->remove($post_id, $viewer);
            if ($promo) { $promos->unredeem((int) $promo['id']); }
            $this->jsonError('Not enough credits.', ['need_credits' => true, 'balance' => $credits->get_balance($viewer), 'price' => $charge]);
        }

        // Pay the creator their share (net of the platform fee — tiered by the creator's
        // plan) and record per-post revenue.
        $creator_row = $this->userModel->get_user_by_id($creator_id);
        $creator_row = (is_array($creator_row) && count($creator_row) === 1) ? $creator_row[0] : null;
        $net = (int) round($charge * (100 - Plan::fee_percent($creator_row)) / 100);
        if ($net > 0) {
            $credits->apply_delta($creator_id, $net, 'ppv_earning', 'Pay-per-view unlock');
            (new PostsModel())->add_earnings($post_id, $net * 10); // 1 credit = 10 cents
            $unlocks->set_net($post_id, $viewer, $net);
        }
        $this->notify($creator_id, 'purchases', 'New pay-per-view sale',
            'Someone unlocked your post for ' . Notify::credits($charge) . '.', '/dashboard', 'fa-coins');
        $this->notify($viewer, 'purchases', 'Post unlocked',
            Notify::credits($charge) . ' spent · ' . Notify::credits($credits->get_balance($viewer)) . ' left. It is in your Purchases.', '/purchases', 'fa-unlock');
        InboxAutomationService::trigger($creator_id, $viewer, 'new_purchase', 'ppv' . $post_id);

        $this->jsonSuccess(['assets' => $this->ppv_reveal_assets($post), 'balance' => $credits->get_balance($viewer)]);
    }

    /**
     * Spend credits to unlock a content bundle. Charges once, then grants access to
     * every post in the bundle by writing a ppv_unlocks row per post (so all existing
     * entitlement checks unlock automatically), and pays the creator net of the fee.
     */
    public function bundle_unlockAction(){
        $viewer = (int) Session::get('user_id');
        if ($viewer <= 0) {
            $this->jsonError('Sign in to unlock this bundle.', ['need_login' => true]);
        }
        $bundle_id = (int) ($this->post['bundle_id'] ?? 0);
        $model     = new ContentBundlesModel();
        $bundle    = $model->get_public($bundle_id);
        if (!$bundle) { $this->jsonError('That bundle is not available.'); }

        $creator_id = (int) $bundle['creator_id'];
        $price      = (int) $bundle['price_credits'];
        $asset_ids  = $model->get_item_asset_ids($bundle_id);
        if (empty($asset_ids)) { $this->jsonError('This bundle has no content.'); }

        // The creator already owns everything in their own bundle.
        if ($creator_id === $viewer) {
            $this->jsonSuccess(['already' => true, 'message' => 'This is your own bundle.']);
        }
        if ((new BlocksModel())->either_blocked($viewer, $creator_id)) { $this->jsonError('That bundle is not available.'); }
        if ($this->seller_suspended($creator_id)) { $this->jsonError('That bundle is not available.'); }
        if ($price <= 0) { $this->jsonError('This bundle is not for sale.'); }
        // Adult (or not yet scanned) media is only sold to fans who have adult content on.
        $vrow = $this->userModel->get_user_by_id($viewer);
        if (!(is_array($vrow) && count($vrow) === 1 && !empty($vrow[0]['adult_content_enabled']))) {
            foreach ((array) $model->get_media_for_bundle($bundle_id) as $a) {
                $st = (string) ($a['moderation_status'] ?? '');
                if ($st !== 'n_a' && ($st !== 'approved' || !empty($a['is_adult']))) {
                    $this->jsonError('This bundle has adult content. Turn on adult content in Settings to buy it.');
                }
            }
        }

        $credits = new CreditsModel();
        if ($model->has_unlocked($bundle_id, $viewer)) {
            $this->jsonSuccess(['already' => true, 'message' => 'You already own this bundle.']);
        }
        $balance = $credits->get_balance($viewer);
        if ($balance < $price) {
            $this->jsonError('You need ' . ($price - $balance) . ' more credits to unlock this bundle.', ['need_credits' => true, 'balance' => $balance, 'price' => $price]);
        }

        // Record the bundle unlock first (UNIQUE(bundle_id,fan_id) is the mutex), then
        // debit; roll the row back if the charge fails.
        if (!$model->record_unlock($bundle_id, $creator_id, $viewer, $price)) {
            $this->jsonSuccess(['already' => true, 'message' => 'You already own this bundle.']);
        }
        if ($credits->apply_delta($viewer, -$price, 'bundle_unlock', 'Unlocked a content bundle') === false) {
            $model->remove_unlock($bundle_id, $viewer);
            $this->jsonError('Not enough credits.', ['need_credits' => true, 'balance' => $credits->get_balance($viewer), 'price' => $price]);
        }

        // The bundle_unlocks row is the grant — the media now appears in the fan's
        // Purchases (which reads bundle_unlocks). No post unlocking involved.

        // Pay the creator net of the tiered platform fee.
        $creator_row = $this->userModel->get_user_by_id($creator_id);
        $creator_row = (is_array($creator_row) && count($creator_row) === 1) ? $creator_row[0] : null;
        $net = (int) round($price * (100 - Plan::fee_percent($creator_row)) / 100);
        if ($net > 0) { $credits->apply_delta($creator_id, $net, 'bundle_earning', 'Content bundle purchase'); $model->set_unlock_net($bundle_id, $viewer, $net); }
        $this->notify($creator_id, 'purchases', 'New bundle sale',
            'Someone purchased your bundle for ' . Notify::credits($price) . '.', '/dashboard', 'fa-coins');
        $this->notify($viewer, 'purchases', 'Bundle purchased',
            Notify::credits($price) . ' spent · ' . Notify::credits($credits->get_balance($viewer)) . ' left. It is in your Purchases.', '/purchases', 'fa-box-open');
        InboxAutomationService::trigger($creator_id, $viewer, 'new_purchase', 'bundle' . (int) ($this->post['bundle_id'] ?? 0));

        $n = count($asset_ids);
        $this->jsonSuccess(['unlocked' => $n, 'balance' => $credits->get_balance($viewer), 'message' => 'Purchased — ' . $n . ' item' . ($n === 1 ? '' : 's') . ' added to your Purchases.']);
    }

    /** Join a free membership tier (auth required). Paid tiers go through checkout. */
    public function join_free_planAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Sign in to join', ['need_login' => true]);
        }

        $user_id = (int) Session::get('user_id');
        $plan    = (new CreatorPlansModel())->get_public((int) ($this->post['plan_id'] ?? 0));

        if (!$plan) {
            $this->jsonError('That plan is no longer available');
        }
        if ((int) $plan['price_cents'] !== 0) {
            $this->jsonError('This is a paid plan');
        }

        if ((new BlocksModel())->either_blocked($user_id, (int) $plan['user_id'])) { $this->jsonError('That plan is no longer available'); }
        if ($this->seller_suspended((int) $plan['user_id'])) { $this->jsonError('That plan is no longer available'); }
        (new CreatorSubscriptionsModel())->join_free($user_id, (int) $plan['user_id'], $plan);
        $cname = Notify::name_of((int) $plan['user_id']); $chandle = Notify::handle_of((int) $plan['user_id']);
        $this->notify($user_id, 'subscriptions', 'You joined ' . $plan['name'], ($cname !== '' ? $cname . '\'s ' : '') . 'free membership is active.', $chandle !== '' ? '/@' . $chandle : '/', 'fa-heart');
        $this->notify((int) $plan['user_id'], 'subscriptions', 'New member', (Notify::name_of($user_id) ?: 'Someone') . ' joined ' . $plan['name'] . '.', '/audience', 'fa-user-plus');
        InboxAutomationService::trigger((int) $plan['user_id'], $user_id, 'new_subscriber');

        $this->jsonSuccess(['message' => 'You joined ' . $plan['name'], 'plan_id' => (int) $plan['id']]);
    }

    /** Start Stripe Checkout for a paid membership on the creator's connected account. */
    public function subscribe_planAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Sign in to subscribe', ['need_login' => true]);
        }

        $user_id    = (int) Session::get('user_id');
        $plansModel = new CreatorPlansModel();
        $plan       = $plansModel->get_public((int) ($this->post['plan_id'] ?? 0));

        if (!$plan) {
            $this->jsonError('That plan is no longer available');
        }
        if ((int) $plan['price_cents'] === 0) {
            $this->jsonError('This is a free plan');
        }

        $creator_id = (int) $plan['user_id'];
        if ((new BlocksModel())->either_blocked($user_id, $creator_id)) { $this->jsonError('That plan is no longer available'); }
        if ($this->seller_suspended($creator_id)) { $this->jsonError('That plan is no longer available'); }
        $subsModel = new CreatorSubscriptionsModel();
        if ($subsModel->is_subscribed_to_plan($user_id, (int) $plan['id'])) {
            $this->jsonError('You are already a member of this plan');
        }
        // One paid membership per creator — switching tiers is done from My Subscriptions.
        if ($subsModel->has_active_paid_for_creator($user_id, $creator_id)) {
            $this->jsonError('You already have a paid membership with this creator. Manage or switch it in My Subscriptions.');
        }

        // The creator must have a payments-enabled Connect account.
        $creator    = $this->userModel->get_user_by_id($creator_id);
        $creator    = (is_array($creator) && count($creator) === 1) ? $creator[0] : null;
        $connect_id = $creator ? (string) ($creator['stripe_connect_account_id'] ?? '') : '';
        if (!$creator || $connect_id === '' || empty(StripeService::connect_account_status($connect_id)['payouts_enabled'])) {
            $this->jsonError("This creator isn't set up to accept payments yet");
        }

        // Create the Stripe price on first subscribe, then cache it on the plan.
        $price_id = (string) ($plan['stripe_price_id'] ?? '');
        if ($price_id === '') {
            $created = StripeService::create_connect_price($connect_id, $plan);
            if (empty($created['price_id'])) {
                $this->jsonError('Could not start checkout. Please try again.');
            }
            $price_id = $created['price_id'];
            $plansModel->set_stripe_ids((int) $plan['id'], $created['product_id'], $price_id);
        }

        // Optional discount code — validate for the subscription context, then apply a
        // Stripe coupon on the creator's connected account (created once, cached on the
        // promo row). The redemption is counted when the subscription is recorded.
        $coupon_id = '';
        $promo_id  = 0;
        $code = trim((string) ($this->post['code'] ?? ''));
        if ($code !== '') {
            $this->promo_guard();
            $promo = (new CreatorPromoCodesModel())->get_redeemable($creator_id, $code, 'subscription');
            if (!$promo) {
                $this->promo_miss();
                $this->jsonError("That discount code isn't valid.");
            }
            $promo_id  = (int) $promo['id'];
            $coupon_id = (string) ($promo['stripe_coupon_id'] ?? '');
            if ($coupon_id === '') {
                $coupon_id = StripeService::create_connect_coupon($connect_id, (int) $promo['percent_off']);
                if ($coupon_id !== '') { (new CreatorPromoCodesModel())->set_stripe_coupon($promo_id, $coupon_id); }
            }
        }

        $base    = Main::get_base_domain();
        $handle  = rawurlencode((string) $creator['u_name']);
        $success = $base . '/@' . $handle . '?sub=success&session_id={CHECKOUT_SESSION_ID}';
        $cancel  = $base . '/@' . $handle . '?sub=cancel';
        $meta    = ['subscriber_id' => (string) $user_id, 'creator_id' => (string) $creator_id, 'plan_id' => (string) $plan['id']];
        if ($promo_id > 0) { $meta['promo_id'] = (string) $promo_id; }

        // Free trial: compute the actual trial-end date from the plan's value + unit
        // (e.g. "+2 week", "+1 month") and hand Stripe a trial_end timestamp — no day
        // conversion. Only while enabled.
        $trial_end = 0;
        if (!empty($plan['trial_enabled']) && (int) ($plan['trial_value'] ?? 0) > 0) {
            $tu = in_array(($plan['trial_unit'] ?? 'day'), ['day', 'week', 'month'], true) ? $plan['trial_unit'] : 'day';
            $trial_end = strtotime('+' . (int) $plan['trial_value'] . ' ' . $tu, time());
        }
        $session = StripeService::create_subscription_checkout(
            $connect_id, $price_id, Plan::fee_percent($creator), $success, $cancel, $meta, (string) Session::get('user_email'), $trial_end, $coupon_id
        );
        if (empty($session['url'])) {
            $this->jsonError('Could not start checkout. Please try again.');
        }

        $this->jsonSuccess(['url' => $session['url']]);
    }

    /** Cancel a creator membership. Free → immediate; paid → at period end (via Stripe). */
    public function cancel_creator_subscriptionAction(){
        $response = ['success' => false, 'message' => 'Something went wrong'];

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $user_id = (int) Session::get('user_id');
        $subs    = new CreatorSubscriptionsModel();
        $sub     = $subs->get_owned($user_id, (int) ($this->post['id'] ?? 0));

        if (!$sub || $sub['status'] !== 'active') {
            $response['message'] = 'Subscription not found';
            echo json_encode($response);
            exit;
        }

        if (!empty($sub['is_free'])) {
            $subs->set_status($user_id, (int) $sub['id'], 'canceled');
            $response['state']   = 'canceled';
            $response['message'] = 'Membership canceled';
        } else {
            $creator = $this->userModel->get_user_by_id((int) $sub['creator_id']);
            $creator = (is_array($creator) && count($creator) === 1) ? $creator[0] : null;
            $connect = $creator ? (string) ($creator['stripe_connect_account_id'] ?? '') : '';
            if ($connect === '' || empty($sub['stripe_subscription_id'])
                || !StripeService::set_subscription_cancel_at_period_end($connect, $sub['stripe_subscription_id'], true)) {
                $response['message'] = 'Could not cancel. Please try again.';
                echo json_encode($response);
                exit;
            }
            $subs->set_cancel_at_period_end($user_id, (int) $sub['id'], true);
            $this->notify($user_id, 'subscriptions', 'Membership ending', 'Your membership to ' . (Notify::name_of((int) $sub['creator_id']) ?: 'this creator') . ' ends at the current billing period. Resume anytime before then.', '/account/settings?section=subscriptions', 'fa-heart-crack');
            $response['state']   = 'canceling';
            $response['message'] = 'Your membership will end at the current billing period';
        }

        $response['success'] = true;
        echo json_encode($response);
        exit;
    }

    /** Resume a creator membership: free → reactivate; paid → undo the scheduled cancellation. */
    public function reactivate_creator_subscriptionAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $user_id = (int) Session::get('user_id');
        $subs    = new CreatorSubscriptionsModel();
        $sub     = $subs->get_owned($user_id, (int) ($this->post['id'] ?? 0));

        if (!$sub) {
            $this->jsonError('Subscription not found');
        }

        if (!empty($sub['is_free'])) {
            $plan = (new CreatorPlansModel())->get_public((int) $sub['plan_id']);
            if (!$plan || (int) $plan['price_cents'] !== 0) {
                $this->jsonError('This plan is no longer available');
            }
            $subs->set_status($user_id, (int) $sub['id'], 'active');
        } else {
            if ($sub['status'] !== 'active') {
                $this->jsonError('This membership has ended — subscribe again from the profile');
            }
            $creator = $this->userModel->get_user_by_id((int) $sub['creator_id']);
            $creator = (is_array($creator) && count($creator) === 1) ? $creator[0] : null;
            $connect = $creator ? (string) ($creator['stripe_connect_account_id'] ?? '') : '';
            if ($connect === '' || empty($sub['stripe_subscription_id'])
                || !StripeService::set_subscription_cancel_at_period_end($connect, $sub['stripe_subscription_id'], false)) {
                $this->jsonError('Could not resume. Please try again.');
            }
            $subs->set_cancel_at_period_end($user_id, (int) $sub['id'], false);
            $this->notify($user_id, 'subscriptions', 'Membership resumed', 'Your membership to ' . (Notify::name_of((int) $sub['creator_id']) ?: 'this creator') . ' will renew as usual.', '/account/settings?section=subscriptions', 'fa-heart');
        }

        $this->jsonSuccess(['state' => 'active', 'message' => 'Membership resumed']);
    }

    // ---------- Public post engagement (likes / comments / views) ----------

    /** Whether this viewer is allowed to see — and thus engage with — the post. */
    private function post_engagement_ok(array $post, int $viewer_id, bool $owner_counts = true): bool{
        if (!$post || ($post['state'] ?? '') !== 'published') { return false; }
        $creator_id = (int) $post['creator_id'];
        if ($owner_counts && $viewer_id === $creator_id) { return true; }
        if ($viewer_id > 0 && $viewer_id !== $creator_id && (new BlocksModel())->either_blocked($viewer_id, $creator_id)) { return false; }
        if (($post['audience'] ?? 'free') === 'free') { return true; }
        if ($viewer_id <= 0) { return false; }
        // PPV is locked for everyone (subscribers included) until purchased — never fall through to tiers.
        if (($post['audience'] ?? '') === 'ppv') { return (new PpvUnlocksModel())->has_unlocked((int) $post['id'], $viewer_id); }
        $subs     = new CreatorSubscriptionsModel();
        $plan_ids = (array) $subs->active_plan_ids($viewer_id, $creator_id);
        $tiers    = (new PostsModel())->tiers_for_posts(array((int) $post['id']))[(int) $post['id']] ?? array();
        $prices   = array();
        $tier_id  = (int) ($post['tier_id'] ?? 0);
        if (empty($tiers) && $tier_id > 0) {
            $plan = (new CreatorPlansModel())->get_public($tier_id);
            $prices[$tier_id] = (int) ($plan['price_cents'] ?? 0);
        }
        return PostsModel::tier_entitled($post, $tiers, $plan_ids, $subs->max_active_tier_price($viewer_id, $creator_id), $prices);
    }

    private function time_ago(string $dt): string{
        $t = strtotime((string) $dt); if (!$t) { return ''; }
        $s = time() - $t;
        if ($s < 60) { return 'just now'; }
        $m = intdiv($s, 60); if ($m < 60) { return $m . 'm ago'; }
        $h = intdiv($m, 60); if ($h < 24) { return $h . 'h ago'; }
        $d = intdiv($h, 24); if ($d < 7) { return $d . 'd ago'; }
        return date('M j, Y', $t);
    }

    private function commenter_name(array $row): string{
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        return ($name === '') ? ('@' . (string) ($row['u_name'] ?? 'user')) : $name;
    }

    /** Signed, ready asset URLs for a post — returned to a viewer who is entitled to see it.
     *  PPV is sold per-post, so an entitled viewer here bought it (or owns it): serve images
     *  UNWATERMARKED. Free/subscriber posts keep the watermarked display variant. */
    /** Feed moderation gate for one post: false when blocked, unscanned, or adult for a viewer who opted out. */
    private function moderation_ok(int $post_id, int $viewer): bool{
        $mod = (new PostsModel())->moderation_map([$post_id])[$post_id] ?? '';
        if ($mod === 'blocked' || $mod === 'pending') { return false; }
        if ($mod === 'adult') {
            if ($viewer <= 0) { return false; }
            $rows = (new UsersModel())->get_user_by_id($viewer);
            $row  = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            return !empty($row['adult_content_enabled']);
        }
        return true;
    }

    private function ppv_reveal_assets(array $post): array{
        $img_variant = (($post['audience'] ?? '') === 'ppv') ? 'original' : 'display';
        $out = [];
        foreach ((new PostsModel())->get_assets((int) $post['id']) as $a) {
            if (!empty($a['deleted_at']) || $a['status'] !== 'ready') { continue; }
            if (($a['moderation_status'] ?? '') === 'blocked') { continue; }   // quarantined after sale: never re-served
            if ($a['type'] === 'video') {
                $out[] = ['type' => 'video',
                    'url'    => MediaService::signed_variant($a, 'original', 900),
                    'poster' => MediaService::signed_variant($a, 'poster', 900)];
            } else {
                $out[] = ['type' => 'image',
                    'url' => MediaService::signed_variant($a, $img_variant, 900), 'poster' => ''];
            }
        }
        return $out;
    }

}
