<?php
/**
 * "Stop Being a Creator": permanently deletes a creator's creator content (Daniel, 2026-10-18), the way the creator
 * would by hand, keeping what others were sent:
 *  - the creator's plan ends first (AccountBilling::on_leave_creator, in the request);
 *  - scheduled cross-posts are withdrawn from Post for Me;
 *  - posts are deleted;
 *  - influencers, scenes, edits, automations, links, collections, brand, inbox settings and the
 *    profile's bio and images are removed;
 *  - media files nobody was sent are deleted from storage for good.
 * Runs as a queue job (CreatorDeleteJob) after the account is already back to a regular user.
 */
class CreatorDeletion {

    public static function run($user_id): array {
        $cid = (int) $user_id;
        $m   = new CreatorDeletionModel();
        $out = array('social' => 0,
                     'posts_deleted' => 0, 'influencers' => 0,
                     'files_deleted' => 0, 'rows' => 0);

        // Scheduled cross-posts would otherwise still go out on the creator's social accounts.
        foreach ($m->scheduled_social_posts($cid) as $sp) {
            if ((string) $sp['post_for_me_post_id'] === '' || PostForMeService::delete_post((string) $sp['post_for_me_post_id'])) { $m->mark_social_post_canceled((int) $sp['id']); $out['social']++; }
        }

        // Posts
        $posts = new PostsModel();
        foreach (array_map('intval', array_column((array) $posts->list_for_creator($cid), 'id')) as $pid) {
            $posts->delete_post($cid, $pid); $out['posts_deleted']++;
        }

        // AI: influencers (running jobs are canceled and refunded), own scenes, clip edits
        foreach ((array) (new InfluencersModel())->list_for_creator($cid) as $infl) {
            InfluencerActions::delete($cid, $infl); $out['influencers']++;
        }
        $scenes = new SceneTemplatesModel();
        foreach ((array) $scenes->list_for_creator($cid) as $sc) { if ((int) ($sc['creator_id'] ?? 0) === $cid) { $scenes->delete_own($cid, (int) $sc['id']); } }
        $edits = new EditProjectsModel();
        foreach ((array) $edits->list_for_creator($cid, 1000) as $ep) { $edits->soft_delete($cid, (int) $ep['id']); }

        // Media files: gone for good unless someone was sent them
        $purge = array();
        foreach ($m->purgeable_media($cid) as $a) {
            $keys = array_unique(array_filter(array((string) $a['original_key'], (string) $a['display_key'], (string) $a['thumb_key'], (string) $a['poster_key'], (string) $a['blurred_key'])));
            foreach ($keys as $k) { S3Service::delete_key($k); }
            $purge[] = (int) $a['id'];
        }
        if ($purge) { PublicThumbService::queue_purge(array('assets' => $purge)); }   // their public copies go too (before the rows do)
        foreach ($purge as $aid) { $m->delete_media_row($cid, $aid); $out['files_deleted']++; }

        // Everything else owned outright, then the profile's creator content
        $out['rows'] = $m->delete_owned_rows($cid);
        $m->reset_profile($cid);

        error_log('[creator delete] user ' . $cid . ': ' . json_encode($out));
        return $out;
    }
}
