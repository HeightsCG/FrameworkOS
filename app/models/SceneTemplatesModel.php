<?php
/**
 * Scene templates: an admin-managed library of base prompts creators run with one of their
 * influencers (Studio, Scenes). Adult templates are only listed for viewers who opted in to
 * adult content. Votes record a creator's thumbs up / down on a variant a template produced.
 */
class SceneTemplatesModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** $f: title, category, base_prompt, is_adult, default_aspect, is_active, sort_order. Returns the new id. */
    public function add(array $f, $created_by = 0){
        $now = date('Y-m-d H:i:s');
        return (int) parent::insert('scene_templates', array_merge(self::clean($f), array(
            'created_by' => ((int) $created_by > 0) ? (int) $created_by : null, 'created_at' => $now, 'updated_at' => $now)));
    }

    public function update_one($id, array $f){
        $data = self::clean($f);
        $data['updated_at'] = date('Y-m-d H:i:s');
        return parent::update('scene_templates', $data, 'id = :id AND deleted_at IS NULL', array('id' => (int) $id));
    }

    private static function clean(array $f){
        return array(
            'title'          => mb_substr(trim((string) ($f['title'] ?? '')), 0, 120),
            'category'       => mb_substr(trim((string) ($f['category'] ?? '')), 0, 60),
            'base_prompt'    => mb_substr(trim((string) ($f['base_prompt'] ?? '')), 0, 4000),
            'is_adult'       => !empty($f['is_adult']) ? 1 : 0,
            'default_aspect' => Aspect::normalize($f['default_aspect'] ?? '', Aspect::DEFAULT_IMAGE),
            'is_active'      => !empty($f['is_active']) ? 1 : 0,
            'sort_order'     => (int) ($f['sort_order'] ?? 0),
        );
    }

    public function set_thumb($id, $key){
        return parent::update('scene_templates', array('thumb_key' => ((string) $key !== '') ? (string) $key : null, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id', array('id' => (int) $id));
    }

    public function set_active($id, $active){
        return parent::update('scene_templates', array('is_active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND deleted_at IS NULL', array('id' => (int) $id));
    }

    public function soft_delete($id){
        return parent::update('scene_templates', array('deleted_at' => date('Y-m-d H:i:s')), 'id = :id AND deleted_at IS NULL', array('id' => (int) $id));
    }

    public function get_one($id){
        $r = parent::select("SELECT * FROM scene_templates WHERE id = :id AND deleted_at IS NULL", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Every template with its vote totals, for /admin. */
    public function list_all(){
        return (array) parent::select(
            "SELECT t.*, COALESCE(SUM(v.vote = 1), 0) AS ups, COALESCE(SUM(v.vote = -1), 0) AS downs
             FROM scene_templates t LEFT JOIN scene_template_votes v ON v.template_id = t.id
             WHERE t.deleted_at IS NULL GROUP BY t.id ORDER BY t.category ASC, t.sort_order ASC, t.id ASC");
    }

    /** What a creator can pick: active templates; adult ones only when $show_adult. */
    public function list_active($show_adult){
        $adult = $show_adult ? '' : ' AND is_adult = 0';
        return (array) parent::select(
            "SELECT * FROM scene_templates WHERE deleted_at IS NULL AND is_active = 1 $adult ORDER BY category ASC, sort_order ASC, id ASC");
    }

    /* ---- votes ---- */

    /** Record (or change) a creator's vote on a variant; $vote 1, -1, or 0 to clear. */
    public function vote($creator_id, $template_id, $asset_id, $job_id, $vote){
        $vote = ((int) $vote > 0) ? 1 : (((int) $vote < 0) ? -1 : 0);
        if ($vote === 0) {
            return parent::delete_all('scene_template_votes', 'creator_id = :c AND asset_id = :a', array('c' => (int) $creator_id, 'a' => (int) $asset_id));
        }
        $now = date('Y-m-d H:i:s');
        return parent::sql(
            "INSERT INTO scene_template_votes (creator_id, template_id, asset_id, job_id, vote, created_at, updated_at)
             VALUES (:c, :t, :a, :j, :v, :n1, :n2)
             ON DUPLICATE KEY UPDATE vote = VALUES(vote), template_id = VALUES(template_id), updated_at = VALUES(updated_at)",
            array(':c' => (int) $creator_id, ':t' => (int) $template_id, ':a' => (int) $asset_id, ':j' => ((int) $job_id > 0) ? (int) $job_id : null,
                  ':v' => $vote, ':n1' => $now, ':n2' => $now));
    }

    /** A creator's votes for a list of assets: asset_id => 1 | -1. */
    public function votes_for($creator_id, array $asset_ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $asset_ids))));
        if (empty($ids)) { return array(); }
        $rows = (array) parent::select("SELECT asset_id, vote FROM scene_template_votes WHERE creator_id = :c AND asset_id IN (" . implode(',', $ids) . ")",
            array('c' => (int) $creator_id));
        $out = array();
        foreach ($rows as $r) { $out[(int) $r['asset_id']] = (int) $r['vote']; }
        return $out;
    }
}
