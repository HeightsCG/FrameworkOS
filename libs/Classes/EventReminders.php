<?php
/**
 * The reminder email before an event: once per registration, about 24 hours ahead (run every minute by
 * cron/scheduler.php). EventMail lays it out: the meeting link for an online event (never shown on the event
 * page; it only travels by email), the address for an in-person one, and the host's instructions.
 */
class EventReminders {

    public static function run($limit = 200): int {
        $events = new EventsModel();
        $sent = 0;
        foreach ($events->due_reminders($limit) as $row) {
            if (!$events->claim_reminder((int) $row['reg_id'])) { continue; }   // another tick already took it
            EventMail::reminder($row, (int) $row['user_id']);
            $sent++;
        }
        return $sent;
    }
}
