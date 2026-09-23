<?php
/** Inbox automation: settings, AI reply approval queue, test drafts. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiInboxController extends BaseApiController {

    public function inbox_settings_saveAction(){
        $user = $this->inbox_user();
        $f = [];
        foreach (['cls_enabled', 'mode', 'quiet_start', 'quiet_end', 'quiet_action',
                       'max_consecutive', 'upsell_enabled', 'disclose_ai'] as $k) {
            $f[$k] = $this->post[$k] ?? null;
        }
        $f['persona']      = html_entity_decode((string) ($this->post['persona'] ?? ''), ENT_QUOTES, 'UTF-8');
        $f['avoid_topics'] = html_entity_decode((string) ($this->post['avoid_topics'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (!empty($f['cls_enabled']) && !Plan::has_feature($user, 'inbox_ai')) {
            $this->jsonError(Plan::feature_message('inbox_ai'), ['need_upgrade' => true]);
        }

        $clean = (new InboxSettingsModel())->save((int) $user['user_id'], $f);
        $this->jsonSuccess(['message' => 'Inbox settings saved', 'settings' => $clean]);
    }

    public function inbox_queue_listAction(){
        $user = $this->inbox_user();
        $tz   = (string) ($user['content_timezone'] ?? 'UTC');
        $m    = new InboxRepliesModel();
        $items = []; $history = [];
        foreach ($m->pending_for_creator((int) $user['user_id'], 50) as $r) { $items[]   = $this->inbox_reply_json($r, $tz); }
        foreach ($m->recent_for_creator((int) $user['user_id'], 20)  as $r) { $history[] = $this->inbox_reply_json($r, $tz); }
        $this->jsonSuccess(['items' => $items, 'history' => $history, 'pending_count' => count($items)]);
    }

    public function inbox_reply_sendAction(){
        $user = $this->inbox_user();
        $m    = new InboxRepliesModel();
        $row  = $m->get_one((int) $user['user_id'], (int) ($this->post['id'] ?? 0));
        if (!$row || $row['status'] !== 'pending_approval') {
            $this->jsonError('That draft is no longer waiting.');
        }
        $text = html_entity_decode((string) ($this->post['text'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (trim($text) === '') { $text = (string) $row['draft_text']; }
        set_time_limit(90);
        $res = InboxAutomationService::send_reply($row, $text, (int) Session::get('user_id'), false);
        if (!$res['ok']) {
            $this->jsonError((string) ($res['error']));
        }
        $this->jsonSuccess(['message' => 'Sent']);
    }

    public function inbox_reply_dismissAction(){
        $user = $this->inbox_user();
        $m    = new InboxRepliesModel();
        $row  = $m->get_one((int) $user['user_id'], (int) ($this->post['id'] ?? 0));   // ownership first
        $n    = $row ? $m->mark_dismissed((int) $row['id'], (int) Session::get('user_id')) : 0;
        echo json_encode(['success' => ($n === 1), 'message' => ($n === 1) ? 'Dismissed' : 'That draft is no longer waiting.']);
        exit;
    }

    /** Try the current persona/guardrails on a sample fan message. Nothing is stored or sent. */
    public function inbox_test_draftAction(){
        $user   = $this->inbox_user();
        if (!Plan::has_feature($user, 'inbox_ai')) { $this->jsonError(Plan::feature_message('inbox_ai'), ['need_upgrade' => true]); }
        $sample = trim(html_entity_decode((string) ($this->post['sample_text'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($sample === '') {
            $this->jsonError('Type a sample message first.');
        }
        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'inbox_test', 1) >= 10) {
            $this->jsonError('Slow down — try again in a minute.');
        }
        $this->loginAttemptsModel->record($ip, (string) $user['user_id'], 'inbox_test');
        if (!ClaudeService::configured()) {
            $this->jsonError('AI replies are not configured on this server.');
        }
        // Use what's on the form right now (unsaved edits included) so tuning is one loop.
        $settings = (new InboxSettingsModel())->get_for_creator((int) $user['user_id']);
        foreach (['persona', 'avoid_topics'] as $k) {
            if (isset($this->post[$k])) { $settings[$k] = mb_substr(strip_tags(html_entity_decode((string) $this->post[$k], ENT_QUOTES, 'UTF-8')), 0, 2000); }
        }
        foreach (['upsell_enabled', 'disclose_ai'] as $k) {
            if (isset($this->post[$k])) { $settings[$k] = !empty($this->post[$k]) ? 1 : 0; }
        }
        $cb  = (new CreatorBrandModel())->get_for_user((int) $user['user_id']);
        $res = InboxAutomationService::draft($settings, $cb, $user, [], mb_substr($sample, 0, 500), 'a fan', 'cls');
        if (!$res['ok']) {
            $this->jsonError((string) ($res['error']));
        }
        $this->jsonSuccess(['hold' => $res['hold'], 'text' => $res['hold'] ? '' : $res['text'], 'message' => $res['hold'] ? 'This one would be held for you to answer personally.' : 'Draft ready']);
    }

    // ---- welcome & trigger messages (Creator Link Studio events) ---------------------------

    public function auto_messages_listAction(){
        $user  = $this->inbox_user();
        $cid   = (int) $user['user_id'];
        $model = new AutoMessagesModel();
        $rows  = $model->get_for_creator($cid);
        $sent  = $model->send_counts($cid);
        $media = new MediaAssetsModel();
        $items = [];
        foreach (AutoMessagesModel::TRIGGERS as $t) {
            $r = $rows[$t] ?? null;
            $assets = [];
            if ($r && !empty($r['asset_ids'])) {
                foreach ($media->get_owned_ready($cid, $r['asset_ids']) as $a) {
                    $assets[] = ['id' => (int) $a['id'], 'type' => (string) $a['type'],
                        'thumb' => MediaService::signed_variant($a, $a['type'] === 'video' ? 'poster' : 'thumb', 900)];
                }
            }
            $items[$t] = [
                'enabled' => $r ? !empty($r['enabled']) : false,
                'is_default' => $r ? !empty($r['is_default']) : true,
                'text'    => $r ? (string) $r['text'] : '',
                'price'   => $r ? (int) round(((int) $r['price_credits']) / 10) : 0,
                'assets'  => $assets,
                'sent'    => (int) ($sent[$t] ?? 0),
            ];
        }
        $this->jsonSuccess(['items' => $items]);
    }

    /** Save (and enable) one trigger, or with ai_generate=1 just return a Claude draft. */
    public function auto_message_saveAction(){
        $user    = $this->inbox_user();
        $cid     = (int) $user['user_id'];
        $trigger = (string) ($this->post['trigger'] ?? '');
        if (!AutoMessagesModel::is_trigger($trigger)) { $this->jsonError('Unknown trigger.'); }
        if (!empty($this->post['ai_generate'])) {
            if (!Plan::has_feature($user, 'inbox_ai')) { $this->jsonError(Plan::feature_message('inbox_ai'), ['need_upgrade' => true]); }
            if (!ClaudeService::configured()) { $this->jsonError('AI is not configured on this server.'); }
            $ip = $this->get_ip_address();
            if ($this->loginAttemptsModel->count_recent($ip, 'inbox_test', 1) >= 10) {
                $this->jsonError('Slow down — try again in a minute.');
            }
            $this->loginAttemptsModel->record($ip, (string) $cid, 'inbox_test');
            $text = InboxAutomationService::draft_trigger_message($user, $trigger);
            if ($text === '') { $this->jsonError('Could not draft that message. Try again.'); }
            $this->jsonSuccess(['text' => $text, 'message' => 'Draft ready']);
        }
        $text = trim(html_entity_decode((string) ($this->post['text'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $asset_ids = [];
        foreach ((new MediaAssetsModel())->get_owned_ready($cid, array_slice(array_map('intval', (array) ($this->post['asset_ids'] ?? [])), 0, 10)) as $a) {
            $asset_ids[] = (int) $a['id'];
        }
        if ($text === '' && empty($asset_ids)) { $this->jsonError('Write the message first.'); }
        $price = (!empty($asset_ids) && (int) ($this->post['price'] ?? 0) > 0) ? $this->ppv_credits_from_dollars($this->post['price']) : 0;
        (new AutoMessagesModel())->save($cid, $trigger, $text, true, $asset_ids, $price);
        $this->jsonSuccess(['message' => 'Saved']);
    }

    public function auto_message_deleteAction(){
        $user    = $this->inbox_user();
        $trigger = (string) ($this->post['trigger'] ?? '');
        if (!AutoMessagesModel::is_trigger($trigger)) { $this->jsonError('Unknown trigger.'); }
        (new AutoMessagesModel())->delete_one((int) $user['user_id'], $trigger);
        $this->jsonSuccess(['message' => 'Turned off']);
    }

    private function inbox_reply_json(array $r, string $tz): array{
        return [
            'id'            => (int) $r['id'],
            'channel'       => (string) $r['channel'],
            'peer_name'     => (string) ($r['peer_name'] ?? ''),
            'inbound_text'  => (string) ($r['inbound_text'] ?? ''),
            'draft_text'    => (string) ($r['draft_text'] ?? ''),
            'final_text'    => (string) ($r['final_text'] ?? ''),
            'status'        => (string) $r['status'],
            'reason'        => (string) ($r['reason'] ?? ''),
            'error'         => (string) ($r['error'] ?? ''),
            'created_human' => $this->scheduler_next_human($r['created_at'], $tz),
            'sent_human'    => $this->scheduler_next_human($r['sent_at'] ?? '', $tz),
        ];
    }

}
