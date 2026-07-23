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
        return array(
            'users'           => (int) $this->scalar("SELECT COUNT(*) AS n FROM user_accounts WHERE deleted = 0"),
            'creators'        => (int) $this->scalar("SELECT COUNT(*) AS n FROM user_accounts WHERE deleted = 0 AND role_id = :r", array('r' => $crole)),
            'active_subs'     => (int) $subs['n'],
            'mrr_cents'       => (int) $subs['mrr'],
            'revenue_credits' => (int) $this->scalar(
                "SELECT (SELECT COALESCE(SUM(price_credits),0) FROM ppv_unlocks)
                      + (SELECT COALESCE(SUM(price_credits),0) FROM bundle_unlocks) AS n"),
            'mod_pending'     => (int) $mod['pend'],
            'mod_flagged'     => (int) $mod['flag'],
            'mod_blocked'     => (int) $mod['blocked'],
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
