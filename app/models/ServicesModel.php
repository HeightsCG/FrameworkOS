<?php
/**
 * Services (PRD §22) — first-class objects (not content). Creators sell services
 * (consulting, coaching, design, sessions…). The platform handles listing,
 * discovery, purchase and payment collection; it does NOT handle scheduling or
 * delivery hosting — the creator provides an external scheduling URL (Calendly,
 * Acuity, …) and delivery details, revealed only after purchase.
 */
class ServicesModel extends Model {

    public static function delivery_methods(){
        return array('cls_video', 'zoom', 'teams', 'meet', 'webex', 'discord', 'phone', 'in_person', 'custom');
    }

    /** Method => label, in picker order. CLS Video (our own calls) only once the video server is set up. */
    public static function method_labels(): array {
        $all = array('cls_video' => 'CLS Video', 'zoom' => 'Zoom', 'teams' => 'Microsoft Teams', 'meet' => 'Google Meet', 'webex' => 'Webex',
                     'discord' => 'Discord', 'phone' => 'Phone', 'in_person' => 'In Person', 'custom' => 'Other');
        if (!LiveKit::enabled()) { unset($all['cls_video']); }
        return $all;
    }

    public static function method_label($method): string {
        return $method === 'cls_video' ? 'CLS Video' : (self::method_labels()[(string) $method] ?? 'Other');
    }

    /* ---------- Creator management ---------- */

    public function create($creator_id, array $f){
        $now = date('Y-m-d H:i:s');
        return (int) parent::insert('services', array(
            'creator_id'       => (int) $creator_id,
            'name'             => mb_substr((string) $f['name'], 0, 190),
            'description'      => (string) ($f['description'] ?? ''),
            'price_credits'    => max(0, (int) ($f['price_credits'] ?? 0)),
            'duration_min'     => max(0, (int) ($f['duration_min'] ?? 0)),
            'delivery_method'  => in_array($f['delivery_method'] ?? 'custom', self::delivery_methods(), true) ? $f['delivery_method'] : 'custom',
            'scheduling_url'   => self::web_link($f['scheduling_url'] ?? ''),
            'delivery_details' => (string) ($f['delivery_details'] ?? ''),
            'capacity'         => max(0, (int) ($f['capacity'] ?? 0)),
            'category'         => mb_substr((string) ($f['category'] ?? ''), 0, 64),
            'refund_policy'    => mb_substr((string) ($f['refund_policy'] ?? ''), 0, 500),
            'status'           => in_array($f['status'] ?? 'draft', array('draft', 'published'), true) ? $f['status'] : 'draft',
            'created_at'       => $now,
            'updated_at'       => $now,
        ));
    }

    /** Buyer-facing links must be http(s); anything else (javascript:, data:) is dropped. */
    private static function web_link($url){
        $url = trim((string) $url);
        return preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 500) : '';
    }

    public function update_service($creator_id, $id, array $f){
        if (!$this->get_one($creator_id, $id)) { return false; }
        $data = array('updated_at' => date('Y-m-d H:i:s'));
        foreach (array('name', 'description', 'price_credits', 'duration_min', 'delivery_method',
                       'scheduling_url', 'delivery_details', 'capacity', 'category', 'refund_policy', 'status') as $k) {
            if (!array_key_exists($k, $f)) { continue; }
            if ($k === 'delivery_method' && !in_array($f[$k], self::delivery_methods(), true)) { continue; }
            if ($k === 'status' && !in_array($f[$k], array('draft', 'published'), true)) { continue; }
            if (in_array($k, array('price_credits', 'duration_min', 'capacity'), true)) { $data[$k] = max(0, (int) $f[$k]); }
            elseif ($k === 'scheduling_url') { $data[$k] = self::web_link($f[$k]); }
            else { $data[$k] = $f[$k]; }
        }
        return parent::update('services', $data, 'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function delete_service($creator_id, $id){
        return parent::delete('services', 'id = :id AND creator_id = :c', 1, array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM services WHERE id = :id AND creator_id = :c", array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }

    /** All of a creator's services (management view), with purchase counts. */
    public function list_for_creator($creator_id){
        return (array) parent::select(
            "SELECT s.*, (SELECT COUNT(*) FROM service_purchases p WHERE p.service_id = s.id AND p.status = 'paid') AS purchases
             FROM services s WHERE s.creator_id = :c ORDER BY s.created_at DESC",
            array('c' => (int) $creator_id));
    }

    /* ---------- Public / purchase ---------- */

    public function get_public($id){
        $r = parent::select("SELECT * FROM services WHERE id = :id AND status = 'published'", array('id' => (int) $id));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }

    /** Published services for a creator's profile. */
    public function list_public_for_creator($creator_id){
        return (array) parent::select(
            "SELECT s.*, (SELECT COUNT(*) FROM service_purchases p WHERE p.service_id = s.id AND p.status = 'paid') AS purchases
             FROM services s WHERE s.creator_id = :c AND s.status = 'published' ORDER BY s.created_at DESC",
            array('c' => (int) $creator_id));
    }

    public function purchase_count($service_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM service_purchases WHERE service_id = :s AND status = 'paid'", array('s' => (int) $service_id));
        return is_array($r) && count($r) ? (int) $r[0]['n'] : 0;
    }

    /** Has this user already bought this service (grants access to the booking details)? */
    /** The buyer's paid booking id for a service, or 0 (CLS Video: the booking's call room). */
    public function paid_purchase_id($service_id, $user_id): int {
        $r = parent::select("SELECT id FROM service_purchases WHERE service_id = :s AND buyer_id = :u AND status = 'paid' ORDER BY id DESC LIMIT 1",
            array('s' => (int) $service_id, 'u' => (int) $user_id));
        return (is_array($r) && count($r)) ? (int) $r[0]['id'] : 0;
    }

    public function has_purchased($service_id, $user_id){
        $r = parent::select("SELECT id FROM service_purchases WHERE service_id = :s AND buyer_id = :u AND status = 'paid' LIMIT 1",
            array('s' => (int) $service_id, 'u' => (int) $user_id));
        return is_array($r) && count($r) >= 1;
    }

    /**
     * Take the booking row; returns its id, or 0 if this buyer already has a live one. UNIQUE(service_id, buyer_id) is the
     * mutex against a double charge; a refunded buyer booking again gets their old row back (conditional update).
     */
    public function record_purchase($service_id, $buyer_id, $price_credits = 0){
        try {
            return (int) parent::insert('service_purchases', array(
                'service_id'    => (int) $service_id,
                'buyer_id'      => (int) $buyer_id,
                'price_credits' => max(0, (int) $price_credits),
                'status'        => 'paid',
                'created_at'    => date('Y-m-d H:i:s'),
            ));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') { throw $e; }
            $back = parent::update('service_purchases',
                // A new booking reusing an old refunded row: not delivered and not paid out yet.
                array('status' => 'paid', 'price_credits' => max(0, (int) $price_credits), 'net_credits' => 0, 'created_at' => date('Y-m-d H:i:s'),
                      'delivered_at' => null, 'earning_released_at' => null),
                'service_id = :s AND buyer_id = :b AND status <> :paid', array('s' => (int) $service_id, 'b' => (int) $buyer_id, 'paid' => 'paid'));
            if ($back <= 0) { return 0; }   // already booked (or a concurrent request won)
            $r = parent::select("SELECT id FROM service_purchases WHERE service_id = :s AND buyer_id = :b", array('s' => (int) $service_id, 'b' => (int) $buyer_id));
            return (is_array($r) && count($r)) ? (int) $r[0]['id'] : 0;
        }
    }

    public function set_net($purchase_id, $net_credits){
        return parent::update('service_purchases', array('net_credits' => max(0, (int) $net_credits)), 'id = :id', array('id' => (int) $purchase_id));
    }

    /** Sold / earned / refunded for one service. */
    public function stats($service_id){
        $r = parent::select("SELECT COALESCE(SUM(status = 'paid'), 0) AS sold,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN net_credits END), 0) AS net,
                COALESCE(SUM(CASE WHEN status = 'paid' AND earning_released_at IS NOT NULL THEN net_credits END), 0) AS earned,
                COALESCE(SUM(status = 'refunded'), 0) AS refunded_n, COUNT(*) AS rows_n
             FROM service_purchases WHERE service_id = :s", array('s' => (int) $service_id));
        $x = (is_array($r) && count($r)) ? $r[0] : array();
        // earned = paid to the creator (delivered); pending = booked, waiting for them to mark it delivered
        return array('sold' => (int) ($x['sold'] ?? 0), 'net' => (int) ($x['net'] ?? 0), 'earned' => (int) ($x['earned'] ?? 0),
                     'pending' => max(0, (int) ($x['net'] ?? 0) - (int) ($x['earned'] ?? 0)), 'refunded_n' => (int) ($x['refunded_n'] ?? 0), 'rows' => (int) ($x['rows_n'] ?? 0));
    }

    /** One page of paid buyers (the manage page's Buyers table): search by name, handle or email. */
    public function buyers_page($service_id, $q, $limit, $offset){
        $limit = max(1, min(50, (int) $limit)); $offset = max(0, (int) $offset);
        $where = "p.service_id = :s AND p.status = 'paid'";
        $args  = array('s' => (int) $service_id);
        if ((string) $q !== '') {
            $where .= " AND (cp.display_name LIKE :q1 OR CONCAT(u.first_name, ' ', u.last_name) LIKE :q2 OR u.u_name LIKE :q3 OR u.user_email LIKE :q4)";
            $like = '%' . addcslashes((string) $q, '%_\\') . '%';
            $args += array('q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like);
        }
        $from = "FROM service_purchases p JOIN user_accounts u ON u.user_id = p.buyer_id LEFT JOIN creator_profiles cp ON cp.user_id = p.buyer_id WHERE $where";
        $n = parent::select("SELECT COUNT(*) AS n $from", $args);
        $rows = parent::select(
            "SELECT p.id, p.buyer_id, p.status, p.price_credits, p.net_credits, p.created_at, p.delivered_at,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.u_name) AS name,
                    u.u_name AS handle, cp.avatar_url
             $from ORDER BY p.created_at DESC, p.id DESC LIMIT $limit OFFSET $offset", $args);
        return array('rows' => (array) $rows, 'total' => (int) ((is_array($n) && count($n)) ? $n[0]['n'] : 0));
    }

    /** Everyone who ever booked (CSV export), newest first. */
    public function all_buyers($service_id){
        return (array) parent::select(
            "SELECT p.*, COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.u_name) AS name,
                    u.u_name AS handle, u.user_email AS email
             FROM service_purchases p JOIN user_accounts u ON u.user_id = p.buyer_id LEFT JOIN creator_profiles cp ON cp.user_id = p.buyer_id
             WHERE p.service_id = :s ORDER BY p.created_at DESC", array('s' => (int) $service_id));
    }

    public function purchase($service_id, $purchase_id){
        $r = parent::select("SELECT * FROM service_purchases WHERE id = :id AND service_id = :s", array('id' => (int) $purchase_id, 's' => (int) $service_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** A creator's booking earnings waiting for them to mark the bookings delivered (credits), for the Cash Out tab. */
    public function pending_earnings($creator_id){
        $r = parent::select("SELECT COALESCE(SUM(p.net_credits), 0) AS n FROM service_purchases p JOIN services s ON s.id = p.service_id
             WHERE s.creator_id = :c AND p.status = 'paid' AND p.net_credits > 0 AND p.earning_released_at IS NULL", array('c' => (int) $creator_id));
        return (int) ($r[0]['n'] ?? 0);
    }

    /** One booking by its id (CLS Video: the booking's private room). */
    public function purchase_by_id($purchase_id){
        $r = parent::select("SELECT * FROM service_purchases WHERE id = :id", array('id' => (int) $purchase_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** A service by id whatever its status (a booked call still happens if the service was later unpublished). */
    public function get_by_id($id){
        $r = parent::select("SELECT * FROM services WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Move a booking from one status to another; true only for the request that moved it (the mutex). */
    public function set_purchase_status($purchase_id, $from, $to){
        return parent::update('service_purchases', array('status' => (string) $to), 'id = :id AND status = :f',
            array('id' => (int) $purchase_id, 'f' => (string) $from)) > 0;
    }

    public function set_status($creator_id, $id, $status){
        return parent::update('services', array('status' => (string) $status, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Undo a purchase row whose payment did not go through. */
    public function remove_purchase($purchase_id){
        return parent::delete('service_purchases', 'id = :id', 1, array('id' => (int) $purchase_id));
    }

    /** A user's purchased services (for the profile / "my services"). */
    public function my_purchases($user_id){
        return (array) parent::select(
            "SELECT s.*, MAX(p.created_at) AS purchased_at, u.u_name AS creator_handle
             FROM service_purchases p
             JOIN services s ON s.id = p.service_id
             JOIN user_accounts u ON u.user_id = s.creator_id
             WHERE p.buyer_id = :u AND p.status = 'paid' AND s.status = 'published'
             GROUP BY s.id ORDER BY purchased_at DESC",
            array('u' => (int) $user_id));
    }

    /** Paid bookings this user made and wasn't refunded for (their Purchases page), newest first. */
    public function paid_bookings_for_user($user_id){
        return (array) parent::select(
            "SELECT p.id, p.service_id, p.price_credits, p.created_at AS purchased_at, s.name,
                    u.u_name AS creator_handle, COALESCE(cp.display_name, CONCAT(u.first_name, ' ', u.last_name)) AS creator_name
             FROM service_purchases p
             JOIN services s ON s.id = p.service_id
             JOIN user_accounts u ON u.user_id = s.creator_id AND u.deleted = 0
             LEFT JOIN creator_profiles cp ON cp.user_id = s.creator_id
             WHERE p.buyer_id = :u AND p.price_credits > 0 AND p.status = 'paid'
             ORDER BY p.created_at DESC",
            array('u' => (int) $user_id));
    }
}
