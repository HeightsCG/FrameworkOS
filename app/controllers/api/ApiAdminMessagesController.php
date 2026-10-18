<?php
/**
 * Admin messaging: staff write to users as Creator Link Studio (PlatformAccount: participant 0, not an account). A message lands in
 * the user's Inbox (they can reply) and is emailed to them; replies are answered from /admin/messages. Announcements
 * go to a whole audience through BroadcastsModel. Routed from /api/<action> by ApiRoutes; every send is audited.
 */
class ApiAdminMessagesController extends BaseApiController {

    use AuditTrail;
    /** Reads are not staff actions worth a log line. */
    protected $audit_skip = array('admin_message_inbox', 'admin_message_thread', 'admin_announce_count');

    /** Message one user: {user_id, body} starts or continues their team conversation; {conversation_id, body} replies in one. */
    public function admin_message_sendAction(){
        $team  = $this->team_guard();
        $body  = trim(html_entity_decode((string) ($this->post['body'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($body === '') { $this->jsonError('Type a message'); }
        $model = new MessagesModel();
        $conv_id = (int) ($this->post['conversation_id'] ?? 0);
        if ($conv_id > 0) {
            if (!$model->is_participant($conv_id, $team)) { $this->jsonError('Conversation not found'); }
        } else {
            $uid = (int) ($this->post['user_id'] ?? 0);
            $rows = $uid > 0 ? $this->userModel->get_user_by_id($uid) : null;
            $u = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            if (!$u || (int) ($u['deleted'] ?? 0) === 1 || $uid === $team) { $this->jsonError('User not found'); }
            $conv_id = $model->get_or_create($team, $uid);   // the team is always the creator_id side
        }
        $conv = $model->get($conv_id);
        $uid  = (int) $conv['creator_id'] === $team ? (int) $conv['user_id'] : (int) $conv['creator_id'];
        if ((new BlocksModel())->either_blocked($team, $uid)) { $this->jsonError('This user blocked Creator Link Studio.'); }
        $mid = $model->send($conv_id, $team, $body);
        $model->mark_read($conv_id, $team);   // answering it reads it
        Notify::send($uid, 'messages', 'New message from ' . PlatformAccount::NAME, $body, '/inbox/thread/' . $conv_id, 'fa-envelope', false, true);   // always emailed
        $msg = $model->get_message($mid);
        $this->jsonSuccess(['conversation_id' => $conv_id, 'user_id' => $uid, 'message' => 'Message sent',
            'sent' => $msg ? $this->message_json($msg, $team) : null]);
    }

    /** The team inbox: every conversation with a message, newest first. */
    public function admin_message_inboxAction(){
        $team  = $this->team_guard();
        $model = new MessagesModel();
        $rows  = $model->inbox_rows($team);
        $others = array();
        foreach ($rows as $c) { $others[] = (int) $c['creator_id'] === $team ? (int) $c['user_id'] : (int) $c['creator_id']; }
        $ids = $model->identity_map($others);
        $out = array();
        foreach ($rows as $c) {
            $mine  = (int) $c['creator_id'] === $team;
            $other = $mine ? (int) $c['user_id'] : (int) $c['creator_id'];
            $id    = $ids[$other] ?? array('handle' => '', 'name' => 'Deleted account', 'avatar' => '');
            $out[] = array(
                'id' => (int) $c['id'], 'user_id' => $other, 'name' => $id['name'], 'handle' => $id['handle'], 'avatar' => $id['avatar'],
                'preview' => (string) $c['last_body'], 'last_at' => self::iso((string) $c['last_message_at']),
                'unread' => $mine ? (int) $c['creator_unread'] : (int) $c['user_unread'],
                'last_mine' => (int) $c['last_sender_id'] === $team,
            );
        }
        $this->jsonSuccess(['conversations' => $out]);
    }

    /** One team conversation, oldest first (marks it read for the team). */
    public function admin_message_threadAction(){
        $team  = $this->team_guard();
        $model = new MessagesModel();
        $conv_id = (int) ($this->post['conversation_id'] ?? 0);
        $c = $model->get($conv_id);
        if (!$c || !$model->is_participant($conv_id, $team)) { $this->jsonError('Conversation not found'); }
        $other = (int) $c['creator_id'] === $team ? (int) $c['user_id'] : (int) $c['creator_id'];
        $id = $model->identity_map(array($other))[$other] ?? array('handle' => '', 'name' => 'Deleted account', 'avatar' => '');
        $msgs = array();   // whoever on the team wrote it, it reads as the team's
        foreach ($model->thread($conv_id) as $m) { $msgs[] = $this->message_json($m, $team); }
        $model->mark_read($conv_id, $team);
        $this->jsonSuccess(['conversation_id' => $conv_id,
            'user' => array('id' => $other, 'name' => $id['name'], 'handle' => $id['handle'], 'avatar' => $id['avatar']), 'messages' => $msgs]);
    }

    /** How many accounts an announcement to {segment} reaches. */
    public function admin_announce_countAction(){
        $this->team_guard();
        $seg = $this->segment();
        $this->jsonSuccess(['segment' => $seg, 'count' => (new BroadcastsModel())->platform_count($seg)]);
    }

    /** Send an announcement {segment, body} to everyone in that audience (queued above BroadcastsModel::INLINE_MAX). */
    public function admin_announce_sendAction(){
        $team = $this->team_guard();
        $seg  = $this->segment();
        $body = trim(html_entity_decode((string) ($this->post['body'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($body === '') { $this->jsonError('Type the announcement'); }
        [$bid, $n, $queued] = (new BroadcastsModel())->create_and_send($team, array($seg), $body);
        if ($n === 0) { $this->jsonError('No one is in that audience.'); }
        $this->jsonSuccess(['broadcast_id' => (int) $bid, 'count' => (int) $n, 'queued' => (bool) $queued,
            'message' => 'Announcement sent to ' . number_format($n) . ' ' . ($n === 1 ? 'user' : 'users')]);
    }

    // ---- helpers ------------------------------------------------------------------------

    /** Admins only; returns the team's participant id (PlatformAccount::ID). */
    private function team_guard(): int{
        if ((int) Session::get('user_id') <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::is_admin()) { $this->jsonError('Admins only'); }
        return PlatformAccount::ID;
    }

    private function segment(): string{
        $seg = (string) ($this->post['segment'] ?? '');
        if (!in_array($seg, BroadcastsModel::platform_segments(), true)) { $this->jsonError('Pick an audience'); }
        return $seg;
    }

    private function message_json(array $m, int $team): array{
        return array('id' => (int) $m['id'], 'mine' => (int) $m['sender_id'] === $team, 'body' => (string) $m['body'],
            'media' => (int) ($m['media_count'] ?? 0), 'at' => self::iso((string) $m['created_at']));
    }

    /** Stored UTC → ISO 8601, so the page shows it in the admin's own time. */
    private static function iso(string $utc): string{
        return $utc !== '' ? gmdate('c', strtotime($utc . ' UTC')) : '';
    }
}
