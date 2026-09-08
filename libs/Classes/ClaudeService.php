<?php
/**
 * Small Claude Messages API client (raw cURL, matching BrandService — the repo has
 * no Anthropic SDK). One entry point, chat(), that takes a system prompt and a
 * multi-turn message list and returns the first text block. Never throws.
 *
 * Model: app.ini [global] inbox_claude_model, else claude-opus-5. Thinking is
 * adaptive by default on Opus 5; `output_config.effort` keeps DM replies fast.
 */
class ClaudeService {

    const API           = 'https://api.anthropic.com/v1/messages';
    const API_VERSION   = '2023-06-01';
    const DEFAULT_MODEL = 'claude-opus-5';

    private static function api_key(): string {
        $cfg = Main::get_config();
        $k = (string) ($cfg['global']['anthropic_api_key'] ?? '');
        if ($k === '') { $k = (string) ($cfg['global']['claude_api_key'] ?? ''); }
        return $k;
    }

    public static function configured(): bool {
        return self::api_key() !== '';
    }

    public static function model(): string {
        $cfg = Main::get_config();
        $m = trim((string) ($cfg['global']['inbox_claude_model'] ?? ''));
        return $m !== '' ? $m : self::DEFAULT_MODEL;
    }

    /**
     * @param string $system     System prompt ('' for none).
     * @param array  $messages   [['role'=>'user'|'assistant','content'=>string], ...]
     * @param int    $max_tokens
     * @param int    $timeout    seconds
     * @param string $effort     low | medium | high
     * @return array ['ok'=>bool, 'text'=>string, 'stop_reason'=>string, 'error'=>string]
     */
    public static function chat($system, array $messages, $max_tokens = 1024, $timeout = 30, $effort = 'low'): array {
        $key = self::api_key();
        if ($key === '') { return self::fail('Claude API key is not configured'); }

        $messages = self::normalize($messages);
        if (empty($messages)) { return self::fail('No messages to send'); }

        $body = array(
            'model'         => self::model(),
            'max_tokens'    => max(64, (int) $max_tokens),
            'messages'      => $messages,
            'output_config' => array('effort' => in_array($effort, array('low', 'medium', 'high'), true) ? $effort : 'low'),
        );
        if (trim((string) $system) !== '') { $body['system'] = (string) $system; }

        $ch = curl_init(self::API);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => max(5, (int) $timeout),
            CURLOPT_HTTPHEADER     => array(
                'x-api-key: ' . $key,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ),
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ));
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('[claude] transport: ' . $err);
            return self::fail('Claude request failed (network)');
        }
        $d = json_decode($raw, true);
        if ($code >= 400 || !is_array($d)) {
            $msg = is_array($d) ? (string) ($d['error']['message'] ?? '') : '';
            error_log('[claude] http ' . $code . ': ' . substr((string) $raw, 0, 300));
            return self::fail('Claude request failed (HTTP ' . $code . ')' . ($msg !== '' ? ': ' . $msg : ''));
        }
        $text = '';
        foreach ((array) ($d['content'] ?? array()) as $block) {
            if (($block['type'] ?? '') === 'text') { $text = (string) $block['text']; break; }
        }
        return array(
            'ok'          => true,
            'text'        => trim($text),
            'stop_reason' => (string) ($d['stop_reason'] ?? ''),
            'error'       => '',
        );
    }

    /** Drop empties, merge consecutive same-role turns, ensure the list starts and ends with a user turn. */
    private static function normalize(array $messages): array {
        $out = array();
        foreach ($messages as $m) {
            $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $text = trim((string) ($m['content'] ?? ''));
            if ($text === '') { continue; }
            if (!empty($out) && $out[count($out) - 1]['role'] === $role) {
                $out[count($out) - 1]['content'] .= "\n\n" . $text;
            } else {
                $out[] = array('role' => $role, 'content' => $text);
            }
        }
        while (!empty($out) && $out[0]['role'] !== 'user') { array_shift($out); }
        while (!empty($out) && $out[count($out) - 1]['role'] !== 'user') { array_pop($out); }
        return $out;
    }

    private static function fail($error): array {
        return array('ok' => false, 'text' => '', 'stop_reason' => '', 'error' => (string) $error);
    }

}
