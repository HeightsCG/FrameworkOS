<?php
/**
 * Every money movement for events, in one place: refund one attendee (creator action or the fan
 * canceling before the start), remove one attendee (no money), cancel the whole event (refund all).
 * Each registration is claimed with a conditional status update first, so a double click or two
 * admins can never refund the same ticket twice.
 */
class EventRefunds {

    /**
     * Refund one going registration. $why: 'creator' (creator refunded them), 'fan' (they canceled
     * before the start), 'event_canceled' (cancel_all — it sends its own notice, so none here).
     */
    public static function refund_one(array $ev, $reg_id, $why = 'creator'): array {
        $events = new EventsModel();
        $reg = $events->registration((int) $ev['id'], (int) $reg_id);
        if (!$reg || (string) $reg['status'] !== 'registered') { return self::no('That registration is no longer active.'); }
        $paid = (int) $reg['price_credits'];
        if ($paid <= 0) { return self::no('That registration was free, so there is nothing to refund.'); }
        if (!$events->set_registration_status((int) $reg['id'], 'registered', 'refunded')) { return self::no('That registration is no longer active.'); }

        $fan = (int) $reg['user_id']; $creator = (int) $ev['creator_id'];
        $credits = new CreditsModel();
        $credits->apply_delta($fan, $paid, 'refund', 'Refund: event ticket');
        // The creator is paid for a ticket after the event (EventEarnings). Re-read now that the ticket is marked refunded:
        // if their share was already paid out, take it back in full (their balance may go negative, repaid from future
        // earnings); if not, they never had it and it simply won't be paid.
        $now_reg = $events->registration((int) $ev['id'], (int) $reg['id']);
        $take = ($now_reg && $now_reg['earning_released_at'] !== null) ? (int) $reg['net_credits'] : 0;
        if ($take > 0) { $credits->apply_delta($creator, -$take, 'refund_reversal', 'Refund reversal: event ticket'); }
        (new RefundsModel())->log('event', (int) $reg['id'], $creator, $fan, $paid, $take, true,
            (int) Session::get('user_id'), $why === 'fan' ? 'Attendee canceled before the start' : ($why === 'event_canceled' ? 'Event canceled' : 'Refunded by the creator'));

        $t = self::title($ev);
        if ($why !== 'event_canceled') {
            Notify::send($fan, 'refunds', 'Ticket refunded', '"' . $t . '" on ' . self::when($ev, $fan) . '. ' . Notify::credit_count($paid) . ($paid === 1 ? ' was' : ' were') . ' returned to your wallet.',
                '/account/settings?section=wallet', 'fa-rotate-left');
        }
        if ($why === 'fan') {
            Notify::send($creator, 'refunds', 'Event ticket canceled',
                (Notify::name_of($fan) ?: 'An attendee') . ' canceled their spot for "' . $t . '" (' . self::when($ev, $creator) . ') and was refunded '
                . Notify::credit_count($paid) . ($take > 0 ? '; ' . Notify::credit_count($take) . ' came out of your balance.' : '.'), '/events/' . (int) $ev['id'], 'fa-rotate-left');
        }
        return array('ok' => true, 'refunded' => $paid, 'clawback' => $take, 'message' => Notify::credit_count($paid) . ' refunded');
    }

    /** Remove one going attendee without moving money (e.g. a free ticket); frees the seat. */
    public static function remove_one(array $ev, $reg_id): array {
        $events = new EventsModel();
        $reg = $events->registration((int) $ev['id'], (int) $reg_id);
        if (!$reg || (string) $reg['status'] !== 'registered') { return self::no('That registration is no longer active.'); }
        if (!$events->set_registration_status((int) $reg['id'], 'registered', 'removed')) { return self::no('That registration is no longer active.'); }
        $fan = (int) $reg['user_id'];
        Notify::send($fan, 'events', 'Registration removed', '"' . self::title($ev) . '" on ' . self::when($ev, $fan) . '. The creator removed your registration.',
            '', 'fa-calendar-xmark');
        return array('ok' => true, 'message' => 'Attendee removed');
    }

    /** Cancel the event: closes registration, refunds every paid ticket, tells every attendee once. */
    public static function cancel_all(array $ev): array {
        $events = new EventsModel();
        $eid = (int) $ev['id'];
        $events->set_status((int) $ev['creator_id'], $eid, 'canceled');   // first, so no one registers mid-cancel
        $t = self::title($ev);
        $refunded_n = 0; $notified = 0;
        foreach ($events->attendees($eid) as $a) {
            if ((string) $a['status'] !== 'registered') { continue; }
            $paid = (int) $a['price_credits'];
            if ($paid > 0) {
                $r = self::refund_one($ev, (int) $a['id'], 'event_canceled');
                if (empty($r['ok'])) { continue; }
                $refunded_n++;
            } elseif (!$events->set_registration_status((int) $a['id'], 'registered', 'canceled')) {
                continue;
            }
            $fan = (int) $a['user_id'];
            Notify::send($fan, 'events', 'Event canceled', '"' . $t . '" on ' . self::when($ev, $fan) . ' was canceled.'
                . ($paid > 0 ? ' ' . Notify::credit_count($paid) . ($paid === 1 ? ' was' : ' were') . ' returned to your wallet.' : ''), '', 'fa-calendar-xmark');
            $notified++;
        }
        return array('ok' => true, 'refunded_n' => $refunded_n, 'notified' => $notified);
    }

    /** "Sat, Aug 15 at 7:00 PM EDT" in the reader's timezone; the event's own zone when the reader has none (UTC default). */
    public static function when(array $ev, $reader_id): string {
        $rows = (new UsersModel())->get_user_by_id((int) $reader_id);
        $tz   = (is_array($rows) && count($rows) === 1) ? (string) ($rows[0]['content_timezone'] ?? '') : '';
        if ($tz === '' || $tz === 'UTC') { $tz = (string) ($ev['timezone'] ?? 'UTC'); }
        try {
            $d = new DateTime((string) $ev['start_at'], new DateTimeZone('UTC'));
            $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
            return $d->format('D, M j \a\t g:i A T');
        } catch (\Throwable $e) { return 'the scheduled time'; }
    }

    public static function title(array $ev): string {
        return mb_substr(html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8'), 0, 60);
    }

    private static function no($msg): array {
        return array('ok' => false, 'refunded' => 0, 'clawback' => 0, 'message' => (string) $msg);
    }
}
