<?php
/**
 * Media attached to an influencer with a role (upload | face | reference | training |
 * generated | video | enhanced). Generated rows carry the job + output index that produced
 * them; the (job_id, result_index) unique key makes a repeated landing a no-op.
 */
class InfluencerImagesModel extends Model {

    const ROLES = array('upload', 'face', 'reference', 'training', 'generated', 'video', 'enhanced');

    public function __construct(){ parent::__construct(); }

    /** Returns the new id; 0 when this asset or job slot is already attached (idempotent). */
    public function attach($influencer_id, $creator_id, $asset_id, $role, $job_id = null, $result_index = 0, $sort_order = 0){
        try {
            return (int) parent::insert('influencer_images', array(
                'influencer_id' => (int) $influencer_id,
                'creator_id'    => (int) $creator_id,
                'asset_id'      => (int) $asset_id,
                'role'          => in_array($role, self::ROLES, true) ? $role : 'generated',
                'job_id'        => $job_id ? (int) $job_id : null,
                'result_index'  => (int) $result_index,
                'sort_order'    => (int) $sort_order,
                'is_excluded'   => 0,
                'created_at'    => date('Y-m-d H:i:s'),
            ));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { return 0; }
            throw $e;
        }
    }

    /** Rows joined to their ready media assets for one role (or all roles when $role = ''). */
    public function list_for_influencer($creator_id, $influencer_id, $role = '', $include_excluded = false){
        $params = array('c' => (int) $creator_id, 'i' => (int) $influencer_id);
        $where  = 'ii.creator_id = :c AND ii.influencer_id = :i AND a.deleted_at IS NULL';
        if ($role !== '') { $where .= ' AND ii.role = :r'; $params['r'] = (string) $role; }
        if (!$include_excluded) { $where .= ' AND ii.is_excluded = 0'; }
        return (array) parent::select(
            "SELECT ii.id AS link_id, ii.role, ii.job_id, ii.result_index, ii.sort_order, ii.is_excluded, a.*
             FROM influencer_images ii JOIN media_assets a ON a.id = ii.asset_id
             WHERE $where ORDER BY ii.sort_order ASC, ii.id ASC",
            $params);
    }

    /** Asset ids in a role that are ready (what training can actually use). */
    public function ready_asset_ids($creator_id, $influencer_id, $role){
        $rows = parent::select(
            "SELECT a.id FROM influencer_images ii JOIN media_assets a ON a.id = ii.asset_id
             WHERE ii.creator_id = :c AND ii.influencer_id = :i AND ii.role = :r AND ii.is_excluded = 0
               AND a.status = 'ready' AND a.deleted_at IS NULL
             ORDER BY ii.sort_order ASC, ii.id ASC",
            array('c' => (int) $creator_id, 'i' => (int) $influencer_id, 'r' => (string) $role));
        $ids = array();
        foreach ((array) $rows as $r) { $ids[] = (int) $r['id']; }
        return $ids;
    }

    public function count_role($creator_id, $influencer_id, $role){
        $r = parent::select(
            "SELECT COUNT(*) AS n FROM influencer_images ii JOIN media_assets a ON a.id = ii.asset_id
             WHERE ii.creator_id = :c AND ii.influencer_id = :i AND ii.role = :r AND ii.is_excluded = 0 AND a.deleted_at IS NULL",
            array('c' => (int) $creator_id, 'i' => (int) $influencer_id, 'r' => (string) $role));
        return (is_array($r) && count($r)) ? (int) $r[0]['n'] : 0;
    }

    public function get_link($creator_id, $influencer_id, $asset_id){
        $r = parent::select("SELECT * FROM influencer_images WHERE creator_id = :c AND influencer_id = :i AND asset_id = :a",
            array('c' => (int) $creator_id, 'i' => (int) $influencer_id, 'a' => (int) $asset_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function detach($creator_id, $influencer_id, $asset_id){
        return parent::delete_all('influencer_images', 'creator_id = :c AND influencer_id = :i AND asset_id = :a',
            array('c' => (int) $creator_id, 'i' => (int) $influencer_id, 'a' => (int) $asset_id));
    }

    public function set_excluded($creator_id, $influencer_id, $asset_id, $excluded){
        return parent::update('influencer_images', array('is_excluded' => $excluded ? 1 : 0),
            'creator_id = :c AND influencer_id = :i AND asset_id = :a',
            array('c' => (int) $creator_id, 'i' => (int) $influencer_id, 'a' => (int) $asset_id));
    }

    /** Influencer an asset belongs to (for the media library filter/labels), or null. */
    public function influencer_for_asset($creator_id, $asset_id){
        $r = parent::select("SELECT influencer_id, role FROM influencer_images WHERE creator_id = :c AND asset_id = :a",
            array('c' => (int) $creator_id, 'a' => (int) $asset_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }
}
