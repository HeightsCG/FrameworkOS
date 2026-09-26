<?php
/**
 * Scheduled event reminder emails (run every minute by cron/scheduler.php). The creator picks when, per event
 * (events.reminders: minutes before the start, from EventsModel::REMINDERS). Each attendee gets each reminder
 * once (event_reminder_sends). Someone who registered after a reminder's time skips that one: their confirmation
 * already carried everything. If several reminders are due at once (the scheduler was down), the attendee gets
 * one email — the one closest to the start — and the earlier ones are marked done.
 * EventMail lays the email out: the meeting link for an online event (it only travels by email), the address
 * for an in-person one, and the host's instructions.
 */
class EventReminders {

    public static function run($limit = 500): int {
        $events = new EventsModel();
        $now = time();
        $sent = 0;
        foreach ($events->events_with_reminders_soon() as $ev) {
            $start = strtotime((string) $ev['start_at'] . ' UTC');
            if ($start === false || $start <= $now) { continue; }
            // Offsets already due for this event, smallest (closest to the start) first.
            $due = array_values(array_filter(EventsModel::clean_reminders($ev['reminders']), function ($m) use ($start, $now) { return $start - $m * 60 <= $now; }));
            if (empty($due)) { continue; }
            sort($due);
            $mailed = array();   // registration id => true: one email per attendee per run
            foreach ($due as $offset) {
                $before = gmdate('Y-m-d H:i:s', $start - $offset * 60);   // registered before this reminder's time
                foreach ($events->reminder_recipients((int) $ev['id'], $offset, $before, $limit) as $r) {
                    if (!$events->claim_reminder((int) $r['reg_id'], $offset)) { continue; }   // another tick already took it
                    if (isset($mailed[(int) $r['reg_id']])) { continue; }                      // a closer reminder just went out
                    $mailed[(int) $r['reg_id']] = true;
                    EventMail::reminder($ev, (int) $r['user_id'], $offset);
                    $sent++;
                }
            }
        }
        return $sent;
    }
}
