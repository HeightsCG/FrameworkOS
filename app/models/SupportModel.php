<?php
/**
 * Support / help desk (sql/2026-09-21_support.sql). A ticket belongs to the user who opened it;
 * staff (is_admin) can see and answer every ticket. Status: open (waiting on staff) |
 * answered (staff replied) | closed. Times are UTC.
 */
class SupportModel extends Model {

    /** Topics shown in the new-request form (key => label). */
    const CATEGORIES = array(
        'account'  => 'Account and sign in',
        'billing'  => 'Plans and billing',
        'payments' => 'Credits, purchases and refunds',
        'payouts'  => 'Payouts',
        'content'  => 'Posts and content',
        'social'   => 'Social publishing',
        'safety'   => 'Report a safety issue',
        'other'    => 'Something else',
    );

    public function __construct(){ parent::__construct(); }

    /** Open a ticket with its first message. Returns the new ticket id. */
    public function create($user_id, $category, $subject, $body){
        $now = gmdate('Y-m-d H:i:s');
        $id = (int) parent::insert('support_tickets', array(
            'user_id' => (int) $user_id, 'category' => (string) $category, 'subject' => (string) $subject,
            'status' => 'open', 'last_message_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ));
        if ($id > 0) { $this->add_message($id, $user_id, false, $body); }
        return $id;
    }

    /** Add a message and move the ticket's status: a staff reply marks it answered, a user reply reopens it. */
    public function add_message($ticket_id, $user_id, $is_staff, $body){
        $now = gmdate('Y-m-d H:i:s');
        parent::insert('support_messages', array(
            'ticket_id' => (int) $ticket_id, 'user_id' => (int) $user_id, 'is_staff' => $is_staff ? 1 : 0,
            'body' => (string) $body, 'created_at' => $now,
        ));
        parent::update('support_tickets',
            array('status' => $is_staff ? 'answered' : 'open', 'last_message_at' => $now, 'closed_at' => null, 'updated_at' => $now),
            'id = :id', array('id' => (int) $ticket_id));
    }

    public function set_closed($ticket_id, $closed){
        $now = gmdate('Y-m-d H:i:s');
        parent::update('support_tickets',
            array('status' => $closed ? 'closed' : 'open', 'closed_at' => $closed ? $now : null, 'updated_at' => $now),
            'id = :id', array('id' => (int) $ticket_id));
    }

    /** One ticket with its owner's handle and email, or null. */
    public function get($ticket_id){
        $rows = parent::select(
            "SELECT t.*, u.u_name, u.first_name, u.last_name, u.user_email
             FROM support_tickets t JOIN user_accounts u ON u.user_id = t.user_id
             WHERE t.id = :id", array('id' => (int) $ticket_id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function messages($ticket_id){
        return (array) parent::select(
            "SELECT m.id, m.user_id, m.is_staff, m.body, m.created_at, u.u_name, u.first_name, u.last_name
             FROM support_messages m JOIN user_accounts u ON u.user_id = m.user_id
             WHERE m.ticket_id = :t ORDER BY m.id ASC", array('t' => (int) $ticket_id));
    }

    /** A user's own tickets, newest activity first, with how many messages each has. */
    public function for_user($user_id){
        return (array) parent::select(
            "SELECT t.*, (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS message_count
             FROM support_tickets t WHERE t.user_id = :u ORDER BY t.last_message_at DESC", array('u' => (int) $user_id));
    }

    /** Staff queue. $status: '' for everything, or open|answered|closed. Open ones first. */
    public function for_staff($status = '', $limit = 200){
        $limit = max(1, min(500, (int) $limit));
        $where = ''; $params = array();
        if (in_array($status, array('open', 'answered', 'closed'), true)) { $where = 'WHERE t.status = :s'; $params['s'] = $status; }
        return (array) parent::select(
            "SELECT t.*, u.u_name, u.first_name, u.last_name,
                    (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS message_count
             FROM support_tickets t JOIN user_accounts u ON u.user_id = t.user_id
             $where ORDER BY (t.status = 'open') DESC, t.last_message_at DESC LIMIT $limit", $params);
    }

    public function count_open(){
        $rows = parent::select("SELECT COUNT(*) AS n FROM support_tickets WHERE status = 'open'");
        return isset($rows[0]['n']) ? (int) $rows[0]['n'] : 0;
    }

    /** How many tickets a user opened in the last hour (to stop floods). */
    public function recent_count($user_id){
        $rows = parent::select("SELECT COUNT(*) AS n FROM support_tickets WHERE user_id = :u AND created_at >= :t", array('u' => (int) $user_id, 't' => gmdate('Y-m-d H:i:s', time() - 3600)));
        return isset($rows[0]['n']) ? (int) $rows[0]['n'] : 0;
    }

    /** Staff user ids, to notify about new requests and replies. */
    public function staff_ids(){
        return array_map('intval', array_column((array) parent::select("SELECT user_id FROM user_accounts WHERE is_admin = 1 AND deleted = 0"), 'user_id'));
    }
}
