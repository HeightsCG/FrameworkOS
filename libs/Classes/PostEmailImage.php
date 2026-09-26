<?php
/**
 * The picture in a "new post" email: the post's cover photo, or its video's poster frame. The email embeds
 * /mail-image/<post_id>, and that URL decides again on every open what may be shown, so a post that is later
 * deleted, re-gated or re-moderated never leaks through an old email.
 *
 * What is shown is what a logged-out visitor may see: a free, non-adult post shows the real image; a
 * subscriber, pay-per-view or adult post shows its blurred preview; unscanned or blocked media shows nothing.
 */
class PostEmailImage {

    /** Absolute URL for the email's <img>, or '' when the post has nothing that may be shown. */
    public static function url(array $post): string {
        return self::pick($post) ? rtrim(Main::get_base_domain(), '/') . '/mail-image/' . (int) $post['id'] : '';
    }

    /** [asset row, variant] for a post, or null. */
    public static function pick(array $post) {
        if ((string) ($post['state'] ?? '') !== 'published' || empty($post['on_cls'])) { return null; }
        $pid   = (int) $post['id'];
        $posts = new PostsModel();
        $mod   = $posts->moderation_map(array($pid))[$pid] ?? '';
        if ($mod === 'blocked' || $mod === 'pending') { return null; }
        $cover = null;
        foreach ((array) $posts->get_assets($pid) as $a) {
            if (!empty($a['deleted_at']) || (string) $a['status'] !== 'ready' || !in_array((string) $a['type'], array('image', 'video'), true)) { continue; }
            if ($cover === null || !empty($a['is_cover'])) { $cover = $a; }
            if (!empty($a['is_cover'])) { break; }
        }
        if (!$cover) { return null; }
        $gated   = (string) ($post['audience'] ?? 'free') !== 'free' || $mod === 'adult';
        $variant = $gated ? 'blurred' : ((string) $cover['type'] === 'video' ? 'poster' : 'display');
        return MediaService::signed_variant($cover, $variant, 60) !== '' ? array($cover, $variant) : null;
    }
}
