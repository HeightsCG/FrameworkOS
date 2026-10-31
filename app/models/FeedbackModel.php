<?php
/**
 * Feedback sent from the "Feedback" link in the app top bar (sql/2026-10-27_feedback.sql). One row per note, with the
 * page it was sent from. Staff read them on /admin/feedback. Status: new | reviewed. Times are UTC.
 */
class FeedbackModel extends Model {

    /** Kinds offered in the modal (key => label). */
    const KINDS = array(
        'idea'    => 'Idea',
        'problem' => 'Problem',
        'praise'  => 'Praise',
        'other'   => 'Other',
    );

    public function __construct(){ parent::__construct(); }

    public function create(array $row){
        return (int) parent::insert('feedback', array(
            'user_id'    => (int) $row['user_id'],
            'kind'       => (string) $row['kind'],
            'message'    => (string) $row['message'],
            'page_url'   => (string) $row['page_url'],
            'page_title' => (string) $row['page_title'],
            'user_agent' => (string) $row['user_agent'],
            'viewport'   => (string) $row['viewport'],
            'status'     => 'new',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    public function get($id){
        $rows = parent::select("SELECT * FROM feedback WHERE id = :id", array('id' => (int) $id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function set_status($id, $status, $staff_id){
        $reviewed = $status === 'reviewed';
        parent::update('feedback',
            array('status' => $reviewed ? 'reviewed' : 'new', 'reviewed_by' => $reviewed ? (int) $staff_id : null, 'reviewed_at' => $reviewed ? gmdate('Y-m-d H:i:s') : null),
            'id = :id', array('id' => (int) $id));
    }

    /** Everything for the staff page, newest first, with the sender's name and handle. */
    public function for_staff($limit = 500){
        $limit = max(1, min(1000, (int) $limit));
        return (array) parent::select(
            "SELECT f.*, u.u_name, u.first_name, u.last_name, cp.avatar_url
             FROM feedback f JOIN user_accounts u ON u.user_id = f.user_id
             LEFT JOIN creator_profiles cp ON cp.user_id = f.user_id
             ORDER BY f.created_at DESC, f.id DESC LIMIT $limit");
    }

    public function count_new(){
        $rows = parent::select("SELECT COUNT(*) AS n FROM feedback WHERE status = 'new'");
        return isset($rows[0]['n']) ? (int) $rows[0]['n'] : 0;
    }

    /** How many notes a user sent in the last hour (to stop floods). */
    public function recent_count($user_id){
        $rows = parent::select("SELECT COUNT(*) AS n FROM feedback WHERE user_id = :u AND created_at >= :t", array('u' => (int) $user_id, 't' => gmdate('Y-m-d H:i:s', time() - 3600)));
        return isset($rows[0]['n']) ? (int) $rows[0]['n'] : 0;
    }
}
