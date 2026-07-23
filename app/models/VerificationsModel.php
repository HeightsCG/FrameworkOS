<?php
/**
 * Creator verification (PRD §33). A creator requests verification; an admin reviews and
 * approves/rejects. Approval sets user_accounts.verified = 1, which drives the verified
 * badge in search and on the profile. Lightweight (no KYC/document upload yet — the
 * request carries a legal name + note the admin reviews manually).
 */
class VerificationsModel extends Model {

    /** File (or return the existing open) verification request. Returns the request id. */
    public function request($user_id, $full_name, $note){
        $ex = parent::select("SELECT id FROM verifications WHERE user_id = :u AND status = 'pending' LIMIT 1", array('u' => (int) $user_id));
        if (is_array($ex) && count($ex)) { return (int) $ex[0]['id']; }
        return (int) parent::insert('verifications', array(
            'user_id'    => (int) $user_id,
            'full_name'  => mb_substr((string) $full_name, 0, 190),
            'note'       => mb_substr((string) $note, 0, 2000),
            'status'     => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    /** Latest request status for a user: 'pending' | 'approved' | 'rejected' | ''. */
    public function status_for($user_id){
        $r = parent::select("SELECT status FROM verifications WHERE user_id = :u ORDER BY id DESC LIMIT 1", array('u' => (int) $user_id));
        return (is_array($r) && count($r)) ? (string) $r[0]['status'] : '';
    }

    public function pending_for_admin($limit = 40){
        $limit = max(1, min(100, (int) $limit));
        return (array) parent::select(
            "SELECT v.*, u.u_name,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '')) AS name
             FROM verifications v
             JOIN user_accounts u ON u.user_id = v.user_id
             LEFT JOIN creator_profiles cp ON cp.user_id = v.user_id
             WHERE v.status = 'pending'
             ORDER BY v.created_at ASC
             LIMIT $limit");
    }

    public function pending_count(){
        $r = parent::select("SELECT COUNT(*) AS n FROM verifications WHERE status = 'pending'");
        return is_array($r) && count($r) ? (int) $r[0]['n'] : 0;
    }

    public function get($id){
        $r = parent::select("SELECT * FROM verifications WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }

    /** Approve (sets verified=1) or reject a request. */
    public function resolve($id, $admin_id, $approve, $note = ''){
        $v = $this->get($id);
        if (!$v) { return false; }
        parent::update('verifications',
            array('status' => $approve ? 'approved' : 'rejected',
                  'reviewed_by' => (int) $admin_id, 'review_note' => mb_substr((string) $note, 0, 255),
                  'reviewed_at' => date('Y-m-d H:i:s')),
            'id = :id', array('id' => (int) $id));
        parent::update('user_accounts', array('verified' => $approve ? 1 : 0),
            'user_id = :u', array('u' => (int) $v['user_id']));
        return true;
    }
}
