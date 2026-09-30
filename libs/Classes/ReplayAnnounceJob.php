<?php
/**
 * Job handler: a creator put an event's replay on sale. Tell everyone who registered for the event plus the creator's
 * whole audience (followers, subscribers, buyers: BroadcastsModel 'all', which already leaves out blocked people) with
 * a link straight to the event page, where they buy and watch it. Queued once per event recording.
 */
class ReplayAnnounceJob {

    public static function queue($event_id, $recording_id): void {
        try {
            (new DatabaseJobQueue())->dispatch('replay_announce', array('event_id' => (int) $event_id),
                'replay_announce:' . (int) $event_id . ':' . (int) $recording_id);   // never announced twice for the same replay
        } catch (\Throwable $e) {
            error_log('[replay] announce queue ' . (int) $event_id . ': ' . $e->getMessage());
        }
    }

    public static function handle(array $payload): string {
        $events = new EventsModel();
        $ev = $events->get_public((int) ($payload['event_id'] ?? 0));
        $info = $ev ? EventReplay::info($ev) : null;
        if (!$ev || !$info || !$info['on_sale']) { return 'not on sale'; }
        $creator = (int) $ev['creator_id'];
        $blocked = (array) (new BlocksModel())->related_ids($creator);   // keyed by user id
        $ids = array_unique(array_merge(array_map('intval', (array) $events->going_user_ids((int) $ev['id'])),
                                        array_map('intval', (array) PostNotifier::audience_ids($creator))));
        $name  = Notify::name_of($creator) ?: 'A creator you follow';
        $title = html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8');
        $link  = '/@' . Notify::handle_of($creator) . '/events/' . (int) $ev['id'];
        $len   = EventReplay::length($info['duration']);
        $n = 0;
        foreach ($ids as $uid) {
            if ($uid <= 0 || $uid === $creator || isset($blocked[$uid])) { continue; }
            $free = $info['free_attendees'] && $events->going_registration((int) $ev['id'], $uid);
            Notify::send($uid, 'events', 'Replay available: ' . mb_substr($title, 0, 60),
                $name . ' posted the full recording' . ($len !== '' ? ' (' . $len . ')' : '') . '. ' . ($free ? 'It’s free for you because you registered.' : 'Watch it for ' . Notify::credits($info['price']) . '.'),
                $link, 'fa-circle-play');
            $n++;
        }
        return 'announced to ' . $n;
    }
}
