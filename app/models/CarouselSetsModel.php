<?php
/**
 * Carousel sets: one row per carousel a creator generates. The slots are influencer_jobs rows of
 * type 'carousel' that share the set's group_key (current slot = the one not superseded).
 */
class CarouselSetsModel extends Model {

    const FOCUS = array('angles', 'expressions', 'poses', 'details', 'without_her');

    public function __construct(){ parent::__construct(); }

    public function create($creator_id, $influencer_id, array $f){
        return (int) parent::insert('carousel_sets', array(
            'creator_id'    => (int) $creator_id,
            'influencer_id' => (int) $influencer_id,
            'group_key'     => (string) $f['group_key'],
            'seed_asset_id' => !empty($f['seed_asset_id']) ? (int) $f['seed_asset_id'] : null,
            'seed_text'     => ((string) ($f['seed_text'] ?? '') !== '') ? (string) $f['seed_text'] : null,
            'focus'         => in_array($f['focus'] ?? '', self::FOCUS, true) ? $f['focus'] : 'angles',
            'aspect'        => (string) ($f['aspect'] ?? '3:4'),
            'model_key'     => (string) ($f['model_key'] ?? ''),
            'slot_count'    => (int) ($f['slot_count'] ?? 0),
            'constants'     => ((string) ($f['constants'] ?? '') !== '') ? (string) $f['constants'] : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ));
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM carousel_sets WHERE id = :id AND creator_id = :c", array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function get_by_group($creator_id, $group_key){
        $r = parent::select("SELECT * FROM carousel_sets WHERE group_key = :g AND creator_id = :c", array('g' => (string) $group_key, 'c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Newest sets for one influencer. */
    public function list_for_influencer($creator_id, $influencer_id, $limit = 10){
        $limit = max(1, min(50, (int) $limit));
        return (array) parent::select("SELECT * FROM carousel_sets WHERE creator_id = :c AND influencer_id = :i ORDER BY id DESC LIMIT $limit",
            array('c' => (int) $creator_id, 'i' => (int) $influencer_id));
    }
}
