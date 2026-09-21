<?php
/**
 * Drafts one blog article for a keyword with Claude, validates it against hard rules, and saves it for
 * Admin review (spec §5). Never publishes. One Claude call per draft, one retry on validation failure.
 */
class SeoDrafter {
    const PROMPT_VERSION = 'v1';
    const MIN_WORDS = 1000;
    const MAX_WORDS = 2000;

    /** Relative paths an article may link to: product pages + published articles. */
    public static function allowed_paths(): array {
        $paths = array('/', '/blog');
        foreach (SeoController::public_pages() as $p) { $paths[] = $p['path']; }
        foreach (SeoMeta::internal_links() as $k => $v) { $paths[] = preg_replace('/[#?].*$/', '', is_array($v) ? (string) ($v['path'] ?? '') : (string) $v); }   // validate() compares bare paths
        try { foreach ((new SeoArticlesModel())->published(200, 0) as $a) { $paths[] = '/blog/' . $a['slug']; } } catch (\Throwable $e) {}
        return array_values(array_unique(array_filter($paths)));
    }

    public static function slugify($s): string {
        $s = strtolower(trim((string) $s));
        $s = preg_replace("/['\x{2019}]/u", '', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim(mb_substr($s, 0, 120), '-');
    }

    public static function reading_minutes($md): int {
        return max(1, (int) ceil(Markdown::word_count($md) / 230));
    }

    /** Accepts a raw model reply; strips ``` fences and returns the decoded object or null. */
    public static function parse_json($text){
        $t = trim((string) $text);
        $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
        $t = preg_replace('/\s*```$/', '', $t);
        $d = json_decode($t, true);
        if (!is_array($d)) { $s = strpos($t, '{'); $e = strrpos($t, '}'); if ($s !== false && $e !== false && $e > $s) { $d = json_decode(substr($t, $s, $e - $s + 1), true); } }
        return is_array($d) ? $d : null;
    }

    /** Hard rules. Returns error strings; empty array = valid. */
    public static function validate(array $a, int $except_id = 0): array {
        $err = array();
        $title = trim((string) ($a['title'] ?? '')); $slug = (string) ($a['slug'] ?? ''); $meta = trim((string) ($a['meta_description'] ?? ''));
        $body = (string) ($a['body_md'] ?? ''); $faq = (array) ($a['faq'] ?? array());
        if ($title === '' || mb_strlen($title) > 70) { $err[] = 'title must be 1-70 characters'; }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) < 3 || strlen($slug) > 120) { $err[] = 'slug must be lowercase a-z 0-9 and hyphens, 3-120 chars'; }
        if ($meta === '' || mb_strlen($meta) > 155) { $err[] = 'meta_description must be 1-155 characters'; }
        $words = Markdown::word_count($body);
        if ($words < self::MIN_WORDS || $words > self::MAX_WORDS) { $err[] = "body must be " . self::MIN_WORDS . '-' . self::MAX_WORDS . " words (got $words)"; }
        if (Markdown::headings($body, 2) < 3) { $err[] = 'body needs at least 3 "## " sections'; }
        $allowed = self::allowed_paths();
        foreach (Markdown::links($body) as $l) {
            $path = preg_replace('/[#?].*$/', '', $l);
            if (strpos($l, '/') !== 0 || strpos($l, '//') === 0) { $err[] = "external link not allowed: $l"; }
            elseif (!in_array($path, $allowed, true)) { $err[] = "unknown internal link: $l"; }
        }
        $all = $title . ' ' . $meta . ' ' . $body . ' ' . json_encode($faq, JSON_UNESCAPED_UNICODE);
        if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F000}-\x{1F2FF}]/u', $all)) { $err[] = 'emoji not allowed'; }
        if (preg_match('/\bas an ai\b/i', $all)) { $err[] = 'model self-reference ("As an AI") not allowed'; }
        if (preg_match('/<\/?[a-z][^>]*>/i', $body)) { $err[] = 'raw HTML not allowed in body'; }
        $n = 0; foreach ($faq as $f) { if (is_array($f) && trim((string) ($f['q'] ?? '')) !== '' && trim((string) ($f['a'] ?? '')) !== '') { $n++; } }
        if ($n < 3 || $n > 5) { $err[] = 'faq needs 3-5 question/answer pairs'; }
        try { if ($slug !== '' && (new SeoArticlesModel())->slug_exists($slug, $except_id)) { $err[] = 'slug already exists'; } } catch (\Throwable $e) {}
        return $err;
    }

    /** Facts Claude may use; nothing else is allowed to appear as a number or a claim. */
    private static function context(): string {
        $site = Main::site_name(); $base = SeoMeta::base();
        $lines = array("Product: $site ($base). A creator platform: one public page at $base/@handle with posts, membership tiers, pay-per-view posts, content bundles, services, events and tracked links; a studio that publishes to nine social networks with AI captions; an inbox with AI replies; Stripe Connect payouts to the creator's bank; fans pay memberships by card and everything else with a credit wallet (\$1 = 10 credits).");
        foreach (PagesController::our_facts() as $k => $v) { $lines[] = ucfirst($k) . ': ' . $v; }
        foreach (PagesController::pricing_rows() as $r) {
            $t = (array) ($r['tier'] ?? array()); $lim = (array) ($t['limits'] ?? array());
            if (empty($t['name'])) { continue; }
            $price = isset($r['amount']) && $r['amount'] !== null ? ('$' . number_format(((int) $r['amount']) / 100) . '/' . (string) ($r['interval'] ?? 'month')) : 'price shown at checkout';
            $lines[] = 'Plan ' . $t['name'] . ': ' . $price . ', take rate ' . (int) ($lim['fee_percent'] ?? 0) . '%, ' . (int) ($lim['seats'] ?? 1) . ' seat(s), ' . (int) ($lim['ai_credits'] ?? 0) . ' AI credits/month, ' . (int) ($lim['automations'] ?? 0) . ' automations, ' . (int) ($lim['storage_gb'] ?? 0) . ' GB storage.';
        }
        $lines[] = 'Pages you may link to (relative paths only):';
        foreach (SeoController::public_pages() as $p) { $lines[] = '- ' . $p['path'] . ' — ' . $p['title'] . ': ' . $p['description']; }
        try {
            $recent = (new SeoArticlesModel())->published(8, 0);
            if (!empty($recent)) { $lines[] = 'Published articles you may link to (and must not repeat):'; foreach ($recent as $a) { $lines[] = '- /blog/' . $a['slug'] . ' — ' . $a['title'] . ': ' . (string) $a['excerpt']; } }
        } catch (\Throwable $e) {}
        $lines[] = 'Competitors you may name only as "other subscription platforms" — no fees, no claims: ' . implode(', ', array_column(PagesController::COMPETITORS, 'name')) . '.';
        return implode("\n", $lines);
    }

    private static function system_prompt(): string {
        $site = Main::site_name();
        return "You write practical, plain-English guides for the $site blog, read by independent creators who sell content, memberships and services online. Voice: direct, specific, second person, no hype, no filler, no emoji, sentence-case headings. Never invent statistics, studies, quotes, prices, fees or competitor facts; use only the facts in the context. A worked example with round, clearly hypothetical numbers (\"say you want \$1,000 a month from 50 members\") is fine when framed as an example; never present invented numbers as real data or averages. Never mention being an AI. Mention $site naturally at most three times, only where it genuinely helps, and link to its pages using the relative paths given. No external links. Output ONLY a JSON object with keys: title (<=70 chars, sentence case), slug (lowercase-hyphenated, <=80 chars), meta_description (<=155 chars), excerpt (one or two sentences), body_md (Markdown, 1200-1800 words, at least four \"## \" sections, some \"### \" subsections, one relative link per section from the allowed list, no H1, no raw HTML), faq (array of 3-5 {\"q\",\"a\"} objects answering real search questions), secondary_keywords (array of 3-6 short phrases).";
    }

    /**
     * Draft for one keyword row. $note = Admin's rewrite instruction (appended to the request);
     * $existing_article_id = replace that article's content instead of creating a new row.
     */
    public static function draft(array $kw, string $note = '', int $existing_article_id = 0): array {
        $keywords = new SeoKeywordsModel(); $articles = new SeoArticlesModel();
        $kid = (int) $kw['id']; $keyword = (string) $kw['keyword'];
        $existing = $existing_article_id > 0 ? $articles->get($existing_article_id) : null;
        $prev = $keywords->get($kid); $prev_status = (string) ($prev['status'] ?? 'queued');   // restored if a rewrite fails
        $keywords->set_status($kid, 'drafting');
        $user = "Target keyword: \"$keyword\"" . (!empty($kw['volume']) ? " (about {$kw['volume']} searches/month)" : '') . ".\n\nContext (the only facts you may use):\n" . self::context();
        if ($note !== '') { $user .= "\n\nEditor's note for this rewrite: $note"; }
        if ($existing) {
            $user .= "\n\nCurrent draft to revise (keep what works, apply the editor's note, return the full article):\nTitle: " . (string) $existing['title'] . "\n\n" . (string) $existing['body_md'];
        }
        $errors = array(); $data = null; $model = ClaudeService::model();
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $msg = $user . ($attempt === 2 && !empty($errors) ? "\n\nYour previous draft failed these checks; fix every one and return the full JSON again:\n- " . implode("\n- ", $errors) : '');
            $r = ClaudeService::chat(self::system_prompt(), array(array('role' => 'user', 'content' => $msg)), 8000, 240, 'medium');
            if (empty($r['ok'])) { $errors = array('Claude: ' . (string) ($r['error'] ?? 'request failed')); continue; }
            $data = self::parse_json($r['text']);
            if ($data === null) { $errors = array('reply was not valid JSON'); continue; }
            $data['slug'] = $existing ? (string) $existing['slug'] : self::slugify((string) ($data['slug'] ?? $data['title'] ?? $keyword));   // a rewrite never moves the URL
            if ($existing_article_id === 0) { $base = $data['slug']; $i = 2; while ((new SeoArticlesModel())->slug_exists($data['slug'])) { $data['slug'] = $base . '-' . $i++; } }
            $errors = self::validate($data, $existing_article_id);
            if (empty($errors)) { break; }
        }
        if (!empty($errors) || $data === null) {
            if ($existing_article_id > 0) { $keywords->set_status($kid, $prev_status === 'drafting' ? 'drafted' : $prev_status, null, implode('; ', $errors)); }
            else { $keywords->set_status($kid, 'queued', null, implode('; ', $errors)); $keywords->set_priority($kid, (int) ($prev['priority'] ?? $kw['priority'] ?? 100) + 50); }   // one bad keyword can't block the queue daily
            return array('ok' => false, 'article_id' => 0, 'error' => implode('; ', $errors));
        }
        $fields = array(
            'slug' => $data['slug'], 'title' => trim((string) $data['title']), 'meta_description' => trim((string) $data['meta_description']),
            'excerpt' => trim((string) ($data['excerpt'] ?? '')), 'body_md' => (string) $data['body_md'],
            'body_html' => Markdown::render((string) $data['body_md'], self::allowed_paths()),
            'target_keyword' => $keyword, 'secondary_keywords' => json_encode(array_values((array) ($data['secondary_keywords'] ?? array())), JSON_UNESCAPED_UNICODE),
            'faq' => json_encode(array_values((array) $data['faq']), JSON_UNESCAPED_UNICODE),
            'reading_minutes' => self::reading_minutes((string) $data['body_md']), 'status' => 'review',
            'rewrite_note' => null, 'model' => $model, 'prompt_version' => self::PROMPT_VERSION,
        );
        if ($existing && $existing['status'] === 'published') { unset($fields['status']); }   // never takes a live article offline
        if ($existing_article_id > 0) { $articles->update_fields($existing_article_id, $fields); $aid = $existing_article_id; }
        else { $aid = $articles->create($fields); }
        $keywords->set_status($kid, ($existing && $existing['status'] === 'published') ? 'published' : 'drafted', $aid, null);
        try {
            Notify::many((new UsersModel())->admin_ids(), 'system', 'New article ready for review', '"' . $fields['title'] . '" was drafted for "' . $keyword . '".', '/admin/article/' . $aid, 'fa-newspaper');
        } catch (\Throwable $e) { error_log('[seo] notify admins: ' . $e->getMessage()); }
        return array('ok' => true, 'article_id' => $aid, 'error' => '');
    }
}
