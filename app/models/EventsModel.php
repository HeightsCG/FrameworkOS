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
            'format'              => self::format($f['format'] ?? 'virtual'),
            'location'            => mb_substr((string) ($f['location'] ?? ''), 0, 255),
            'venue_name'          => mb_substr((string) ($f['venue_name'] ?? ''), 0, 160),
            'street'              => mb_substr((string) ($f['street'] ?? ''), 0, 190),
            'city'                => mb_substr((string) ($f['city'] ?? ''), 0, 100),
            'region'              => mb_substr((string) ($f['region'] ?? ''), 0, 60),
            'postal_code'         => mb_substr((string) ($f['postal_code'] ?? ''), 0, 20),
            'external_url'        => self::web_link($f['external_url'] ?? ''),
            'access_instructions' => (string) ($f['access_instructions'] ?? ''),
            'status'              => in_array($f['status'] ?? 'draft', array('draft', 'published', 'canceled'), true) ? $f['status'] : 'draft',
            'created_at'          => $now,
            'updated_at'          => $now,
        ));
    }

    /** One display line from the structured address: "Venue, 512 E Washington St, Orlando, FL 32801". */
    public static function address_line(array $a){
        $tail = trim(implode(' ', array_filter(array(trim((string) ($a['region'] ?? '')), trim((string) ($a['postal_code'] ?? ''))))));
        $parts = array_filter(array(trim((string) ($a['venue_name'] ?? '')), trim((string) ($a['street'] ?? '')), trim((string) ($a['city'] ?? '')), $tail), 'strlen');
        return implode(', ', $parts);
    }

    /** 'virtual' (video link) or 'in_person' (address). */
    public static function format($v){
        return ((string) $v === 'in_person') ? 'in_person' : 'virtual';
    }

    /** Buyer-facing links must be http(s); anything else (javascript:, data:) is dropped. */
    private static function web_link($url){
        $url = trim((string) $url);
        return preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 500) : '';
    }

    public function update_event($creator_id, $id, array $f){
        if (!$this->get_one($creator_id, $id)) { return false; }
        $data = array('updated_at' => date('Y-m-d H:i:s'));
        foreach (array('title', 'description', 'start_at', 'end_at', 'timezone', 'access_type', 'price_credits',
                       'tier_id', 'capacity', 'format', 'location', 'venue_name', 'street', 'city', 'region', 'postal_code', 'external_url', 'access_instructions', 'status') as $k) {
            if (!array_key_exists($k, $f)) { continue; }
            if ($k === 'access_type' && !in_array($f[$k], self::access_types(), true)) { continue; }
            if ($k === 'format') { $data[$k] = self::format($f[$k]); continue; }
            if ($k === 'status' && !in_array($f[$k], array('draft', 'published', 'canceled'), true)) { continue; }
            if (in_array($k, array('end_at', 'tier_id'), true)) { $data[$k] = !empty($f[$k]) ? $f[$k] : null; }
            elseif (in_array($k, array('price_credits', 'capacity'), true)) { $data[$k] = max(0, (int) $f[$k]); }
            elseif ($k === 'external_url') { $data[$k] = self::web_link($f[$k]); }
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

    /**
     * Atomically take a seat before any charge (UNIQUE event_id+user_id is the mutex).
     * Returns null when the user is already registered (a concurrent request won), else
     * ['id', 'fresh' => new row?, 'prior_paid' => credits paid on a canceled row being reactivated].
     */
    public function claim_registration($event_id, $user_id){
        $e = (int) $event_id; $u = (int) $user_id;
        try {
            $id = (int) parent::insert('event_registrations', array(
                'event_id' => $e, 'user_id' => $u, 'status' => 'registered', 'price_credits' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ));
            return array('id' => $id, 'fresh' => true, 'prior_paid' => 0);
        } catch (\PDOException $ex) {
            if ((string) $ex->getCode() !== '23000') { throw $ex; }
        }
        $row = parent::select("SELECT id, status, price_credits, net_credits FROM event_registrations WHERE event_id = :e AND user_id = :u", array('e' => $e, 'u' => $u));
        if (!is_array($row) || count($row) !== 1) { return null; }
        $prev = (string) $row[0]['status'];
        if (!in_array($prev, array('canceled', 'refunded', 'removed'), true)) { return null; }   // already registered
        // Only a self-canceled paid seat is still paid for; a refunded or removed one is charged fresh.
        $data = array('status' => 'registered');
        if ($prev !== 'canceled') { $data += array('price_credits' => 0, 'net_credits' => 0); }
        $n = parent::update('event_registrations', $data, 'id = :id AND status = :prev', array('id' => (int) $row[0]['id'], 'prev' => $prev));
        if ($n < 1) { return null; }   // a concurrent request won
        return array('id' => (int) $row[0]['id'], 'fresh' => false, 'prev' => $prev,
                     'prior_paid' => $prev === 'canceled' ? (int) $row[0]['price_credits'] : 0,
                     'prev_paid' => (int) $row[0]['price_credits'], 'prev_net' => (int) $row[0]['net_credits']);
    }

    /** Undo a claim whose payment failed: drop a fresh row, or put a reactivated one back as it was. */
    public function release_registration(array $claim){
        if (!empty($claim['fresh'])) { return parent::delete('event_registrations', 'id = :id', 1, array('id' => (int) $claim['id'])); }
        return parent::update('event_registrations',
            array('status' => (string) ($claim['prev'] ?? 'canceled'), 'price_credits' => (int) ($claim['prev_paid'] ?? 0), 'net_credits' => (int) ($claim['prev_net'] ?? 0)),
            'id = :id', array('id' => (int) $claim['id']));
    }

    /** Sales for the event page: going count, gross + net of going tickets, refunds. Credits. */
    public function stats($event_id){
        $r = parent::select("SELECT
                COALESCE(SUM(status = 'registered'), 0) AS going,
                COALESCE(SUM(CASE WHEN status = 'registered' THEN price_credits END), 0) AS gross,
                COALESCE(SUM(CASE WHEN status = 'registered' THEN net_credits END), 0) AS net,
                COALESCE(SUM(status = 'refunded'), 0) AS refunded_n,
                COALESCE(SUM(CASE WHEN status = 'refunded' THEN price_credits END), 0) AS refunded_credits
             FROM event_registrations WHERE event_id = :e", array('e' => (int) $event_id));
        $x = (is_array($r) && count($r)) ? $r[0] : array();
        return array('going' => (int) ($x['going'] ?? 0), 'gross' => (int) ($x['gross'] ?? 0), 'net' => (int) ($x['net'] ?? 0),
                     'refunded_n' => (int) ($x['refunded_n'] ?? 0), 'refunded_credits' => (int) ($x['refunded_credits'] ?? 0));
    }

    /** Everyone who ever registered, going first, with identity for the attendee list and CSV. */
    public function attendees($event_id){
        return (array) parent::select(
            "SELECT r.id, r.user_id, r.status, r.price_credits, r.net_credits, r.created_at,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.u_name) AS name,
                    u.u_name AS handle, u.user_email AS email, cp.avatar_url
             FROM event_registrations r
             JOIN user_accounts u ON u.user_id = r.user_id
             LEFT JOIN creator_profiles cp ON cp.user_id = r.user_id
             WHERE r.event_id = :e
             ORDER BY (r.status = 'registered') DESC, r.created_at DESC", array('e' => (int) $event_id));
    }

    /** One page of the people going (the event page's attendee table): search by name, handle or email. */
    public function going_page($event_id, $q, $limit, $offset){
        $limit = max(1, min(50, (int) $limit)); $offset = max(0, (int) $offset);
        $where = "r.event_id = :e AND r.status = 'registered'";
        $args  = array('e' => (int) $event_id);
        if ((string) $q !== '') {
            $where .= " AND (cp.display_name LIKE :q1 OR CONCAT(u.first_name, ' ', u.last_name) LIKE :q2 OR u.u_name LIKE :q3 OR u.user_email LIKE :q4)";
            $like = '%' . addcslashes((string) $q, '%_\\') . '%';
            $args += array('q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like);
        }
        $from = "FROM event_registrations r JOIN user_accounts u ON u.user_id = r.user_id LEFT JOIN creator_profiles cp ON cp.user_id = r.user_id WHERE $where";
        $n = parent::select("SELECT COUNT(*) AS n $from", $args);
        $rows = parent::select(
            "SELECT r.id, r.user_id, r.status, r.price_credits, r.net_credits, r.created_at,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.u_name) AS name,
                    u.u_name AS handle, cp.avatar_url
             $from ORDER BY r.created_at DESC, r.id DESC LIMIT $limit OFFSET $offset", $args);
        return array('rows' => (array) $rows, 'total' => (int) ((is_array($n) && count($n)) ? $n[0]['n'] : 0));
    }

    /** Every registration row ever (going, canceled, refunded, removed): is there anything to export? */
    public function registration_count($event_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM event_registrations WHERE event_id = :e", array('e' => (int) $event_id));
        return (int) ((is_array($r) && count($r)) ? $r[0]['n'] : 0);
    }

    public function registration($event_id, $reg_id){
        $r = parent::select("SELECT * FROM event_registrations WHERE id = :id AND event_id = :e", array('id' => (int) $reg_id, 'e' => (int) $event_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Move a registration from one status to another; true only for the request that moved it (the mutex). */
    public function set_registration_status($reg_id, $from, $to){
        return parent::update('event_registrations', array('status' => (string) $to), 'id = :id AND status = :from',
            array('id' => (int) $reg_id, 'from' => (string) $from)) > 0;
    }

    public function going_user_ids($event_id){
        $r = parent::select("SELECT user_id FROM event_registrations WHERE event_id = :e AND status = 'registered'", array('e' => (int) $event_id));
        return array_map(function ($x) { return (int) $x['user_id']; }, (array) $r);
    }

    public function has_paid_going($event_id){
        $r = parent::select("SELECT id FROM event_registrations WHERE event_id = :e AND status = 'registered' AND price_credits > 0 LIMIT 1", array('e' => (int) $event_id));
        return is_array($r) && count($r) === 1;
    }

    public function set_status($creator_id, $event_id, $status){
        return parent::update('events', array('status' => (string) $status, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c', array('id' => (int) $event_id, 'c' => (int) $creator_id));
    }

    public function add_message($event_id, $creator_id, $body, $recipients){
        return (int) parent::insert('event_messages', array('event_id' => (int) $event_id, 'creator_id' => (int) $creator_id,
            'body' => (string) $body, 'recipients' => (int) $recipients, 'created_at' => date('Y-m-d H:i:s')));
    }

    public function messages($event_id){
        return (array) parent::select("SELECT * FROM event_messages WHERE event_id = :e ORDER BY id DESC", array('e' => (int) $event_id));
    }

    /** One page of sent messages, newest first. */
    public function messages_page($event_id, $limit, $offset){
        $limit = max(1, min(50, (int) $limit)); $offset = max(0, (int) $offset);
        $rows = parent::select("SELECT id, body, recipients, created_at FROM event_messages WHERE event_id = :e ORDER BY id DESC LIMIT $limit OFFSET $offset", array('e' => (int) $event_id));
        return array('rows' => (array) $rows, 'total' => $this->message_count($event_id));
    }

    public function message_count($event_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM event_messages WHERE event_id = :e", array('e' => (int) $event_id));
        return (int) ((is_array($r) && count($r)) ? $r[0]['n'] : 0);
    }

    public function set_paid($registration_id, $price_credits, $net_credits = 0){
        return parent::update('event_registrations', array('price_credits' => max(0, (int) $price_credits), 'net_credits' => max(0, (int) $net_credits)),
            'id = :id', array('id' => (int) $registration_id));
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
