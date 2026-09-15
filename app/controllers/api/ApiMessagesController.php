<?php
/** Internal 1:1 direct messages. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiMessagesController extends BaseApiController {

    /** Send a message — into an existing conversation, or start one with a creator. */
    public function message_sendAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Sign in to send messages.', ['need_login' => true]); }
        $body = trim(html_entity_decode((string) ($this->post['body'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($body === '') { $this->jsonError('Type a message.'); }
        if (mb_strlen($body) > 2000) { $body = mb_substr($body, 0, 2000); }
        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'message', 1) >= 20) {
            $this->jsonError('You\'re sending messages too fast. Try again in a moment.');
        }
        $model   = new MessagesModel();
        $conv_id = (int) ($this->post['conversation_id'] ?? 0);
        if ($conv_id > 0) {
            if (!$model->is_participant($conv_id, $me)) { $this->jsonError('Conversation not found'); }
        } else {
            $to = (int) ($this->post['to_creator'] ?? 0);
            if ($to <= 0 || $to === $me) { $this->jsonError('Invalid recipient'); }
            $conv_id = $model->open_between($me, $to);
            if ($conv_id <= 0) {
                $this->jsonError('You can only message people you follow, subscribe to, or who follow you.');
            }
        }
        $this->loginAttemptsModel->record($ip, (string) $me, 'message');
        $mid = $model->send($conv_id, $me, $body);

        // Notify the recipient in-platform.
        $conv = $model->get($conv_id);
        if ($conv) {
            $recipient = ((int) $conv['creator_id'] === $me) ? (int) $conv['user_id'] : (int) $conv['creator_id'];
            $sender = $model->identity_map([$me])[$me] ?? ['name' => 'Someone'];
            // Email only if the recipient is offline (they'd see an online DM live).
            $this->notify($recipient, 'messages', 'New message from ' . $sender['name'], mb_substr($body, 0, 140), '/', 'fa-comment-dots', true);
        }

        $payload = json_encode(['success' => true, 'conversation_id' => $conv_id,
            'sent' => ['id' => $mid, 'mine' => true, 'body' => $body, 'created_at' => date('Y-m-d H:i:s')]]);

        // Inbox automation (internal DMs): a fan wrote to a creator → queue an AI reply and
        // process it after this response is on its way; a creator wrote by hand → drop any
        // draft still waiting for that conversation.
        if ($conv) {
            if ((int) $conv['creator_id'] !== $me) {
                $event_id = InboxAutomationService::enqueue_cls_message((int) $conv_id, (int) $mid, (int) $conv['creator_id'], $me, $body);
                if ($event_id > 0) {
                    InboxAutomationService::respond_early($payload);
                    InboxAutomationService::process_event($event_id);
                    exit;
                }
            } else {
                (new InboxRepliesModel())->dismiss_pending_for_peer((int) $conv['creator_id'], 'cls', (string) $conv_id, 'creator_replied');
            }
        }
        echo $payload; exit;
    }

    /** The signed-in account's inbox. */
    public function message_inboxAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $model  = new MessagesModel();
        $rows   = $model->inbox_rows($me);
        $others = [];
        foreach ($rows as $c) { $others[] = ((int) $c['creator_id'] === $me) ? (int) $c['user_id'] : (int) $c['creator_id']; }
        $ids = $model->identity_map($others);
        $out = [];
        foreach ($rows as $c) {
            $i_am_creator = ((int) $c['creator_id'] === $me);
            $other = $i_am_creator ? (int) $c['user_id'] : (int) $c['creator_id'];
            $id    = $ids[$other] ?? ['handle' => '', 'name' => 'Unknown', 'avatar' => '', 'is_creator' => false];
            $out[] = [
                'id' => (int) $c['id'], 'other_name' => $id['name'], 'other_handle' => $id['handle'],
                'other_avatar' => $id['avatar'], 'other_is_creator' => !empty($id['is_creator']),
                'preview' => (string) $c['last_body'], 'last_at' => (string) $c['last_message_at'],
                'unread' => $i_am_creator ? (int) $c['creator_unread'] : (int) $c['user_unread'],
                'last_mine' => ((int) $c['last_sender_id'] === $me),
            ];
        }
        $this->jsonSuccess(['conversations' => $out]);
    }

    /** Full thread for a conversation (marks it read for the viewer). */
    public function message_threadAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $conv_id = (int) ($this->post['conversation_id'] ?? 0);
        $model   = new MessagesModel();
        $c = $model->get($conv_id);
        if (!$c || !$model->is_participant($conv_id, $me)) { $this->jsonError('Conversation not found'); }
        $other = ((int) $c['creator_id'] === $me) ? (int) $c['user_id'] : (int) $c['creator_id'];
        $id    = $model->identity_map([$other])[$other] ?? ['handle' => '', 'name' => 'Unknown', 'avatar' => '', 'is_creator' => false];
        $msgs  = [];
        foreach ($model->thread($conv_id) as $m) {
            $msgs[] = ['id' => (int) $m['id'], 'mine' => ((int) $m['sender_id'] === $me), 'body' => (string) $m['body'], 'created_at' => (string) $m['created_at']];
        }
        $model->mark_read($conv_id, $me);
        $this->jsonSuccess(['conversation_id' => $conv_id, 'other' => $id, 'messages' => $msgs]);
    }

    /** Picker list for starting a new conversation: people you follow/subscribe to or who follow/subscribe to you (optional search). */
    public function message_peopleAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $q     = trim((string) ($this->post['q'] ?? ''));
        $model = new MessagesModel();
        $this->jsonSuccess(['people' => $model->connections($me, $q), 'is_search' => ($q !== '')]);
    }

    /** Open (find or create) a conversation with a connected account and return its thread. */
    public function message_openAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $to = (int) ($this->post['to_creator'] ?? 0);
        if ($to <= 0 || $to === $me) { $this->jsonError('Invalid recipient'); }
        $model   = new MessagesModel();
        $conv_id = $model->open_between($me, $to);
        if ($conv_id <= 0) { $this->jsonError('You can only message people you follow, subscribe to, or who follow you.'); }
        $id      = $model->identity_map([$to])[$to] ?? ['handle' => '', 'name' => 'Unknown', 'avatar' => '', 'is_creator' => false];
        $msgs    = [];
        foreach ($model->thread($conv_id) as $m) {
            $msgs[] = ['id' => (int) $m['id'], 'mine' => ((int) $m['sender_id'] === $me), 'body' => (string) $m['body'], 'created_at' => (string) $m['created_at']];
        }
        $model->mark_read($conv_id, $me);
        $this->jsonSuccess(['conversation_id' => $conv_id, 'other' => $id, 'messages' => $msgs]);
    }

    /** Total unread messages — drives the launcher badge. */
    public function message_unread_countAction(){
        $me = (int) Session::get('user_id');
        $this->jsonSuccess(['count' => $me > 0 ? (new MessagesModel())->total_unread($me) : 0]);
    }

    /* ---------- Broadcast (PRD §25) — creator messages their whole audience ---------- */

}
