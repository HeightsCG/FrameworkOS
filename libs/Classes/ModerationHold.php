<?php
/**
 * The content check hold (2026-11-09, replacing the adult/age-verification hold). A post whose image or video the
 * scanner flagged (media_assets.moderation_status 'flagged', cron/moderate.php) is never published: a due scheduled
 * post, an edited live post or a post published during the scan goes back to drafts with posts.held_reason
 * 'moderation', and the creator is told which post and given a link to that draft in Content Studio. Staff clear a
 * false positive from the admin Moderation queue (approve), which releases the hold and tells the creator.
 */
class ModerationHold {

    const HELD_REASON = 'moderation';

    /** Content Studio opens a post from this link (studio.js reads ?post=). */
    public static function studio_link(int $post_id): string {
        return '/studio?post=' . $post_id;
    }

    /** The start of a post's caption, for naming it in a notice ("your post" when it has none). */
    public static function post_name($caption): string {
        $c = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $caption), ENT_QUOTES, 'UTF-8')));
        if ($c === '') { return 'your post'; }
        return 'your post "' . (mb_strlen($c) > 40 ? rtrim(mb_substr($c, 0, 40)) . '...' : $c) . '"';
    }

    /**
     * Hold the post when its media is flagged: back to drafts (nothing is deleted) and one notice to the creator.
     * True when the post has flagged media (held now or already held).
     */
    public static function hold(int $creator_id, int $post_id, bool $was_live = false): bool {
        $m = new PostsModel();
        $why = $m->media_hold_state($post_id);
        if ($why === '') { return false; }
        $post = $m->get_one($creator_id, $post_id);
        if (!$post) { return false; }
        $already = (string) ($post['state'] ?? '') === 'draft' && (string) ($post['held_reason'] ?? '') === self::HELD_REASON;
        $m->hold_for_plan($creator_id, $post_id, self::HELD_REASON);
        if (!$already) {
            Notify::send($creator_id, 'creator_activity', $was_live ? 'Your post was held' : 'Your post was not published',
                $why === 'blocked'
                    ? 'An image in ' . self::post_name($post['caption'] ?? '') . ' was blocked by our content check and cannot be published, so the post is in your drafts. Remove the image to publish it.'
                    : 'An image in ' . self::post_name($post['caption'] ?? '') . ' was flagged by our content check, so the post is in your drafts. Remove the image, or wait for our team to review it.',
                self::studio_link($post_id), 'fa-flag');
        }
        return true;
    }

    /** After the scanner flags (or blocks) an asset: every published or scheduled post using it is held. Returns the held post ids. */
    public static function hold_posts_for_asset(int $asset_id): array {
        $held = array();
        foreach ((new PostsModel())->live_posts_for_asset($asset_id) as $p) {
            if (self::hold((int) $p['creator_id'], (int) $p['id'], true)) { $held[] = (int) $p['id']; }
        }
        return $held;
    }

    /**
     * After staff clear a flagged asset: drafts held for it whose media is now clean are released, and the creator is
     * told they can publish. Returns the released post ids.
     */
    public static function release_posts_for_asset(int $asset_id): array {
        $m = new PostsModel(); $out = array();
        foreach ($m->held_posts_for_asset($asset_id, self::HELD_REASON) as $p) {
            $pid = (int) $p['id']; $cid = (int) $p['creator_id'];
            if ($m->has_flagged_media($pid)) { continue; }   // another image on it is still flagged
            $m->clear_hold($cid, $pid);
            Notify::send($cid, 'creator_activity', 'Your image was cleared',
                'An image in ' . self::post_name($p['caption'] ?? '') . ' was reviewed and cleared. You can publish the post now.',
                self::studio_link($pid), 'fa-circle-check');
            $out[] = $pid;
        }
        return $out;
    }
}
