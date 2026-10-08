<?php
/**
 * Inbound tracking links (/go/<code>) and what came of them. A click sets the cls_tl cookie; follows, signups,
 * memberships and purchases made with it are logged as tracking_link_events (TrackingLinks::attribute).
 * Revenue is the sum of amount_credits. Codes starting with "cls" are system links (see TrackingLinks).
 */
class TrackingLinksModel extends Model {

    const KINDS = array('click', 'follow', 'signup', 'subscription', 'ppv', 'purchase');

    /** A live link by its code (any owner), or null. */
    public function get_by_code($code){
        $rows = parent::select("SELECT * FROM tracking_links WHERE code = :code AND deleted = 0", array('code' => strtolower((string) $code)));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Is this code taken (deleted links keep theirs, so an old URL never lands on someone else)? */
    public function code_exists($code){
        $rows = parent::select("SELECT 1 FROM tracking_links WHERE code = :code", array('code' => strtolower((string) $code)));
        return is_array($rows) && count($rows) > 0;
    }

    public function add($creator_id, $code, $label, $target_path){
        return (int) parent::insert('tracking_links', array(
            'creator_id'  => (int) $creator_id,
            'code'        => strtolower((string) $code),
            'label'       => mb_substr((string) $label, 0, 120),
            'target_path' => (string) $target_path,
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'deleted'     => 0,
        ));
    }

    /** The creator's system link for a code like clssim42, made on first use. */
    public function system_link($creator_id, $code, $label){
        $row = $this->get_by_code($code);
        if ($row) { return $row; }
        try { $this->add($creator_id, $code, $label, ''); } catch (\Throwable $e) { /* made by a concurrent click */ }
        return $this->get_by_code($code);
    }

    public function delete_link($creator_id, $id){
        return parent::update('tracking_links', array('deleted' => 1), "id = :id AND creator_id = :c AND code NOT LIKE 'cls%'", array('id' => (int) $id, 'c' => (int) $creator_id));   // system links stay
    }

    public function record_event($link_id, $creator_id, $kind, $user_id, $amount, $ref_table, $ref_id){
        if (!in_array($kind, self::KINDS, true)) { return 0; }
        return (int) parent::insert('tracking_link_events', array(
            'link_id'        => (int) $link_id,
            'creator_id'     => (int) $creator_id,
            'kind'           => $kind,
            'user_id'        => (int) $user_id > 0 ? (int) $user_id : null,
            'amount_credits' => (int) $amount,
            'ref_table'      => $ref_table !== null && (string) $ref_table !== '' ? mb_substr((string) $ref_table, 0, 40) : null,
            'ref_id'         => (int) $ref_id > 0 ? (int) $ref_id : null,
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ));
    }

    /** Already logged (a reloaded success page, a follow after an unfollow)? */
    public function has_event($link_id, $kind, $user_id, $ref_table, $ref_id){
        $rows = parent::select(
            "SELECT 1 FROM tracking_link_events
             WHERE link_id = :l AND kind = :k AND user_id <=> :u AND ref_table <=> :t AND ref_id <=> :r LIMIT 1",
            array('l' => (int) $link_id, 'k' => (string) $kind, 'u' => (int) $user_id > 0 ? (int) $user_id : null,
                  't' => $ref_table !== null && (string) $ref_table !== '' ? (string) $ref_table : null, 'r' => (int) $ref_id > 0 ? (int) $ref_id : null));
        return is_array($rows) && count($rows) > 0;
    }

    /** The creator's links with counts per kind and revenue since $start_utc (all time when ''). */
    public function stats($creator_id, $start_utc = ''){
        $since = $start_utc !== '' ? ' AND e.created_at >= :s' : '';
        $params = array('c' => (int) $creator_id);
        if ($start_utc !== '') { $params['s'] = $start_utc; }
        return (array) parent::select(
            "SELECT l.id, l.code, l.label, l.target_path, l.created_at,
                    COALESCE(SUM(e.kind = 'click'), 0) AS clicks,
                    COALESCE(SUM(e.kind = 'follow'), 0) AS follows,
                    COALESCE(SUM(e.kind = 'signup'), 0) AS signups,
                    COALESCE(SUM(e.kind = 'subscription'), 0) AS subscriptions,
                    COALESCE(SUM(e.kind IN ('ppv', 'purchase')), 0) AS purchases,
                    COALESCE(SUM(e.amount_credits), 0) AS revenue
             FROM tracking_links l
             LEFT JOIN tracking_link_events e ON e.link_id = l.id" . $since . "
             WHERE l.creator_id = :c AND l.deleted = 0
             GROUP BY l.id, l.code, l.label, l.target_path, l.created_at
             ORDER BY (l.code LIKE 'cls%') ASC, l.created_at DESC, l.id DESC", $params);
    }
}
