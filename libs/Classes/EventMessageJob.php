<?php
/**
 * A creator's message to everyone going to an event. Small audiences are delivered in-request
 * (deliver()); larger ones through the job queue ('event_message' → handle()).
 * Each attendee gets it in their Inbox thread with the creator, plus a notification (email if offline).
 */
class EventMessageJob {

    public static function handle(array $payload): string {
        $eid = (int) ($payload['event_id'] ?? 0); $cid = (int) ($payload['creator_id'] ?? 0);
        $ev  = (new EventsModel())->get_one($cid, $eid);
        if (!$ev) { return 'FAIL event gone'; }
        return 'delivered to ' . self::deliver($ev, $cid, (string) ($payload['body'] ?? ''), array_map('intval', (array) ($payload['ids'] ?? array())));
    }

    public static function deliver(array $ev, $creator_id, $body, array $ids): int {
        $messages = new MessagesModel();
        $title = EventRefunds::title($ev);
        $n = 0;
        foreach ($ids as $uid) {
            $conv_id = $messages->get_or_create((int) $creator_id, (int) $uid);   // creator is always the creator_id side
            $messages->send($conv_id, (int) $creator_id, $body);
            Notify::send((int) $uid, 'events', $title, mb_substr($body, 0, 140), '/inbox/thread/' . (int) $conv_id, 'fa-calendar-check', true);
            $n++;
        }
        return $n;
    }
}
