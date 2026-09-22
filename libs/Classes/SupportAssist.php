<?php
/**
 * AI Assist for staff answering a support request (/support/ticket/<id>). Claude drafts reply
 * options, or reworks the text staff already typed, from the request thread, the requester's
 * Diagnosis checks, the Quick Answers and the product facts. Never sends anything itself.
 */
class SupportAssist {

    /** Rework modes offered on the reply box (key => instruction). */
    const REWRITES = array(
        'shorter'  => 'Make it shorter: keep every fact and step, cut everything else.',
        'friendly' => 'Make it warmer and more reassuring without adding filler or new facts.',
        'detail'   => 'Add the concrete steps the customer needs, using only the facts given.',
        'grammar'  => 'Fix spelling, grammar and punctuation only. Change nothing else.',
    );

    private static function system_prompt(): string {
        $site = Main::site_name();
        return "You help the $site support team write replies to customer support requests. Write as the support team, in plain English: warm, direct, specific, second person, short paragraphs, no emoji, no headings, no markdown, no sign-off or signature, no subject line. Open with the answer or the next step, not with thanks or apologies boilerplate (one short acknowledgement is fine). Only state facts found in the request, the account checks, the how-to answers or the product facts. Never promise or claim an action (refund, credit adjustment, reset, cancellation) was taken unless the staff note says it was; if one would help, say what support can do or ask the customer to confirm. Never mention internal tools, diagnosis, checks, databases or that you are an AI. Never name the payment processor; say \"your bank\" or \"your card\". Amounts of credits are credits, never dollars (10 credits = \$1). Do not speculate beyond the facts. Support can only: adjust a credit balance, refund a one-time purchase (credits go back to the wallet), cancel a membership or creator plan, reset two-step sign-in, send a password reset, mark an email verified or resend the verification email; never offer anything else. Address the customer by first name when known.";
    }

    /** Everything Claude may rely on for this request. */
    public static function context(array $t, array $messages, array $u, array $checks): string {
        $name = trim((string) ($u['first_name'] ?? ''));
        $lines = array('Request: "' . (string) $t['subject'] . '"', 'Topic: ' . (SupportModel::CATEGORIES[$t['category']] ?? 'Something else'), 'Status: ' . (string) $t['status']);
        $lines[] = 'Customer: ' . ($name !== '' ? $name : '@' . (string) ($u['u_name'] ?? '')) . ' (@' . (string) ($u['u_name'] ?? '') . '), role ' . ((string) ($u['role_name'] ?? '') ?: 'User');
        $lines[] = '';
        $lines[] = 'Conversation so far, oldest first:';
        foreach ($messages as $m) {
            $lines[] = ($m['is_staff'] ? 'SUPPORT' : 'CUSTOMER') . ': ' . trim((string) $m['body']);
        }
        $lines[] = '';
        $lines[] = 'Account checks (facts about this customer\'s account right now):';
        foreach ($checks as $c) { $lines[] = '- ' . $c[0] . ': ' . $c[1] . ($c[2] === 'warn' ? ' (needs attention)' : ''); }
        $lines[] = '';
        $lines[] = 'How-to answers you may use:';
        foreach (SupportModel::QUICK_ANSWERS as $qa) { $lines[] = '- ' . $qa[0] . ' ' . $qa[1]; }
        $lines[] = '';
        $lines[] = 'Product facts:';
        try { $lines[] = SeoDrafter::product_context(); } catch (\Throwable $e) {}
        return implode("\n", $lines);
    }

    /**
     * $mode: 'draft' (three different reply options) or a REWRITES key (rework $current).
     * Returns array('ok' => bool, 'options' => [['label' => ..., 'text' => ...]], 'error' => '').
     */
    public static function run(string $mode, string $context, string $note, string $current): array {
        if (!ClaudeService::configured()) { return array('ok' => false, 'options' => array(), 'error' => 'AI Assist is not set up'); }
        $note = trim($note); $current = trim($current);
        if ($mode === 'draft') {
            $ask = "Write three different replies to the customer's latest message. Make them genuinely different approaches (for example: resolve it with steps, ask for the one detail needed, or explain what happens next), each fitting the facts. Label each with 2 to 4 words in sentence case describing the approach."
                . ($note !== '' ? "\n\nStaff note, must shape every option: $note" : '')
                . ($current !== '' ? "\n\nStaff started writing this; build on it:\n$current" : '')
                . "\n\nReturn ONLY JSON: {\"options\":[{\"label\":\"...\",\"text\":\"...\"},{...},{...}]}";
        } elseif (isset(self::REWRITES[$mode])) {
            if ($current === '') { return array('ok' => false, 'options' => array(), 'error' => 'Write a reply first'); }
            $ask = self::REWRITES[$mode] . ($note !== '' ? "\nStaff note: $note" : '') . "\n\nReply to rework:\n$current\n\nReturn ONLY JSON: {\"options\":[{\"label\":\"Reworked\",\"text\":\"...\"}]}";
        } else {
            return array('ok' => false, 'options' => array(), 'error' => 'Unknown assist action');
        }
        $r = ClaudeService::chat(self::system_prompt(), array(array('role' => 'user', 'content' => $context . "\n\n---\n\n" . $ask)), 2500, 90, 'low');
        if (empty($r['ok'])) { return array('ok' => false, 'options' => array(), 'error' => 'AI Assist could not reach the model. Try again.'); }
        $d = SeoDrafter::parse_json($r['text']);
        $out = array();
        foreach ((array) ($d['options'] ?? array()) as $o) {
            $text = trim((string) ($o['text'] ?? ''));
            if ($text === '') { continue; }
            $out[] = array('label' => mb_substr(trim((string) ($o['label'] ?? '')), 0, 40), 'text' => mb_substr($text, 0, 5000));
        }
        if (empty($out)) { return array('ok' => false, 'options' => array(), 'error' => 'AI Assist returned nothing usable. Try again.'); }
        return array('ok' => true, 'options' => array_slice($out, 0, 3), 'error' => '');
    }
}
