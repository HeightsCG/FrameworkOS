<?php
/**
 * Home (/) — the public discovery feed. Every account (fan or creator, even
 * logged-out) lands here on a stream of recently published content from all
 * creators. Analytics moved to /dashboard (creator-only).
 *
 * Safety mirrors the profile grid: 'blocked' content is hidden from everyone,
 * unscanned ('pending') content is withheld, and adult ('flagged') content is
 * shown only to viewers who opted in. Gated posts (subscribers / unpurchased
 * PPV) appear as a blurred, locked teaser — the clear rendition is never signed
 * here — with a click-through to the creator's profile to unlock.
 */
class IndexController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        $viewer_id     = (int) Session::get('user_id');
        $viewer_logged = $viewer_id > 0;

        // Viewer's adult-content preference (logged-out defaults to OFF).
        $show_adult = false;
        if ($viewer_logged) {
            $rows = (new UsersModel())->get_user_by_id($viewer_id);
            $row  = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            $show_adult = !empty($row['adult_content_enabled']);
        }

        $posts_model = new PostsModel();
        $rows = (new FeedModel())->recent(30);

        $post_ids = array_map(function ($p) { return (int) $p['id']; }, (array) $rows);
        $moderation_map = !empty($post_ids) ? $posts_model->moderation_map($post_ids) : array();
        $unlocked_map = ($viewer_logged && !empty($post_ids))
            ? (new PpvUnlocksModel())->unlocked_map($viewer_id, $post_ids) : array();
        $liked_map = ($viewer_logged && !empty($post_ids))
            ? (new PostLikesModel())->liked_map($viewer_id, $post_ids) : array();

        $cards = array();
        foreach ((array) $rows as $p) {
            $id       = (int) $p['id'];
            $is_owner = $viewer_logged && (int) $p['creator_id'] === $viewer_id;
            $mod      = $moderation_map[$id] ?? '';
            if ($mod === 'blocked') { continue; }                      // quarantined — never shown
            if ($mod === 'pending' && !$is_owner) { continue; }        // not yet scanned
            if ($mod === 'flagged' && !$show_adult && !$is_owner) { continue; } // adult — opted out

            $audience = (string) $p['audience'];
            // Entitlement in the feed: free (or your own post) shows the clear cover;
            // an unlocked PPV shows it too. Everything else is a locked teaser that
            // links through to the creator's profile to subscribe / unlock.
            if ($is_owner || $audience === 'free') {
                $entitled = true;
            } elseif ($audience === 'ppv') {
                $entitled = isset($unlocked_map[$id]);
            } else {
                $entitled = false; // subscribers-only — consume on the profile
            }

            $display = trim((string) ($p['display_name'] ?? ''));
            if ($display === '') { $display = '@' . (string) $p['u_name']; }
            $cover_asset = array(
                'type'        => (string) ($p['cover_type'] ?? 'image'),
                'thumb_key'   => (string) ($p['cover_thumb_key'] ?? ''),
                'poster_key'  => (string) ($p['cover_poster_key'] ?? ''),
                'blurred_key' => (string) ($p['cover_blurred_key'] ?? ''),
            );
            $has_cover = ($cover_asset['thumb_key'] !== '' || $cover_asset['poster_key'] !== '' || $cover_asset['blurred_key'] !== '');
            $cap = trim((string) $p['caption']);

            $card = array(
                'id'             => $id,
                'profile_url'    => '/@' . rawurlencode((string) $p['u_name']),
                'author'         => $display,
                'handle'         => (string) $p['u_name'],
                'avatar'         => (string) ($p['avatar_url'] ?? ''),
                'caption'        => $cap,
                'audience'       => $audience,
                'entitled'       => $entitled,
                'is_video'       => ($cover_asset['type'] === 'video'),
                'has_cover'      => $has_cover,
                'media_count'    => (int) $p['asset_count'],
                'published_at'   => !empty($p['published_at']) ? (string) $p['published_at'] : '',
                'views'          => (int) $p['views'],
                'likes'          => (int) $p['likes'],
                'liked'          => isset($liked_map[$id]),
                'comments'       => (int) $p['comments'],
            );
            if ($audience === 'ppv') {
                $card['ppv_price_credits'] = (int) $p['ppv_price_credits'];
                $card['unlocked']          = isset($unlocked_map[$id]);
            }
            if (!$has_cover) {
                $card['cover'] = '';
            } elseif ($entitled) {
                $card['cover'] = MediaService::signed_variant($cover_asset, ($cover_asset['type'] === 'video' ? 'poster' : 'thumb'), 900);
            } else {
                // Non-entitled: only the blurred rendition is ever signed.
                $card['cover'] = MediaService::signed_variant($cover_asset, 'blurred', 900);
            }
            $cards[] = $card;
        }

        $this->view->cards         = $cards;
        $this->view->viewer_logged = $viewer_logged;
        $this->view->render();
    }

}
