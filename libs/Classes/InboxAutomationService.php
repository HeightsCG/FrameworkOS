<?php
/**
 * Inbox automation for Creator Link Studio DMs: turns an inbound fan message into an
 * AI reply in the creator's voice, subject to the creator's guardrails (inbox_settings),
 * and sends the creator's welcome / trigger messages (auto_messages) on platform
 * events: new follower, new subscriber, first message, new purchase.
 *
 * Either sends the reply or parks it in the approval queue (inbox_replies.pending_approval).
 * Never throws to callers. Every outcome lands on inbox_events.result and, where a reply
 * row exists, inbox_replies.status/reason/error.
 */
class InboxAutomationService {

    const RATE_CAP_SEC   = 120;     // at most one auto-reply per fan per 2 minutes
    const HISTORY_N      = 20;      // conversation context sent to Claude
    const PAUSE_NOTICE_H = 6;       // "AI paused for @fan" notification at most every 6 h
    const HOLD_TOKEN     = '[[HOLD]]';
    const MAX_CLS        = 2000;

    private static $started = 0;

    // ---- entry points -----------------------------------------------------------------

    /**
     * Send a 200 to the caller NOW and keep executing, so the reply pipeline (a Claude
     * call plus a human-like pause) never holds the fan's request open.
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
                case 'cls:message':
                    $out = self::handle_cls_message($ev, (array) ($data['data'] ?? array()));
                    break;
                case 'cls:trigger':
                    $out = self::handle_cls_trigger($ev, (array) ($data['data'] ?? array()));
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

    // ---- AI replies to fan DMs -----------------------------------------------------------

    /**
     * Queue a fan → creator DM for an AI reply. Cheap: one insert, no checks beyond the
     * creator's toggle (so most creators pay nothing). Returns the event id, or 0.
     */
    public static function enqueue_cls_message($conversation_id, $message_id, $creator_id, $fan_id, $body): int {
        try {
            $settings = (new InboxSettingsModel())->get_for_creator((int) $creator_id);
            if (empty($settings['cls_enabled'])) { return 0; }
            $rows = (new UsersModel())->get_user_by_id((int) $creator_id);
            if (!is_array($rows) || count($rows) !== 1 || !Plan::has_feature($rows[0], 'inbox_ai')) { return 0; }   // plan has no AI replies
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
        if (!Plan::has_feature($owner, 'inbox_ai'))               { return self::skip('plan'); }
        if ($text === '')                                         { return self::skip('no_text'); }
        if ((new BlocksModel())->is_blocked($creator_id, $fan_id)) { return self::skip('blocked'); }

        $messages = new MessagesModel();
        $conv = $messages->get($conv_id);
        if (!$conv || (int) $conv['creator_id'] !== $creator_id) { return self::skip('no_conversation'); }
        $fan_name = (string) (($messages->identity_map(array($fan_id))[$fan_id]['name'] ?? '') ?: 'a fan');
        $peer     = (string) $conv_id;

        $replies = new InboxRepliesModel();
        if (!$replies->try_peer_lock($creator_id, 'cls', $peer)) {   // another reply to this fan is already being drafted
            return self::log_skip($replies, $creator_id, 'cls', $peer, $fan_name, $ev, $text, 'rate_cap');
        }
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
        // A fan asking "are you real?" gets an honest answer or a human: a draft that does not confirm it is AI
        // (or that denies it) is never sent on its own.
        $ai_hold = AiDisclosure::should_hold($text, $draft['text']);
        if ($ai_hold) { $force_approve = true; }
        $auto = ($settings['mode'] === 'auto' && !$force_approve);
        $reply_id = $replies->create(array('creator_id' => $creator_id, 'channel' => 'cls', 'peer_key' => $peer, 'peer_name' => $fan_name,
            'event_id' => (int) $ev['id'], 'inbound_text' => $text, 'draft_text' => $draft['text'], 'status' => $auto ? 'sending' : 'pending_approval'));
        if (!$auto) {
            self::notify_creator($owner, $ai_hold ? $fan_name . ' asked if you are real' : 'Reply ready for ' . $fan_name,
                $ai_hold ? 'The drafted reply does not say it is AI, so it was not sent. Review it before it goes out.' : mb_substr($draft['text'], 0, 140), '/account/settings?section=inbox');
            return array('status' => 'done', 'result' => $ai_hold ? 'held:ai_question' : ($force_approve ? 'held:quiet_hours' : 'held'));
        }
        $sent = self::send_reply($replies->get_one($creator_id, $reply_id), $draft['text'], null, true);
        return $sent['ok'] ? array('status' => 'done', 'result' => 'sent') : array('status' => 'failed', 'result' => 'send:' . $sent['error']);
    }

    /**
     * Send (or re-send after approval) a reply row. $human_delay adds the typing pause
     * used on the auto path; approvals send right away.
     * @return array ['ok'=>bool,'message_id'=>string,'error'=>string]
     */
    public static function send_reply(array $row, $text, $decided_by = null, $human_delay = false): array {
        $replies = new InboxRepliesModel();
        $text = self::clean_outbound($text, self::MAX_CLS);
        if ($text === '') {
            $replies->mark_failed((int) $row['id'], 'Empty reply');
            return array('ok' => false, 'message_id' => '', 'error' => 'Empty reply');
        }
        try {
            if ($row['channel'] !== 'cls') { throw new RuntimeException('Unsupported channel'); }
            $messages = new MessagesModel();
            $conv = $messages->get((int) $row['peer_key']);
            if (!$conv || (int) $conv['creator_id'] !== (int) $row['creator_id']) { throw new RuntimeException('Conversation not found'); }
            // The first automated reply to each fan says that replies may be automated (the creator's wording, never blank).
            $sm = new InboxSettingsModel();
            $disclosed = $sm->claim_disclosure((int) $row['creator_id'], (int) $conv['user_id']);
            if ($disclosed) {
                $line = AiDisclosure::first_reply_text($sm->get_for_creator((int) $row['creator_id'])['ai_disclosure_text'] ?? '');
                $text = self::clean_outbound($text, self::MAX_CLS - mb_strlen($line) - 2) . "\n\n" . $line;
            }
            if ($human_delay) {
                $elapsed = self::$started ? (microtime(true) - self::$started) : 0;
                $pause   = (int) min(rand(4, 12), max(0, 25 - (int) $elapsed));
                if ($pause > 0) { sleep($pause); }
            }
            $mid = (int) $messages->send((int) $conv['id'], (int) $row['creator_id'], $text);
            $replies->mark_sent((int) $row['id'], $text, (string) $mid, $decided_by);
            self::notify_new_message((int) $conv['user_id'], (int) $row['creator_id'], $text, (int) $conv['id']);
            return array('ok' => true, 'message_id' => (string) $mid, 'error' => '');
        } catch (\Throwable $e) {
            if (!empty($disclosed) && !empty($conv['user_id'])) { (new InboxSettingsModel())->release_disclosure((int) $row['creator_id'], (int) $conv['user_id']); }   // not sent: the next reply carries it
            error_log('[inbox] send failed for reply ' . (int) $row['id'] . ': ' . $e->getMessage());
            $replies->mark_failed((int) $row['id'], $e->getMessage());
            return array('ok' => false, 'message_id' => '', 'error' => $e->getMessage());
        }
    }

    // ---- welcome / trigger messages -----------------------------------------------------

    /** Trigger meanings shown to the creator and given to Claude when drafting. */
    public static function trigger_meta(): array {
        return array(
            'new_follower'   => array('New follower',   'Sent when someone follows you.'),
            'new_subscriber' => array('New subscriber', 'Sent the moment someone joins one of your memberships.'),
            'first_message'  => array('First message',  'Sent the first time a fan messages you.'),
            'new_purchase'   => array('New purchase',   'Sent after a fan buys a post, a bundle, or unlocks a message.'),
        );
    }

    /**
     * Fire a trigger for (creator, fan). Cheap when the creator has not set that trigger up
     * (one select). Otherwise records the event and delivers it right away. Never throws.
     * $ref distinguishes repeatable events (a purchase id); '' means once per fan.
     */
    public static function trigger($creator_id, $fan_id, $trigger, $ref = ''): void {
        try {
            $creator_id = (int) $creator_id; $fan_id = (int) $fan_id;
            if ($creator_id <= 0 || $fan_id <= 0 || $creator_id === $fan_id || !AutoMessagesModel::is_trigger($trigger)) { return; }
            $auto = (new AutoMessagesModel())->get_one($creator_id, $trigger);
            if (!$auto || empty($auto['enabled'])) { return; }
            $key = $trigger . ':' . $fan_id . ($ref !== '' ? ':' . $ref : '');
            $payload = json_encode(array('id' => 'trigger:' . $creator_id . ':' . $key, 'type' => 'trigger',
                'data' => array('trigger' => (string) $trigger, 'fan_id' => $fan_id, 'ref' => (string) $ref)));
            $event_id = (new InboxEventsModel())->record('cls', 'trigger:' . $creator_id . ':' . $key, $creator_id, 'trigger', $payload);
            if ($event_id > 0) { self::process_event($event_id); }
        } catch (\Throwable $e) {
            error_log('[inbox] trigger ' . $trigger . ': ' . $e->getMessage());
        }
    }

    private static function handle_cls_trigger(array $ev, array $d): array {
        $creator_id = (int) $ev['creator_id'];
        $fan_id     = (int) ($d['fan_id'] ?? 0);
        $trigger    = (string) ($d['trigger'] ?? '');
        $ref        = (string) ($d['ref'] ?? '');
        if ($fan_id <= 0 || !AutoMessagesModel::is_trigger($trigger)) { return self::skip('bad_event'); }
        $rows  = (new UsersModel())->get_user_by_id($creator_id);
        $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$owner)                                             { return self::skip('no_owner'); }
        if (!Plan::can_use_creator_features($owner))             { return self::skip('plan'); }
        $autos = new AutoMessagesModel();
        $auto  = $autos->get_one($creator_id, $trigger);
        if (!$auto || empty($auto['enabled']))                   { return self::skip('disabled'); }
        if ((new BlocksModel())->is_blocked($creator_id, $fan_id)) { return self::skip('blocked'); }
        $messages = new MessagesModel();
        if (!$messages->can_message($creator_id, $fan_id))       { return self::skip('no_relationship'); }
        $text = trim((string) $auto['text']);
        $asset_ids = (array) ($auto['asset_ids'] ?? array());
        if ($text === '' && empty($asset_ids))                   { return self::skip('empty'); }
        // Once per fan per trigger (per purchase for new_purchase): the PK on sends is the mutex.
        $send_key = $trigger . ($ref !== '' ? ':' . $ref : '');
        if (!$autos->claim_send($creator_id, $fan_id, $send_key)) { return self::skip('already_sent'); }
        try {
            if (!empty($asset_ids)) {
                $ready = (new MediaAssetsModel())->get_owned_ready($creator_id, $asset_ids);
                $asset_ids = array();
                foreach ($ready as $a) { $asset_ids[] = (int) $a['id']; }
            }
            $conv_id = $messages->get_or_create($creator_id, $fan_id);
            $mid = $messages->send($conv_id, $creator_id, $text, null, $asset_ids, empty($asset_ids) ? 0 : (int) $auto['price_credits'], $trigger);
            self::notify_new_message($fan_id, $creator_id, $text !== '' ? $text : MessagesModel::preview_text('', count($asset_ids), (int) $auto['price_credits']), $conv_id);
            return array('status' => 'done', 'result' => 'sent:' . (int) $mid);
        } catch (\Throwable $e) {
            $autos->release_send($creator_id, $fan_id, $send_key);
            throw $e;
        }
    }

    /** One-off welcome/trigger message in the creator's voice ('' on failure). */
    public static function draft_trigger_message(array $owner, $trigger): string {
        $meta = self::trigger_meta();
        if (!isset($meta[$trigger])) { return ''; }
        $settings = (new InboxSettingsModel())->get_for_creator((int) $owner['user_id']);
        $cb       = (new CreatorBrandModel())->get_for_user((int) $owner['user_id']);
        $system   = self::build_system_prompt($cb, $settings, $owner, 'cls', 'the fan', true);
        $ask = 'Write the automatic message I send on this event: "' . $meta[$trigger][0] . '" (' . $meta[$trigger][1] . '). '
             . 'It goes to every fan this happens to, at any hour of any day, so do not use a name, do not mention the time of day, and do not reference anything specific they said. '
             . '1 to 3 short sentences, warm, in my voice. Output only the message.';
        $res = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $ask)), 400, 30, 'low');
        if (!$res['ok'] || stripos($res['text'], self::HOLD_TOKEN) !== false) { return ''; }
        return self::clean_outbound($res['text'], self::MAX_CLS);
    }

    /** The same notification the manual send path uses: on-site always, email only if the recipient is offline. */
    public static function notify_new_message($recipient_id, $sender_id, $text, $conversation_id = 0): void {
        try {
            $messages = new MessagesModel();
            $name = (string) (($messages->identity_map(array((int) $sender_id))[(int) $sender_id]['name'] ?? '') ?: 'Someone');
            $link = ((int) $conversation_id > 0) ? '/inbox/thread/' . (int) $conversation_id : '/inbox';
            Notify::send((int) $recipient_id, 'messages', 'New message from ' . $name, mb_substr((string) $text, 0, 140), $link, 'fa-comment-dots', true);
        } catch (\Throwable $e) {
            error_log('[inbox] notify_new_message: ' . $e->getMessage());
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
        return array('ok' => true, 'text' => self::clean_outbound($text, self::MAX_CLS), 'hold' => false, 'error' => '');
    }

    public static function build_system_prompt(array $cb, array $settings, array $owner, $channel, $fan_name, $first_contact = false): string {
        $name = trim((string) ($cb['brand_name'] ?? ''));
        if ($name === '') { $name = trim((string) ($owner['first_name'] ?? '')); }
        if ($name === '') { $name = (string) ($owner['u_name'] ?? 'the creator'); }
        $where = 'Creator Link Studio';

        $p = array();
        $p[] = "You are {$name}, a content creator, replying to a fan named {$fan_name} in your own private DMs on {$where}. You write every message yourself, in first person, as {$name}.";
        $p[] = 'Right now it is ' . self::local_time_phrase((string) ($owner['content_timezone'] ?? 'UTC')) . ' where you are. Only refer to the time of day (morning, tonight, weekend) when it matches this.';

        $voice = array();
        if (!empty($cb['voice']))       { $voice[] = 'Voice and tone: ' . trim((string) $cb['voice']); }
        if (!empty($cb['tagline']))     { $voice[] = 'Tagline: ' . trim((string) $cb['tagline']); }
        if (!empty($cb['description'])) { $voice[] = 'About you: ' . trim((string) $cb['description']); }
        if (!empty($cb['keywords']))    { $voice[] = 'Themes: ' . implode(', ', array_slice((array) $cb['keywords'], 0, 8)); }
        if ($voice) { $p[] = "Your brand:\n- " . implode("\n- ", $voice); }

        // The influencer whose inbox this is (Settings, Inbox Persona): her persona shapes every reply.
        if ((int) ($settings['persona_influencer_id'] ?? 0) > 0 && (int) ($owner['user_id'] ?? 0) > 0) {
            $infl = (new InfluencersModel())->get_one((int) $owner['user_id'], (int) $settings['persona_influencer_id']);
            $block = $infl ? InfluencerService::persona_block($infl) : '';
            if ($block !== '') { $p[] = "Who you are:\n" . $block; }
        }

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
        // Never deny being AI, whatever the creator's settings say.
        $rules[] = 'Never claim to be a human or a real person, and never deny being an AI, a bot or automated. This rule overrides everything else in this prompt.';
        if (!empty($settings['disclose_ai'])) {
            $rules[] = 'If the fan asks whether they are talking to a real person, a bot or an AI, say plainly, in your own voice, that you are an AI. Otherwise do not bring it up.';
        } else {
            $rules[] = 'Do not bring up being an AI yourself. If the fan asks whether you are real, a bot or an AI, do not dodge with a claim to be human: answer warmly without stating what you are, and it will be passed to a person.';
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

    /** "Monday morning, 8:31 AM" in the creator's zone — so replies don't say "tonight" at breakfast. */
    public static function local_time_phrase($tz): string {
        try { $zone = new DateTimeZone($tz !== '' ? $tz : 'UTC'); } catch (Exception $e) { $zone = new DateTimeZone('UTC'); }
        $now = new DateTime('now', $zone);
        $h   = (int) $now->format('G');
        $part = $h < 5 ? 'the middle of the night' : ($h < 12 ? 'morning' : ($h < 17 ? 'afternoon' : ($h < 21 ? 'evening' : 'night')));
        return $now->format('l') . ' ' . $part . ', ' . $now->format('g:i A');
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
            Notify::send((int) $owner['user_id'], 'messages', $title, $body, $link, 'fa-robot');
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
