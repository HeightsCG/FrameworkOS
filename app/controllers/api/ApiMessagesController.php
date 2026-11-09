<?php
/**
 * Internal direct messages: text and library media. Routed from
 * /api/<action> by ApiRoutes; extends BaseApiController.
 */
class ApiMessagesController extends BaseApiController {

    const INBOX_PAGE  = 30;   // conversations per page of the inbox list
    const THREAD_PAGE = 50;   // messages per page of a thread

    const MAX_ATTACH = 10;

    /** Send a message — into an existing conversation, or start one with a creator. */
    public function message_sendAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Sign in to send messages.', ['need_login' => true]); }
        $body = trim(html_entity_decode((string) ($this->post['body'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if (mb_strlen($body) > 2000) { $body = mb_substr($body, 0, 2000); }

        // Media: creators only, from their own library.
        $asset_ids = [];
        $wanted = (array) ($this->post['asset_ids'] ?? []);
        if (!empty($wanted)) {
            if (!Permissions::has_role('Creator')) { $this->jsonError('Only creators can attach media.'); }
            $assets = (new MediaAssetsModel())->get_owned_ready($me, array_slice(array_map('intval', $wanted), 0, self::MAX_ATTACH));
            foreach ($assets as $a) { $asset_ids[] = (int) $a['id']; }
            if (empty($asset_ids)) { $this->jsonError('Those files are not ready to send.'); }
        }
        if ($body === '' && empty($asset_ids)) { $this->jsonError('Type a message.'); }

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
                $this->jsonError('You can only message people you follow or who follow you.');
            }
        }
        $conv = $model->get($conv_id);
        if (!$conv) { $this->jsonError('Conversation not found'); }
        $other_id = ((int) $conv['creator_id'] === $me) ? (int) $conv['user_id'] : (int) $conv['creator_id'];
        if ((new BlocksModel())->either_blocked($me, $other_id)) { $this->jsonError('You can\'t message this account.'); }
        if (!empty($asset_ids) && (int) $conv['creator_id'] !== $me) { $this->jsonError('Only creators can attach media.'); }

        $this->loginAttemptsModel->record($ip, (string) $me, 'message');
        $mid = $model->send($conv_id, $me, $body, null, $asset_ids);

        // Notify the recipient in-platform (email only if they are offline).
        $recipient = ((int) $conv['creator_id'] === $me) ? (int) $conv['user_id'] : (int) $conv['creator_id'];
        $sender = $model->identity_map([$me])[$me] ?? ['name' => 'Someone'];
        if (PlatformAccount::is($recipient)) {
            // A reply to the Creator Link Studio team: every admin is told, it's answered at /admin/messages (no inbox automation).
            $preview = MessagesModel::preview_text($body, count($asset_ids));
            foreach ($this->userModel->admin_ids() as $aid) {
                $this->notify((int) $aid, 'messages', $sender['name'] . ' replied to Creator Link Studio', $preview, '/admin/messages?c=' . $conv_id, 'fa-comment-dots', true, true);   // always emailed: a reply to the team must never go unseen
            }
            $sent = $this->shape([$model->get_message($mid)], $me, $conv);
            echo json_encode(['success' => true, 'conversation_id' => $conv_id, 'sent' => $sent[0] ?? null]); exit;
        }
        $this->notify($recipient, 'messages', 'New message from ' . $sender['name'],
            MessagesModel::preview_text($body, count($asset_ids)), '/inbox/thread/' . $conv_id, 'fa-comment-dots', true);

        $sent = $this->shape([$model->get_message($mid)], $me, $conv);
        $payload = json_encode(['success' => true, 'conversation_id' => $conv_id, 'sent' => $sent[0] ?? null]);

        // Fan → creator: welcome them on their first message, then queue an AI reply and
        // process it after this response is on its way. Creator wrote by hand → drop any
        // draft still waiting for that conversation.
        if ((int) $conv['creator_id'] !== $me) {
            if ($model->count_from($conv_id, $me) === 1) {
                InboxAutomationService::trigger((int) $conv['creator_id'], $me, 'first_message');
            }
            $event_id = ($body !== '') ? InboxAutomationService::enqueue_cls_message((int) $conv_id, (int) $mid, (int) $conv['creator_id'], $me, $body) : 0;
            if ($event_id > 0) {
                InboxAutomationService::respond_early($payload);
                InboxAutomationService::process_event($event_id);
                exit;
            }
        } else {
            (new InboxRepliesModel())->dismiss_pending_for_peer((int) $conv['creator_id'], 'cls', (string) $conv_id, 'creator_replied');
        }
        echo $payload; exit;
    }

    /** The signed-in account's inbox. */
    public function message_inboxAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $model  = new MessagesModel();
        // Paged (INBOX_PAGE at a time, "Load More" passes the last row's before_at/before_id); the poll passes since
        // (the newest activity the page has) and gets only conversations that changed since.
        $since  = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($this->post['since'] ?? '')) ? (string) $this->post['since'] : '';
        $b_at   = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($this->post['before_at'] ?? '')) ? (string) $this->post['before_at'] : '';
        // Search and the Unread tab look through the whole inbox, not just the loaded page.
        $q      = trim(html_entity_decode((string) ($this->post['q'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $only_unread = (string) ($this->post['unread'] ?? '') === '1';
        $finding = ($q !== '' || $only_unread);
        $rows   = $finding ? $model->inbox_find($me, $q, $only_unread)
                           : $model->inbox_rows($me, $since !== '' ? 0 : self::INBOX_PAGE + 1, $b_at !== '' ? [$b_at, (int) ($this->post['before_id'] ?? 0)] : [], $since);
        $more   = !$finding && $since === '' && count($rows) > self::INBOX_PAGE;
        if ($more) { array_pop($rows); }
        $others = [];
        foreach ($rows as $c) { $others[] = ((int) $c['creator_id'] === $me) ? (int) $c['user_id'] : (int) $c['creator_id']; }
        $ids = $model->identity_map($others);
        $out = [];
        foreach ($rows as $c) {
            $i_am_creator = ((int) $c['creator_id'] === $me);
            $other = $i_am_creator ? (int) $c['user_id'] : (int) $c['creator_id'];
            $id    = $ids[$other] ?? ['handle' => '', 'name' => 'Unknown', 'avatar' => '', 'is_creator' => false];
            $out[] = [
                'id' => (int) $c['id'], 'other_id' => $other, 'other_name' => $id['name'], 'other_handle' => $id['handle'],
                'other_avatar' => $id['avatar'], 'other_is_creator' => !empty($id['is_creator']), 'other_is_team' => !empty($id['is_team']),
                'preview' => (string) $c['last_body'], 'last_at' => (string) $c['last_message_at'],
                'unread' => $i_am_creator ? (int) $c['creator_unread'] : (int) $c['user_unread'],
                'last_mine' => ((int) $c['last_sender_id'] === $me),
            ];
        }
        $this->jsonSuccess(['conversations' => $out, 'has_more' => $more, 'unread_total' => $since !== '' ? $model->total_unread($me) : null,
            'unread_convs' => $model->unread_conversations($me), 'found' => $finding]);
    }

    /** Full thread for a conversation (marks it read for the viewer). */
    public function message_threadAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $conv_id = (int) ($this->post['conversation_id'] ?? 0);
        $model   = new MessagesModel();
        $c = $model->get($conv_id);
        if (!$c || !$model->is_participant($conv_id, $me)) { $this->jsonError('Conversation not found'); }
        $other_id = ((int) $c['creator_id'] === $me) ? (int) $c['user_id'] : (int) $c['creator_id'];
        if ((new BlocksModel())->either_blocked($me, $other_id)) { $this->jsonError('Conversation not found'); }
        $this->respond_thread($model, $c, $me);
    }

    /** Picker list for starting a new conversation: people you follow/subscribe to or who follow/subscribe to you (optional search). */
    public function message_peopleAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $q     = trim((string) ($this->post['q'] ?? ''));
        $model = new MessagesModel();
        // Sending media (a hand-off from another page): only people you'd message as their creator can receive it. With another creator you are the fan side.
        $media = !empty($this->post['media']);
        $this->jsonSuccess(['people' => $model->connections($me, $q, 50, $media), 'is_search' => ($q !== ''), 'media' => $media]);
    }

    /** Open (find or create) a conversation with a connected account and return its thread. */
    public function message_openAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $to = (int) ($this->post['to_creator'] ?? 0);
        if ($to <= 0 || $to === $me) { $this->jsonError('Invalid recipient'); }
        $model   = new MessagesModel();
        $conv_id = $model->open_between($me, $to);
        if ($conv_id <= 0) { $this->jsonError('You can only message people you follow or who follow you.'); }
        $this->respond_thread($model, $model->get($conv_id), $me);
    }

    /** Delete a message you sent, for both people. */
    public function message_deleteAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $model = new MessagesModel();
        $m = $model->get_message((int) ($this->post['message_id'] ?? 0));
        if (!$m || !$model->is_participant((int) $m['conversation_id'], $me)) { $this->jsonError('Message not found'); }
        if ((int) $m['sender_id'] !== $me) { $this->jsonError('You can only delete messages you sent'); }
        $model->delete_message((int) $m['id']);
        $this->jsonSuccess(['message' => 'Message deleted']);
    }

    /** Delete a conversation from your own inbox. The other person keeps theirs. */
    public function conversation_deleteAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $model = new MessagesModel();
        $conv_id = (int) ($this->post['conversation_id'] ?? 0);
        if (!$model->get($conv_id) || !$model->is_participant($conv_id, $me)) { $this->jsonError('Conversation not found'); }
        $model->delete_for($conv_id, $me);
        $this->jsonSuccess(['message' => 'Conversation deleted']);
    }

    /** Total unread messages — drives the launcher badge. */
    public function message_unread_countAction(){
        $me = (int) Session::get('user_id');
        $this->jsonSuccess(['count' => $me > 0 ? (new MessagesModel())->total_unread($me) : 0]);
    }

    /** What the creator knows about the fan in a conversation: relationship (creator side only). */
    public function message_peer_infoAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $model = new MessagesModel();
        $c = $model->get((int) ($this->post['conversation_id'] ?? 0));
        if (!$c || (int) $c['creator_id'] !== $me) { $this->jsonError('Conversation not found'); }
        $this->jsonSuccess(['info' => $model->peer_summary($me, (int) $c['user_id'])]);
    }

    /** The creator's ready library media for the attach picker. */
    public function message_media_listAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::has_role('Creator')) { $this->jsonError('Creators only'); }
        $filters = [];
        $q = trim(html_entity_decode((string) ($this->post['q'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($q !== '') { $filters['search'] = $q; }
        $type = (string) ($this->post['type'] ?? '');
        if (in_array($type, ['image', 'video'], true)) { $filters['type'] = $type; }
        $out = [];
        foreach ((array) (new MediaAssetsModel())->get_for_creator($me, $filters) as $a) {
            if (($a['status'] ?? '') !== 'ready') { continue; }
            $out[] = [
                'id'       => (int) $a['id'],
                'type'     => (string) $a['type'],
                'name'     => (string) ((isset($a['display_name']) && $a['display_name'] !== '' && $a['display_name'] !== null) ? $a['display_name'] : $a['filename']),
                'thumb'    => MediaService::signed_variant($a, $a['type'] === 'video' ? 'poster' : 'thumb', 900),
                'duration' => isset($a['duration_sec']) ? (int) $a['duration_sec'] : 0,
            ];
            if (count($out) >= 80) { break; }
        }
        $this->jsonSuccess(['assets' => $out]);
    }

    // ---- helpers ------------------------------------------------------------------------

    private function respond_thread(MessagesModel $model, array $c, int $me): void{
        $conv_id = (int) $c['id'];
        $other   = ((int) $c['creator_id'] === $me) ? (int) $c['user_id'] : (int) $c['creator_id'];
        $id      = $model->identity_map([$other])[$other] ?? ['handle' => '', 'name' => 'Unknown', 'avatar' => '', 'is_creator' => false];
        $id['id'] = $other;
        // The newest THREAD_PAGE messages; before_id loads the page before it ("Load Earlier"); after_id (the poll) only
        // the ones newer than what the page has.
        $cleared = MessagesModel::cleared_id($c, $me);
        $after   = (int) ($this->post['after_id'] ?? 0);
        $more    = false;
        if ($after > 0) {
            $rows = $model->thread($conv_id, max($cleared, $after));
        } else {
            $rows = $model->thread($conv_id, $cleared, self::THREAD_PAGE + 1, (int) ($this->post['before_id'] ?? 0));
            $more = count($rows) > self::THREAD_PAGE;
            if ($more) { array_shift($rows); }
        }
        $msgs    = $this->shape($rows, $me, $c);
        $model->mark_read($conv_id, $me);
        $this->jsonSuccess(['conversation_id' => $conv_id, 'other' => $id, 'messages' => $msgs, 'has_more' => $more, 'partial' => $after > 0,
            'i_am_creator' => ((int) $c['creator_id'] === $me)]);
    }

    /** Media not cleared by the content check (flagged, blocked or not yet scanned). */
    private static function not_cleared(array $a): bool{
        $st = (string) ($a['moderation_status'] ?? '');
        if ($st === 'n_a') { return false; }   // video from before videos were moderated
        return $st !== 'approved';
    }

    /** Shape message rows for one viewer: every asset gets a signed display url (original for video) plus a thumb for the grid. */
    private function shape(array $rows, int $me, array $conv): array{
        $model = new MessagesModel();
        $with_media = [];
        foreach ($rows as $m) {
            if ((int) ($m['media_count'] ?? 0) > 0) { $with_media[] = (int) $m['id']; }
        }
        $assets   = $with_media ? $model->assets_for_messages($with_media) : [];
        $out = [];
        foreach ($rows as $m) {
            $mid   = (int) $m['id'];
            $mine  = ((int) $m['sender_id'] === $me);
            $items = [];
            foreach ((array) ($assets[$mid] ?? []) as $a) {
                $is_video = ($a['type'] === 'video');
                if (!$mine && self::not_cleared($a)) {
                    // Media the content check has not cleared never reaches the other side: a blurred still only.
                    $items[] = ['type' => $a['type'], 'thumb' => MediaService::signed_variant($a, 'blurred', 900), 'held' => true];
                } else {
                    $items[] = [
                        'type'    => $a['type'],
                        'url'     => MediaService::signed_variant($a, $is_video ? 'original' : 'display', 900),
                        'poster'  => $is_video ? MediaService::signed_variant($a, 'poster', 900) : '',
                        'thumb'   => MediaService::signed_variant($a, $is_video ? 'poster' : 'thumb', 900),
                    ];
                }
            }
            $out[] = [
                'id' => $mid, 'mine' => $mine, 'body' => (string) $m['body'], 'created_at' => (string) $m['created_at'],
                'media_count' => (int) ($m['media_count'] ?? 0), 'assets' => $items,
                'auto' => !empty($m['trigger_key']),
            ];
        }
        return $out;
    }

}
