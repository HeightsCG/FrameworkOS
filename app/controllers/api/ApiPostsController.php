<?php
/** Fan-facing feed, post detail and engagement. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiPostsController extends BaseApiController {

    /**
     * Home discovery feed — cross-creator published content, newest first, paginated.
     * Every post is free to view. Mirrors the safety gate used on the profile grid
     * (blocked and flagged hidden from all, pending withheld).
     */
    public function feedAction(){
        $viewer = (int) Session::get('user_id');
        $logged = $viewer > 0;

        $limit  = 4;
        $offset = max(0, (int) ($this->post['offset'] ?? 0));

        $posts_model = new PostsModel();
        $feed_model  = new FeedModel();
        $cards    = [];
        $pos      = $offset;   // raw rows consumed so far; next_offset continues from here
        $has_more = false;
        $batch    = 12;
        // Rows hidden from this viewer (flagged, still being scanned, blocked) are filtered after the SQL LIMIT, so keep
        // reading until $limit cards survive or the rows run out (a few rounds at most).
        for ($round = 0; $round < 5 && count($cards) < $limit; $round++) {
            $rows     = (array) $feed_model->recent($batch + 1, $pos, $viewer);   // one extra row says whether more exist
            $has_more = count($rows) > $batch;
            if ($has_more) { $rows = array_slice($rows, 0, $batch); }
            if (empty($rows)) { break; }

            $post_ids       = array_map(function ($p) { return (int) $p['id']; }, $rows);
            $moderation_map = !empty($post_ids) ? $posts_model->moderation_map($post_ids) : [];
            $liked_map      = ($logged && !empty($post_ids)) ? (new PostLikesModel())->liked_map($viewer, $post_ids) : [];

            // Discover is the shared surface: the creator is treated exactly like any other
            // viewer here (no owner exemptions). The Studio is where they see their own work.
            foreach ($rows as $i => $p) {
                if (count($cards) >= $limit) { $has_more = true; break; }   // page full: the rest of this batch is the next page
                $pos++;
                $id  = (int) $p['id'];
                $mod = $moderation_map[$id] ?? '';
                if ($mod === 'blocked' || $mod === 'flagged' || $mod === 'pending') { continue; }

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
                    'is_video'    => ($cover_asset['type'] === 'video'),
                    'media_count' => (int) $p['asset_count'],
                    'views'       => (int) $p['views'],
                    'likes'       => (int) $p['likes'],
                    'liked'       => isset($liked_map[$id]),
                    'comments'    => (int) $p['comments'],
                ];
                if (!$has_cover) {
                    $card['cover'] = '';
                } else {
                    // the public, cacheable webp copy when there is one (PublicThumbService checks the asset is approved).
                    $pub = PublicThumbService::url(array(
                        'moderation_status' => (string) ($p['cover_moderation_status'] ?? ''),
                        'public_display_key' => (string) ($p['cover_public_display_key'] ?? '')), 'feed');
                    $card['cover'] = $pub !== '' ? $pub : MediaService::signed_variant($cover_asset, ($cover_asset['type'] === 'video' ? 'poster' : 'thumb'), 900);
                }
                $cards[] = $card;
            }
            if (!$has_more) { break; }
        }

        $this->jsonSuccess(['viewer_logged' => $logged, 'items' => $cards, 'has_more' => $has_more, 'next_offset' => $pos]);
    }

    /**
     * Count of posts published since a watermark id — the Home feed polls this to
     * raise its "N new posts" alert. Same filters as feedAction, moderation gate included.
     */
    public function feed_newAction(){
        $since  = (int) ($this->post['since_id'] ?? 0);
        $viewer = (int) Session::get('user_id');
        $this->jsonSuccess(['count' => (new FeedModel())->count_since($since, 50, $viewer)]);
    }

    /**
     * "Load More" on a creator's profile: the next page of post cards. Same visibility rules as the page and the same
     * gate code (ProfilePosts).
     */
    public function profile_postsAction(){
        $handle = preg_replace('/[^a-z0-9_]/', '', strtolower(ltrim((string) ($this->post['handle'] ?? ''), '@')));
        $offset = max(0, (int) ($this->post['offset'] ?? 0));
        $viewer = (int) Session::get('user_id');
        $rows   = $handle !== '' ? $this->userModel->get_user_by_username($handle) : array();
        $user   = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$user || !ProfilePosts::creator_visible($user, $viewer)) { $this->jsonError('This page is not available.'); }
        $profile = (new CreatorProfileModel())->get_for_user((int) $user['user_id']);
        // the display name the page uses (cover alt text)
        $display_name = trim(html_entity_decode((string) ($profile['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($display_name === '') { $display_name = trim($user['first_name'] . ' ' . $user['last_name']); }
        if ($display_name === '') { $display_name = '@' . $user['u_name']; }
        // the search box looks through every post (up to 60 matches), not just the loaded page
        $q    = trim(html_entity_decode((string) ($this->post['q'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $page = $q !== '' ? ProfilePosts::page($user, $display_name, $viewer, 0, 60, $q) : ProfilePosts::page($user, $display_name, $viewer, $offset);
        $this->jsonSuccess(['items' => $page['cards'], 'html' => ProfilePosts::render($page['cards']), 'has_more' => $page['has_more'], 'next_offset' => $page['next_offset']]);
    }

    /**
     * Full detail for a single post — feeds the Home lightbox: signed asset URLs for a
     * post that passes the visibility gates. Author identity is carried by the feed card,
     * so it isn't repeated here.
     */
    public function post_detailAction(){
        $viewer = (int) Session::get('user_id');
        $post   = (new PostsModel())->get_by_id((int) ($this->post['id'] ?? 0));
        if (!$post || ($post['state'] ?? '') !== 'published') {
            $this->jsonError('Post not found');
        }
        if (empty($post['on_cls'])) { $this->jsonError('Post not found'); }   // socials-only post
        if ($this->seller_suspended((int) $post['creator_id'])) { $this->jsonError('Post not found'); }   // suspended, or Free: no public presence
        if ($viewer > 0 && (new BlocksModel())->either_blocked($viewer, (int) $post['creator_id'])) { $this->jsonError('Post not found'); }
        $id = (int) $post['id'];

        // Same moderation gate the feed applies, re-checked so a post can't be
        // reached by guessing its id. Like the feed, the creator gets no owner exemption.
        if (!$this->moderation_ok($id, $viewer)) {
            $this->jsonError('Post not available');
        }

        if (!$this->post_engagement_ok($post, $viewer, false)) { $this->jsonError('Post not available'); }
        $liked = ($viewer > 0) ? isset((new PostLikesModel())->liked_map($viewer, [$id])[$id]) : false;

        $out = [
            'id'               => $id,
            'caption'          => trim((string) $post['caption']),
            'published_at'     => !empty($post['published_at']) ? date('M j, Y', strtotime((string) $post['published_at'])) : '',
            'likes'            => (int) $post['likes'],
            'liked'            => $liked,
            'comments'         => (int) $post['comments'],
            'views'            => (int) $post['views'],
            'comments_enabled' => (int) $post['comments_enabled'],
        ];
        $out['assets'] = $this->post_assets($post);

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
        // Visible to this viewer? (post_engagement_ok)
        $can_view = $this->post_engagement_ok($post, $viewer);
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

    // ---------- Public post engagement (likes / comments / views) ----------

    /** Whether this viewer is allowed to see — and thus engage with — the post. */
    private function post_engagement_ok(array $post, int $viewer_id, bool $owner_counts = true): bool{
        if (!$post || ($post['state'] ?? '') !== 'published') { return false; }
        $creator_id = (int) $post['creator_id'];
        // same visibility gates as post_detail: socials-only, suspended/Free seller, moderation (no owner exemption).
        if (empty($post['on_cls'])) { return false; }
        if (($post['audience'] ?? 'free') !== 'free') { return false; }   // legacy subscriber/pay-per-view rows are never made public
        if ($this->seller_suspended($creator_id)) { return false; }
        if (!$this->moderation_ok((int) $post['id'], $viewer_id)) { return false; }
        if ($owner_counts && $viewer_id === $creator_id) { return true; }
        if ($viewer_id > 0 && $viewer_id !== $creator_id && (new BlocksModel())->either_blocked($viewer_id, $creator_id)) { return false; }
        return true;
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

    /** Feed moderation gate for one post: false when blocked, flagged or unscanned. */
    private function moderation_ok(int $post_id, int $viewer): bool{
        $mod = (new PostsModel())->moderation_map([$post_id])[$post_id] ?? '';
        return !in_array($mod, ['blocked', 'flagged', 'pending'], true);
    }

    /** Signed, ready asset URLs for a visible post (images use the watermarked display variant). */
    private function post_assets(array $post): array{
        $out = [];
        foreach ((new PostsModel())->get_assets((int) $post['id']) as $a) {
            if (!empty($a['deleted_at']) || $a['status'] !== 'ready') { continue; }
            if (($a['moderation_status'] ?? '') === 'blocked') { continue; }   // quarantined: never re-served
            if ($a['type'] === 'video') {
                $out[] = ['type' => 'video',
                    'url'    => MediaService::signed_variant($a, 'original', 900),
                    'poster' => MediaService::signed_variant($a, 'poster', 900)];
            } else {
                $out[] = ['type' => 'image',
                    'url' => MediaService::signed_variant($a, 'display', 900), 'poster' => ''];
            }
        }
        return $out;
    }

}
