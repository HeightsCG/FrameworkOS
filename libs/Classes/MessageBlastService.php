<?php
/**
 * Scheduled mass messages — the `kind = 'message'` flavour of a Scheduler rule.
 * Same contract as AutoPostService::run_rule() so the worker and "Run now" can
 * dispatch on kind without caring which one they got: never throws, returns
 * ['ok' => bool, 'post_id' => null, 'message' => string].
 *
 * Text is either the rule's message_text or, with message_ai = 1, generated from
 * the rule's topic in the creator's brand voice. Targets: Fanvue smart lists
 * (POST /chats/mass-messages) and/or a Creator Link Studio broadcast segment.
 */
class MessageBlastService {

    public static function run_rule(array $rule, array $user){
        try {
            if (!Plan::can_use_creator_features($user)) {
                return self::fail('Your plan does not include scheduled messages.');
            }
            $targets = SchedulerRulesModel::targets($rule);
            if (empty($targets['fanvue']) && $targets['cls'] === '') {
                return self::fail('No audience selected for this message.');
            }

            $text = trim((string) ($rule['message_text'] ?? ''));
            if (!empty($rule['message_ai'])) {
                $text = self::generate($user, (string) ($rule['topic'] ?? ''));
                if ($text === '') { return self::fail('Could not write the message. Check the topic and try again.'); }
            }
            if ($text === '') { return self::fail('The message is empty.'); }

            $parts = array(); $errors = array();

            if (!empty($targets['fanvue'])) {
                $accounts = new FanvueAccountsModel();
                $account  = $accounts->get_connected_for_user((int) $user['user_id']);
                if (!$account || !FanvueAccountsModel::has_chat_scope($account)) {
                    $errors[] = 'Fanvue: reconnect in Settings > Integrations to grant inbox access.';
                } else {
                    $token = FanvueService::access_token_for($account);
                    if ($token === '') {
                        $errors[] = 'Fanvue: session expired, reconnect in Settings > Integrations.';
                    } else {
                        try {
                            FanvueService::create_mass_message($token, $text, $targets['fanvue']);
                            $parts[] = 'Sent on Fanvue to ' . implode(', ', array_map(function ($l) { return str_replace('_', ' ', $l); }, $targets['fanvue'])) . '.';
                            $accounts->set_error((int) $user['user_id'], '');
                        } catch (\Throwable $e) {
                            $errors[] = $e->getMessage();
                            $accounts->set_error((int) $user['user_id'], $e->getMessage());
                        }
                    }
                }
            }

            if ($targets['cls'] !== '') {
                try {
                    list($bid, $n) = (new BroadcastsModel())->create_and_send((int) $user['user_id'], $targets['cls'], mb_substr($text, 0, 2000));
                    $parts[] = ($n > 0) ? ('Sent to ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . ' on Creator Link Studio.') : 'No one to send to on Creator Link Studio yet.';
                } catch (\Throwable $e) {
                    $errors[] = 'Creator Link Studio: ' . $e->getMessage();
                }
            }

            $msg = trim(implode(' ', $parts) . (empty($errors) ? '' : ' ' . implode(' ', $errors)));
            return array('ok' => !empty($parts) && empty($errors), 'post_id' => null, 'message' => $msg !== '' ? $msg : 'Nothing was sent.');
        } catch (\Throwable $e) {
            error_log('[blast] rule ' . (int) ($rule['id'] ?? 0) . ': ' . $e->getMessage());
            return self::fail($e->getMessage());
        }
    }

    /** Brand-voice mass message from a topic ('' on failure). */
    public static function generate(array $owner, $topic): string {
        $topic = trim((string) $topic);
        if ($topic === '' || !ClaudeService::configured()) { return ''; }
        $settings = (new InboxSettingsModel())->get_for_creator((int) $owner['user_id']);
        $cb       = (new CreatorBrandModel())->get_for_user((int) $owner['user_id']);
        $system   = InboxAutomationService::build_system_prompt($cb, $settings, $owner, 'fanvue', 'your fans', true);
        $ask = 'Write one message to send to all of my fans at once about: ' . $topic . '. '
             . 'It goes to many people, so no names and nothing that only fits one person. '
             . '2 to 4 short sentences, in my voice. Output only the message.';
        $res = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $ask)), 500, 30, 'low');
        if (!$res['ok'] || stripos($res['text'], InboxAutomationService::HOLD_TOKEN) !== false) { return ''; }
        return InboxAutomationService::clean_outbound($res['text'], 5000);
    }

    private static function fail($message){
        return array('ok' => false, 'post_id' => null, 'message' => (string) $message);
    }

}
