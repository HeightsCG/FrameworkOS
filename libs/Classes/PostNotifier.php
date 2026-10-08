<?php
/**
 * "New content from creators you follow": when a post goes live, every follower and
 * active subscriber gets a creator_activity notification. Small audiences are told
 * in-request; larger ones through the job queue (PostNotifyJob) so publishing stays fast.
 */
class PostNotifier {

    const INLINE_MAX = 50;

    /** Call once, right after a post transitions to published. Never throws. */
    public static function published($creator_id, $post_id): void {
        try {
            $creator_id = (int) $creator_id; $post_id = (int) $post_id;
            if ($creator_id <= 0 || $post_id <= 0) { return; }
            // Socials-only posts (on_cls = 0) never appear on the platform, so followers aren't told.
            $post = (new PostsModel())->get_by_id($post_id);
            if ($post && empty($post['on_cls'])) { return; }
            // A creator without a paid plan has no public page yet: nobody is told and search engines aren't pinged.
            $owner = (new UsersModel())->get_user_by_id($creator_id);
            if (!Plan::can_sell((is_array($owner) && count($owner) === 1) ? $owner[0] : null)) { return; }
            try { IndexNow::creator($creator_id); } catch (\Throwable $e) { error_log('[indexnow] post ' . $post_id . ': ' . $e->getMessage()); }   // their page changed: tell search engines
            $ids = self::audience_ids($creator_id);
            if (empty($ids)) { return; }
            if (count($ids) > self::INLINE_MAX && class_exists('DatabaseJobQueue')) {
                (new DatabaseJobQueue())->dispatch('post_notify', array('creator_id' => $creator_id, 'post_id' => $post_id), 'post_notify:' . $post_id);
                return;
            }
            self::deliver($creator_id, $post_id, $ids);
        } catch (\Throwable $e) {
            error_log('[post_notify] ' . $e->getMessage());
        }
    }

    /** Followers ∪ active subscribers, never the creator. */
    public static function audience_ids($creator_id): array {
        return (new BroadcastsModel())->recipient_ids((int) $creator_id, array('all'));
    }

    public static function deliver($creator_id, $post_id, array $ids): int {
        $post = (new PostsModel())->get_by_id((int) $post_id);
        if (!$post || (string) $post['state'] !== 'published') { return 0; }
        $name   = Notify::name_of($creator_id) ?: 'A creator you follow';
        $handle = Notify::handle_of($creator_id);
        $cap    = trim(html_entity_decode((string) ($post['caption'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $aud    = (string) ($post['audience'] ?? 'free');
        $title  = $name . ' posted' . ($aud === 'ppv' ? ' pay-per-view content' : ($aud === 'subscribers' ? ' for subscribers' : ''));
        $body   = $cap !== '' ? mb_substr($cap, 0, 140) : 'New post';
        $link   = $handle !== '' ? '/@' . $handle : '/';
        // Only people not told yet: a retried job (or a second publish) never repeats the notice.
        $posts = new PostsModel();
        $fresh = array_values(array_filter($ids, function ($uid) use ($posts, $post_id) { return $posts->claim_post_notice((int) $post_id, (int) $uid); }));
        $image = PostEmailImage::url($post);   // the post's photo or video frame (blurred when it is gated or adult)
        return empty($fresh) ? 0 : Notify::many($fresh, 'creator_activity', $title, $body, $link, 'fa-photo-film', false, $image);
    }
}
