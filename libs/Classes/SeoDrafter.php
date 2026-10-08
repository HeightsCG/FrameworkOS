<?php
/**
 * Drafts one blog article for a keyword with Claude, validates it against hard rules, and publishes it
 * as soon as it passes (no admin review since 2026-09-22). One Claude call per draft, one retry on validation failure.
 */
class SeoDrafter {
    const PROMPT_VERSION = 'v1';
    const MIN_WORDS = 1000;
    const MAX_WORDS = 2000;

    /** Topic clusters the blog targets. Each maps to the feature page an article in it must link to. */
    const CLUSTERS = array(
        'ai-influencer-monetization' => array('label' => 'AI influencer monetization', 'feature' => '/features/ai-influencer'),
        'ai-dm-chatter'              => array('label' => 'AI DM chatter',              'feature' => '/features/dm-agent'),
        'lora-character-training'    => array('label' => 'LoRA character training',    'feature' => '/lora-character-training'),
        'platform-comparisons'       => array('label' => 'Platform comparisons',       'feature' => '/features'),
        'creator-payouts'            => array('label' => 'Creator payouts',            'feature' => '/features/payouts'),
    );

    /** A known cluster slug, or '' . */
    public static function cluster($c): string {
        $c = (string) $c;
        return isset(self::CLUSTERS[$c]) ? $c : '';
    }

    /** The feature page an article in this cluster must link to (falls back to /features). */
    public static function feature_for($cluster): string {
        $c = self::cluster($cluster);
        return $c !== '' ? self::CLUSTERS[$c]['feature'] : '/features';
    }

    /** Articles this draft should link to: same cluster first, newest first. */
    public static function link_targets($cluster, $except_id = 0): array {
        try { return (new SeoArticlesModel())->for_linking(self::cluster($cluster), (int) $except_id, 6); }
        catch (\Throwable $e) { return array(); }
    }

    /** Relative paths an article may link to: product pages + published articles. */
    public static function allowed_paths(): array {
        $paths = array('/', '/blog');
        foreach (SeoController::public_pages() as $p) { $paths[] = $p['path']; }
        foreach (self::CLUSTERS as $c) { $paths[] = $c['feature']; }   // feature pages an article in that cluster must link to
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

    /**
     * Mechanical fixes applied before validate(), so a small slip doesn't stop the day's article from publishing:
     * an over-long meta description is cut at a word boundary, and links to paths outside the allow-list become plain text.
     */
    public static function fit(array $a): array {
        $meta = trim(preg_replace('/\s+/', ' ', (string) ($a['meta_description'] ?? '')));
        if (mb_strlen($meta) > 155) {
            $cut = mb_substr($meta, 0, 154); $sp = mb_strrpos($cut, ' ');
            $meta = rtrim($sp !== false && $sp > 100 ? mb_substr($cut, 0, $sp) : $cut, " ,;:-") . '.';
            if (mb_strlen($meta) > 155) { $meta = mb_substr($meta, 0, 155); }
        }
        $a['meta_description'] = $meta;
        $allowed = self::allowed_paths();
        $a['body_md'] = preg_replace_callback('/\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) use ($allowed) {
            $l = $m[2]; $path = preg_replace('/[#?].*$/', '', $l);
            $ok = strpos($l, '/') === 0 && strpos($l, '//') !== 0 && in_array($path, $allowed, true);
            return $ok ? $m[0] : $m[1];
        }, (string) ($a['body_md'] ?? ''));
        return $a;
    }

    /**
     * Hard rules. Returns error strings; empty array = valid.
     * $strict_links adds the internal-linking rules (3+ article links, 1 feature link): on for freshly
     * drafted articles, off for the admin editor so an older article can still be re-published.
     */
    public static function validate(array $a, int $except_id = 0, bool $strict_links = false): array {
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
        if ($strict_links) {
            $article_links = 0; $feature_links = 0;
            $feature = self::feature_for($a['cluster'] ?? '');
            foreach (Markdown::links($body) as $l) {
                $path = preg_replace('/[#?].*$/', '', $l);
                if (strpos($path, '/blog/') === 0) { $article_links++; }
                elseif ($path === $feature) { $feature_links++; }
            }
            if ($article_links < 3) { $err[] = "body needs at least 3 links to other /blog/ articles (got $article_links)"; }
            if ($feature_links < 1) { $err[] = "body needs one link to the feature page $feature"; }
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

    /** The product facts block, shared with SupportAssist. */
    public static function product_context(): string { return self::context(); }

    /** Facts Claude may use; nothing else is allowed to appear as a number or a claim. */
    private static function context(): string {
        $site = Main::site_name(); $base = SeoMeta::base();
        $lines = array("Product: $site ($base). A creator platform: one public page at $base/@handle with posts, membership tiers, pay-per-view posts, content bundles, services, events and tracked links; a studio that publishes to nine social networks with AI captions; an inbox with AI replies; payouts to the creator's bank; fans pay memberships by card and everything else with a credit wallet (\$1 = 10 credits).");
        foreach (PagesController::our_facts() as $k => $v) { $lines[] = ucfirst($k) . ': ' . $v; }
        $lines[] = 'Platform fee, exactly: ' . PagesController::fee_sentence() . ' Never describe the fee as a range, never attach a fee to the Free plan, and never write "20%" about ' . $site . '.';
        foreach (PagesController::pricing_rows() as $r) {
            $t = (array) ($r['tier'] ?? array()); $lim = (array) ($t['limits'] ?? array());
            if (empty($t['name'])) { continue; }
            $price = isset($r['amount']) && $r['amount'] !== null ? ('$' . number_format(((int) $r['amount']) / 100) . '/' . (string) ($r['interval'] ?? 'month')) : 'price shown at checkout';
            $bits = array();
            foreach (PlanTiers::ROWS as $row) { $bits[] = strtolower($row['label']) . ' ' . PlanTiers::fmt_tier_limit($t, $row['key']); }
            foreach (PlanTiers::FEATURES as $fk => $fl) { $bits[] = html_entity_decode($fl, ENT_QUOTES, 'UTF-8') . (PlanTiers::has_feature($t, $fk) ? ' included' : ' not included'); }
            $lines[] = 'Plan ' . $t['name'] . ': ' . $price . ', ' . implode(', ', $bits) . '.';
        }
        if (PagesController::addon_sentence() !== '') { $lines[] = 'Add-ons: ' . PagesController::addon_sentence(); }
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
        return "You write practical, plain-English guides for the $site blog, read by independent creators who sell content, memberships and services online. Voice: direct, specific, second person, no hype, no filler, no emoji, sentence-case headings. Never invent statistics, studies, quotes, prices, fees or competitor facts; use only the facts in the context. A worked example with round, clearly hypothetical numbers (\"say you want \$1,000 a month from 50 members\") is fine when framed as an example; never present invented numbers as real data or averages. Never mention being an AI. Never name the payment processor (no \"Stripe\"); say \"payouts to your bank\". Mention $site naturally at most three times, only where it genuinely helps, and link to its pages using the relative paths given. No external links. Output ONLY a JSON object with keys: title (<=70 chars, sentence case), slug (lowercase-hyphenated, <=80 chars), meta_description (<=155 chars), excerpt (one or two sentences), body_md (Markdown, 1200-1800 words, at least four \"## \" sections, some \"### \" subsections, one relative link per section from the allowed list, no H1, no raw HTML), faq (array of 3-5 {\"q\",\"a\"} objects answering real search questions), secondary_keywords (array of 3-6 short phrases).";
    }

    /** Visual motif per topic for the cover prompt (abstract objects only — no people, no text). */
    private static function cover_motif(string $topic): string {
        $m = array(
            'Pricing' => 'stacked translucent glass tiers rising like steps, a small price tag shape',
            'Memberships' => 'three nested translucent rings of increasing size, like tiers of a club',
            'Pay-per-view' => 'a frosted glass panel partly lifted to reveal a glowing card behind it',
            'Link in bio' => 'a single glowing link chain shape connecting floating rounded tiles',
            'Payouts' => 'smooth coins flowing along a curved glass channel toward a small vault door',
            'Bundles' => 'several rounded tiles neatly grouped inside one translucent box',
            'Services' => 'a calendar tile and a speech bubble tile floating side by side',
            'Events' => 'a ticket shape and a spotlight beam on a soft stage floor',
            'Social' => 'many small rounded tiles radiating outward from one central tile',
            'AI' => 'a softly glowing sphere made of fine mesh lines',
            'Platforms' => 'two translucent platforms at different heights with a bridge between them',
            'Getting paid' => 'a glowing coin resting on a stack of rounded glass tiles',
        );
        return $m[$topic] ?? 'rounded translucent glass tiles floating in soft light';
    }

    /**
     * Generate and store a cover image for an article (fal via ImageGenService, S3 under creator/ so it is public).
     * Returns the public URL, or '' on any failure (never blocks drafting). Saves it on the row when $save.
     */
    public static function make_cover(array $article, bool $save = true): string {
        try {
            if (!class_exists('ImageGenService') || !S3Service::configured()) { return ''; }
            $topic = BlogController::topic($article);
            $prompt = 'Minimal editorial 3D illustration for a creator-economy blog article about "' . $topic . '": ' . self::cover_motif($topic)
                . '. Soft studio lighting, lavender and deep violet palette (#8273f8, #5b4be0, #4636c4) on a pale lilac background, gentle gradients, glossy glass and matte clay materials, generous empty space, centered composition. No people, no faces, no hands, no text, no letters, no numbers, no logos.';
            $img = ImageGenService::generate($prompt, 'landscape');
            if (empty($img['ok'])) { error_log('[seo] cover for article ' . ($article['id'] ?? '?') . ': ' . ($img['error'] ?? 'failed')); return ''; }
            $ext = in_array((string) $img['ext'], array('jpg', 'jpeg', 'png', 'webp'), true) ? (string) $img['ext'] : 'jpg';
            $tmp = tempnam(sys_get_temp_dir(), 'clscover');
            file_put_contents($tmp, $img['bytes']);
            $key = 'creator/blog/' . preg_replace('/[^a-z0-9-]/', '', (string) ($article['slug'] ?? 'article')) . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
            $url = S3Service::upload_file($key, $tmp, (string) ($img['mime'] ?: 'image/jpeg'));
            @unlink($tmp);
            if ($url === '') { return ''; }
            if ($save && !empty($article['id'])) {
                $old = (string) ($article['cover_image_url'] ?? '');
                (new SeoArticlesModel())->update_fields((int) $article['id'], array('cover_image_url' => $url));
                if ($old !== '' && strpos($old, '/creator/blog/') !== false) { S3Service::delete_by_url($old); }
                PublicThumbService::blog_cover((int) $article['id'], $url, (string) ($article['cover_webp_url'] ?? ''));   // webp copies for the page
            }
            return $url;
        } catch (\Throwable $e) {
            error_log('[seo] make_cover: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Draft for one keyword row. $note = Admin's rewrite instruction (appended to the request);
     * $existing_article_id = replace that article's content instead of creating a new row.
     */
    /** Words that carry no topic meaning when comparing titles. */
    const STOPWORDS = array('a','an','and','are','as','at','be','best','by','can','do','does','for','from','how','in','is','it','of','on','or','that','the','to','top','vs','what','when','which','why','with','you','your');

    /** Crude stem so "make"/"making" and "payout"/"payouts" compare equal in the duplicate check. */
    private static function stem($w): string {
        foreach (array('ings', 'ing', 'ies', 'ers', 'er', 'ed', 'es', 's') as $suf) {
            if (mb_strlen($w) > mb_strlen($suf) + 2 && substr($w, -mb_strlen($suf)) === $suf) {
                $w = substr($w, 0, -mb_strlen($suf));
                if ($suf === 'ies') { $w .= 'y'; }
                break;
            }
        }
        return (mb_strlen($w) > 3 && substr($w, -1) === 'e') ? substr($w, 0, -1) : $w;   // make/making and price/pricing land on the same stem
    }

    private static function tokens($s): array {
        $s = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', (string) $s));
        $out = array();
        foreach (preg_split('/\s+/', $s) as $w) {
            $w = trim($w);
            if ($w === '' || mb_strlen($w) < 3 || in_array($w, self::STOPWORDS, true)) { continue; }
            $out[self::stem($w)] = true;
        }
        return array_keys($out);
    }

    /**
     * Is this title/keyword already covered? Returns the clashing articles (title + why), empty when clear.
     * Catches an exact primary keyword, the same normalised title, and heavy word overlap (>= 0.8 of the shorter title).
     */
    public static function duplicates($title, $keyword, $except_id = 0): array {
        $hits = array();
        try { $rows = (new SeoArticlesModel())->primary_keywords((int) $except_id); }
        catch (\Throwable $e) { return array(); }
        $kw_n = strtolower(trim((string) $keyword));
        $t_tok = self::tokens($title);
        foreach ($rows as $r) {
            $why = '';
            if ($kw_n !== '' && strtolower(trim((string) $r['target_keyword'])) === $kw_n) { $why = 'same primary keyword'; }
            elseif (self::slugify($title) !== '' && self::slugify($title) === self::slugify($r['title'])) { $why = 'same title'; }
            else {
                $o_tok = self::tokens($r['title']);
                $min = min(count($t_tok), count($o_tok));
                if ($min >= 3) {
                    $overlap = count(array_intersect($t_tok, $o_tok)) / $min;
                    if ($overlap >= 0.75) { $why = 'nearly the same title'; }
                }
            }
            if ($why !== '') { $hits[] = array('id' => (int) $r['id'], 'title' => (string) $r['title'], 'why' => $why); }
        }
        return $hits;
    }

    /** The signup block shown under every article (rendered by blog-article.php). Written here, not by Claude, so it never drifts. */
    public static function cta_html(): string {
        $site = htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8');
        return '<aside class="gd-cta">'
            . '<h2 class="gd-cta__title">Start earning from your own page</h2>'
            . '<p class="gd-cta__text">' . $site . ' gives you one public page with memberships, pay-per-view posts, bundles, services and events, and pays out to your bank.</p>'
            . '<a class="gd-cta__btn" href="/?auth=register">Create Your Account</a>'
            . '</aside>';
    }

    public static function draft(array $kw, string $note = '', int $existing_article_id = 0): array {
        $keywords = new SeoKeywordsModel(); $articles = new SeoArticlesModel();
        $kid = (int) $kw['id']; $keyword = (string) $kw['keyword'];
        $existing = $existing_article_id > 0 ? $articles->get($existing_article_id) : null;
        $prev = $keywords->get($kid); $prev_status = (string) ($prev['status'] ?? 'queued');   // restored if a rewrite fails
        $keywords->set_status($kid, 'drafting');
        $cluster = self::cluster($existing['cluster'] ?? ($kw['cluster'] ?? ''));
        $feature = self::feature_for($cluster);
        $targets = self::link_targets($cluster, $existing_article_id);
        $user = "Target keyword: \"$keyword\"" . (!empty($kw['volume']) ? " (about {$kw['volume']} searches/month)" : '') . ".";
        if ($cluster !== '') { $user .= "\nTopic cluster: " . self::CLUSTERS[$cluster]['label'] . "."; }
        $user .= "\n\nLink to at least THREE of these published articles, each where it genuinely helps the reader, using their exact paths:\n";
        foreach ($targets as $t) { $user .= '- /blog/' . $t['slug'] . ' — ' . $t['title'] . "\n"; }
        if (empty($targets)) { $user .= "(none published yet — link to the product pages instead)\n"; }
        $user .= "Link exactly once to the feature page $feature, where it fits the topic.\n";
        $user .= "\nContext (the only facts you may use):\n" . self::context();
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
            $data = self::fit($data);
            $data['cluster'] = $cluster;
            $errors = self::validate($data, $existing_article_id, count($targets) >= 3);
            if (empty($errors) && $existing_article_id === 0) {
                $dupes = self::duplicates((string) $data['title'], $keyword, $existing_article_id);
                if (!empty($dupes)) {
                    $errors = array('this topic is already covered (' . $dupes[0]['why'] . '): "' . $dupes[0]['title'] . '" — cover a different angle with a different title and primary keyword');
                }
            }
            if (empty($errors)) { break; }
        }
        if (!empty($errors) || $data === null) {
            $dupe = (bool) preg_grep('/already covered/', $errors);
            if ($existing_article_id > 0) { $keywords->set_status($kid, $prev_status === 'drafting' ? 'drafted' : $prev_status, null, implode('; ', $errors)); }
            elseif ($dupe) { $keywords->set_status($kid, 'skipped', null, implode('; ', $errors)); }   // covered already: retire the keyword instead of publishing a near-duplicate
            else { $keywords->set_status($kid, 'queued', null, implode('; ', $errors)); $keywords->set_priority($kid, (int) ($prev['priority'] ?? $kw['priority'] ?? 100) + 50); }   // one bad keyword can't block the queue daily
            return array('ok' => false, 'article_id' => 0, 'error' => implode('; ', $errors));
        }
        $fields = array(
            'slug' => $data['slug'], 'title' => trim((string) $data['title']), 'meta_description' => trim((string) $data['meta_description']),
            'excerpt' => trim((string) ($data['excerpt'] ?? '')), 'body_md' => (string) $data['body_md'],
            'body_html' => Markdown::render((string) $data['body_md'], self::allowed_paths()),
            'target_keyword' => $keyword, 'cluster' => $cluster, 'author' => Main::site_name(), 'secondary_keywords' => json_encode(array_values((array) ($data['secondary_keywords'] ?? array())), JSON_UNESCAPED_UNICODE),
            'faq' => json_encode(array_values((array) $data['faq']), JSON_UNESCAPED_UNICODE),
            'reading_minutes' => self::reading_minutes((string) $data['body_md']), 'status' => 'review',
            'rewrite_note' => null, 'model' => $model, 'prompt_version' => self::PROMPT_VERSION,
        );
        if ($existing && $existing['status'] === 'published') { unset($fields['status']); }   // never takes a live article offline
        if ($existing_article_id > 0) { $articles->update_fields($existing_article_id, $fields); $aid = $existing_article_id; }
        else { $aid = $articles->create($fields); }
        // Cover image: only when the article has none yet (a rewrite keeps its cover). Never blocks the draft.
        $saved = $articles->get($aid);
        if ($saved && trim((string) ($saved['cover_image_url'] ?? '')) === '') { self::make_cover($saved); }
        // Passed every hard check above, so it goes live now (cover first): no review step (Daniel, 2026-09-22).
        $articles->set_status($aid, 'published');
        $keywords->set_status($kid, 'published', $aid, null);
        try { IndexNow::ping(array('/blog/' . $fields['slug'], '/blog')); } catch (\Throwable $e) { error_log('[seo] indexnow: ' . $e->getMessage()); }
        try {
            Notify::many((new UsersModel())->admin_ids(), 'system', 'New article published', '"' . $fields['title'] . '" is live on the blog, written for "' . $keyword . '".', '/admin/article/' . $aid, 'fa-newspaper');
        } catch (\Throwable $e) { error_log('[seo] notify admins: ' . $e->getMessage()); }
        return array('ok' => true, 'article_id' => $aid, 'error' => '');
    }
}
