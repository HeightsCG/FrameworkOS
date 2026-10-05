<?php
/** Launch campaigns and the one-off scheduled broadcasts they create (launch_campaigns, scheduled_broadcasts). */
class LaunchCampaignsModel extends Model {

    public function __construct(){ parent::__construct(); }

    public function create($creator_id, array $f){
        return (int) parent::insert('launch_campaigns', array(
            'creator_id'        => (int) $creator_id,
            'influencer_id'     => ((int) ($f['influencer_id'] ?? 0) > 0) ? (int) $f['influencer_id'] : null,
            'destination'       => (($f['destination'] ?? 'cls') === 'fanvue') ? 'fanvue' : 'cls',
            'launch_at'         => (string) $f['launch_at'],
            'anticipation_days' => max(0, min(14, (int) ($f['anticipation_days'] ?? 0))),
            'promo_code_id'     => ((int) ($f['promo_code_id'] ?? 0) > 0) ? (int) $f['promo_code_id'] : null,
            'created_at'        => date('Y-m-d H:i:s'),
        ));
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM launch_campaigns WHERE id = :id AND creator_id = :c", array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Queue one message to send at $send_at (UTC). */
    public function add_broadcast($creator_id, $campaign_id, array $segments, $body, $send_at){
        return (int) parent::insert('scheduled_broadcasts', array(
            'creator_id'  => (int) $creator_id,
            'campaign_id' => ((int) $campaign_id > 0) ? (int) $campaign_id : null,
            'segments'    => implode(',', BroadcastsModel::clean_segments($segments) ?: array('followers')),
            'body'        => mb_substr(trim((string) $body), 0, 2000),
            'send_at'     => (string) $send_at,
            'status'      => 'scheduled',
            'created_at'  => date('Y-m-d H:i:s'),
        ));
    }

    public function broadcasts_for($creator_id, $campaign_id){
        return parent::select("SELECT * FROM scheduled_broadcasts WHERE creator_id = :c AND campaign_id = :k ORDER BY send_at ASC",
            array('c' => (int) $creator_id, 'k' => (int) $campaign_id));
    }

    /** Scheduled messages whose time has come, oldest first. */
    public function due_broadcasts($limit = 50){
        return parent::select("SELECT * FROM scheduled_broadcasts WHERE status = 'scheduled' AND send_at <= :n ORDER BY send_at ASC LIMIT " . max(1, (int) $limit),
            array('n' => date('Y-m-d H:i:s')));
    }

    /** Take one message for sending. False when another worker already has it. */
    public function claim_broadcast($id){
        return (int) parent::update('scheduled_broadcasts', array('status' => 'sending'), "id = :id AND status = 'scheduled'", array('id' => (int) $id)) > 0;
    }

    public function finish_broadcast($id, $broadcast_id, $recipients){
        return parent::update('scheduled_broadcasts', array('status' => 'sent', 'broadcast_id' => ((int) $broadcast_id > 0) ? (int) $broadcast_id : null,
            'recipients' => (int) $recipients, 'sent_at' => date('Y-m-d H:i:s'), 'error' => null), 'id = :id', array('id' => (int) $id));
    }

    public function fail_broadcast($id, $error){
        return parent::update('scheduled_broadcasts', array('status' => 'failed', 'error' => mb_substr((string) $error, 0, 300)), 'id = :id', array('id' => (int) $id));
    }

    /** Stop the unsent messages of a campaign. */
    public function cancel_broadcasts($creator_id, $campaign_id){
        return parent::update('scheduled_broadcasts', array('status' => 'cancelled'), "creator_id = :c AND campaign_id = :k AND status = 'scheduled'",
            array('c' => (int) $creator_id, 'k' => (int) $campaign_id));
    }
}
