<?php
/**
 * Inbox automation orchestrator: turns an inbound fan message (Fanvue webhook now,
 * internal DMs later) into an AI reply in the creator's voice, subject to the
 * creator's guardrails (inbox_settings). Either sends it or parks it in the
 * approval queue (inbox_replies.pending_approval).
 *
 * Never throws to callers. Every outcome lands on inbox_events.result and, where a
 * reply row exists, inbox_replies.status/reason/error. Fanvue-side failures are also
 * written to user_fanvue_accounts.last_error so Settings can show them.
 */
class InboxAutomationService {

    const RATE_CAP_SEC   = 120;     // at most one auto-reply per fan per 2 minutes
    const HISTORY_N      = 20;      // conversation context sent to Claude
    const PAUSE_NOTICE_H = 6;       // "AI paused for @fan" notification at most every 6 h
    const HOLD_TOKEN     = '[[HOLD]]';
    const MAX_FANVUE     = 5000;
    const MAX_CLS        = 2000;

    private static $started = 0;

    // ---- entry points -----------------------------------------------------------------

    /**
     * Send a 200 to the caller NOW and keep executing. Fanvue retries anything that takes
     * over 10 s, so the reply pipeline must not hold the response open.
     */
    public static function respond_early($body = 'ok', $content_type = 'text/plain'): void {
        ignore_user_abort(true);
        @set_time_limit(120);
        self::$started = microtime(true);
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $content_type);
            header('Connection: close');
            header('Content-Length: ' . strlen($body));
        }
        if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
        echo $body;
        while (ob_get_level() > 0) { @ob_end_flush(); }
        flush();
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    }

    /** Claim one pending event and run it. Safe to call from the webhook and the worker. */
    public static function process_event($event_id, $reclaim = false): array {
        $events = new InboxEventsModel();
        $ev = $reclaim ? $events->reclaim($event_id) : $events->claim($event_id);
        if (!$ev) { return array('status' => 'skipped', 'result' => 'not_claimable'); }
        if (self::$started === 0) { self::$started = microtime(true); }
        try {
            $data = json_decode((string) $ev['payload'], true);
            $data = is_array($data) ? $data : array();
            switch ($ev['provider'] . ':' . $ev['event_type']) {
                case 'fanvue:creator.message.received':
                    $out = self::handle_fanvue_received($ev, (array) ($data['data'] ?? array()));
                    break;
                case 'cls:message':
                    $out = self::handle_cls_message($ev, (array) ($data['data'] ?? array()));
                    break;
                default:
                    $out = array('status' => 'skipped', 'result' => 'unhandled:' . $ev['event_type']);
            }
        } catch (\Throwable $e) {
            error_log('[inbox] event ' . (int) $ev['id'] . ' failed: ' . $e->getMessage());
            $out = array('status' => 'failed', 'result' => mb_substr($e->getMessage(), 0, 250));
        }
        $events->finish((int) $ev['id'], $out['status'], $out['result']);
        return $out;
    }

    /** Worker fallback: anything the in-request path left behind. Returns processed count. */
    public static function drain_pending($limit = 20): int {
        $n = 0;
        foreach ((new InboxEventsModel())->stale(90, $limit) as $row) {
            $out = self::process_event((int) $row['id'], true);
            if ($out['result'] !== 'not_claimable') { $n++; }
            echo '[inbox] event ' . (int) $row['id'] . ' → ' . $out['status'] . ' (' . $out['result'] . ")\n";
        }
        return $n;
    }

    // ---- internal DMs (Creator Link Studio) ------------------------------------------------

    /**
     * Queue a fan → creator DM for an AI reply. Cheap: one insert, no checks beyond the
     * creator's toggle (so most creators pay nothing). Returns the event id, or 0.
     */
    public static function enqueue_cls_message($conversation_id, $message_id, $creator_id, $fan_id, $body): int {
        try {
            $settings = (new InboxSettingsModel())->get_for_creator((int) $creator_id);
            if (empty($settings['cls_enabled'])) { return 0; }
            $payload = json_encode(array('id' => 'cls:' . (int) $message_id, 'type' => 'message', 'data' => array(
                'conversation_id' => (int) $conversation_id, 'message_id' => (int) $message_id,
                'fan_id' => (int) $fan_id, 'text' => (string) $body)));
            return (new InboxEventsModel())->record('cls', 'cls:' . (int) $message_id, (int) $creator_id, 'message', $payload);
        } catch (\Throwable $e) {
            error_log('[inbox] enqueue_cls_message: ' . $e->getMessage());
            return 0;
        }
    }

    private static function handle_cls_message(array $ev, array $d): array {
        $creator_id = (int) $ev['creator_id'];
        $conv_id    = (int) ($d['conversation_id'] ?? 0);
        $fan_id     = (int) ($d['fan_id'] ?? 0);
        $text       = trim((string) ($d['text'] ?? ''));
        $rows  = (new UsersModel())->get_user_by_id($creator_id);
        $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$owner || $conv_id <= 0 || $fan_id <= 0) { return self::skip('no_owner'); }

        $settings = (new InboxSettingsModel())->get_for_creator($creator_id);
        if (empty($settings['cls_enabled']))                      { return self::skip('disabled'); }
        if (!Plan::can_use_creator_features($owner))                 { return self::skip('plan'); }
        if ($text === '')                                         { return self::skip('no_text'); }
        if ((new BlocksModel())->is_blocked($creator_id, $fan_id)) { return self::skip('blocked'); }

        $messages = new MessagesModel();
        $conv = $messages->get($conv_id);
        if (!$conv || (int) $conv['creator_id'] !== $creator_id) { return self::skip('no_conversation'); }
        $fan_name = (string) (($messages->identity_map(array($fan_id))[$fan_id]['name'] ?? '') ?: 'a fan');
        $peer     = (string) $conv_id;

        $replies = new InboxRepliesModel();
        $last = $replies->last_sent_at_for_peer($creator_id, 'cls', $peer);
        if ($last !== null && (time() - strtotime($last)) < self::RATE_CAP_SEC) {
            return self::log_skip($replies, $creator_id, 'cls', $peer, $fan_name, $ev, $text, 'rate_cap');
        }

        // History from our own thread: creator rows → assistant; ours = message ids we sent.
        $our_ids = $replies->sent_provider_ids_for_peer($creator_id, 'cls', $peer);
        $turns = array();
        foreach ((array) $messages->thread($conv_id) as $m) {
            if ((int) $m['id'] === (int) ($d['message_id'] ?? 0)) { continue; }   // the inbound is appended by draft()
            $body = trim((string) $m['body']);
            if ($body === '') { continue; }
            $from_creator = ((int) $m['sender_id'] === $creator_id);
            $turns[] = array('role' => $from_creator ? 'assistant' : 'user', 'content' => $body,
                             'ours' => $from_creator && in_array((string) $m['id'], $our_ids, true));
        }
        $turns = array_slice($turns, -self::HISTORY_N);

        $streak = self::consecutive_ai_count($turns);
        if ($streak >= (int) $settings['max_consecutive']) {
            $since = $replies->last_pause_notice_for_peer($creator_id, 'cls', $peer);
            $res   = self::log_skip($replies, $creator_id, 'cls', $peer, $fan_name, $ev, $text, 'max_consecutive');
            if ($since === null || (time() - strtotime($since)) > self::PAUSE_NOTICE_H * 3600) {
                self::notify_creator($owner, 'AI replies paused for ' . $fan_name,
                    'They have had ' . $streak . ' automated replies in a row. Jump in to keep the conversation going.', '/account/settings?section=inbox');
            }
            return $res;
        }

        $force_approve = false;
        if (InboxSettingsModel::is_quiet($settings, (string) ($owner['content_timezone'] ?? 'UTC'))) {
            if ($settings['quiet_action'] === 'skip') { return self::log_skip($replies, $creator_id, 'cls', $peer, $fan_name, $ev, $text, 'quiet_hours'); }
            $force_approve = true;
        }
        if (!ClaudeService::configured()) { return self::skip('claude_unavailable'); }

        $replies->dismiss_pending_for_peer($creator_id, 'cls', $peer, 'superseded');
        $cb    = (new CreatorBrandModel())->get_for_user($creator_id);
        $draft = self::draft($settings, $cb, $owner, $turns, $text, $fan_name, 'cls');
        if (!$draft['ok']) {
            $replies->create(array('creator_id' => $creator_id, 'channel' => 'cls', 'peer_key' => $peer, 'peer_name' => $fan_name,
                'event_id' => (int) $ev['id'], 'inbound_text' => $text, 'status' => 'failed', 'reason' => 'claude'));
            return array('status' => 'failed', 'result' => 'claude:' . $draft['error']);
        }
        if ($draft['hold']) {
            $replies->create(array('creator_id' => $creator_id, 'channel' => 'cls', 'peer_key' => $peer, 'peer_name' => $fan_name,
                'event_id' => (int) $ev['id'], 'inbound_text' => $text, 'status' => 'skipped', 'reason' => 'needs_human'));
            self::notify_creator($owner, $fan_name . ' needs a personal reply', mb_substr($text, 0, 140), '/account/settings?section=inbox');
            return array('status' => 'done', 'result' => 'needs_human');
        }
        $auto = ($settings['mode'] === 'auto' && !$force_approve);
        $reply_id = $replies->create(array('creator_id' => $creator_id, 'channel' => 'cls', 'peer_key' => $peer, 'peer_name' => $fan_name,
            'event_id' => (int) $ev['id'], 'inbound_text' => $text, 'draft_text' => $draft['text'], 'status' => $auto ? 'sending' : 'pending_approval'));
        if (!$auto) {
            self::notify_creator($owner, 'Reply ready for ' . $fan_name, mb_substr($draft['text'], 0, 140), '/account/settings?section=inbox');
            return array('status' => 'done', 'result' => $force_approve ? 'held:quiet_hours' : 'held');
        }
        $sent = self::send_reply($replies->get_one($creator_id, $reply_id), $draft['text'], null, true);
        return $sent['ok'] ? array('status' => 'done', 'result' => 'sent') : array('status' => 'failed', 'result' => 'send:' . $sent['error']);
    }

    // ---- Fanvue pipeline ---------------------------------------------------------------

    private static function handle_fanvue_received(array $ev, array $d): array {
        $creator_id = (int) $ev['creator_id'];
        $rows  = (new UsersModel())->get_user_by_id($creator_id);
        $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$owner) { return array('status' => 'skipped', 'result' => 'no_owner'); }

        $settings = (new InboxSettingsModel())->get_for_creator($creator_id);
        $fan      = (array) ($d['fan'] ?? array());
        $fan_uuid = (string) ($fan['uuid'] ?? '');
        $fan_name = (string) ($fan['display_name'] ?? ($fan['handle'] ?? 'a fan'));
        $text     = trim((string) ($d['text'] ?? ''));

        // --- guards (cheap → expensive) -------------------------------------------
        if (empty($settings['fanvue_enabled']))                     { return self::skip('disabled'); }
        if (!Plan::can_use_creator_features($owner))                 { return self::skip('plan'); }
        if ($fan_uuid === '')                                       { return self::skip('no_fan'); }
        if (($d['sender'] ?? '') !== 'fan')                         { return self::skip('not_fan'); }
        if (!empty($d['is_automated']))                             { return self::skip('automated'); }
        if (!empty($d['is_muted']))                                 { return self::skip('muted'); }
        $type = (string) ($d['message_type'] ?? 'SINGLE_RECIPIENT');
        if ($type !== 'SINGLE_RECIPIENT')                           { return self::skip('type:' . $type); }
        if ($text === '')                                           { return self::skip('no_text'); }

        $accounts = new FanvueAccountsModel();
        $account  = $accounts->get_connected_for_user($creator_id);
        if (!$account || !FanvueAccountsModel::has_chat_scope($account)) {
            if ($account) { $accounts->set_error($creator_id, 'Reconnect Fanvue to enable inbox replies.'); }
            return self::skip('scope');
        }

        $replies = new InboxRepliesModel();
        $last = $replies->last_sent_at_for_peer($creator_id, 'fanvue', $fan_uuid);
        if ($last !== null && (time() - strtotime($last)) < self::RATE_CAP_SEC) {
            return self::log_skip($replies, $creator_id, 'fanvue', $fan_uuid, $fan_name, $ev, $text, 'rate_cap');
        }

        $token = FanvueService::access_token_for($account);
        if ($token === '') { return self::skip('token'); }

        // Conversation context (tolerated failure → reply to the inbound alone).
        $history = FanvueService::get_messages($token, $fan_uuid, self::HISTORY_N);
        $our_ids = $replies->sent_provider_ids_for_peer($creator_id, 'fanvue', $fan_uuid);
        $turns   = self::fanvue_history_to_turns($history, (string) $account['fanvue_user_uuid'], $our_ids, (string) ($d['uuid'] ?? ''), $text);

        $streak = self::consecutive_ai_count($turns);
        if ($streak >= (int) $settings['max_consecutive']) {
            $since = $replies->last_pause_notice_for_peer($creator_id, 'fanvue', $fan_uuid);
            $res   = self::log_skip($replies, $creator_id, 'fanvue', $fan_uuid, $fan_name, $ev, $text, 'max_consecutive');
            if ($since === null || (time() - strtotime($since)) > self::PAUSE_NOTICE_H * 3600) {
                self::notify_creator($owner, 'AI replies paused for ' . $fan_name,
                    'They have had ' . $streak . ' automated replies in a row. Jump in to keep the conversation going.',
                    '/account/settings?section=inbox');
            }
            return $res;
        }

        $force_approve = false;
        if (InboxSettingsModel::is_quiet($settings, (string) ($owner['content_timezone'] ?? 'UTC'))) {
            if ($settings['quiet_action'] === 'skip') {
                return self::log_skip($replies, $creator_id, 'fanvue', $fan_uuid, $fan_name, $ev, $text, 'quiet_hours');
            }
            $force_approve = true;
        }
        if (!ClaudeService::configured()) { return self::skip('claude_unavailable'); }

        // --- draft ------------------------------------------------------------------
        $replies->dismiss_pending_for_peer($creator_id, 'fanvue', $fan_uuid, 'superseded');
        $cb    = (new CreatorBrandModel())->get_for_user($creator_id);
        $draft = self::draft($settings, $cb, $owner, $turns, $text, $fan_name, 'fanvue');

        if (!$draft['ok']) {
            $replies->create(array('creator_id' => $creator_id, 'channel' => 'fanvue', 'peer_key' => $fan_uuid,
                'peer_name' => $fan_name, 'event_id' => (int) $ev['id'], 'inbound_text' => $text,
                'status' => 'failed', 'reason' => 'claude'));
            return array('status' => 'failed', 'result' => 'claude:' . $draft['error']);
        }
        if ($draft['hold']) {
            $replies->create(array('creator_id' => $creator_id, 'channel' => 'fanvue', 'peer_key' => $fan_uuid,
                'peer_name' => $fan_name, 'event_id' => (int) $ev['id'], 'inbound_text' => $text,
                'status' => 'skipped', 'reason' => 'needs_human'));
            self::notify_creator($owner, $fan_name . ' needs a personal reply',
                mb_substr($text, 0, 140), '/account/settings?section=inbox');
            return array('status' => 'done', 'result' => 'needs_human');
        }

        $reply_id = $replies->create(array('creator_id' => $creator_id, 'channel' => 'fanvue', 'peer_key' => $fan_uuid,
            'peer_name' => $fan_name, 'event_id' => (int) $ev['id'], 'inbound_text' => $text,
            'draft_text' => $draft['text'], 'status' => ($settings['mode'] === 'auto' && !$force_approve) ? 'sending' : 'pending_approval'));

        if ($settings['mode'] !== 'auto' || $force_approve) {
            self::notify_creator($owner, 'Reply ready for ' . $fan_name,
                mb_substr($draft['text'], 0, 140), '/account/settings?section=inbox');
            return array('status' => 'done', 'result' => $force_approve ? 'held:quiet_hours' : 'held');
        }

        $row  = $replies->get_one($creator_id, $reply_id);
        $sent = self::send_reply($row, $draft['text'], null, true);
        return $sent['ok'] ? array('status' => 'done', 'result' => 'sent')
                           : array('status' => 'failed', 'result' => 'send:' . $sent['error']);
    }

    // ---- shared pieces --------------------------------------------------------------------

    /**
     * Send (or re-send after approval) a reply row. $human_delay adds the typing pause
     * used on the auto path; approvals send right away.
     * @return array ['ok'=>bool,'message_id'=>string,'error'=>string]
     */
    public static function send_reply(array $row, $text, $decided_by = null, $human_delay = false): array {
        $replies = new InboxRepliesModel();
        $text = self::clean_outbound($text, ($row['channel'] === 'fanvue') ? self::MAX_FANVUE : self::MAX_CLS);
        if ($text === '') {
            $replies->mark_failed((int) $row['id'], 'Empty reply');
            return array('ok' => false, 'message_id' => '', 'error' => 'Empty reply');
        }
        try {
            if ($row['channel'] === 'fanvue') {
                $accounts = new FanvueAccountsModel();
                $account  = $accounts->get_connected_for_user((int) $row['creator_id']);
                if (!$account) { throw new RuntimeException('Fanvue is not connected'); }
                $token = FanvueService::access_token_for($account);
                if ($token === '') { throw new RuntimeException('Fanvue session expired. Reconnect in Settings > Integrations.'); }

                FanvueService::send_typing($token, (string) $row['peer_key']);
                if ($human_delay) {
                    $elapsed = self::$started ? (microtime(true) - self::$started) : 0;
                    $pause   = (int) min(rand(3, 8), max(0, 25 - (int) $elapsed));
                    if ($pause > 0) { sleep($pause); }
                }
                $msg = FanvueService::send_message($token, (string) $row['peer_key'], $text, 'cls-inbox-' . (int) $row['id']);
                $mid = (string) ($msg['uuid'] ?? '');
                $replies->mark_sent((int) $row['id'], $text, $mid, $decided_by);
                $accounts->set_error((int) $row['creator_id'], '');
                return array('ok' => true, 'message_id' => $mid, 'error' => '');
            }
            if ($row['channel'] === 'cls') {
                $messages = new MessagesModel();
                $conv = $messages->get((int) $row['peer_key']);
                if (!$conv || (int) $conv['creator_id'] !== (int) $row['creator_id']) { throw new RuntimeException('Conversation not found'); }
                if ($human_delay) {
                    $elapsed = self::$started ? (microtime(true) - self::$started) : 0;
                    $pause   = (int) min(rand(4, 12), max(0, 25 - (int) $elapsed));
                    if ($pause > 0) { sleep($pause); }
                }
                $mid = (int) $messages->send((int) $conv['id'], (int) $row['creator_id'], $text);
                $replies->mark_sent((int) $row['id'], $text, (string) $mid, $decided_by);
                // Same notification the manual path sends (email only if the fan is offline).
                try {
                    $rows = (new UsersModel())->get_user_by_id((int) $row['creator_id']);
                    $creator = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
                    $name = $creator ? (string) (($messages->identity_map(array((int) $row['creator_id']))[(int) $row['creator_id']]['name'] ?? '') ?: 'Someone') : 'Someone';
                    (new UserNotificationsModel())->push((int) $conv['user_id'], 'messages', 'New message from ' . $name, mb_substr($text, 0, 140), '/', 'fa-comment-dots');
                } catch (\Throwable $e) {}
                return array('ok' => true, 'message_id' => (string) $mid, 'error' => '');
            }
            throw new RuntimeException('Unsupported channel');
        } catch (\Throwable $e) {
            error_log('[inbox] send failed for reply ' . (int) $row['id'] . ': ' . $e->getMessage());
            $replies->mark_failed((int) $row['id'], $e->getMessage());
            if ($row['channel'] === 'fanvue') {
                try { (new FanvueAccountsModel())->set_error((int) $row['creator_id'], $e->getMessage()); } catch (\Throwable $x) {}
            }
            return array('ok' => false, 'message_id' => '', 'error' => $e->getMessage());
        }
    }

    /**
     * Ask Claude for the reply. @return ['ok','text','hold','error']
     * $turns: [['role'=>'user'|'assistant','content'=>...], ...] oldest → newest, WITHOUT the inbound.
     */
    public static function draft(array $settings, array $cb, array $owner, array $turns, $inbound, $fan_name, $channel): array {
        $system   = self::build_system_prompt($cb, $settings, $owner, $channel, $fan_name, count($turns) === 0);
        $messages = $turns;
        $messages[] = array('role' => 'user', 'content' => (string) $inbound);
        $res = ClaudeService::chat($system, $messages, 600, 30, 'low');
        if (!$res['ok']) { return array('ok' => false, 'text' => '', 'hold' => false, 'error' => $res['error']); }
        $text = trim($res['text']);
        if ($res['stop_reason'] === 'refusal' || $text === '' || stripos($text, self::HOLD_TOKEN) !== false) {
            return array('ok' => true, 'text' => '', 'hold' => true, 'error' => '');
        }
        return array('ok' => true, 'text' => self::clean_outbound($text, ($channel === 'fanvue') ? self::MAX_FANVUE : self::MAX_CLS), 'hold' => false, 'error' => '');
    }

    /** Trigger meanings shown to the creator and given to Claude when drafting. */
    public static function trigger_meta(): array {
        return array(
            'new_subscriber'        => array('New subscriber',        'Sent the moment someone subscribes.'),
            'new_follower'          => array('New follower',          'Sent when someone follows you for free.'),
            'first_message_reply'   => array('First message',         'Sent the first time a fan ever messages you.'),
            'renewed'               => array('Subscription renewed',  'Sent when a subscription renews.'),
            're_subscribed'         => array('Came back',             'Sent when a lapsed subscriber re-subscribes.'),
            'subscription_canceled' => array('Cancelled auto-renew',  'Sent when a subscriber turns off renewal.'),
            'new_purchase'          => array('New purchase',          'Sent after a fan buys something from you.'),
        );
    }

    /** One-off welcome/trigger message in the creator's voice ('' on failure). */
    public static function draft_trigger_message(array $owner, $trigger): string {
        $meta = self::trigger_meta();
        if (!isset($meta[$trigger])) { return ''; }
        $settings = (new InboxSettingsModel())->get_for_creator((int) $owner['user_id']);
        $cb       = (new CreatorBrandModel())->get_for_user((int) $owner['user_id']);
        $system   = self::build_system_prompt($cb, $settings, $owner, 'fanvue', 'the fan', true);
        $ask = 'Write the automatic message Fanvue sends on this event: "' . $meta[$trigger][0] . '" (' . $meta[$trigger][1] . '). '
             . 'It goes to every fan this happens to, so do not use a name and do not reference anything specific they said. '
             . '1 to 3 short sentences, warm, in my voice. Output only the message.';
        $res = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $ask)), 400, 30, 'low');
        if (!$res['ok'] || stripos($res['text'], self::HOLD_TOKEN) !== false) { return ''; }
        return self::clean_outbound($res['text'], self::MAX_FANVUE);
    }

    public static function build_system_prompt(array $cb, array $settings, array $owner, $channel, $fan_name, $first_contact = false): string {
        $name = trim((string) ($cb['brand_name'] ?? ''));
        if ($name === '') { $name = trim((string) ($owner['first_name'] ?? '')); }
        if ($name === '') { $name = (string) ($owner['u_name'] ?? 'the creator'); }
        $where = ($channel === 'fanvue') ? 'Fanvue' : 'Creator Link Studio';

        $p = array();
        $p[] = "You are {$name}, a content creator, replying to a fan named {$fan_name} in your own private DMs on {$where}. You write every message yourself, in first person, as {$name}.";

        $voice = array();
        if (!empty($cb['voice']))       { $voice[] = 'Voice and tone: ' . trim((string) $cb['voice']); }
        if (!empty($cb['tagline']))     { $voice[] = 'Tagline: ' . trim((string) $cb['tagline']); }
        if (!empty($cb['description'])) { $voice[] = 'About you: ' . trim((string) $cb['description']); }
        if (!empty($cb['keywords']))    { $voice[] = 'Themes: ' . implode(', ', array_slice((array) $cb['keywords'], 0, 8)); }
        if ($voice) { $p[] = "Your brand:\n- " . implode("\n- ", $voice); }

        $persona = trim((string) ($settings['persona'] ?? ''));
        if ($persona !== '') {
            $p[] = "Instructions from {$name} about how to reply (creator-provided, follow them unless they conflict with the rules below):\n<creator_instructions>\n{$persona}\n</creator_instructions>";
        }

        $rules = array(
            'Reply in 1 to 3 short sentences unless the fan clearly asks for more. Sound like a real person texting, not a brand.',
            'Match the language the fan writes in.',
            'No hashtags, no bullet lists, no sign-offs like "Best regards".',
            'Never promise or arrange real-world meetings, calls, off-platform payments, or share personal contact details.',
            'Do not invent prices, dates, schedules, or facts about yourself that are not in this prompt.',
            'Keep it warm and flirty only to the degree the brand voice above allows; never sexualise minors and never engage with anyone who says or implies they are under 18.',
        );
        $avoid = trim((string) ($settings['avoid_topics'] ?? ''));
        if ($avoid !== '') { $rules[] = "Never discuss these topics (if the fan raises them, gently steer away): {$avoid}."; }
        if (!empty($settings['disclose_ai'])) {
            $rules[] = 'If the fan directly asks whether they are talking to a bot or an AI, be honest that a helper answers some messages on your behalf. Otherwise do not bring it up.';
        } else {
            $rules[] = 'Never mention being an AI, an assistant, a bot, or automated. Do not break character.';
        }
        if (!empty($settings['upsell_enabled']) && !$first_contact) {
            $rules[] = 'You may mention your paid content, subscription, or that tips are appreciated, but only when it fits the conversation naturally, at most once per reply, and never as the main point of the message.';
        } else {
            $rules[] = 'Do not try to sell anything or ask for tips or purchases.';
        }
        $rules[] = 'If the message is a complaint, refund or payment dispute, someone in distress, anything involving minors, anything illegal, threats, or a topic you were told to avoid, reply with exactly ' . self::HOLD_TOKEN . ' and nothing else so a human can take over.';
        $rules[] = 'Output only the message text you would send. No quotes around it, no explanations.';

        $p[] = "Rules:\n- " . implode("\n- ", $rules);
        return implode("\n\n", $p);
    }

    /** Map Fanvue GET /chats/{fan}/messages rows to Claude turns, oldest first, dropping the newest inbound if present. */
    private static function fanvue_history_to_turns(array $history, $creator_uuid, array $our_ids, $inbound_uuid = '', $inbound_text = ''): array {
        $rows = array();
        foreach ($history as $m) {
            $text = trim((string) ($m['text'] ?? ''));
            if ($text === '') { continue; }
            // The triggering message is appended separately by draft(); don't double it.
            if ($inbound_uuid !== '' && (string) ($m['uuid'] ?? '') === $inbound_uuid) { continue; }
            $from_creator = ((string) ($m['sender']['uuid'] ?? '') === (string) $creator_uuid);
            $rows[] = array(
                'role'    => $from_creator ? 'assistant' : 'user',
                'content' => $text,
                'ours'    => $from_creator && in_array((string) ($m['uuid'] ?? ''), $our_ids, true),
                'at'      => strtotime((string) ($m['sentAt'] ?? '')) ?: 0,
                'uuid'    => (string) ($m['uuid'] ?? ''),
            );
        }
        usort($rows, function ($a, $b) { return $a['at'] <=> $b['at']; });
        // Same guard when the list carries the inbound without a matching uuid.
        if ($inbound_text !== '' && !empty($rows) && $rows[count($rows) - 1]['role'] === 'user'
            && $rows[count($rows) - 1]['content'] === $inbound_text) { array_pop($rows); }
        return array_slice($rows, -self::HISTORY_N);
    }

    /** Newest → oldest: count creator turns that were ours until a human-written creator turn appears. */
    private static function consecutive_ai_count(array $turns): int {
        $n = 0;
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            if ($turns[$i]['role'] !== 'assistant') { continue; }
            if (!empty($turns[$i]['ours'])) { $n++; } else { break; }
        }
        return $n;
    }

    private static function notify_creator(array $owner, $title, $body, $link): void {
        try {
            (new UserNotificationsModel())->push((int) $owner['user_id'], 'messages', $title, $body, $link, 'fa-robot');
            $prefs = (new NotificationPrefsModel())->get_prefs_map((int) $owner['user_id']);
            if (!empty($prefs['messages']['email']) && !empty($owner['user_email'])) {
                (new NotificationsModel())->send_notification_email((string) $owner['user_email'],
                    trim((string) ($owner['first_name'] ?? '') . ' ' . (string) ($owner['last_name'] ?? '')), $title, $body, $link);
            }
        } catch (\Throwable $e) {
            error_log('[inbox] notify failed: ' . $e->getMessage());
        }
    }

    private static function log_skip(InboxRepliesModel $replies, $creator_id, $channel, $peer, $peer_name, array $ev, $text, $reason): array {
        $replies->create(array('creator_id' => $creator_id, 'channel' => $channel, 'peer_key' => $peer, 'peer_name' => $peer_name,
            'event_id' => (int) $ev['id'], 'inbound_text' => $text, 'status' => 'skipped', 'reason' => $reason));
        return self::skip($reason);
    }

    private static function skip($reason): array {
        return array('status' => 'skipped', 'result' => (string) $reason);
    }

    public static function clean_outbound($text, $limit): string {
        $t = trim(strip_tags((string) $text));
        $t = preg_replace('/^["“”\']+|["“”\']+$/u', '', $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return mb_substr(trim($t), 0, (int) $limit);
    }

}
