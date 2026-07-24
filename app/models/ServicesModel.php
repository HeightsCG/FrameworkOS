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
        return array('zoom', 'teams', 'meet', 'webex', 'discord', 'phone', 'in_person', 'custom');
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
            'scheduling_url'   => mb_substr((string) ($f['scheduling_url'] ?? ''), 0, 500),
            'delivery_details' => (string) ($f['delivery_details'] ?? ''),
            'capacity'         => max(0, (int) ($f['capacity'] ?? 0)),
            'category'         => mb_substr((string) ($f['category'] ?? ''), 0, 64),
            'refund_policy'    => mb_substr((string) ($f['refund_policy'] ?? ''), 0, 500),
            'status'           => in_array($f['status'] ?? 'draft', array('draft', 'published'), true) ? $f['status'] : 'draft',
            'created_at'       => $now,
            'updated_at'       => $now,
        ));
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
    public function has_purchased($service_id, $user_id){
        $r = parent::select("SELECT id FROM service_purchases WHERE service_id = :s AND buyer_id = :u AND status = 'paid' LIMIT 1",
            array('s' => (int) $service_id, 'u' => (int) $user_id));
        return is_array($r) && count($r) >= 1;
    }

    /** Record a paid purchase. Returns the new row id. */
    public function record_purchase($service_id, $buyer_id, $price_credits = 0){
        return (int) parent::insert('service_purchases', array(
            'service_id'    => (int) $service_id,
            'buyer_id'      => (int) $buyer_id,
            'price_credits' => max(0, (int) $price_credits),
            'status'        => 'paid',
            'created_at'    => date('Y-m-d H:i:s'),
        ));
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
}
