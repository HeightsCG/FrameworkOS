<?php
/**
 * Creator brand identity — generated from a website URL, reviewed and saved by
 * the creator. One row per creator. colors/keywords are stored as JSON strings
 * and returned as arrays.
 */
class CreatorBrandModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** The creator's saved brand, or a defaults array if none exists yet. */
    public function get_for_user($user_id){
        $rows = parent::select(
            "SELECT * FROM creator_brand WHERE user_id = :u",
            array('u' => (int) $user_id)
        );
        if (is_array($rows) && count($rows) === 1) {
            $r = $rows[0];
            $r['colors']   = self::to_array($r['colors']);
            $r['keywords'] = self::to_array($r['keywords']);
            return $r;
        }
        return array(
            'source_url'  => '',
            'brand_name'  => '',
            'tagline'     => '',
            'description' => '',
            'voice'       => '',
            'colors'      => array(),
            'keywords'    => array(),
        );
    }

    private static function to_array($json){
        $a = json_decode((string) $json, true);
        return is_array($a) ? $a : array();
    }

    /** Upsert the brand fields for a creator. */
    public function save($user_id, $fields){
        $now  = date('Y-m-d H:i:s');
        $data = array(
            'source_url'  => mb_substr((string) ($fields['source_url'] ?? ''), 0, 512),
            'brand_name'  => mb_substr((string) ($fields['brand_name'] ?? ''), 0, 190),
            'tagline'     => mb_substr((string) ($fields['tagline'] ?? ''), 0, 255),
            'description' => (string) ($fields['description'] ?? ''),
            'voice'       => (string) ($fields['voice'] ?? ''),
            'colors'      => json_encode(array_values((array) ($fields['colors'] ?? array()))),
            'keywords'    => json_encode(array_values((array) ($fields['keywords'] ?? array()))),
            'updated_at'  => $now,
        );
        $exists = parent::select("SELECT id FROM creator_brand WHERE user_id = :u", array('u' => (int) $user_id));
        if (is_array($exists) && count($exists) === 1) {
            return parent::update('creator_brand', $data, 'user_id = :u', array('u' => (int) $user_id));
        }
        $data['user_id']    = (int) $user_id;
        $data['created_at'] = $now;
        return parent::insert('creator_brand', $data);
    }
}
