<?php
/**
 * Cross-promotion swaps (/promote). Two creators who opted in (creator_profiles.cross_promo_in) agree to feature
 * each other: while a swap is active both profiles show the other under "Featured Creators".
 * Active is computed, never stored by a cron: status = 'active' AND ends_at > NOW(). Clicks on a featured card
 * go through /promote/click/<swap>/<to> and land in promo_swap_clicks.
 */
class PromoSwapsModel extends Model {

    /** Swap lengths a creator can pick, in days. */
    const DAYS = array(7, 14, 30);

    /** Plans that may cross-promote when the admin never saved the setting (AdminSettingsModel 'cross_promo_plans'). */
    const DEFAULT_PLANS = 'creator,studio';

    public function __construct(){
        parent::__construct();
    }

    /* ---------- eligibility ---------- */

    /** Plan keys allowed to cross-promote: the admin setting, kept to real PlanTiers keys. */
    public static function plans(): array {
        $raw = (new AdminSettingsModel())->get('cross_promo_plans', self::DEFAULT_PLANS);
        $out = array();
        foreach (explode(',', strtolower($raw)) as $k) {
            $k = trim($k);
            if ($k !== '' && isset(PlanTiers::TIERS[$k]) && !in_array($k, $out, true)) { $out[] = $k; }
        }
        return $out;
    }

    /** The plan key of a user row: Free for a creator without a paid plan, '' for a non-creator. */
    public static function plan_key($user): string {
        if (!is_array($user) || !Plan::is_creator_row($user)) { return ''; }
        $t = Plan::tier($user);
        return $t !== '' ? $t : PlanTiers::FREE_KEY;
    }

    /** Is this creator's plan allowed to cross-promote? */
    public static function eligible_user($user): bool {
        $k = self::plan_key($user);
        return $k !== '' && in_array($k, self::plans(), true);
    }

    public static function eligible($user_id): bool {
        $rows = (new UsersModel())->get_user_by_id((int) $user_id);
        return is_array($rows) && count($rows) === 1 && (string) ($rows[0]['user_status'] ?? '') === 'Active' && self::eligible_user($rows[0]);
    }

    /* ---------- opt-in ---------- */

    public function opted_in($user_id): bool {
        $r = parent::select("SELECT cross_promo_in FROM creator_profiles WHERE user_id = :u", array('u' => (int) $user_id));
        return is_array($r) && count($r) === 1 && (int) $r[0]['cross_promo_in'] === 1;
    }

    /** Switch the opt-in; false when the creator has no profile row yet. */
    public function set_opt_in($user_id, $on): bool {
        if (!(new CreatorProfileModel())->get_for_user((int) $user_id)) { return false; }
        parent::update('creator_profiles', array('cross_promo_in' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')), 'user_id = :u', array('u' => (int) $user_id));
        return true;
    }

    /* ---------- browse ---------- */

    /**
     * Opted-in creators $me could swap with: active creator accounts with a public page, on an eligible plan,
     * not blocked either way. $category '' = every niche; $q matches name or handle. Same niche first.
     */
    public function browse($me, $category = '', $q = '', $limit = 200){
        $params = array('me' => (int) $me, 'b1' => (int) $me, 'b2' => (int) $me);
        $where = '';
        if ((string) $category !== '') { $where .= ' AND cp.directory_category = :cat'; $params['cat'] = (string) $category; }
        if (trim((string) $q) !== '') {
            $where .= ' AND (cp.display_name LIKE :q1 OR u.u_name LIKE :q2 OR CONCAT(u.first_name, \' \', u.last_name) LIKE :q3)';
            $like = '%' . addcslashes(trim((string) $q), '%_\\') . '%';
            $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like;
        }
        $rows = (array) parent::select(
            "SELECT u.*, r.role_name, COALESCE(NULLIF(TRIM(cp.display_name), ''), TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')))) AS display_name, cp.avatar_url, cp.directory_category,
                    (SELECT COUNT(*) FROM follows f WHERE f.creator_id = u.user_id) AS followers
             FROM creator_profiles cp JOIN user_accounts u ON u.user_id = cp.user_id JOIN user_roles r ON r.id = u.role_id
             WHERE cp.cross_promo_in = 1 AND u.user_id <> :me AND u.deleted = 0 AND u.user_status = 'Active'
               AND r.role_name = 'Creator' AND u.u_name <> '' AND " . Plan::paid_sql('u') . "
               AND " . BlocksModel::exclude_sql('u.user_id', 'b1', 'b2') . $where . "
             ORDER BY followers DESC, u.user_id DESC
             LIMIT " . max(1, min(500, (int) $limit)), $params);
        $out = array();
        foreach ($rows as $r) {
            if (!self::eligible_user($r)) { continue; }
            $out[] = array('user_id' => (int) $r['user_id'], 'u_name' => (string) $r['u_name'], 'display_name' => (string) $r['display_name'],
                           'avatar_url' => (string) $r['avatar_url'], 'category' => (string) ($r['directory_category'] ?? ''),
                           'followers' => (int) $r['followers'], 'verified' => (int) ($r['verified'] ?? 0));
        }
        return $out;
    }

    /* ---------- swaps ---------- */

    public function get($id){
        $r = parent::select("SELECT * FROM promo_swaps WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** A pending or running swap between the two (either direction), or null. */
    public function open_between($a, $b){
        $r = parent::select(
            "SELECT * FROM promo_swaps
             WHERE ((requester_id = :a1 AND partner_id = :b1) OR (requester_id = :b2 AND partner_id = :a2))
               AND (status = 'pending' OR (status = 'active' AND ends_at > NOW()))
             ORDER BY id DESC LIMIT 1",
            array('a1' => (int) $a, 'b1' => (int) $b, 'a2' => (int) $a, 'b2' => (int) $b));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /**
     * New pending request, or 0 when the pair already has one open. The unique open_pair key (generated column) is
     * what stops two requests sent at the same moment: the second INSERT fails on it. An active swap past ends_at
     * is closed first so it doesn't hold the key.
     */
    public function create($requester_id, $partner_id, $days, $note){
        $now = date('Y-m-d H:i:s');
        parent::sql(
            "UPDATE promo_swaps SET status = 'ended', updated_at = :t
             WHERE open_pair = :pair AND status = 'active' AND ends_at <= NOW()",
            array(':t' => $now, ':pair' => min((int) $requester_id, (int) $partner_id) . '-' . max((int) $requester_id, (int) $partner_id)));
        if ($this->open_between($requester_id, $partner_id)) { return 0; }
        try {
            return $this->insert_request($requester_id, $partner_id, $days, $note, $now);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { return 0; }   // the other request won
            throw $e;
        }
    }

    private function insert_request($requester_id, $partner_id, $days, $note, $now){
        return (int) parent::insert('promo_swaps', array(
            'requester_id' => (int) $requester_id,
            'partner_id'   => (int) $partner_id,
            'days'         => in_array((int) $days, self::DAYS, true) ? (int) $days : 7,
            'note'         => mb_substr((string) $note, 0, 500),
            'status'       => 'pending',
            'created_at'   => $now,
            'updated_at'   => $now,
        ));
    }

    /** Pending -> active for its length from now. Only the partner accepts (the caller checks). */
    /** True only when this call moved it (a double click or a withdraw in between changes nothing). */
    public function accept($id, $days){
        $now = date('Y-m-d H:i:s');
        return parent::update('promo_swaps', array('status' => 'active', 'accepted_at' => $now, 'ends_at' => date('Y-m-d H:i:s', time() + (int) $days * 86400), 'updated_at' => $now),
            "id = :id AND status = 'pending'", array('id' => (int) $id)) > 0;
    }

    public function decline($id){
        return parent::update('promo_swaps', array('status' => 'declined', 'updated_at' => date('Y-m-d H:i:s')), "id = :id AND status = 'pending'", array('id' => (int) $id)) > 0;
    }

    /** Either side ends a running swap now (or the requester withdraws a pending one). */
    public function end_swap($id, $by){
        $now = date('Y-m-d H:i:s');
        return parent::update('promo_swaps', array('status' => 'ended', 'ended_by' => (int) $by, 'ends_at' => $now, 'updated_at' => $now),
            "id = :id AND (status = 'pending' OR (status = 'active' AND ends_at > NOW()))", array('id' => (int) $id)) > 0;
    }

    /** Pending and running swaps of $uid (either side), for opting out. */
    public function open_for($uid){
        return (array) parent::select(
            "SELECT * FROM promo_swaps WHERE (requester_id = :u1 OR partner_id = :u2) AND (status = 'pending' OR (status = 'active' AND ends_at > NOW()))",
            array('u1' => (int) $uid, 'u2' => (int) $uid));
    }

    /**
     * Every swap of $uid with the other creator's identity and both click counts, newest first:
     * 'state' is pending | active | declined | ended (an active swap past ends_at reads as ended).
     */
    public function for_creator($uid, $limit = 100){
        $rows = (array) parent::select(
            "SELECT s.*, IF(s.requester_id = :u1, s.partner_id, s.requester_id) AS other_id,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')))) AS other_name, cp.avatar_url AS other_avatar, u.u_name AS other_handle,
                    (SELECT COUNT(*) FROM promo_swap_clicks c WHERE c.swap_id = s.id AND c.to_creator_id = :u2) AS clicks_in,
                    (SELECT COUNT(*) FROM promo_swap_clicks c WHERE c.swap_id = s.id AND c.to_creator_id <> :u3) AS clicks_out
             FROM promo_swaps s
             JOIN user_accounts u ON u.user_id = IF(s.requester_id = :u4, s.partner_id, s.requester_id)
             LEFT JOIN creator_profiles cp ON cp.user_id = u.user_id
             WHERE (s.requester_id = :u5 OR s.partner_id = :u6) AND u.deleted = 0
             ORDER BY s.id DESC LIMIT " . max(1, min(500, (int) $limit)),
            array('u1' => (int) $uid, 'u2' => (int) $uid, 'u3' => (int) $uid, 'u4' => (int) $uid, 'u5' => (int) $uid, 'u6' => (int) $uid));
        $now = time();
        foreach ($rows as &$r) {
            $r['state'] = (string) $r['status'];
            if ($r['state'] === 'active' && strtotime((string) $r['ends_at'] . ' UTC') <= $now) { $r['state'] = 'ended'; }
            $r['incoming'] = (int) $r['partner_id'] === (int) $uid;
        }
        unset($r);
        return $rows;
    }

    /**
     * The creators $uid features right now: the other side of each active swap, with a public page.
     * Each row: swap_id, user_id, u_name, display_name, avatar_url, verified, paid_from_cents / paid_from_interval (cheapest
     * paid membership, null = none) and free_plans.
     */
    public function featured_for($uid){
        return (array) parent::select(
            "SELECT s.id AS swap_id, u.*, COALESCE(NULLIF(TRIM(cp.display_name), ''), TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')))) AS display_name, cp.avatar_url, cp.avatar_webp_url,
                    (SELECT MIN(p.price_cents) FROM creator_plans p WHERE p.user_id = u.user_id AND p.is_active = 1 AND p.price_cents > 0) AS paid_from_cents,
                    (SELECT p.billing_interval FROM creator_plans p WHERE p.user_id = u.user_id AND p.is_active = 1 AND p.price_cents > 0 ORDER BY p.price_cents ASC LIMIT 1) AS paid_from_interval,
                    (SELECT COUNT(*) FROM creator_plans p WHERE p.user_id = u.user_id AND p.is_active = 1 AND p.price_cents = 0) AS free_plans
             FROM promo_swaps s
             JOIN user_accounts u ON u.user_id = IF(s.requester_id = :u1, s.partner_id, s.requester_id)
             JOIN creator_profiles cp ON cp.user_id = u.user_id AND cp.cross_promo_in = 1
             JOIN creator_profiles mine ON mine.user_id = :u4 AND mine.cross_promo_in = 1
             WHERE (s.requester_id = :u2 OR s.partner_id = :u3) AND s.status = 'active' AND s.ends_at > NOW()
               AND u.deleted = 0 AND u.user_status = 'Active' AND u.u_name <> '' AND " . Plan::paid_sql('u') . "
             ORDER BY s.accepted_at DESC LIMIT 12",
            array('u1' => (int) $uid, 'u2' => (int) $uid, 'u3' => (int) $uid, 'u4' => (int) $uid));
    }

    /** Is $to the other side of this swap from $from, and is the swap running? */
    public function active_pair($swap_id, $to_id){
        $r = parent::select(
            "SELECT * FROM promo_swaps WHERE id = :id AND status = 'active' AND ends_at > NOW() AND (requester_id = :t1 OR partner_id = :t2)",
            array('id' => (int) $swap_id, 't1' => (int) $to_id, 't2' => (int) $to_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Count a click unless this viewer already clicked through to $to on this swap in the last hour. */
    public function record_click($swap_id, $from_id, $to_id, $viewer_hash){
        $seen = parent::select(
            "SELECT 1 FROM promo_swap_clicks WHERE swap_id = :s AND to_creator_id = :t AND viewer_hash = :v AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 1",
            array('s' => (int) $swap_id, 't' => (int) $to_id, 'v' => (string) $viewer_hash));
        if (is_array($seen) && count($seen) > 0) { return 0; }
        return (int) parent::insert('promo_swap_clicks', array(
            'swap_id' => (int) $swap_id, 'from_creator_id' => (int) $from_id, 'to_creator_id' => (int) $to_id,
            'viewer_hash' => (string) $viewer_hash, 'created_at' => date('Y-m-d H:i:s'),
        ));
    }
}
