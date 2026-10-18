<?php
/**
 * "Stop Being a Creator": permanently deletes a creator's creator content (Daniel, 2026-10-18), the way the creator
 * would by hand, keeping what fans paid for or were sent:
 *  - memberships and the creator's plan end first (AccountBilling::on_leave_creator, in the request);
 *  - scheduled cross-posts are withdrawn from Post for Me;
 *  - upcoming events with paid tickets are canceled (every ticket refunded); events nobody ever paid for are deleted;
 *    past paid events stay as the fans' records (replays they bought keep playing);
 *  - open paid bookings are refunded; services with no bookings are deleted, the rest hidden;
 *  - sold posts are archived and sold bundles taken off sale (buyers keep them); everything else is deleted;
 *  - influencers, scenes, edits, automations, links, promo codes, plans, collections, brand, inbox settings, custom
 *    domains and the profile's bio and images are removed;
 *  - media files nobody bought or was sent are deleted from storage for good.
 * Runs as a queue job (CreatorDeleteJob) after the account is already back to a regular user.
 */
class CreatorDeletion {

    public static function run($user_id): array {
        $cid = (int) $user_id;
        $m   = new CreatorDeletionModel();
        $out = array('social' => 0, 'events_canceled' => 0, 'events_deleted' => 0, 'bookings_refunded' => 0, 'services_deleted' => 0,
                     'posts_deleted' => 0, 'posts_archived' => 0, 'bundles_deleted' => 0, 'bundles_off_sale' => 0, 'influencers' => 0,
                     'files_deleted' => 0, 'rows' => 0);

        // Scheduled cross-posts would otherwise still go out on the creator's social accounts.
        foreach ($m->scheduled_social_posts($cid) as $sp) {
            if ((string) $sp['post_for_me_post_id'] === '' || PostForMeService::delete_post((string) $sp['post_for_me_post_id'])) { $m->mark_social_post_canceled((int) $sp['id']); $out['social']++; }
        }

        // Events
        $events = new EventsModel(); $kept_events = array();
        foreach ($m->events($cid) as $e) {
            $id  = (int) $e['id'];
            $end = strtotime((string) (!empty($e['end_at']) ? $e['end_at'] : $e['start_at']) . ' UTC');
            $upcoming = $end !== false && $end > time();
            if ($upcoming && in_array((string) $e['status'], array('published', 'draft'), true) && $events->has_paid_going($id)) {
                EventRefunds::cancel_all((array) $events->get_one($cid, $id));
                $out['events_canceled']++;
            }
            if (empty($e['paid_ever'])) { $m->delete_event_children($id); $events->delete_event($cid, $id); $out['events_deleted']++; }
            else { $kept_events[] = $id; if ($upcoming && (string) $e['status'] === 'published') { $events->set_status($cid, $id, 'canceled'); } }
        }

        // Services
        $services = new ServicesModel();
        foreach ($m->open_paid_bookings($cid) as $b) {
            $sv = $services->get_one($cid, (int) $b['service_id']);
            if ($sv && !empty(ServiceRefunds::refund_one($sv, (int) $b['id'])['ok'])) { $out['bookings_refunded']++; }
        }
        foreach ($m->services($cid) as $s) {
            if ((int) $s['bookings'] === 0) { $services->delete_service($cid, (int) $s['id']); $out['services_deleted']++; }
            else { $services->set_status($cid, (int) $s['id'], 'draft'); }
        }

        // Posts: buyers keep sold ones (archived, as a manual delete does)
        $posts = new PostsModel();
        $ids   = array_map('intval', array_column((array) $posts->list_for_creator($cid), 'id'));
        $sold  = $ids ? (new PpvUnlocksModel())->stats_for_posts($ids) : array();
        foreach ($ids as $pid) {
            if (!empty($sold[$pid]['unlocks'])) { $posts->set_state($cid, $pid, 'archived'); $out['posts_archived']++; }
            else { $posts->delete_post($cid, $pid); $out['posts_deleted']++; }
        }

        // Bundles: buyers keep sold ones (off sale)
        $bundles = new ContentBundlesModel();
        foreach ((array) $bundles->get_for_creator($cid) as $bd) {
            if ($bundles->has_sales((int) $bd['id'])) { $bundles->set_active($cid, (int) $bd['id'], 0); $out['bundles_off_sale']++; }
            else { $bundles->delete_bundle($cid, (int) $bd['id']); $out['bundles_deleted']++; }
        }

        // AI: influencers (running jobs are canceled and refunded), own scenes, clip edits
        foreach ((array) (new InfluencersModel())->list_for_creator($cid) as $infl) {
            InfluencerActions::delete($cid, $infl); $out['influencers']++;
        }
        $scenes = new SceneTemplatesModel();
        foreach ((array) $scenes->list_for_creator($cid) as $sc) { if ((int) ($sc['creator_id'] ?? 0) === $cid) { $scenes->delete_own($cid, (int) $sc['id']); } }
        $edits = new EditProjectsModel();
        foreach ((array) $edits->list_for_creator($cid, 1000) as $ep) { $edits->soft_delete($cid, (int) $ep['id']); }

        // Custom domains
        $domains = new CreatorDomainsModel();
        foreach ($domains->list_for_user($cid) as $d) { $domains->remove($cid, (int) $d['id']); }

        // Media files: gone for good unless a fan bought or was sent them
        $purge = array();
        foreach ($m->purgeable_media($cid, $kept_events) as $a) {
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
