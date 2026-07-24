<?php
/**
 * Events (PRD §23) — first-class objects (not content). Creators host externally
 * (link/venue); the platform handles listing, registration, paid tickets, and gating
 * (free / paid-with-credits / subscribers / tier-specific). Access details are only
 * revealed to registered users.
 */
class EventsModel extends Model {

    public static function access_types(){ return array('free', 'paid', 'subscribers', 'tier'); }

    /* ---------- Creator management ---------- */

    public function create($creator_id, array $f){
        $now = date('Y-m-d H:i:s');
        return (int) parent::insert('events', array(
            'creator_id'          => (int) $creator_id,
            'title'               => mb_substr((string) $f['title'], 0, 190),
            'description'         => (string) ($f['description'] ?? ''),
            'start_at'            => (string) $f['start_at'],
            'end_at'              => !empty($f['end_at']) ? (string) $f['end_at'] : null,
            'timezone'            => (string) ($f['timezone'] ?? 'UTC'),
            'access_type'         => in_array($f['access_type'] ?? 'free', self::access_types(), true) ? $f['access_type'] : 'free',
            'price_credits'       => max(0, (int) ($f['price_credits'] ?? 0)),
            'tier_id'             => !empty($f['tier_id']) ? (int) $f['tier_id'] : null,
            'capacity'            => max(0, (int) ($f['capacity'] ?? 0)),
            'location'            => mb_substr((string) ($f['location'] ?? ''), 0, 255),
            'external_url'        => mb_substr((string) ($f['external_url'] ?? ''), 0, 500),
            'access_instructions' => (string) ($f['access_instructions'] ?? ''),
            'status'              => in_array($f['status'] ?? 'draft', array('draft', 'published', 'canceled'), true) ? $f['status'] : 'draft',
            'created_at'          => $now,
            'updated_at'          => $now,
        ));
    }

    public function update_event($creator_id, $id, array $f){
        if (!$this->get_one($creator_id, $id)) { return false; }
        $data = array('updated_at' => date('Y-m-d H:i:s'));
        foreach (array('title', 'description', 'start_at', 'end_at', 'timezone', 'access_type', 'price_credits',
                       'tier_id', 'capacity', 'location', 'external_url', 'access_instructions', 'status') as $k) {
            if (!array_key_exists($k, $f)) { continue; }
            if ($k === 'access_type' && !in_array($f[$k], self::access_types(), true)) { continue; }
            if ($k === 'status' && !in_array($f[$k], array('draft', 'published', 'canceled'), true)) { continue; }
            if (in_array($k, array('end_at', 'tier_id'), true)) { $data[$k] = !empty($f[$k]) ? $f[$k] : null; }
            elseif (in_array($k, array('price_credits', 'capacity'), true)) { $data[$k] = max(0, (int) $f[$k]); }
            else { $data[$k] = $f[$k]; }
        }
        return parent::update('events', $data, 'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function delete_event($creator_id, $id){
        return parent::delete('events', 'id = :id AND creator_id = :c', 1, array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM events WHERE id = :id AND creator_id = :c", array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }

    /** All of a creator's events (management view), with registration counts. */
    public function list_for_creator($creator_id){
        return (array) parent::select(
            "SELECT e.*, (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'registered') AS attendees
             FROM events e WHERE e.creator_id = :c ORDER BY e.start_at DESC",
            array('c' => (int) $creator_id));
    }

    /* ---------- Public / registration ---------- */

    public function get_public($id){
        $r = parent::select("SELECT * FROM events WHERE id = :id AND status = 'published'", array('id' => (int) $id));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }

    /** Published, non-past-ended events for a creator's profile (soonest first). */
    public function list_public_for_creator($creator_id){
        return (array) parent::select(
            "SELECT e.*, (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'registered') AS attendees
             FROM events e
             WHERE e.creator_id = :c AND e.status = 'published'
               AND (e.end_at IS NULL OR e.end_at >= UTC_TIMESTAMP() OR e.start_at >= UTC_TIMESTAMP())
             ORDER BY e.start_at ASC",
            array('c' => (int) $creator_id));
    }

    public function attendee_count($event_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM event_registrations WHERE event_id = :e AND status = 'registered'", array('e' => (int) $event_id));
        return is_array($r) && count($r) ? (int) $r[0]['n'] : 0;
    }

    public function is_registered($event_id, $user_id){
        $r = parent::select("SELECT id FROM event_registrations WHERE event_id = :e AND user_id = :u AND status = 'registered'",
            array('e' => (int) $event_id, 'u' => (int) $user_id));
        return is_array($r) && count($r) === 1;
    }

    /** Register a user (idempotent). Returns true on success/already-registered. */
    public function register($event_id, $user_id, $price_credits = 0){
        if ($this->is_registered($event_id, $user_id)) { return true; }
        // Reactivate a prior canceled row, else insert.
        $ex = parent::select("SELECT id FROM event_registrations WHERE event_id = :e AND user_id = :u", array('e' => (int) $event_id, 'u' => (int) $user_id));
        if (is_array($ex) && count($ex)) {
            return parent::update('event_registrations',
                array('status' => 'registered', 'price_credits' => max(0, (int) $price_credits)),
                'id = :id', array('id' => (int) $ex[0]['id']));
        }
        return (bool) parent::insert('event_registrations', array(
            'event_id' => (int) $event_id, 'user_id' => (int) $user_id,
            'status' => 'registered', 'price_credits' => max(0, (int) $price_credits),
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    public function cancel_registration($event_id, $user_id){
        return parent::update('event_registrations', array('status' => 'canceled'),
            'event_id = :e AND user_id = :u', array('e' => (int) $event_id, 'u' => (int) $user_id));
    }

    /** A user's registered upcoming events (for the profile / "my events"). */
    public function my_events($user_id){
        return (array) parent::select(
            "SELECT e.*, r.created_at AS registered_at, u.u_name AS creator_handle
             FROM event_registrations r
             JOIN events e ON e.id = r.event_id
             JOIN user_accounts u ON u.user_id = e.creator_id
             WHERE r.user_id = :u AND r.status = 'registered' AND e.status = 'published'
             ORDER BY e.start_at ASC",
            array('u' => (int) $user_id));
    }
}
