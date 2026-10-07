<?php
/**
 * Who may enter a CLS Video call, and when. Used by the join API (which hands out the LiveKit pass) and by the
 * event / service pages (which decide whether to show "Join").
 *
 *  Event   (room ev-<id>):  ticket holders (registration 'registered') and the creator or their team (host),
 *                           from JOIN_EARLY_MIN before the start until LATE_MIN after the end. A free event open to
 *                           everyone (EventsModel::open_call) lets anyone in, registered or not, even without an account.
 *                           An optional call password applies to everyone but the host.
 *  Booking (room sv-<purchase id>): the buyer of that booking and the creator or their team (host), any time while
 *                           the booking is paid (creator and buyer agree the time, as with outside links today).
 */
class LiveAccess {

    const JOIN_EARLY_MIN = 15;
    const LATE_MIN       = 30;

    /** [opens, closes] as Unix times for an event's call. */
    public static function window(array $ev): array
    {
        $start = strtotime((string) $ev['start_at'] . ' UTC');
        $end   = !empty($ev['end_at']) ? strtotime((string) $ev['end_at'] . ' UTC') : $start + 3600;
        return array($start - self::JOIN_EARLY_MIN * 60, $end + self::LATE_MIN * 60);
    }

    /** 'early' | 'open' | 'closed' for an event's call right now. */
    public static function event_phase(array $ev, $now = null): string
    {
        $now = $now ?? time();
        list($open, $close) = self::window($ev);
        return $now < $open ? 'early' : ($now > $close ? 'closed' : 'open');
    }

    /** True when the signed-in person runs this creator's account (the creator, or a Manager on their team, as the event / service workspaces require). */
    public static function is_host($creator_id): bool
    {
        return Permissions::creator_id() === (int) $creator_id && (int) $creator_id > 0 && Permissions::can_act_as_creator() && Permissions::team_allows('manage');
    }

    /**
     * Decide one join. Returns ['ok', 'message', 'room', 'host', 'guest', 'title', 'creator_id', 'defaults' (the call's
     * starting settings, LiveControl::defaults), plus 'need_login' /
     * 'need_password' / 'opens_at' when refused for that reason]. $kind: 'event' (id = event id) or 'booking' (id =
     * service purchase id). $password: what they typed (events with a call password). $user_id 0 = not signed in.
     */
    public static function check($kind, $id, $user_id, $password = null): array
    {
        $no = function ($m, array $x = array()) { return $x + array('ok' => false, 'message' => $m); };
        if (!LiveKit::enabled()) { return $no('Video calls are not available right now.'); }
        $user_id = (int) $user_id;

        if ($kind === 'event') {
            $ev = (new EventsModel())->get_public((int) $id);
            if (!$ev || (string) ($ev['format'] ?? '') !== 'cls_video') { return $no('This call is not available.'); }
            $open = EventsModel::open_call($ev);   // free and open to everyone: no account or registration needed
            $host = $user_id > 0 && self::is_host((int) $ev['creator_id']);
            if (!$host) {
                if ($user_id <= 0 && !$open) { return $no('Sign in to join.', array('need_login' => true)); }
                if (!$open && !(new EventsModel())->going_registration((int) $ev['id'], $user_id)) {
                    return $no('Only registered attendees can join this call.');
                }
                if ($user_id > 0 && (new BlocksModel())->either_blocked($user_id, (int) $ev['creator_id'])) { return $no('This call is not available.'); }
            }
            $phase = self::event_phase($ev);
            // The host may open the room early to get set up; everyone else waits for the window.
            if ($phase === 'closed') { return $no('This event has ended.'); }
            if ($phase === 'early' && !$host) {
                return $no('The call opens ' . self::JOIN_EARLY_MIN . ' minutes before the start.', array('opens_at' => self::window($ev)[0]));
            }
            $pw = trim((string) ($ev['call_password'] ?? ''));
            if ($pw !== '' && !$host && !hash_equals($pw, trim((string) $password))) {
                return $no(trim((string) $password) === '' ? 'Enter the call password.' : 'That password isn’t right.', array('need_password' => true, 'wrong_password' => trim((string) $password) !== ''));
            }
            return array('ok' => true, 'message' => '', 'room' => LiveKit::room_for_event((int) $ev['id']), 'host' => $host, 'guest' => $user_id <= 0,
                'title' => html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8'), 'creator_id' => (int) $ev['creator_id'],
                'defaults' => LiveControl::defaults($ev));
        }

        if ($user_id <= 0) { return $no('Sign in to join.', array('need_login' => true)); }
        if ($kind === 'booking') {
            $services = new ServicesModel();
            $p = $services->purchase_by_id((int) $id);
            if (!$p || (string) $p['status'] !== 'paid') { return $no('This booking is not active.'); }
            $sv = $services->get_by_id((int) $p['service_id']);
            if (!$sv || (string) ($sv['delivery_method'] ?? '') !== 'cls_video') { return $no('This call is not available.'); }
            $host = self::is_host((int) $sv['creator_id']);
            if (!$host && (int) $p['buyer_id'] !== (int) $user_id) { return $no('This call is only for the person who booked it.'); }
            return array('ok' => true, 'message' => '', 'room' => LiveKit::room_for_booking((int) $p['id']), 'host' => $host, 'guest' => false,
                'title' => html_entity_decode((string) $sv['name'], ENT_QUOTES, 'UTF-8'), 'creator_id' => (int) $sv['creator_id'],
                'defaults' => LiveControl::defaults(null));
        }
        return $no('This call is not available.');
    }
}
