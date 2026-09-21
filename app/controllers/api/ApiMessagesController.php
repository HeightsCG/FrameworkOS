<?php
/**
 * Internal direct messages: text, library media, and paid unlocks. Routed from
 * /api/<action> by ApiRoutes; extends BaseApiController.
 */
class ApiMessagesController extends BaseApiController {

    const MAX_ATTACH = 10;

    /** Send a message — into an existing conversation, or start one with a creator. */
    public function message_sendAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Sign in to send messages.', ['need_login' => true]); }
        $body = trim(html_entity_decode((string) ($this->post['body'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if (mb_strlen($body) > 2000) { $body = mb_substr($body, 0, 2000); }

        // Media and a price: creators only, from their own library.
        $asset_ids = []; $price = 0;
        $wanted = (array) ($this->post['asset_ids'] ?? []);
        if (!empty($wanted) || !empty($this->post['price'])) {
            if (!Permissions::has_role('Creator')) { $this->jsonError('Only creators can attach media.'); }
            $assets = (new MediaAssetsModel())->get_owned_ready($me, array_slice(array_map('intval', $wanted), 0, self::MAX_ATTACH));
            foreach ($assets as $a) { $asset_ids[] = (int) $a['id']; }
            if (!empty($wanted) && empty($asset_ids)) { $this->jsonError('Those files are not ready to send.'); }
            if ((int) ($this->post['price'] ?? 0) > 0) { $price = $this->ppv_credits_from_dollars($this->post['price']); }
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
                $this->jsonError('You can only message people you follow, subscribe to, or who follow you.');
            }
        }
        $conv = $model->get($conv_id);
        if (!$conv) { $this->jsonError('Conversation not found'); }
        $other_id = ((int) $conv['creator_id'] === $me) ? (int) $conv['user_id'] : (int) $conv['creator_id'];
        if ((new BlocksModel())->either_blocked($me, $other_id)) { $this->jsonError('You can\'t message this account.'); }
        if (!empty($asset_ids) && (int) $conv['creator_id'] !== $me) { $this->jsonError('Only creators can attach media.'); }

        $this->loginAttemptsModel->record($ip, (string) $me, 'message');
        $mid = $model->send($conv_id, $me, $body, null, $asset_ids, $price);

        // Notify the recipient in-platform (email only if they are offline).
        $recipient = ((int) $conv['creator_id'] === $me) ? (int) $conv['user_id'] : (int) $conv['creator_id'];
        $sender = $model->identity_map([$me])[$me] ?? ['name' => 'Someone'];
        $this->notify($recipient, 'messages', 'New message from ' . $sender['name'],
            MessagesModel::preview_text($body, count($asset_ids), $price), '/inbox/thread/' . $conv_id, 'fa-comment-dots', true);

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
                'id' => (int) $c['id'], 'other_id' => $other, 'other_name' => $id['name'], 'other_handle' => $id['handle'],
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
        $this->respond_thread($model, $model->get($conv_id), $me);
    }

    /** Total unread messages — drives the launcher badge. */
    public function message_unread_countAction(){
        $me = (int) Session::get('user_id');
        $this->jsonSuccess(['count' => $me > 0 ? (new MessagesModel())->total_unread($me) : 0]);
    }

    /** Spend credits to unlock a priced message. Records the unlock and pays the creator. */
    public function message_unlockAction(){
        $viewer = (int) Session::get('user_id');
        if ($viewer <= 0) { $this->jsonError('Sign in to unlock this.', ['need_login' => true]); }
        $mid   = (int) ($this->post['message_id'] ?? 0);
        $model = new MessagesModel();
        $msg   = $model->get_message($mid);
        $conv  = $msg ? $model->get((int) $msg['conversation_id']) : null;
        if (!$msg || !$conv || !$model->is_participant((int) $conv['id'], $viewer)) { $this->jsonError('That message is not available.'); }
        $creator_id = (int) $msg['sender_id'];
        $price      = (int) $msg['price_credits'];
        if ($creator_id === $viewer) { $this->jsonSuccess(['message' => $this->shape([$msg], $viewer, $conv)[0]]); }
        if ($price <= 0) { $this->jsonError('This message is not for sale.'); }

        $unlocks = new MessageUnlocksModel();
        $credits = new CreditsModel();
        if ($unlocks->has_unlocked($mid, $viewer)) {
            $this->jsonSuccess(['already' => true, 'message' => $this->shape([$msg], $viewer, $conv)[0], 'balance' => $credits->get_balance($viewer)]);
        }
        $balance = $credits->get_balance($viewer);
        if ($balance < $price) {
            $this->jsonError('You need ' . ($price - $balance) . ' more credits to unlock this.',
                ['need_credits' => true, 'balance' => $balance, 'price' => $price, 'shortfall' => $price - $balance]);
        }
        // Record first: UNIQUE(message_id, fan_id) is the mutex against a double charge. Then debit.
        if (!$unlocks->record($mid, $creator_id, $viewer, $price)) {
            $this->jsonSuccess(['already' => true, 'message' => $this->shape([$msg], $viewer, $conv)[0], 'balance' => $balance]);
        }
        if ($credits->apply_delta($viewer, -$price, 'message_unlock', 'Unlocked a message') === false) {
            $unlocks->remove($mid, $viewer);
            $this->jsonError('Not enough credits.', ['need_credits' => true, 'balance' => $credits->get_balance($viewer), 'price' => $price]);
        }
        $creator_row = $this->userModel->get_user_by_id($creator_id);
        $creator_row = (is_array($creator_row) && count($creator_row) === 1) ? $creator_row[0] : null;
        $net = (int) round($price * (100 - Plan::fee_percent($creator_row)) / 100);
        if ($net > 0) { $credits->apply_delta($creator_id, $net, 'message_earning', 'Message unlock'); }
        $who = $model->identity_map([$viewer])[$viewer] ?? ['name' => 'A fan'];
        $this->notify($creator_id, 'purchases', 'New message unlock',
            $who['name'] . ' unlocked your message for ' . Notify::credits($price) . '.', '/inbox/thread/' . (int) $conv['id'], 'fa-coins');
        $this->notify($viewer, 'purchases', 'Message unlocked',
            Notify::credits($price) . ' spent · ' . Notify::credits($credits->get_balance($viewer)) . ' left. It is in your Purchases.', '/purchases', 'fa-unlock');
        InboxAutomationService::trigger($creator_id, $viewer, 'new_purchase', 'msg' . $mid);

        $this->jsonSuccess(['message' => $this->shape([$msg], $viewer, $conv)[0], 'balance' => $credits->get_balance($viewer)]);
    }

    /** What the creator knows about the fan in a conversation: relationship + spend (creator side only). */
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
        $msgs    = $this->shape($model->thread($conv_id), $me, $c);
        $model->mark_read($conv_id, $me);
        $this->jsonSuccess(['conversation_id' => $conv_id, 'other' => $id, 'messages' => $msgs,
            'viewer_credits' => (new CreditsModel())->get_balance($me), 'i_am_creator' => ((int) $c['creator_id'] === $me)]);
    }

    /**
     * Shape message rows for one viewer. A priced message the viewer has not paid for is
     * `locked`: it carries only blurred covers. Otherwise every asset gets a signed url
     * (originals once paid, display renditions when free) plus a thumb for the grid.
     */
    private function shape(array $rows, int $me, array $conv): array{
        $model = new MessagesModel();
        $with_media = []; $priced = [];
        foreach ($rows as $m) {
            if ((int) ($m['media_count'] ?? 0) > 0) { $with_media[] = (int) $m['id']; }
            if ((int) ($m['price_credits'] ?? 0) > 0) { $priced[] = (int) $m['id']; }
        }
        $assets   = $with_media ? $model->assets_for_messages($with_media) : [];
        $unlocked = $priced ? (new MessageUnlocksModel())->unlocked_map($me, $priced) : [];
        $counts   = ($priced && (int) $conv['creator_id'] === $me) ? $model->unlock_counts($priced) : [];
        $out = [];
        foreach ($rows as $m) {
            $mid   = (int) $m['id'];
            $mine  = ((int) $m['sender_id'] === $me);
            $price = (int) ($m['price_credits'] ?? 0);
            $locked = ($price > 0 && !$mine && empty($unlocked[$mid]));
            $items = [];
            foreach ((array) ($assets[$mid] ?? []) as $a) {
                $is_video = ($a['type'] === 'video');
                if ($locked) {
                    $items[] = ['type' => $a['type'], 'locked_url' => MediaService::signed_variant($a, 'blurred', 900)];
                } else {
                    $items[] = [
                        'type'    => $a['type'],
                        'url'     => MediaService::signed_variant($a, ($is_video || $price > 0) ? 'original' : 'display', 900),
                        'poster'  => $is_video ? MediaService::signed_variant($a, 'poster', 900) : '',
                        'thumb'   => MediaService::signed_variant($a, $is_video ? 'poster' : 'thumb', 900),
                        'blurred' => $price > 0 ? MediaService::signed_variant($a, 'blurred', 900) : '',
                    ];
                }
            }
            $out[] = [
                'id' => $mid, 'mine' => $mine, 'body' => (string) $m['body'], 'created_at' => (string) $m['created_at'],
                'price_credits' => $price, 'price_dollars' => $price > 0 ? (int) round($price / 10) : 0,
                'locked' => $locked, 'unlocked' => ($price > 0 && !$mine && !empty($unlocked[$mid])),
                'media_count' => (int) ($m['media_count'] ?? 0), 'assets' => $items,
                'unlocks' => (int) ($counts[$mid] ?? 0), 'auto' => !empty($m['trigger_key']),
            ];
        }
        return $out;
    }

}
