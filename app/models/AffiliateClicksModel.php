<?php
/** Visits through an affiliate link (affiliate_clicks): one row per viewer per hour (Affiliates::capture). */
class AffiliateClicksModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** Record a visit unless this viewer already has one for this affiliate in the last hour. Returns true when written. */
    public function record($affiliate_id, $viewer_hash, $landing): bool {
        $r = parent::select("SELECT id FROM affiliate_clicks WHERE affiliate_id = :a AND viewer_hash = :h AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR LIMIT 1",
            array('a' => (int) $affiliate_id, 'h' => (string) $viewer_hash));
        if (is_array($r) && count($r)) { return false; }
        parent::insert('affiliate_clicks', array('affiliate_id' => (int) $affiliate_id, 'viewer_hash' => (string) $viewer_hash,
            'landing' => mb_substr((string) $landing, 0, 200), 'created_at' => gmdate('Y-m-d H:i:s')));
        return true;
    }

    public function count_for($affiliate_id): int {
        $r = parent::select("SELECT COUNT(*) AS n FROM affiliate_clicks WHERE affiliate_id = :a", array('a' => (int) $affiliate_id));
        return (int) ($r[0]['n'] ?? 0);
    }
}
