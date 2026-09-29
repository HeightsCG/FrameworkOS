<?php
/**
 * The attendee's event emails (registration confirmation, reminder), laid out like a real event email:
 * the event block (title, host, date/time in the event's own zone, where), a Join Meeting button for online
 * events (the meeting link only ever travels by email), instructions, and Add to Google Calendar / event page.
 * Always emailed (it's their ticket); the in-app notice is written alongside, as before.
 */
class EventMail {

    public static function confirmation(array $ev, $user_id, $charged_credits = 0): void {
        $intro = 'You’re registered' . ($charged_credits > 0 ? ' and paid ' . Notify::credits($charged_credits) : '') . '. Here are your event details.';
        self::send($ev, $user_id, 'You’re registered: ' . EventRefunds::title($ev), 'You’re registered', $intro,
            'Registration confirmed', '"' . EventRefunds::title($ev) . '" on ' . EventRefunds::when($ev, $user_id) . '.', 'fa-calendar-check');
    }

    /** $offset: minutes before the start this reminder was scheduled for (EventsModel::REMINDERS). */
    public static function reminder(array $ev, $user_id, $offset = 1440): void {
        $offset = (int) $offset;
        $when = $offset >= 10080 ? 'next week' : ($offset >= 1440 ? 'tomorrow' : 'in ' . (EventsModel::REMINDERS[$offset] ?? $offset . ' minutes'));
        $t = EventRefunds::title($ev);
        self::send($ev, $user_id, 'Starts ' . $when . ': ' . $t, 'Your event starts ' . $when, 'A quick reminder with everything you need.',
            'Event reminder', '"' . $t . '" starts ' . EventRefunds::when($ev, $user_id) . '.', 'fa-calendar-day');
    }

    /** The data the email template needs, built from an events row. */
    public static function details(array $ev): array {
        $dec = function ($k) use ($ev) { return trim(html_entity_decode((string) ($ev[$k] ?? ''), ENT_QUOTES, 'UTF-8')); };
        $tz  = EventsModel::clean_timezone($ev['timezone'] ?? '', 'UTC');
        $date_line = ''; $time_line = ''; $start_utc = null; $end_utc = null;
        try {
            $start_utc = new DateTime((string) $ev['start_at'], new DateTimeZone('UTC'));
            $end_utc   = !empty($ev['end_at']) ? new DateTime((string) $ev['end_at'], new DateTimeZone('UTC')) : (clone $start_utc)->modify('+1 hour');
            $s = (clone $start_utc)->setTimezone(new DateTimeZone($tz)); $e = (clone $end_utc)->setTimezone(new DateTimeZone($tz));
            $date_line = $s->format('l, F j, Y');
            $time_line = $s->format('g:i A') . ' – ' . ($s->format('Y-m-d') === $e->format('Y-m-d') ? $e->format('g:i A') : $e->format('M j, g:i A')) . ' ' . $s->format('T');
        } catch (\Throwable $x) {}
        $in_person = (($ev['format'] ?? 'virtual') === 'in_person');
        $city_line = implode(', ', array_filter(array($dec('city'), trim($dec('region') . ' ' . $dec('postal_code'))), 'strlen'));
        $place = ($dec('venue_name') !== '' || $dec('street') !== '' || $city_line !== '') ? array($dec('venue_name'), $dec('street'), $city_line) : array($dec('location'));
        $join  = $in_person ? '' : (preg_match('#^https?://#i', (string) ($ev['external_url'] ?? '')) ? trim((string) $ev['external_url']) : '');
        // CLS Video: the button opens our call page, which checks the ticket (safe to forward: it needs the fan's login).
        if ((string) ($ev['format'] ?? '') === 'cls_video') { $join = rtrim(Main::get_base_domain(), '/') . '/live/event/' . (int) $ev['id']; }
        $host  = ''; $handle = '';
        $rows  = (new UsersModel())->get_user_by_id((int) $ev['creator_id']);
        if (is_array($rows) && count($rows) === 1) {
            $handle = (string) $rows[0]['u_name'];
            $prof = (new CreatorProfileModel())->get_for_user((int) $ev['creator_id']);
            $host = trim((string) ($prof['display_name'] ?? '')) !== '' ? trim((string) $prof['display_name']) : $handle;
        }
        $cal = '';
        if ($start_utc) {
            $cal = 'https://calendar.google.com/calendar/render?' . http_build_query(array(
                'action' => 'TEMPLATE', 'text' => $dec('title'),
                'dates' => $start_utc->format('Ymd\THis\Z') . '/' . $end_utc->format('Ymd\THis\Z'),
                'details' => trim(($join !== '' ? 'Join: ' . $join . "\n\n" : '') . $dec('access_instructions')),
                'location' => $in_person ? implode(', ', array_filter($place, 'strlen')) : $join,
            ));
        }
        return array(
            'title' => $dec('title'), 'host' => $host, 'date_line' => $date_line, 'time_line' => $time_line,
            'format' => $in_person ? 'in_person' : 'virtual', 'place_lines' => $place, 'join_url' => $join,
            'instructions' => $dec('access_instructions'), 'calendar_url' => $cal,
            'event_url' => $handle !== '' ? '/@' . rawurlencode($handle) . '/events/' . (int) $ev['id'] : '',
        );
    }

    private static function send(array $ev, $user_id, $subject, $heading, $intro, $notice_title, $notice_body, $icon): void {
        try {
            $d = self::details($ev);
            (new UserNotificationsModel())->push((int) $user_id, 'events', $notice_title, $notice_body, $d['event_url'], $icon);   // the in-app notice
            $rows = (new UsersModel())->get_user_by_id((int) $user_id);
            $u = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            if (!$u || (string) ($u['user_email'] ?? '') === '') { return; }
            (new NotificationsModel())->send_event_email((string) $u['user_email'], trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')),
                $subject, $heading, $intro, $d);
        } catch (\Throwable $e) {
            error_log('[event-mail] ' . $e->getMessage());
        }
    }
}
