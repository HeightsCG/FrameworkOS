<?php
/**
 * Platform admin dashboard (PRD §38). Read models + moderation/user actions for staff
 * (user_accounts.is_admin). Everything here is already behind an is_admin gate in the
 * controller; the model does not re-check auth.
 */
class AdminModel extends Model {

    public function __construct(){ parent::__construct(); }

    private function creator_role_id(){
        $rr = parent::select("SELECT id FROM user_roles WHERE role_name = 'Creator'");
        return (is_array($rr) && count($rr)) ? (int) $rr[0]['id'] : 0;
    }
    private function scalar($sql, $params = array(), $col = 'n'){
        $r = parent::select($sql, $params);
        return (is_array($r) && count($r)) ? $r[0][$col] : null;
    }

    /** Platform KPIs for the overview strip. */
    public function overview(){
        $crole = $this->creator_role_id();
        $subs  = parent::select("SELECT COUNT(*) AS n, COALESCE(SUM(price_cents),0) AS mrr FROM creator_subscriptions WHERE status = 'active'");
        $subs  = (is_array($subs) && count($subs)) ? $subs[0] : array('n' => 0, 'mrr' => 0);
        $mod   = parent::select(
            "SELECT COALESCE(SUM(moderation_status='pending'),0) AS pend,
                    COALESCE(SUM(moderation_status='flagged'),0) AS flag,
                    COALESCE(SUM(moderation_status='blocked'),0) AS blocked
             FROM media_assets WHERE deleted_at IS NULL AND type = 'image'");
        $mod   = (is_array($mod) && count($mod)) ? $mod[0] : array('pend' => 0, 'flag' => 0, 'blocked' => 0);

        // Money. Credits are $0.10 each, so gross cents = price_credits * 10.
        // Platform take on PPV is exact (gross − creator net, which is stored per post);
        // for bundles we apply the default platform fee (no per-row net is recorded).
        $fee          = Main::platform_fee_percent();
        $ppv_gross    = (int) $this->scalar("SELECT COALESCE(SUM(price_credits),0)*10 AS n FROM ppv_unlocks");
        $ppv_net      = (int) $this->scalar("SELECT COALESCE(SUM(earnings_cents),0) AS n FROM posts");
        $bundle_gross = (int) $this->scalar("SELECT COALESCE(SUM(price_credits),0)*10 AS n FROM bundle_unlocks");
        $mrr          = (int) $subs['mrr'];
        $platform_cents = max(0, $ppv_gross - $ppv_net) + (int) round($bundle_gross * $fee / 100);

        return array(
            'users'          => (int) $this->scalar("SELECT COUNT(*) AS n FROM user_accounts WHERE deleted = 0"),
            'creators'       => (int) $this->scalar("SELECT COUNT(*) AS n FROM user_accounts WHERE deleted = 0 AND role_id = :r", array('r' => $crole)),
            'active_subs'    => (int) $subs['n'],
            'mrr_cents'      => $mrr,
            'platform_cents' => $platform_cents,                          // all-time platform take on one-time sales
            'gross_cents'    => $ppv_gross + $bundle_gross,               // gross transaction volume (PPV + bundles)
            'sub_fee_cents'  => (int) round($mrr * $fee / 100),           // platform's recurring cut of subscriptions (monthly)
            'mod_pending'    => (int) $mod['pend'],
            'mod_flagged'    => (int) $mod['flag'],
            'mod_blocked'    => (int) $mod['blocked'],
        );
    }

    /** Images awaiting a decision (flagged first, then unscanned), with creator + AI signal. */
    public function moderation_queue($limit = 40){
        $limit = max(1, min(100, (int) $limit));
        return (array) parent::select(
            "SELECT ma.*, u.u_name AS creator_handle,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '')) AS creator_name
             FROM media_assets ma
             JOIN user_accounts u ON u.user_id = ma.creator_id
             LEFT JOIN creator_profiles cp ON cp.user_id = ma.creator_id
             WHERE ma.deleted_at IS NULL AND ma.type = 'image'
               AND ma.moderation_status IN ('pending', 'flagged')
             ORDER BY (ma.moderation_status = 'flagged') DESC, ma.created_at DESC
             LIMIT $limit"
        );
    }

    /** Recent PPV + bundle sales (refunded ones drop off automatically — the unlock row is removed). */
    public function recent_sales($limit = 25){
        $limit = max(1, min(100, (int) $limit));
        $rows = parent::select(
            "SELECT s.* FROM (
                SELECT 'ppv' AS kind, pu.post_id AS ref_id, pu.fan_id, pu.creator_id, pu.price_credits, pu.created_at, p.caption COLLATE utf8mb4_unicode_ci AS item
                FROM ppv_unlocks pu JOIN posts p ON p.id = pu.post_id
                UNION ALL
                SELECT 'bundle' AS kind, bu.bundle_id AS ref_id, bu.fan_id, bu.creator_id, bu.price_credits, bu.created_at, b.name COLLATE utf8mb4_unicode_ci AS item
                FROM bundle_unlocks bu JOIN content_bundles b ON b.id = bu.bundle_id
             ) s ORDER BY s.created_at DESC LIMIT $limit");
        if (empty($rows)) { return array(); }
        $ids = array();
        foreach ($rows as $r) { $ids[] = (int) $r['fan_id']; $ids[] = (int) $r['creator_id']; }
        $idmap = (new MessagesModel())->identity_map($ids);
        $out = array();
        foreach ($rows as $r) {
            $fan = $idmap[(int) $r['fan_id']] ?? array('handle' => '', 'name' => 'Unknown');
            $cre = $idmap[(int) $r['creator_id']] ?? array('handle' => '', 'name' => 'Unknown');
            $out[] = array(
                'kind'           => (string) $r['kind'],
                'ref_id'         => (int) $r['ref_id'],
                'fan_id'         => (int) $r['fan_id'],
                'fan_handle'     => (string) $fan['handle'],
                'fan_name'       => (string) $fan['name'],
                'creator_handle' => (string) $cre['handle'],
                'item'           => html_entity_decode((string) ($r['item'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'credits'        => (int) $r['price_credits'],
                'created_at'     => (string) $r['created_at'],
            );
        }
        return $out;
    }

    public function get_asset($asset_id){
        $r = parent::select("SELECT * FROM media_assets WHERE id = :id", array('id' => (int) $asset_id));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }

    /** Users list with role + status, searchable and status-filterable. */
    public function users($q = '', $status = '', $limit = 60){
        $limit  = max(1, min(200, (int) $limit));
        $where  = array('u.deleted = 0');
        $params = array();
        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\%', '\_'), $q) . '%';
            $where[] = '(u.u_name LIKE :q1 OR u.user_email LIKE :q2 OR TRIM(CONCAT(u.first_name, " ", u.last_name)) LIKE :q3)';
            $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like;
        }
        if ($status === 'Active' || $status === 'Disabled') {
            $where[] = 'u.user_status = :st'; $params['st'] = $status;
        }
        return (array) parent::select(
            "SELECT u.user_id, u.u_name, u.user_email, u.first_name, u.last_name, u.role_id, u.is_admin,
                    u.user_status, u.created_at, u.last_active_at, r.role_name
             FROM user_accounts u LEFT JOIN user_roles r ON r.id = u.role_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY u.created_at DESC
             LIMIT $limit",
            $params
        );
    }

    /** Suspend / reactivate an account. */
    public function set_user_status($user_id, $status){
        if (!in_array($status, array('Active', 'Disabled'), true)) { return false; }
        return parent::update('user_accounts',
            array('user_status' => $status),
            'user_id = :id', array('id' => (int) $user_id));
    }

    /** Set an asset's moderation decision. */
    public function set_moderation($asset_id, $status){
        if (!in_array($status, array('approved', 'blocked', 'flagged', 'pending'), true)) { return false; }
        return parent::update('media_assets',
            array('moderation_status' => $status),
            'id = :id', array('id' => (int) $asset_id));
    }
}
