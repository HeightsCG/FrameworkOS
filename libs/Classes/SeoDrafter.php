<?php
/**
 * Drafts one blog article for a keyword with Claude, validates it against hard rules, and publishes it
 * as soon as it passes (no admin review since 2026-09-22). One Claude call per draft, one retry on validation failure.
 */
class SeoDrafter {
    const PROMPT_VERSION = 'v3';   // v3 (2026-10-11): no fee/plan injection, no fixed structure, section openers name their subject
    const MIN_WORDS = 1000;   // length when no intent is known (the admin editor on older articles)
    const MAX_WORDS = 2000;

    /**
     * Topic clusters. feature = the page an article in the cluster must link to; links = the only product pages it may
     * link to; related = the other clusters whose articles it may link to; intent = default length band (LENGTHS).
     * Pricing and selling clusters never reach AI influencer pages; AI clusters stay among themselves.
     */
    const CLUSTERS = array(
        'pricing-and-fees' => array('label' => 'Pricing, fees and plans', 'feature' => '/pricing', 'intent' => 'howto',
            'links' => array('/pricing', '/features/payouts', '/best-creator-monetization-platforms', '/onlyfans-alternatives', '/fanvue-alternatives', '/compare/onlyfans', '/compare/fanvue', '/compare/patreon', '/compare/fansly', '/compare/kofi', '/compare/linktree', '/compare/beacons', '/compare/stan'),
            'related' => array('platform-comparisons', 'creator-payouts')),
        'platform-comparisons' => array('label' => 'Platform comparisons', 'feature' => '/best-creator-monetization-platforms', 'intent' => 'guide',
            'links' => array('/best-creator-monetization-platforms', '/onlyfans-alternatives', '/fanvue-alternatives', '/compare/onlyfans', '/compare/fanvue', '/compare/patreon', '/compare/fansly', '/compare/kofi', '/compare/linktree', '/compare/beacons', '/compare/stan', '/pricing', '/features', '/features/link-in-bio', '/features/payouts'),
            'related' => array('pricing-and-fees', 'creator-payouts')),
        'creator-payouts' => array('label' => 'Creator payouts', 'feature' => '/features/payouts', 'intent' => 'guide',
            'links' => array('/features/payouts', '/pricing', '/features/memberships', '/features/pay-per-view', '/monetize-your-content'),
            'related' => array('pricing-and-fees', 'memberships-and-ppv')),
        'memberships-and-ppv' => array('label' => 'Memberships and pay-per-view', 'feature' => '/features/memberships', 'intent' => 'howto',
            'links' => array('/features/memberships', '/features/pay-per-view', '/features/payouts', '/monetize-your-content', '/pricing', '/features'),
            'related' => array('creator-monetization', 'creator-payouts', 'pricing-and-fees')),
        'creator-monetization' => array('label' => 'Selling from a creator page', 'feature' => '/monetize-your-content', 'intent' => 'guide',
            'links' => array('/monetize-your-content', '/features', '/features/memberships', '/features/pay-per-view', '/features/payouts', '/features/link-in-bio', '/features/services-and-events', '/features/custom-domains', '/pricing'),
            'related' => array('memberships-and-ppv', 'creator-payouts', 'social-publishing')),
        'social-publishing' => array('label' => 'Social publishing', 'feature' => '/features/publishing', 'intent' => 'howto',
            'links' => array('/features/publishing', '/features', '/features/link-in-bio', '/monetize-your-content'),
            'related' => array('creator-monetization')),
        'ai-influencer-monetization' => array('label' => 'AI influencer monetization', 'feature' => '/features/ai-influencer', 'intent' => 'guide',
            'links' => array('/features/ai-influencer', '/lora-character-training', '/consistent-ai-model-face', '/ai-ofm-tools', '/compare/eromify'),
            'related' => array('lora-character-training', 'ai-dm-chatter')),
        'lora-character-training' => array('label' => 'LoRA character training', 'feature' => '/lora-character-training', 'intent' => 'howto',
            'links' => array('/lora-character-training', '/consistent-ai-model-face', '/features/ai-influencer', '/ai-ofm-tools', '/compare/eromify'),
            'related' => array('ai-influencer-monetization')),
        'ai-dm-chatter' => array('label' => 'AI DM chatter', 'feature' => '/features/dm-agent', 'intent' => 'howto',
            'links' => array('/features/dm-agent', '/ai-ofm-tools', '/features/ai-influencer', '/features/pay-per-view'),
            'related' => array('ai-influencer-monetization', 'memberships-and-ppv')),
    );

    /** Word ranges per intent; validate() allows 20% either side. */
    const LENGTHS = array(
        'quick' => array(600, 800, 'quick answer'),
        'howto' => array(900, 1200, 'how-to'),
        'guide' => array(1400, 1900, 'guide or comparison'),
    );

    /** The only external domains an article may cite (subdomains included): official help centres, platform docs, public statistics. */
    const CITATION_DOMAINS = array(
        'irs.gov', 'ftc.gov', 'sba.gov', 'bls.gov', 'census.gov', 'gov.uk', 'canada.ca', 'ato.gov.au', 'europa.eu', 'oecd.org',
        'pewresearch.org', 'help.instagram.com', 'creators.instagram.com', 'transparency.meta.com', 'support.google.com', 'blog.youtube',
        'help.x.com', 'support.tiktok.com', 'help.pinterest.com', 'support.patreon.com', 'help.ko-fi.com', 'help.fanvue.com',
        'help.beacons.ai', 'help.stan.store', 'creatorhub.fansly.com', 'huggingface.co',
        // approved 2026-10-09: model publishers and papers, Bluesky docs, Linktree help, OnlyFans help/terms pages
        'bfl.ai', 'arxiv.org', 'docs.bsky.app', 'help.linktr.ee', 'onlyfans.com',
    );

    /** Every article cites at least this many distinct sources from CITATION_DOMAINS, each a URL that resolves. */
    const MIN_CITATIONS = 2;

    /** Set false in tests: validate() otherwise fetches every cited URL to prove it exists (a dead or invented source rejects the draft). */
    public static $check_citation_urls = true;
    private static $url_cache = array();

    /**
     * Source pages verified to exist (checked 2026-10-09, each answered 200). Offered to the model so it cites a real page
     * instead of inventing a plausible path; validate() still fetches every citation. Keep to CITATION_DOMAINS.
     */
    const KNOWN_SOURCES = array(
        'https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center' => 'IRS: self-employed individuals tax center',
        'https://www.irs.gov/businesses/understanding-your-form-1099-k' => 'IRS: understanding Form 1099-K',
        'https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking' => 'FTC: Endorsement Guides, what people are asking',
        'https://www.ftc.gov/business-guidance/resources/disclosures-101-social-media-influencers' => 'FTC: Disclosures 101 for social media influencers',
        'https://www.sba.gov/business-guide' => 'US SBA: business guide',
        'https://www.gov.uk/working-for-yourself' => 'UK government: working for yourself',
        'https://support.google.com/youtube/answer/72857' => 'YouTube Help: YouTube Partner Program overview and eligibility',
        'https://support.google.com/youtube/answer/1311392' => 'YouTube Help: channel monetization policies',
        'https://support.tiktok.com/en/business-and-creator' => 'TikTok Support: business and creator',
        'https://support.tiktok.com/en/using-tiktok/growing-your-audience/creator-rewards-program' => 'TikTok Support: Creator Rewards Program',
        'https://help.fanvue.com/en/' => 'Fanvue Help Center',
        'https://creatorhub.fansly.com/' => 'Fansly Creator Hub',
        'https://help.beacons.ai/' => 'Beacons Help Center',
        'https://help.stan.store/' => 'Stan Help Center',
        'https://help.linktr.ee/en/' => 'Linktree Help Center',
        'https://onlyfans.com/help' => 'OnlyFans Help Center',
        'https://docs.bsky.app/docs/get-started' => 'Bluesky developer docs: get started',
        'https://huggingface.co/docs/diffusers/training/lora' => 'Hugging Face Diffusers docs: LoRA training',
        'https://huggingface.co/docs/peft/conceptual_guides/lora' => 'Hugging Face PEFT docs: LoRA conceptual guide',
        'https://huggingface.co/docs/diffusers/training/dreambooth' => 'Hugging Face Diffusers docs: DreamBooth training',
        'https://arxiv.org/abs/2106.09685' => 'arXiv: LoRA, Low-Rank Adaptation of Large Language Models (Hu et al., 2021)',
        'https://arxiv.org/abs/2208.12242' => 'arXiv: DreamBooth (Ruiz et al., 2022)',
        'https://arxiv.org/abs/2112.10752' => 'arXiv: High-Resolution Image Synthesis with Latent Diffusion Models (Rombach et al., 2021)',
        'https://bfl.ai/models/flux-kontext' => 'Black Forest Labs: FLUX models',
        'https://www.pewresearch.org/topic/internet-technology/' => 'Pew Research Center: internet and technology research',
    );

    /** Allow-listed hosts that answer 400/401/403/429 to any non-browser request: a page there is accepted but noted as unverified. */
    public static $unverified_citations = array();

    /**
     * The cited URLs that do not exist: 404/410, a 5xx, or no answer at all. Tries HEAD, then GET (several help centres
     * refuse HEAD). A bot wall (400/401/403/429) on an allow-listed host does not prove a page missing, so that URL is
     * accepted and listed in $unverified_citations instead. Cached per process.
     */
    public static function unreachable_citations(array $urls): array {
        $bad = array();
        foreach (array_unique($urls) as $u) {
            if (isset(self::KNOWN_SOURCES[$u])) { continue; }   // vouched for in config (ftc.gov answers automated requests with 404 although the pages exist)
            if (!isset(self::$url_cache[$u])) {
                $ok = false; $last = 0;
                foreach (array(true, false) as $head) {
                    $ch = curl_init($u);
                    curl_setopt_array($ch, array(CURLOPT_NOBODY => $head, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
                        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                        CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.8')));
                    curl_exec($ch);
                    $last = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    curl_close($ch);
                    if ($last >= 200 && $last < 400) { $ok = true; break; }
                }
                if (!$ok && in_array($last, array(400, 401, 403, 429), true)) { $ok = true; self::$unverified_citations[] = $u; }
                self::$url_cache[$u] = $ok;
            }
            if (!self::$url_cache[$u]) { $bad[] = $u; }
        }
        return $bad;
    }

    /** "; cite one of these on that site instead: ..." when KNOWN_SOURCES has pages on the dead URL's host, else ''. */
    public static function known_instead(string $url): string {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST)); $same = array();
        foreach (self::KNOWN_SOURCES as $k => $what) { if (strtolower((string) parse_url($k, PHP_URL_HOST)) === $host) { $same[] = $k; } }
        return $same ? '; cite one of these on that site instead: ' . implode(' ', $same) : '';
    }

    /**
     * Bare citation URLs ("see https://www.irs.gov/..." or <https://...>) become Markdown links to the same page, so
     * they count as citations and render as links; a URL that is already a link target is left alone.
     */
    public static function linkify_bare_citations(string $md): string {
        $md = preg_replace_callback('/<(https:\/\/[^\s<>]+)>/', function ($m) { return self::citation_ok($m[1]) ? '[' . parse_url($m[1], PHP_URL_HOST) . '](' . $m[1] . ')' : $m[0]; }, $md);
        return preg_replace_callback('/(?<![\(\[<"\'\/])\bhttps:\/\/[^\s<>\)\]"\']+/', function ($m) {
            $url = rtrim($m[0], '.,;:!?'); $tail = substr($m[0], strlen($url));
            return self::citation_ok($url) ? '[' . parse_url($url, PHP_URL_HOST) . '](' . $url . ')' . $tail : $m[0];
        }, $md);
    }

    /** The https links in a body that point at a CITATION_DOMAINS host. */
    public static function citations(string $body): array {
        return array_values(array_filter(Markdown::links($body), function ($l) { return preg_match('#^https?://#i', $l) && self::citation_ok($l); }));
    }

    /**
     * The rules the SEO audit scores, on title, meta, citations and prose (no network): title <= 60, meta 110-155,
     * at least one "## " section (how many, and whether they are questions, is the piece's own choice: a fixed shape
     * made the corpus read as one template), >= MIN_CITATIONS distinct cited hosts, sentence length, paragraph length
     * and openers, and a first sentence under each heading that names the section's subject. Shared by validate()
     * (new drafts) and the nightly fact check (so an older article that breaks them is flagged and --rewrite takes it).
     */
    public static function quotability_errors(array $a): array {
        $err = array();
        $title = trim((string) ($a['title'] ?? '')); $meta = trim((string) ($a['meta_description'] ?? '')); $body = (string) ($a['body_md'] ?? '');
        if ($title !== '' && mb_strlen($title) > 60) { $err[] = 'title must be 1-60 characters'; }   // 30-60 as rendered, with the brand suffix dropped past 60
        if (mb_strlen($meta) < 110 || mb_strlen($meta) > 155) { $err[] = 'meta_description must be 110-155 characters'; }
        $h2 = self::h2_texts($body);
        if (count($h2) < 1) { $err[] = 'body needs at least one "## " section'; }
        $questions = count(array_filter($h2, function ($h) { return substr(trim($h), -1) === '?'; }));
        if (count($h2) >= 2 && $questions < 2) { $err[] = 'at least 2 "## " headings must be a question a creator actually asks (ending in ?), answered in the first sentence under it'; }   // restored 2026-10-11 (c_question_headings); the format gives the rest of the shape
        $hosts = array_unique(array_map(function ($u) { return strtolower((string) parse_url($u, PHP_URL_HOST)); }, self::citations($body)));
        if (count($hosts) < self::MIN_CITATIONS) {
            $err[] = 'body needs at least ' . self::MIN_CITATIONS . ' citations to ' . self::MIN_CITATIONS . ' different sources on the citation list (got ' . count($hosts) . ')'
                . (self::$stripped_external ? '; links to domains not on the list were removed: ' . implode(', ', array_slice(array_unique(self::$stripped_external), 0, 4)) : '');
        }
        $err = array_merge($err, self::prose_errors($body, $title));
        $ex = trim((string) ($a['excerpt'] ?? ''));
        if ($ex !== '') {   // the subtitle is the first text on the page: same rule as every paragraph's first sentence
            $ex_first = preg_split('/(?<=[.!?])\s+/', $ex)[0];
            if (preg_match(self::OPENER_PRONOUNS, $ex_first, $m) || preg_match('/^(That)\b|(?:^|[,;:]\s+|\b(?:and|but|so|because|yet|or)\s+)(that) (?:is|was|are|were|means|makes|leaves|keeps|works|matters|costs?|takes|gives|applies|does|did|comes|turns|holds|has|had|will|would|should|can|could|changes|adds|pays)\b/i', $ex_first, $m)) { $err[] = 'the subtitle (excerpt) names its subject outright and carries no pronoun that points back ("' . ($m[1] !== '' ? $m[1] : ($m[2] ?? 'that')) . '"): "' . mb_substr($ex, 0, 60) . '"'; }
        }
        return array_merge($err, self::stock_phrase_errors($body, (int) ($a['id'] ?? 0), (string) ($a['target_keyword'] ?? '')));
    }

    /** The "## " headings of a Markdown body, in order. */
    public static function h2_texts(string $body): array {
        preg_match_all('/^##\s+(.+?)\s*$/m', $body, $m);
        return array_map('trim', $m[1]);
    }

    /**
     * Style rules are ADVISORY (2026-10-12, after paid rewrites were thrown away over word choice): a draft that passes
     * every factual check publishes on its last attempt even with these left, and they are kept on the article
     * (rewrite_note "Style notes: ...") and listed by the nightly report as "(style)". The prompt and the retry feedback
     * still ask for them, so most drafts satisfy them; none of them is worth a wasted Claude call.
     */
    const SOFT_RULES = '/^(stock phrasing|the first sentence of a paragraph|the subtitle \(excerpt\)|the opening paragraph must be a direct answer|at least 2 "## " headings|paragraph has \d+ sentences|sentences average|the first paragraph must define|step-by-step guide:|checklist:|comparison:|cost breakdown:|troubleshooting:|glossary entry:|the platform fee is stated in|plan prices are stated in|body must be \d+-\d+ words|faq is either empty)/';

    public static function is_soft(string $error): bool { return (bool) preg_match(self::SOFT_RULES, $error); }

    /** Split validate() output: [hard errors, style notes]. */
    public static function split_errors(array $errors): array {
        $hard = array(); $soft = array();
        foreach ($errors as $e) { if (self::is_soft((string) $e)) { $soft[] = $e; } else { $hard[] = $e; } }
        return array($hard, $soft);
    }

    /** The opening paragraph answers the title directly in this many words (g_answer_first); the check allows ANSWER_SLACK either side, the prompt asks for the band itself. */
    const ANSWER_WORDS = array(40, 60);
    const ANSWER_SLACK = 6;
    /** Pronouns that may not appear anywhere in a paragraph's first sentence or in the subtitle (relative "that" allowed). */
    const OPENER_PRONOUNS = '/\b(it|its|they|them|their|this|these|those|he|she|such)\b/i';

    /** The most sentences a paragraph may have: a longer one cannot be lifted into an answer whole (the audit's paragraph rule). */
    const PARAGRAPH_MAX_SENTENCES = 6;

    /** Claude calls per draft: the first, then up to two retries that list every failed check. */
    const DRAFT_ATTEMPTS = 3;

    /**
     * Quotability rules the audit scores: 10-25 words per sentence on average, paragraphs of at most
     * PARAGRAPH_MAX_SENTENCES sentences that name their subject instead of opening with "this", "they", "it" or
     * "such", a first paragraph that defines the subject ("X is ..."), and a first sentence under every "## "
     * heading that names the section's subject (section_opener_errors). Returns error strings.
     */
    public static function prose_errors(string $body, string $title = ''): array {
        $err = array();
        $plain = preg_replace(array('/^#.*$/m', '/\[([^\]]*)\]\([^)]*\)/', '/[*_`>|-]+/'), array('', '$1', ' '), $body);
        $sentences = array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/', $plain)), function ($s) { return str_word_count($s) > 2; });
        if (count($sentences) >= 5) {
            $avg = array_sum(array_map('str_word_count', $sentences)) / count($sentences);
            if ($avg > 25) { $err[] = sprintf('sentences average %.0f words; keep the average between 10 and 25', $avg); }
            if ($avg < 10) { $err[] = sprintf('sentences average %.0f words; keep the average between 10 and 25', $avg); }
        }
        $first = null; $openers = 0; $long = 0;   // every offending paragraph is named (up to 6 each), so one retry fixes them all
        foreach (preg_split('/\n\s*\n/', $body) as $p) {
            $p = trim($p);
            if ($p === '' || preg_match('/^(#|[-*>|]|\d+\.)/', $p)) { continue; }
            if ($first === null) { $first = $p; }
            $first_sentence = preg_split('/(?<=[.!?])\s+/', preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $p))[0];
            // "that" counts only as a back-reference (sentence start, or "that is/means/..."), never as the relative pronoun in "a price that fans pay"
            // a pronoun in the opening words of the first sentence can only point at an earlier paragraph; later in the sentence it points inside it ("sell it together")
            $opening = $first_sentence;   // the whole first sentence (Daniel's rule 2026-10-12); relative "that" is allowed below
            if ($openers < 6 && (preg_match(self::OPENER_PRONOUNS, $opening, $m) || preg_match('/^(That)\b|(?:^|[,;:]\s+|\b(?:and|but|so|because|yet|or)\s+)(that) (?:is|was|are|were|means|makes|leaves|keeps|works|matters|costs?|takes|gives|applies|does|did|comes|turns|holds|has|had|will|would|should|can|could|changes|adds|pays)\b/i', $opening, $m))) {
                $m[1] = (string) ($m[1] !== '' ? $m[1] : ($m[2] ?? 'that')); $err[] = 'the first sentence of a paragraph names its subject outright and carries no pronoun that points back ("' . $m[1] . '"): "' . mb_substr($p, 0, 60) . '"'; $openers++; }   // tightened 2026-10-12 (g_self_contained)
            $n = self::sentence_count($p);
            if ($long < 6 && $n > self::PARAGRAPH_MAX_SENTENCES) { $err[] = 'paragraph has ' . $n . ' sentences; keep each paragraph to ' . self::PARAGRAPH_MAX_SENTENCES . ': "' . mb_substr($p, 0, 60) . '"'; $long++; }
        }
        if ($first !== null && !self::opens_with_definition($first)) { $err[] = 'the first paragraph must define the subject in one sentence ("X is ..."): "' . mb_substr($first, 0, 60) . '"'; }
        if ($first !== null) {   // answer first (g_answer_first): the opening paragraph is a direct 40-60 word answer to the title; context and caveats come after it
            $fw = str_word_count(preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $first));
            if ($fw < self::ANSWER_WORDS[0] - self::ANSWER_SLACK || $fw > self::ANSWER_WORDS[1] + self::ANSWER_SLACK) { $err[] = 'the opening paragraph must be a direct answer of ' . self::ANSWER_WORDS[0] . '-' . self::ANSWER_WORDS[1] . ' words to the title (got ' . $fw . '); context and caveats come after it'; }
        }
        return $err;   // section_opener_errors() is advice in the prompt, not a gate: its word-matching rejected good openers and failed rewrites (2026-10-11)
    }

    /** Do two stem lists share a word? Equal stems, or one the prefix of the other from five letters (consistent/consistency, monetize/monetization). */
    private static function stems_overlap(array $a, array $b): bool {
        foreach ($a as $x) { foreach ($b as $y) { if ($x === $y || (mb_strlen($x) >= 5 && mb_strlen($y) >= 5 && (strpos($x, $y) === 0 || strpos($y, $x) === 0))) { return true; } } }
        return false;
    }

    /**
     * The first sentence under each "## " heading must name what the section is about, so the section reads correctly
     * when quoted on its own. It passes when a content word of it (stemmed) is in the heading or the article title, or
     * is a word the section itself keeps using (at least twice in the rest of the section): "Offers come before
     * images." opens a section about offers even when the heading says "what the character sells". A sentence that only
     * carries words the section never returns to ("Most people wait too long.") fails. A section that opens with a list
     * or a table is checked on its first prose paragraph. Up to six sections are named per pass.
     * NOT part of validate() or the nightly rules: a word-matching heuristic cannot judge whether a sentence names its
     * subject, and as a gate it failed real rewrites on good openers. Kept for reports and tests; the prompt carries the rule.
     */
    public static function section_opener_errors(string $body, string $title = ''): array {
        $err = array(); $title_tok = self::tokens($title);
        $sections = array(); $cur = null;
        foreach (preg_split('/\n\s*\n/', $body) as $p) {
            $p = trim($p);
            if ($p === '') { continue; }
            if (preg_match('/^##\s+(.+?)\s*$/', $p, $m)) { $cur = count($sections); $sections[] = array('heading' => $m[1], 'first' => null, 'rest' => array()); continue; }
            if ($cur === null) { continue; }
            if ($sections[$cur]['first'] === null && !preg_match('/^(#|[-*+]\s|>|\||`|\d+\.\s)/', $p)) { $sections[$cur]['first'] = $p; }   // a paragraph opening in **bold** is prose
            else { $sections[$cur]['rest'][] = $p; }
        }
        $n = 0;
        foreach ($sections as $s) {
            if ($s['first'] === null) { continue; }
            $plain = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $s['first']);
            $sentence = preg_split('/(?<=[.!?])\s+/', $plain)[0];
            $mine = self::tokens($sentence);
            if ($mine === array()) { continue; }
            $want = array_unique(array_merge(self::tokens($s['heading']), $title_tok));
            if (self::stems_overlap($mine, $want)) { continue; }
            // the words the rest of the section keeps using
            $counts = array();
            foreach (explode(' ', strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', implode(' ', array_merge(array(mb_substr($plain, mb_strlen($sentence))), $s['rest'])))))) as $w) {
                $w = trim($w); if ($w === '' || mb_strlen($w) < 3 || in_array($w, self::STOPWORDS, true)) { continue; }
                $k = self::stem($w); $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
            $frequent = array_keys(array_filter($counts, function ($c) { return $c >= 2; }));
            if (self::stems_overlap($mine, $frequent)) { continue; }
            if ($n < 6) { $err[] = 'the first sentence under "## ' . $s['heading'] . '" must name the subject of the section: "' . mb_substr($sentence, 0, 60) . '"'; $n++; }
        }
        return $err;
    }

    /** Does a paragraph open with a definition: a sentence of the shape "X is/are/means ..." within its first 80 characters? */
    public static function opens_with_definition(string $p): bool {
        $p = trim(preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $p));
        $sentence = preg_split('/(?<=[.!?])\s+/', $p)[0];
        return (bool) preg_match('/^[^.?!]{2,80}?\b(is|are|means)\b/u', $sentence);
    }

    /** Common first names: an article names no people, real or invented. Ambiguous words (Will, May, Grace...) left out. */
    const FIRST_NAMES = array('Sarah','Jessica','Emily','Ashley','Jennifer','Michael','David','James','John','Robert','Daniel','Matthew','Christopher','Joshua','Andrew','Ryan','Brandon','Tyler','Kevin','Jason','Justin','Emma','Olivia','Sophia','Isabella','Mia','Ava','Abigail','Madison','Chloe','Lily','Hannah','Samantha','Lauren','Rachel','Megan','Amanda','Nicole','Stephanie','Elizabeth','Maria','Laura','Anna','Sofia','Lucas','Liam','Noah','Ethan','Mason','Logan','Aiden','Jacob','Benjamin','Alexander','Elijah','Oliver','Henry','Sebastian','Carlos','Juan','Jose','Luis','Miguel','Priya','Aisha','Fatima','Mohammed','Ahmed','Yuki','Kenji','Maya','Zoe','Jake','Josh','Mike','Dave','Chris','Alex','Ben','Tom','Nina','Lena','Elena','Clara','Julia','Sara','Kate','Katie','Jenny','Amy','Lisa','Leah','Ella','Sienna','Jasmine','Marcus','Tony','Kim');

    /** Capitalised words that are products, places, platforms or colours, never a person (the names check skips them). */
    const SAFE_WORDS = array('Maya','Mason','Sienna','Google','Hugging','Face','Instagram','Facebook','Twitter','Reddit','Snapchat','Pinterest','Threads','Bluesky','Discord','Telegram','Patreon','Fanvue','Fansly','Linktree','Beacons','Apple','Android','Canva','Shopify','Flux','Stable','Diffusion','Midjourney','Claude','Analytics','Creator','Studio','Free','Pro','Link','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday','January','February','March','April','May','June','July','August','September','October','November','December','English','Spanish','The','This','That','These','Those','Your','You','It','A','An','In','On','At','For','From','With','By','And','But','Or','If','When','What','How','Why','Where','Which','Who','Whose','Whether','Here','There','Then','Now','Also','Even','Still','Just','Only','Over','Under','After','Before','During','Since','Until','While','Because','Although','Though','Once','Again','Later','Earlier','Today','Yes','No','Not','Next','Last','First','Second','Third','Step','Part','Day','Week','Month','Year','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Each','Every','Some','All','Most','Many','Much','Few','Other','Another','Same','Different','Own','Real','New','Old','Good','Bad','Best','Better','More','Less','Half','Full','Short','Long','Small','Large','Big','Early','Late','Plan','Plans','Tier','Tiers','Fee','Fees','Post','Posts','Page','Pages','Fan','Fans','Creators','Monthly','Annual','Price','Prices','Bank','Card','Credit','Image','Images','Video','Videos','Photo','Photos','Model','Models','Character','Characters','Dataset','Datasets','Scene','Scenes','Caption','Captions','Prompt','Prompts','Seed','Seeds','Faces','Hair','Body','Style','Lighting','Angle','Angles','Base','Rank','Steps','Rate','Loss','Epoch','Epochs','Batch','Size','Width','Height','Format','Quality','Resolution','Upload','Download','Reply','Replies','Message','Messages','Broadcast','Welcome','Trigger','Automation','Automations','Schedule','Queue','Draft','Drafts','Publish','Published','Review','Live','Online','Public','Private','Subscriber','Subscribers','Member','Members','Membership','Trial','Trials','Promo','Code','Codes','Bundle','Service','Event','Ticket','Tickets','Booking','Bookings','Session','Sessions','Call','Calls','Room','Domain','Domains','Handle','Profile','Bio','Click','Clicks','Views','Likes','Comments','Reach','Growth','Niche','Topic','Content','AI','OFM','DM','DMs','Chat','Agent','Tool','Tools','App','Apps','Platform','Platforms','Network','Networks','Social','Payout','Balance','Earnings','Income','Revenue','Sales','Money','Upgrade','Cancel','Refund','Refunds','Support','Help','Guide','Guides','Tips','Fix','Answer','Question','Questions','FAQ','Example','Examples','Option','Options','Way','Ways','Reason','Reasons','Rule','Rules','Limit','Limits','Minimum','Maximum','Average','Total','Number','Commission','Federal','Trade','Internal','Revenue','Service','Bureau','Department','Office','Center','Centre','Program','Programme','Partner','Research','Statistics','Labor','Labour','Census','Government','Administration','Agency','Authority','Treasury','Pew','Black','Forest','Labs','Diffusers','Rewards','Hub','Community','Standards','Policy','Policies','Terms','Privacy','Endorsement','Endorsements','Disclosure','Disclosures','Influencer','Influencers','Small','Business','Businesses','Self','Employed','Individuals','Form','Forms','Tax','Taxes','Developer','Developers','Docs','Documentation','Paper','Papers','Study','Studies','Report','Reports','Survey','Surveys','Data','Settings','Library','Studio','Inbox','Audience','Dashboard','Pricing','Features','Payouts','Memberships','Services','Events','Bundles','Credits','Wallet','LoRA','Visa','Mastercard','PayPal','Europe','America','Canada','Australia','London','Paris');

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

    /** Product pages an article in this cluster may link to ('/' and '/blog' always). Empty cluster = every allowed path. */
    public static function cluster_links($cluster): array {
        $c = self::cluster($cluster);
        if ($c === '') { return self::allowed_paths(); }
        return array_values(array_unique(array_merge(array('/', '/blog', self::CLUSTERS[$c]['feature']), self::CLUSTERS[$c]['links'])));
    }

    /** The cluster itself plus its related clusters: the articles it may link to. */
    public static function cluster_family($cluster): array {
        $c = self::cluster($cluster);
        return $c === '' ? array() : array_values(array_unique(array_merge(array($c), self::CLUSTERS[$c]['related'])));
    }

    /** quick | howto | guide for a keyword: question-shaped = quick, comparisons and lists = guide, "how to" = howto, else the cluster's default. */
    /**
     * Article formats (2026-10-11). Each has its own structure the prompt asks for and format_errors() checks, so two
     * pieces never share a layout: the pipeline picks one per keyword (format_for) and never publishes two in a row in
     * the same format. 'checks' are the structural facts the body must show.
     */
    const FORMATS = array(
        'guide'           => array('label' => 'step-by-step guide',  'structure' => 'numbered steps in the order the reader does them: every "## " heading starts with a step number ("1. ", "2. "), one step per section, each step opening with what the reader does and ending with how they know it worked. No summary section.'),
        'checklist'       => array('label' => 'checklist',           'structure' => 'a short lead, then one or more checklists: bulleted lists of at least five items the reader ticks off, each item a complete instruction with the specific value or setting, grouped under "## " headings by stage. Most of the body is list items.'),
        'comparison'      => array('label' => 'comparison',          'structure' => 'the two or three options named in the first paragraph, then one "## " section per deciding factor, and one Markdown table (| columns |) that puts the options side by side on the facts. Ends with which option fits which reader, not with a winner.'),
        'cost'            => array('label' => 'cost breakdown',      'structure' => 'a worked example followed from start to finish with round, clearly hypothetical dollar figures (at least four "$" amounts in the body): what comes in, what each fee and plan price takes, what reaches the bank, and the point where the arithmetic changes. One "## " section per stage of the calculation.'),
        'troubleshooting' => array('label' => 'troubleshooting',     'structure' => 'symptom first: each "## " heading names a problem the reader sees ("The face changes between posts"), the first sentence under it names the cause, then the fix, then how to confirm it. No introduction beyond the definition sentence.'),
        'glossary'        => array('label' => 'glossary entry',      'structure' => 'a short entry: the definition sentence, one or two "## " sections (what it covers, where it applies, a concrete example from the product), under 900 words, no steps and no list longer than four items.'),
    );

    /** Format for a keyword from its wording and cluster; $last is the format of the most recent published article, never repeated back to back. */
    public static function format_for($keyword, $cluster = '', $last = ''): string {
        $k = ' ' . strtolower(trim((string) $keyword)) . ' ';
        $order = array();
        if (preg_match('/ (what is|what are|what does|meaning|definition|explained) /', $k)) { $order[] = 'glossary'; }
        if (preg_match('/ (alternatives?|vs|versus|compared?|comparison|best|which|or) /', $k)) { $order[] = 'comparison'; }
        if (preg_match('/ (price|pricing|cost|costs|fee|fees|income|money|earn|earnings|revenue|pay|paid|payout|payouts|worth) /', $k)) { $order[] = 'cost'; }
        if (preg_match('/ (fix|fixing|drift|drifting|wrong|fails?|failing|problems?|mistakes?|why does|why do|not working|keeps?) /', $k)) { $order[] = 'troubleshooting'; }
        if (preg_match('/ (tips|checklist|setup|set up|before you|dataset) /', $k)) { $order[] = 'checklist'; }
        if (strpos($k, ' how to ') === 0 || preg_match('/ (step|steps|guide|train|training|build|create|start) /', $k)) { $order[] = 'guide'; }
        foreach (array('guide', 'checklist', 'comparison', 'cost', 'troubleshooting', 'glossary') as $f) { if (!in_array($f, $order, true)) { $order[] = $f; } }
        foreach ($order as $f) { if ($f !== (string) $last) { return $f; } }
        return 'guide';
    }

    /** The structural facts a format promises, checked on the body (and the FAQ for the glossary). Returns error strings. */
    public static function format_errors(string $format, string $body): array {
        $err = array(); $h2 = self::h2_texts($body);
        switch ($format) {
            case 'guide':
                $numbered = count(array_filter($h2, function ($h) { return preg_match('/^(\d+[.):]|step \d+)/i', $h); }));
                if (count($h2) < 3 || $numbered < count($h2) - 1) { $err[] = 'step-by-step guide: at least three "## " headings and all but one must start with a step number ("1. ")'; }
                break;
            case 'checklist':
                preg_match_all('/^(?:[-*+]|\d+\.)\s+\S/m', $body, $m);
                if (count($m[0]) < 10) { $err[] = 'checklist: the body needs at least ten list items (got ' . count($m[0]) . ')'; }
                break;
            case 'comparison':
                if (!preg_match('/^\|.+\|\s*$\n^\|[\s:|-]+\|\s*$/m', $body)) { $err[] = 'comparison: the body needs one Markdown table putting the options side by side'; }
                if (count($h2) < 2) { $err[] = 'comparison: one "## " section per deciding factor, at least two'; }
                break;
            case 'cost':
                preg_match_all('/\$\s?\d[\d,]*/', $body, $m);
                if (count($m[0]) < 4) { $err[] = 'cost breakdown: the worked example needs at least four dollar figures (got ' . count($m[0]) . ')'; }
                break;
            case 'troubleshooting':
                if (count($h2) < 2) { $err[] = 'troubleshooting: at least two "## " headings, each naming a problem the reader sees'; }
                $q = count(array_filter($h2, function ($h) { return preg_match('/\b(why|what|how|fails?|keeps?|changes?|drifts?|wrong|does not|doesn\x27t|won\x27t|stops?|not)\b/i', $h); }));
                if (count($h2) >= 2 && $q < 2) { $err[] = 'troubleshooting: headings name the symptom ("The face changes between posts", "Why does ... keep ...?")'; }
                break;
            case 'glossary':
                if (count($h2) > 3) { $err[] = 'glossary entry: at most three "## " sections'; }
                if (Markdown::word_count($body) > 1000) { $err[] = 'glossary entry: under 900 words'; }
                break;
        }
        return $err;
    }

    /**
     * Product facts an article may carry that a generic piece cannot: plan names with their fee and price, the payout
     * minimum, the credit rate, included AI credits, storage, influencer counts, the network count, feature names.
     * Read from config, never typed. Each entry: label => regex (case-insensitive) that counts as the fact appearing.
     */
    public static function product_facts(): array {
        $site = Main::site_name(); $facts = array();
        foreach (PagesController::selling_tiers() as $t) {
            $n = preg_quote($t['name'], '/'); $pct = (int) $t['limits']['fee_percent']; $price = number_format((int) $t['price']);
            $facts[$t['name'] . ' fee'] = '/\b' . $pct . '\s?%[^.]{0,60}\b' . $n . '\b|\b' . $n . '\b[^.]{0,60}\b' . $pct . '\s?%/i';
            $facts[$t['name'] . ' price'] = '/\b' . $n . '\b[^.]{0,40}\$' . preg_quote($price, '/') . '\b|\$' . preg_quote($price, '/') . '\b[^.]{0,40}\b' . $n . '\b/i';
            if ((int) ($t['limits']['ai_credits'] ?? 0) > 0) { $facts[$t['name'] . ' AI credits'] = '/\b' . preg_quote(number_format((int) $t['limits']['ai_credits']), '/') . ' (?:ai )?credits\b/i'; }
            if ((int) ($t['limits']['storage_gb'] ?? 0) > 0) { $facts[$t['name'] . ' storage'] = '/\b' . (int) $t['limits']['storage_gb'] . '\s?GB\b/i'; }
            if ((int) ($t['limits']['influencers'] ?? 0) > 0) { $facts[$t['name'] . ' influencers'] = '/\b' . (int) $t['limits']['influencers'] . ' AI influencers?\b|\b(?:one|ten|' . (int) $t['limits']['influencers'] . ') AI influencer\b/i'; }
        }
        $facts['payout minimum'] = '/' . preg_quote(Price::PAYOUT_MIN_LABEL, '/') . '\b[^.]{0,40}\b(?:minimum|payout|cash out|cash-out)|\b(?:minimum|payout|cash out|cash-out)\b[^.]{0,40}' . preg_quote(Price::PAYOUT_MIN_LABEL, '/') . '\b/i';
        $facts['credit rate'] = '/\$1 (?:=|buys|is|equals) ' . (int) Price::CREDITS_PER_DOLLAR . ' credits|\b' . (int) Price::CREDITS_PER_DOLLAR . ' credits (?:per|to the|for a|for every) dollar\b/i';
        $facts['nine networks'] = '/\bnine (?:social )?networks\b/i';
        $facts['credit wallet'] = '/\bcredit wallet\b/i';
        foreach (array('pay-per-view', 'bundles?', 'membership tiers?', 'services and events', 'DM agent', 'AI captions?', 'tracked links?', 'custom domains?', 'welcome messages?', 'broadcasts?') as $f) { $facts['feature ' . $f] = '/\b' . $f . '\b/i'; }
        return $facts;
    }

    /** At least PRODUCT_FACTS_MIN distinct product facts, so the piece says something only this product can. */
    const PRODUCT_FACTS_MIN = 2;
    public static function product_fact_errors(string $body): array {
        $hits = array();
        foreach (self::product_facts() as $label => $re) { if (preg_match($re, $body)) { $hits[] = $label; } }
        // feature names alone are generic words; at least one hit must be a number from config (fee, price, credits, storage, influencers, payout, credit rate)
        $numeric = array_filter($hits, function ($h) { return strpos($h, 'feature ') !== 0 && $h !== 'credit wallet' && $h !== 'nine networks'; });
        if (count($hits) < self::PRODUCT_FACTS_MIN || count($numeric) < 1) {
            return array('the article needs at least ' . self::PRODUCT_FACTS_MIN . ' exact product facts from the facts sheet, one of them a number (a plan\'s fee or price, included AI credits, storage, the payout minimum, the credit rate); got: ' . ($hits ? implode(', ', $hits) : 'none'));
        }
        return array();
    }

    /** Words that do not change what a keyword is about. */
    const INTENT_NOISE = array('a','an','and','the','to','for','of','on','in','with','as','your','you','my','how','what','why','when','which','is','are','do','does','can','should','best','top','guide','tips','tip','step','steps','by','way','ways','ultimate','complete','full','easy','simple','quick','online','vs','versus','or','from','without','that','actually','really','new','2024','2025','2026','creator','creators','content');

    /** The search intent of a keyword as a sorted stem set: "how to monetize digital content" and "monetize digital content" share one. */
    public static function intent_key($keyword): string {
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) $keyword), -1, PREG_SPLIT_NO_EMPTY);
        $stems = array();
        foreach ($words as $w) { if (in_array($w, self::INTENT_NOISE, true) || mb_strlen($w) < 3) { continue; } $stems[self::stem($w)] = true; }
        $stems = array_keys($stems); sort($stems);
        return implode(' ', $stems);
    }

    /** Do two intent keys mean the same search: equal, or one inside the other with at most one extra stem, or 75% shared stems. */
    public static function same_intent(string $a, string $b): bool {
        if ($a === '' || $b === '') { return false; }
        if ($a === $b) { return true; }
        $x = explode(' ', $a); $y = explode(' ', $b);
        $shared = count(array_intersect($x, $y)); $min = min(count($x), count($y)); $max = max(count($x), count($y));
        if ($min >= 3 && $shared === $min && $max - $min <= 1) { return true; }   // "lora training dataset" inside "lora training dataset tips"; a one- or two-stem key must match exactly
        return $min >= 4 && $shared / $max >= 0.8;
    }

    /**
     * The queue after the duplicate check: every queued keyword against every published article (its target keyword and
     * title) and every other queued keyword. Returns ['kept' => rows, 'merged' => rows (+ 'into' => article), 'dropped' => rows
     * (+ 'duplicate_of' => keyword)]. Nothing is written: cron/seo_draft.php --dedupe prints it, --dedupe --apply records it.
     */
    /**
     * Keywords Daniel folded into an existing article by hand (2026-10-11), keyword text (lowercase) => survivor slug. The
     * check treats them as merged whatever their wording, so they draft as an update of that article, never as a new one.
     */
    const EXPLICIT_MERGES = array(
        'character consistency techniques'          => 'consistent-ai-character-across-images',
        'how to keep an ai face consistent'         => 'consistent-ai-character-across-images',
        'how many images to train a lora'           => 'lora-training-dataset-tips',
        'ai dm replies for fan messages'            => 'automated-dm-replies-for-creators',
        'how to compare creator platform pricing'   => 'how-to-pick-a-creator-monetization-platform',
    );

    public static function dedupe_queue(): array {
        $out = array('kept' => array(), 'merged' => array(), 'dropped' => array());
        try { $queue = (new SeoKeywordsModel())->all(); $published = (new SeoArticlesModel())->published(50, 0); }
        catch (\Throwable $e) { return $out; }
        $queued = array_values(array_filter($queue, function ($k) { return (string) $k['status'] === 'queued'; }));
        $by_slug = array(); foreach ($published as $a) { $by_slug[(string) $a['slug']] = $a; }
        usort($queued, function ($a, $b) { return ((int) $a['priority'] <=> (int) $b['priority']) ?: ((int) $a['id'] <=> (int) $b['id']); });
        $kept_keys = array();
        foreach ($queued as $k) {
            $key = self::intent_key($k['keyword']); $hit = null;
            $explicit = self::EXPLICIT_MERGES[strtolower(trim((string) $k['keyword']))] ?? '';
            if ($explicit !== '' && isset($by_slug[$explicit])) { $hit = $by_slug[$explicit]; }
            foreach ($hit ? array() : $published as $a) {
                if (self::same_intent($key, self::intent_key($a['target_keyword'])) || self::same_intent($key, self::intent_key($a['title']))) { $hit = $a; break; }
            }
            if ($hit) { $k['into'] = $hit; $out['merged'][] = $k; continue; }
            $dup = null; foreach ($kept_keys as $kk => $row) { if (self::same_intent($key, $kk)) { $dup = $row; break; } }
            if ($dup) { $k['duplicate_of'] = $dup; $out['dropped'][] = $k; continue; }
            $kept_keys[$key] = $k; $out['kept'][] = $k;
        }
        return $out;
    }

    /** Five-word phrases of a text, lowercased, punctuation dropped, as a set. */
    public static function shingles(string $text, int $n = 5): array {
        $words = preg_split('/\s+/', trim(strtolower(preg_replace('/[^a-z0-9\s]+/i', ' ', preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text)))), -1, PREG_SPLIT_NO_EMPTY);
        $out = array();
        for ($i = 0; $i + $n <= count($words); $i++) { $out[implode(' ', array_slice($words, $i, $n))] = true; }
        return $out;
    }

    /** Share of published articles a phrase may appear in before it counts as stock (a_ai_slop). */
    const STOCK_SHARE = 0.20;
    const STOCK_MIN_ARTICLES = 3;
    private static $stock_cache = null;
    private static $protected_cache = null;

    /**
     * Phrases that are never stock: the product facts the article is required to state (product_facts regexes), the titles
     * of published articles and product pages (they are the link text), and anything with the brand name.
     */
    /** The only phrases the stock check leaves alone: the site name and anything with a figure (the audit removes names and numbers before counting). Facts and titles are reworded, not exempted (2026-10-12). */
    public static function unavoidable_phrase(string $sh): bool { return preg_match('/\d/', $sh) || stripos($sh, Main::site_name()) !== false; }

    /** @deprecated kept for reports: the wider exemption used until 2026-10-12. */
    public static function protected_phrase(string $sh): bool {
        if (self::$protected_cache === null) {
            $titles = array();
            try { foreach ((new SeoArticlesModel())->published(50, 0) as $a) { $titles[] = strtolower(preg_replace('/[^a-z0-9\s]+/i', ' ', (string) $a['title'])); } } catch (\Throwable $e) {}
            try { foreach (SeoController::public_pages() as $pg) { $titles[] = strtolower(preg_replace('/[^a-z0-9\s]+/i', ' ', (string) $pg['title'])); } } catch (\Throwable $e) {}
            $quads = array();
            try { $quads += self::shingles(self::context(), 4); } catch (\Throwable $e) {}   // the facts sheet: product facts, page titles, article titles, source labels
            foreach ($titles as $t) { $quads += self::shingles($t, 4); }
            foreach (self::KNOWN_SOURCES as $u => $label) { $quads += self::shingles($label, 4); }
            self::$protected_cache = array('titles' => array_map(function ($t) { return ' ' . preg_replace('/\s+/', ' ', trim($t)) . ' '; }, $titles), 'facts' => self::product_facts(), 'site' => strtolower(Main::site_name()), 'quads' => $quads);
        }
        $pc = self::$protected_cache;
        if (strpos($sh, $pc['site']) !== false) { return true; }
        // four consecutive words shared with a title, a source label or the facts sheet the model is given: a fact or a link text, not stock
        $w = explode(' ', $sh);
        foreach (array(implode(' ', array_slice($w, 0, 4)), implode(' ', array_slice($w, 1, 4))) as $quad) { if (isset($pc['quads'][$quad])) { return true; } }
        foreach ($pc['titles'] as $t) { if (strpos($t, ' ' . $sh . ' ') !== false) { return true; } }
        $readable = preg_replace('/\b(\d+) (?:a|per) month\b/', '$$1 a month', $sh);   // shingles lost the $ and %; the fact regexes want them back loosely
        foreach ($pc['facts'] as $re) { if (preg_match($re, $sh) || preg_match($re, '$' . $readable) || preg_match($re, preg_replace('/\b(\d+) (?:on|of)\b/', '$1% $2', $sh))) { return true; } }
        if (preg_match('/\b(credits? a month|ai credits|gb of storage|a month each|of each sale|cash out to your bank|to your bank on request|platform fee|platform hold|card processing|credit wallet|social networks|same person|photos or a)\b/', $sh)) { return true; }   // product-fact wording in any spelling
        if (preg_match('/\b(creator|studio|free) plan\b|\b(creator|studio|free) (?:at|is|costs?|takes?|keeps?) \d|\d+ (?:a|per) month|\b\d+ (?:ai )?credits\b|\b\d+ gb\b/', $sh)) { return true; }
        // a number, or product and source vocabulary, makes a phrase a fact in some spelling; stock is the stylistic filler that carries neither
        if (preg_match('/\d/', $sh)) { return true; }
        if (preg_match('/\b(plans?|accounts?|credits?|fees?|sales?|payouts?|bank|wallet|studio|tiers?|memberships?|unlocks?|bundles?|influencers?|irs|ftc|sba|gov|center|centre|help|docs|terms|dollar|dollars|percent|month|monthly|sells?|selling)\b/', $sh)) { return true; }   // a plan name with its price or fee, a monthly price, credits, storage: config numbers are facts, not stock
        return false;
    }
    /** Set false in tests: the stock check otherwise reads every published article on the dev database. */
    public static $check_stock = true;

    /** Chain overlapping five-word phrases into the longer runs they came from ("cash out to your bank on request with no platform hold"), keeping the input order of the first phrase of each run. */
    public static function stock_runs(array $phrases): array {
        $by_head = array(); $by_tail = array(); foreach ($phrases as $p) { $w = explode(' ', $p); $by_head[implode(' ', array_slice($w, 0, 4))][] = $p; $by_tail[implode(' ', array_slice($w, 1, 4))][] = $p; }
        $used = array(); $runs = array();
        foreach ($phrases as $p) {
            if (isset($used[$p])) { continue; }
            $used[$p] = true; $run = explode(' ', $p);
            for ($i = 0; $i < 40; $i++) {   // extend to the right while an unused phrase starts with the run's last four words
                $tail = implode(' ', array_slice($run, -4)); $next = null;
                foreach ($by_head[$tail] ?? array() as $cand) { if (!isset($used[$cand])) { $next = $cand; break; } }
                if ($next === null) { break; }
                $used[$next] = true; $run[] = substr(strrchr(' ' . $next, ' '), 1);
            }
            for ($i = 0; $i < 40; $i++) {   // and to the left while an unused phrase ends with the run's first four words
                $head = implode(' ', array_slice($run, 0, 4)); $prev = null;
                foreach ($by_tail[$head] ?? array() as $cand) { if (!isset($used[$cand])) { $prev = $cand; break; } }
                if ($prev === null) { break; }
                $used[$prev] = true; array_unshift($run, strstr($prev, ' ', true));
            }
            $runs[] = implode(' ', $run);
        }
        return $runs;
    }

    /** The phrases shared by more than STOCK_SHARE of the given bodies (pure, for tests and reports). */
    public static function stock_phrases_in(array $bodies): array {
        $counts = array(); $n = count($bodies);
        foreach ($bodies as $b) { foreach (array_keys(self::shingles((string) $b)) as $sh) { $counts[$sh] = ($counts[$sh] ?? 0) + 1; } }
        $out = array();
        if ($n >= 2) { foreach ($counts as $sh => $k) { if ($k >= 2 && $k / $n > self::STOCK_SHARE) { $out[$sh] = $k / $n; } } }
        return $out;
    }

    /** Five-word phrases present in more than STOCK_SHARE of the published articles (at least two), phrase => share. Cached per process. */
    public static function stock_phrases(int $except_id = 0): array {
        if (self::$stock_cache !== null && self::$stock_cache['except'] === $except_id) { return self::$stock_cache['phrases']; }
        $counts = array(); $n = 0;
        try {
            foreach ((new SeoArticlesModel())->published_markdown() as $row) {
                if ((int) $row['id'] === $except_id || strpos((string) $row['slug'], 'demo-') === 0) { continue; }   // the dev database carries identical demo-* copies; they are not corpus
                $n++;
                foreach (array_keys(self::shingles((string) $row['body_md'])) as $sh) { $counts[$sh] = ($counts[$sh] ?? 0) + 1; }
            }
        } catch (\Throwable $e) { return array(); }
        $out = array();
        if ($n >= 2) { foreach ($counts as $sh => $k) { if ($k >= self::STOCK_MIN_ARTICLES && $k / $n > self::STOCK_SHARE) { $out[$sh] = $k / $n; } } }
        self::$stock_cache = array('except' => $except_id, 'phrases' => $out);
        return $out;
    }

    /**
     * Stock-sentence check: any five-word phrase of this body that already appears in more than STOCK_SHARE of the other
     * published articles is rejected, except phrases made of the article's own topic terms (its target keyword's stems).
     */
    public static function stock_phrase_errors(string $body, int $except_id = 0, string $keyword = '', ?array $stock = null): array {
        if ($stock === null) { if (!self::$check_stock) { return array(); } $stock = self::stock_phrases($except_id); }
        if ($stock === array()) { return array(); }
        $topic = self::tokens($keyword . ' ' . Main::site_name());
        $hits = array();
        foreach (array_keys(self::shingles($body)) as $sh) {
            if (!isset($stock[$sh])) { continue; }
            $topical = 0; foreach (explode(' ', $sh) as $w) { if (in_array(self::stem($w), $topic, true)) { $topical++; } }
            if ($topical >= 2 || self::unavoidable_phrase($sh)) { continue; }   // the phrase is the topic itself, or carries the site name or a figure (the audit strips names and numbers)
            $hits[$sh] = $stock[$sh];
            if (count($hits) >= 6) { break; }
        }
        if ($hits === array()) { return array(); }
        arsort($hits);
        return array('stock phrasing: these five-word phrases already appear in more than ' . (int) round(self::STOCK_SHARE * 100) . '% of published articles, word them for this piece: "' . implode('", "', array_keys($hits)) . '"');
    }

    /** Does a FAQ pair only restate the body: 70% of the answer's content stems already appear in the body? */
    public static function faq_repeats_body(array $pair, string $body): bool {
        $a = self::tokens((string) ($pair['a'] ?? '')); if (count($a) < 4) { return false; }
        $b = array_flip(self::tokens($body)); $hit = 0;
        foreach ($a as $t) { if (isset($b[$t])) { $hit++; } }
        return $hit / count($a) >= 0.7;
    }

    public static function intent_for($keyword, $cluster = ''): string {
        $k = ' ' . strtolower(trim((string) $keyword)) . ' ';
        if (preg_match('/^ (what is|what are|what does|can i|can you|do i|do you|does|is |are |how much|how long|how many|when |should |why )/', $k)) { return 'quick'; }
        if (preg_match('/ (alternatives?|vs|versus|best|compared?|comparison|platforms?|guide) /', $k)) { return 'guide'; }
        if (strpos($k, ' how to ') === 0) { return 'howto'; }
        $c = self::cluster($cluster);
        return $c !== '' ? (string) self::CLUSTERS[$c]['intent'] : 'howto';
    }

    /** array(min, max) words the validator accepts for an intent (the target range +/- 20%). */
    public static function word_bounds($intent): array {
        if (!isset(self::LENGTHS[(string) $intent])) { return array(self::MIN_WORDS, self::MAX_WORDS); }
        $r = self::LENGTHS[(string) $intent];
        return array((int) floor($r[0] * 0.8), (int) ceil($r[1] * 1.2));
    }

    /** Is this an https link to a domain on CITATION_DOMAINS? */
    public static function citation_ok($url): bool {
        $parts = @parse_url((string) $url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user'])) { return false; }
        $host = strtolower((string) $parts['host']);
        foreach (self::CITATION_DOMAINS as $d) { if ($host === $d || substr($host, -strlen('.' . $d)) === '.' . $d) { return true; } }
        return false;
    }

    /** Is this relative link inside the cluster's allow-list (pages) or link family (articles)? $article_clusters = slug => cluster. */
    private static function link_in_cluster($path, $cluster, array $article_clusters): bool {
        if (strpos($path, '/blog/') === 0) { return in_array((string) ($article_clusters[substr($path, 6)] ?? ''), self::cluster_family($cluster), true); }
        return in_array($path, self::cluster_links($cluster), true);
    }

    /** Internal links outside the cluster's allow-list or family, plus "cluster is required". Applied to every article, drafted or edited. */
    public static function link_errors(array $a, $article_clusters = null): array {
        $cluster = self::cluster($a['cluster'] ?? '');
        if ($cluster === '') { return array('cluster is required (it sets the link allow-list)'); }
        if ($article_clusters === null) { try { $article_clusters = (new SeoArticlesModel())->published_clusters(); } catch (\Throwable $e) { $article_clusters = array(); } }
        $err = array();
        foreach (Markdown::links((string) ($a['body_md'] ?? '')) as $l) {
            if (strpos($l, '/') !== 0 || strpos($l, '//') === 0) { continue; }   // external: content_errors() checks citations
            $path = preg_replace('/[#?].*$/', '', $l);
            if (!self::link_in_cluster($path, $cluster, (array) $article_clusters)) { $err[] = (strpos($path, '/blog/') === 0 ? "article link outside this cluster's family: " : "page link outside this cluster's allow-list: ") . $l; }
        }
        return $err;
    }

    /** Links outside the cluster's allow-list (and external links not on CITATION_DOMAINS) become their plain text. */
    /** External links strip_links() turned into plain text (off the citation list), so a "0 citations" error can say why. */
    public static $stripped_external = array();

    public static function strip_links($md, $cluster, $article_clusters = null): string {
        $cluster = self::cluster($cluster);
        if ($cluster !== '' && $article_clusters === null) { try { $article_clusters = (new SeoArticlesModel())->published_clusters(); } catch (\Throwable $e) { $article_clusters = array(); } }
        $allowed = self::allowed_paths();
        self::$stripped_external = array();
        return preg_replace_callback('/\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) use ($cluster, $article_clusters, $allowed) {
            $l = $m[2]; $path = preg_replace('/[#?].*$/', '', $l);
            if (preg_match('#^https?://#i', $l)) { if (self::citation_ok($l)) { return $m[0]; } self::$stripped_external[] = $l; return $m[1]; }
            if (strpos($l, '/') !== 0 || strpos($l, '//') === 0 || !in_array($path, $allowed, true)) { return $m[1]; }
            if ($cluster === '') { return $m[0]; }
            return self::link_in_cluster($path, $cluster, (array) $article_clusters) ? $m[0] : $m[1];
        }, (string) $md);
    }

    /** Articles this draft should link to: its cluster and related clusters only, same cluster first, newest first. */
    public static function link_targets($cluster, $except_id = 0): array {
        $family = self::cluster_family($cluster);
        if (empty($family)) { try { return (new SeoArticlesModel())->for_linking('', (int) $except_id, 6); } catch (\Throwable $e) { return array(); } }
        try { $rows = (new SeoArticlesModel())->for_linking_clusters($family, (int) $except_id, 12); }
        catch (\Throwable $e) { return array(); }
        usort($rows, function ($a, $b) use ($family) { return array_search($a['cluster'], $family, true) <=> array_search($b['cluster'], $family, true); });   // stable: newest first within a cluster
        return array_slice($rows, 0, 6);
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

    /** Walks a JSON text and escapes raw newlines, carriage returns and tabs that sit inside string literals (the usual reason a long reply fails to parse). */
    public static function escape_json_strings(string $t): string {
        $out = ''; $in = false; $esc = false; $n = strlen($t);
        for ($i = 0; $i < $n; $i++) {
            $c = $t[$i];
            if ($in) {
                if ($esc) { $esc = false; $out .= $c; continue; }
                if ($c === '\\') { $esc = true; $out .= $c; continue; }
                if ($c === '"') { $in = false; $out .= $c; continue; }
                if ($c === "\n") { $out .= '\n'; continue; }
                if ($c === "\r") { continue; }
                if ($c === "\t") { $out .= '\t'; continue; }
                $out .= $c; continue;
            }
            if ($c === '"') { $in = true; }
            $out .= $c;
        }
        return $out;
    }

    /** Accepts a raw model reply; strips ``` fences and returns the decoded object or null. */
    public static function parse_json($text){
        $t = trim((string) $text);
        $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
        $t = preg_replace('/\s*```$/', '', $t);
        $d = json_decode($t, true);
        if (!is_array($d)) { $s = strpos($t, '{'); $e = strrpos($t, '}'); if ($s !== false && $e !== false && $e > $s) { $t = substr($t, $s, $e - $s + 1); $d = json_decode($t, true); } }
        if (!is_array($d)) { $d = json_decode(self::escape_json_strings($t), true); }   // a model sometimes leaves real newlines or tabs inside a string

        return is_array($d) ? $d : null;
    }

    /** Live plan names and prices from config: array(name => price), plus the retired names. */
    private static function plan_prices(): array {
        $live = array(); $retired = array();
        foreach (PlanTiers::all() as $t) {
            if (!empty($t['retired'])) { $retired[] = (string) $t['name']; continue; }
            $live[(string) $t['name']] = (int) $t['price'];
        }
        return array($live, $retired);
    }

    /** Every competitor we name anywhere (compare pages and the alternatives lists). */
    private static function competitor_names(): array {
        $names = array_column(PagesController::COMPETITORS, 'name');
        $walk = function ($v) use (&$walk, &$names) { if (is_array($v)) { if (isset($v['name']) && is_string($v['name'])) { $names[] = $v['name']; } foreach ($v as $c) { if (is_array($c)) { $walk($c); } } } };
        if (class_exists('AlternativesPages')) { $walk(AlternativesPages::PAGES); }
        return array_values(array_unique(array_filter(array_map('strval', $names))));
    }

    /** Fee percent per live plan, from PlanTiers: array(name => percent). */
    private static function plan_fees(): array {
        $out = array();
        foreach (PlanTiers::all() as $t) { if (empty($t['retired'])) { $out[(string) $t['name']] = (int) ($t['limits']['fee_percent'] ?? 0); } }   // every live plan, Free included
        return $out;
    }

    /**
     * A monthly "$N" written right next to a plan name ("Creator is $99 a month", "the Studio plan costs $99/mo",
     * "Creator ($99 a month)", "$99 a month for Creator"). Never $1 or $25 (credits, payout minimum) or a non-monthly amount. Calls $fn(plan, amount, match) and returns the text with each match replaced.
     */
    private static function each_plan_price($text, callable $fn): string {
        list($live) = self::plan_prices();
        $site = Main::site_name();
        $text = str_replace($site, "\x01", (string) $text);   // "Creator Link Studio" is not the Creator or Studio plan
        $addons = array();   // "$15 a month each on the Creator plan": an add-on price, not the plan's
        foreach (PlanTiers::ADDONS as $ad) { $addons[] = (float) $ad['price']; }
        $skip = function ($amt) use ($addons) { return in_array((float) str_replace(',', '', $amt), array_merge(array(1.0, 25.0), $addons), true); };   // $1 = 10 credits, $25 payout minimum, add-on prices
        foreach (array_keys($live) as $name) {
            $n = preg_quote($name, '/');
            $per = '(?=\s*(?:a|per)\s+month\b|\s*\/\s*(?:month|mo)\b|\s+monthly\b)';   // a plan PRICE says per month
            $text = preg_replace_callback('/\b(' . $n . ')(\s+plan)?(\s*(?:is|costs?|runs|at|for|=|\()\s*(?:only\s+|just\s+|about\s+)?)\$(\d[\d,]*(?:\.\d+)?)' . $per . '/', function ($m) use ($fn, $skip) {
                return $m[1] . $m[2] . $m[3] . '$' . ($skip($m[4]) ? $m[4] : $fn($m[1], $m[4]));
            }, $text);
            $text = preg_replace_callback('/\$(\d[\d,]*(?:\.\d+)?)(\s*(?:(?:a|per)\s+month|\/\s*(?:month|mo)|monthly)\b\s+(?:for|on)\s+(?:the\s+)?)(' . $n . ')\b/', function ($m) use ($fn, $skip) {
                return '$' . ($skip($m[1]) ? $m[1] : $fn($m[3], $m[1])) . $m[2] . $m[3];
            }, $text);
        }
        return str_replace("\x01", $site, $text);
    }

    /** Every plan price written next to a plan name takes that plan's config price (a wrong plan's price included). */
    public static function fix_plan_prices($text): string {
        list($live) = self::plan_prices();
        return self::each_plan_price($text, function ($plan, $amt) use ($live) {
            $price = (int) ($live[$plan] ?? 0);
            return (float) str_replace(',', '', $amt) === (float) $price ? $amt : number_format($price);
        });
    }

    /**
     * Repairs fee wording around plan names, whole tokens only: rejoins a plan name an older --fix-fee run split
     * ("10% on Creato r" -> "Creator"), and separates a word glued onto a plan name ("3% on Studiodepending" ->
     * "3% on Studio, depending"). "Creators" and an intact "10% on Creator," are never touched.
     */
    public static function fix_fee_glue($text): string {
        list($live, $retired) = self::plan_prices();
        foreach (array_merge(array_keys($live), $retired) as $name) {
            if (mb_strlen($name) < 3) { continue; }
            $n = preg_quote($name, '/'); $head = preg_quote(substr($name, 0, -1), '/'); $tail = preg_quote(substr($name, -1), '/');
            $text = preg_replace('/(\d+% on (?:the )?)' . $n . '((?!s\b)[a-z]+) ([a-z])\b/', '$1' . $name . ', $2$3', (string) $text);   // "Studiodependin g"
            $text = preg_replace('/(\d+% on (?:the )?)' . $head . ' ' . $tail . '\b/', '$1' . $name, $text);                          // "Creato r"
            $text = preg_replace('/(\d+% on (?:the )?' . $n . ')((?!s\b)[a-z]+)/', '$1, $2', $text);                                    // "Studiodepending"
        }
        return (string) $text;
    }

    /**
     * The config sentences the publisher used to inject into every article ({{fee}}, {{plans}}, the non-refundable
     * line). Injection stopped 2026-10-11: it glued half-sentences together and put the same paragraph in every
     * piece. An article may state the fee or the plan prices once, in its own words; copying one of these word for
     * word is rejected, and clean_boilerplate() strips them from older articles.
     */
    public static function boilerplate_sentences(): array {
        return array_values(array_filter(array(
            PagesController::fee_sentence(),
            PagesController::plan_price_sentence(),
            PagesController::FINAL_NOTE,
            'Free accounts are for fans and cannot sell',
        )));
    }

    /** Does a paragraph state our platform fee: a percentage, a fee word, and our brand or one of our plan names? */
    public static function states_our_fee(string $p): bool {
        list($live) = self::plan_prices();
        $names = array_merge(array(Main::site_name()), array_keys($live));
        $re = implode('|', array_map(function ($n) { return preg_quote($n, '/'); }, $names));
        return (bool) (preg_match('/\d+(?:\.\d+)?\s*%/', $p) && preg_match('/\b(?:fee|fees|take rate|cut|commission|keeps?)\b/i', $p) && $re !== '' && preg_match('/\b(?:' . $re . ')\b/', $p));
    }

    /** Does a paragraph state one of our plan prices ("Creator is $49 a month")? */
    public static function states_plan_prices(string $p): bool {
        $hit = false;
        self::each_plan_price($p, function ($plan, $amt) use (&$hit) { $hit = true; return $amt; });
        return $hit;
    }

    /** Sentences in a paragraph (links read as their text). */
    public static function sentence_count(string $p): int {
        $p = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', trim($p));
        return count(array_filter(preg_split('/(?<=[.!?])\s+/', $p), function ($s) { return str_word_count($s) > 1; }));
    }

    /**
     * Mechanical fixes applied before validate(), so a small slip doesn't stop the day's article from publishing:
     * an over-long meta description is cut at a word boundary, links outside the cluster's allow-list become plain text
     * (citations to CITATION_DOMAINS stay), a plan price that is not the config value is replaced with it, and dashes
     * become commas. Nothing is injected: the fee, the plan prices and the refund rule are the article's own words or absent.
     */
    public static function fit(array $a): array {
        $a['body_md'] = self::linkify_bare_citations((string) ($a['body_md'] ?? ''));
        foreach (array('title', 'meta_description', 'excerpt', 'body_md') as $f) {
            $v = (string) ($a[$f] ?? '');
            $v = preg_replace('/\s*\x{2014}\s*/u', ', ', $v);                    // em dash
            $v = preg_replace('/(\d)\s*\x{2013}\s*(\d)/u', '$1-$2', $v);          // en dash in a range
            $v = preg_replace('/\s*\x{2013}\s*/u', ', ', $v);
            $a[$f] = $v;
        }
        if (isset($a['faq']) && is_array($a['faq'])) {
            foreach ($a['faq'] as $i => $f) {
                if (!is_array($f)) { continue; }
                foreach (array('q', 'a') as $k) {
                    $v = (string) ($f[$k] ?? '');
                    $v = preg_replace('/(\d)\s*\x{2013}\s*(\d)/u', '$1-$2', $v);
                    $a['faq'][$i][$k] = preg_replace('/\s*[\x{2013}\x{2014}]\s*/u', ', ', $v);
                }
            }
        }
        $meta = trim(preg_replace('/\s+/', ' ', (string) ($a['meta_description'] ?? '')));
        if (mb_strlen($meta) > 155) {
            $cut = mb_substr($meta, 0, 154); $sp = mb_strrpos($cut, ' ');
            $meta = rtrim($sp !== false && $sp > 100 ? mb_substr($cut, 0, $sp) : $cut, " ,;:-") . '.';
            if (mb_strlen($meta) > 155) { $meta = mb_substr($meta, 0, 155); }
        }
        $a['meta_description'] = $meta;
        $a['body_md'] = self::strip_links((string) ($a['body_md'] ?? ''), $a['cluster'] ?? '');
        $a['body_md'] = self::fix_plan_prices($a['body_md']);
        if (isset($a['faq']) && is_array($a['faq'])) {   // a FAQ that restates the body is noise: keep only pairs that add a question
            $kept = array(); foreach ($a['faq'] as $f) { if (is_array($f) && !self::faq_repeats_body($f, $a['body_md'])) { $kept[] = $f; } }
            $a['faq'] = count($kept) >= 2 ? $kept : array();
        }
        return $a;
    }

    /**
     * Content rules shared by validate() and the nightly fact check: no income promises, no people's names,
     * no em or en dashes, no payment processor, only CITATION_DOMAINS as external links, current plan prices only,
     * no leftover tokens, no facts-sheet sentence copied word for word, and the fee and plan prices each stated in at
     * most one paragraph. Returns error strings.
     */
    public static function content_errors(array $a): array {
        $err = array();
        $body = (string) ($a['body_md'] ?? '');
        $faq = $a['faq'] ?? array();
        $faq_text = is_array($faq) ? implode("\n", array_map(function ($f) { return is_array($f) ? (string) ($f['q'] ?? '') . ' ' . (string) ($f['a'] ?? '') : (string) $f; }, $faq)) : (string) $faq;
        $all = (string) ($a['title'] ?? '') . "\n" . (string) ($a['meta_description'] ?? '') . "\n" . (string) ($a['excerpt'] ?? '') . "\n" . $body . "\n" . $faq_text;
        $plain = preg_replace('/\]\([^)]*\)/', ']', $all);   // link targets are not prose
        if (preg_match('/[\x{2013}\x{2014}]/u', $all)) { $err[] = 'em or en dash not allowed (use a comma or a period)'; }
        if (preg_match('/\bstripe\b/i', $plain)) { $err[] = 'never name the payment processor'; }
        if (strpos($all, '{{') !== false) { $err[] = 'unreplaced {{token}} left in the text'; }
        foreach (Markdown::links($body) as $l) {
            if (preg_match('#^https?://#i', $l) && !self::citation_ok($l)) { $err[] = 'external link to a domain not on the citation list: ' . $l; }
        }
        // income promises
        $promise = preg_replace('/\b(?:no|not|never|without|nothing is|isn\'t a|is not a|there is no|cannot|can\'t|can not|could not|won\'t|will not)\s+(?:a\s+|any\s+)?guarantee[sd]?\b/i', '', $plain);
        if (preg_match('/\bguarantee[sd]?\b/i', $promise)) { $err[] = 'income promise: "guaranteed"'; }
        if (preg_match('/\byou(?:\'ll| will| are going to|\'re going to| can expect to)\s+(?:make|earn|bring in|take home)\b/i', $plain, $m)) { $err[] = 'income promise: "' . $m[0] . '"'; }
        if (preg_match('/\byou(?:\'d| can| could| might| may| should| would)\s+(?:easily\s+)?(?:make|earn|bring in|take home)\s+(?:\$|\d|money|a living|a full[- ]time|six|seven|thousands|hundreds|passive|serious|real money)/i', $plain, $m)) { $err[] = 'income promise: "' . $m[0] . '"'; }
        if (preg_match('/\b(?:six|seven|five)[- ]figures?\b/i', $plain, $m)) { $err[] = 'income promise: "' . $m[0] . '"'; }
        $amount = '(?:\$\s?\d[\d,.]*k?|\d[\d,.]*k?\s*(?:dollars|usd)\b)';
        foreach (preg_split('/(?<=[.!?])\s+|\n+/', $plain) as $sentence) {
            if (!preg_match('/' . $amount . '\s*(?:a|per|\/|each|every)\s*(?:month|mo\b|week|year)|' . $amount . '\s*(?:monthly|a year)\b/i', $sentence)) { continue; }
            if (!preg_match('/\b(?:make|makes|making|earn|earns|earning|earnings|income|revenue|bring in|take home|profit)\b/i', $sentence)) { continue; }
            if (preg_match('/\b(?:some creators|varies|vary|between|cannot guarantee|can\'t guarantee)\b/i', $sentence)) { continue; }   // honest ranges
            if (preg_match('/\b(?:say,? (?:as an? \w+,? )?you|suppose|imagine|for example|hypothetical|run the (?:arithmetic|numbers|maths?|math)|work backwards|the income you want|point difference|members at)\b|a month gross\b/i', $sentence)) { continue; }   // worked examples and arithmetic, not promises
            $err[] = 'earnings claim stated as fact: "' . mb_substr(trim($sentence), 0, 90) . '"';
            break;
        }
        // people's names (brand, competitor and SAFE_WORDS removed first; headings skipped)
        $names_text = str_ireplace(array_merge(array(Main::site_name()), array_column(PagesController::COMPETITORS, 'name')), ' ', preg_replace('/^\s*#.*$/m', ' ', $plain));
        $person = function ($w) { return !in_array($w, self::SAFE_WORDS, true); };
        $name_hit = '';
        if (preg_match('/\b(?:Mr|Mrs|Ms|Mx|Dr|Prof)\.?\s+[A-Z][a-z]+/', $names_text, $m)) { $name_hit = $m[0]; }
        if ($name_hit === '' && preg_match_all('/\b(?:' . implode('|', self::FIRST_NAMES) . ')\b/', $names_text, $mm)) { foreach ($mm[0] as $w) { if ($person($w)) { $name_hit = $w; break; } } }
        if ($name_hit === '' && preg_match_all('/\b(?:by|from|with|says|named|called)\s+([A-Z][a-z]+)\b(?![\w-])/', $names_text, $mm)) { foreach ($mm[1] as $w) { if ($person($w)) { $name_hit = $w; break; } } }
        if ($name_hit === '' && preg_match_all('/(?<![.!?:]\s)(?<!^)\b([A-Z][a-z]+)\'s\b/m', $names_text, $mm)) { foreach ($mm[1] as $w) { if ($person($w)) { $name_hit = $w . "'s"; break; } } }
        if ($name_hit === '' && preg_match_all('/\b([A-Z][a-z]+) ([A-Z][a-z]+),? (?:says|said|writes|wrote|explains|explained|told|notes|noted|recommends)\b/', $names_text, $mm, PREG_SET_ORDER)) { foreach ($mm as $x) { if ($person($x[1]) && $person($x[2])) { $name_hit = $x[0]; break; } } }
        if ($name_hit !== '') { $err[] = 'personal name not allowed: "' . $name_hit . '"'; }
        // plan prices and fee percentages only as configured; retired plans not at all
        list($live, $retired) = self::plan_prices();
        foreach ($retired as $r) { if (preg_match('/\b' . preg_quote($r, '/') . ' plan\b/i', $plain)) { $err[] = 'names the retired ' . $r . ' plan'; } }
        self::each_plan_price($plain, function ($plan, $amt) use ($live, &$err) {
            if ((float) str_replace(',', '', $amt) !== (float) ($live[$plan] ?? -1)) { $err[] = "$plan plan price \$$amt is not the configured price"; }
            return $amt;
        });
        $fees = self::plan_fees();
        $plain_ns = str_replace(Main::site_name(), "\x01", $plain);
        $plan_re = implode('|', array_map(function ($n) { return preg_quote($n, '/'); }, array_keys($live)));
        $comp_re = implode('|', array_map(function ($n) { return preg_quote($n, '/'); }, self::competitor_names()));
        foreach (preg_split('/(?<=[.!?])\s+|\n+/', $plain_ns) as $sentence) {
            if (!preg_match('/\d+(?:\.\d+)?\s*%/', $sentence) || !preg_match('/\b(?:fee|fees|take|takes|take rate|cut|commission|keeps?)\b/i', $sentence)) { continue; }
            if (strpos($sentence, "\x01") === false && ($plan_re === '' || !preg_match('/\b(?:' . $plan_re . ')\b/', $sentence))) { continue; }   // not about us
            if (preg_match('/\bcard processing\b|\bprocessing fees?\b/i', $sentence) && !preg_match('/\b(?:' . $plan_re . ')\b/', $sentence)) { continue; }
            // a figure belongs to its own clause; clauses about a competitor are not ours to check
            foreach (preg_split('/\s*(?:[,;]|\b(?:while|whereas|versus|vs\.?|and|but)\b)\s*/i', $sentence) as $clause) {
                if (!preg_match_all('/(\d+(?:\.\d+)?)\s*%/', $clause, $pm, PREG_OFFSET_CAPTURE)) { continue; }
                if ($comp_re !== '' && preg_match('/\b(?:' . $comp_re . ')(?![\w])/i', $clause)) { continue; }
                foreach ($pm[1] as $p) {
                    $pct = (float) $p[0]; $pos = (int) $p[1];
                    $plan = ''; $best = PHP_INT_MAX;
                    if ($plan_re !== '' && preg_match_all('/\b(' . $plan_re . ')\b/', $clause, $x, PREG_OFFSET_CAPTURE)) {
                        foreach ($x[1] as $hit) { $d = abs($hit[1] - $pos); if ($d < $best) { $best = $d; $plan = $hit[0]; } }   // nearest plan in the clause
                    }
                    if ($plan !== '' && isset($fees[$plan])) { if ($pct !== (float) $fees[$plan]) { $err[] = "fee $p[0]% next to the $plan plan is not the configured " . $fees[$plan] . '%'; } }
                    elseif (strpos($clause, "\x01") !== false && !in_array($pct, array_map('floatval', array_values($fees)), true)) { $err[] = "platform fee $p[0]% is not a configured fee"; }
                }
            }
        }
        // the facts sheet is context, not copy: a sentence lifted from it word for word reads as the same paragraph in every article
        foreach (self::boilerplate_sentences() as $s) {
            if (stripos($plain, $s) !== false) { $err[] = 'copied from the facts sheet word for word, say it in the article\'s own words or leave it out: "' . mb_substr($s, 0, 60) . '"'; break; }
        }
        // the fee and the plan prices: once each, where the topic needs them
        $fee_paras = 0; $price_paras = 0;
        foreach (preg_split('/\n\s*\n/', $body) as $p) {
            if (preg_match('/^\s*(#|\|)/', $p)) { continue; }
            if (self::states_our_fee($p)) { $fee_paras++; }
            if (self::states_plan_prices($p)) { $price_paras++; }
        }
        if ($fee_paras > 1) { $err[] = 'the platform fee is stated in ' . $fee_paras . ' paragraphs; state it once, where the topic needs it, or leave it out'; }
        if ($price_paras > 1) { $err[] = 'plan prices are stated in ' . $price_paras . ' paragraphs; state them once, where the topic needs them, or leave them out'; }
        return array_values(array_unique($err));
    }

    /**
     * Hard rules. Returns error strings; empty array = valid.
     * The cluster is required and every internal link must sit inside its allow-list / link family (always).
     * $strict_links adds the counts (3+ article links, 1 feature link): on when the drafter had 3+ articles to offer.
     * $a['intent'] (quick|howto|guide) sets the length band.
     */
    public static function validate(array $a, int $except_id = 0, bool $strict_links = false): array {
        $err = array();
        $title = trim((string) ($a['title'] ?? '')); $slug = (string) ($a['slug'] ?? ''); $meta = trim((string) ($a['meta_description'] ?? ''));
        $body = (string) ($a['body_md'] ?? ''); $faq = (array) ($a['faq'] ?? array());
        if ($title === '') { $err[] = 'title must be 1-60 characters'; }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) < 3 || strlen($slug) > 120) { $err[] = 'slug must be lowercase a-z 0-9 and hyphens, 3-120 chars'; }
        $words = Markdown::word_count($body);
        list($min_w, $max_w) = self::word_bounds($a['intent'] ?? '');
        if ($words < $min_w || $words > $max_w) { $err[] = "body must be $min_w-$max_w words (got $words)"; }
        $allowed = self::allowed_paths();
        foreach (Markdown::links($body) as $l) {
            $path = preg_replace('/[#?].*$/', '', $l);
            if (preg_match('#^https?://#i', $l)) { continue; }   // citations: quotability_errors() counts them, content_errors() checks the domain
            if (strpos($l, '/') !== 0 || strpos($l, '//') === 0) { $err[] = "external link not allowed: $l"; }
            elseif (!in_array($path, $allowed, true)) { $err[] = "unknown internal link: $l"; }
        }
        $err = array_merge($err, self::quotability_errors($a));
        $err = array_merge($err, self::product_fact_errors($body));   // a product-specific element, or it does not publish
        if (!empty($a['format']) && isset(self::FORMATS[(string) $a['format']])) { $err = array_merge($err, self::format_errors((string) $a['format'], $body)); }
        if (self::$check_citation_urls) { foreach (self::unreachable_citations(self::citations($body)) as $u) { $err[] = 'cited URL does not resolve: ' . $u . self::known_instead($u); } }
        $err = array_merge($err, self::link_errors($a));   // allow-list and cluster: always, drafted or edited
        if ($strict_links) {
            $article_links = 0; $feature_links = 0;
            $feature = self::feature_for($a['cluster'] ?? '');
            foreach (Markdown::links($body) as $l) {
                if (preg_match('#^https?://#i', $l)) { continue; }
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
        if ($n !== 0 && ($n < 2 || $n > 6)) { $err[] = 'faq is either empty or 2-6 question/answer pairs'; }   // optional: most pieces have none
        $err = array_merge($err, self::content_errors($a));
        try { if ($slug !== '' && (new SeoArticlesModel())->slug_exists($slug, $except_id)) { $err[] = 'slug already exists'; } } catch (\Throwable $e) {}
        return $err;
    }

    /**
     * Markdown::render plus citation links: Markdown keeps only relative links, so a link to a CITATION_DOMAINS
     * page is swapped for a placeholder, rendered, then put back as an external anchor.
     */
    public static function render_body($md): string {
        $cites = array();
        $md = preg_replace_callback('/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/', function ($m) use (&$cites) {
            if (!self::citation_ok($m[2])) { return $m[1]; }
            $cites[] = array($m[1], $m[2]);
            return 'clscite' . (count($cites) - 1) . 'x';
        }, (string) $md);
        $html = Markdown::render($md, self::allowed_paths());
        return preg_replace_callback('/clscite(\d+)x/', function ($m) use ($cites) {
            if (!isset($cites[(int) $m[1]])) { return ''; }
            list($text, $url) = $cites[(int) $m[1]];
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" rel="nofollow noopener" target="_blank">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
        }, $html);
    }

    /** The product facts block, shared with SupportAssist. */
    public static function product_context(): string { return self::context(); }

    /** Facts Claude may use; nothing else is allowed to appear as a number or a claim. */
    private static function context(): string {
        $site = Main::site_name(); $base = SeoMeta::base();
        $lines = array("Product: $site ($base). A creator platform: one public page at $base/@handle with posts, membership tiers, pay-per-view posts, content bundles, services, events and tracked links; a studio that publishes to nine social networks with AI captions; an inbox with AI replies; payouts to the creator's bank; fans pay memberships by card and everything else with a credit wallet (\$1 = 10 credits).");
        foreach (PagesController::our_facts() as $k => $v) { $lines[] = ucfirst($k) . ': ' . $v; }
        // the fee as data, not as a sentence the model can lift: a sentence copied word for word is rejected by content_errors()
        $fee_bits = array(); foreach (PagesController::selling_tiers() as $t) { $fee_bits[] = (int) $t['limits']['fee_percent'] . '% of each sale on ' . $t['name'] . ' ($' . number_format((int) $t['price']) . ' a month)'; }
        $lines[] = 'Platform fee (state it in your own words, at most once): ' . implode('; ', $fee_bits) . '. Free is the fan account: it sells nothing and pays no fee. Card processing is charged separately. Never describe the fee as a range, never attach a fee to the Free plan, and never write "20%" about ' . $site . '.';
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
        foreach (SeoController::public_pages() as $p) { $lines[] = '- ' . $p['path'] . ': ' . $p['title'] . '. ' . $p['description']; }
        try {
            $recent = (new SeoArticlesModel())->published(8, 0);
            if (!empty($recent)) { $lines[] = 'Published articles you may link to (and must not repeat):'; foreach ($recent as $a) { $lines[] = '- /blog/' . $a['slug'] . ': ' . $a['title'] . '. ' . (string) $a['excerpt']; } }
        } catch (\Throwable $e) {}
        $lines[] = 'Competitors you may name only as "other subscription platforms", with no fees and no claims: ' . implode(', ', array_column(PagesController::COMPETITORS, 'name')) . '.';
        $lines[] = 'Source pages known to exist (cite one of these where it backs a claim, or another page on the same sites that you are certain exists; every cited URL is fetched and a missing page rejects the article):';
        foreach (self::KNOWN_SOURCES as $u => $what) { $lines[] = '- ' . $u . ' : ' . $what; }
        return implode("\n", $lines);
    }

    private static function system_prompt(): string {
        $site = Main::site_name();
        return "You write practical, plain-English guides for the $site blog, read by independent creators who sell content, memberships and services online. Voice: direct, specific, second person, no hype, no filler, no emoji, sentence-case headings. Never invent statistics, studies, quotes, prices, fees or competitor facts; use only the facts in the context. A worked pricing example with round, clearly hypothetical numbers is fine, with figures chosen for this piece (never the same member count, price and total another article used); never present invented numbers as real data or averages. "
            . "No income promises: never write \"guaranteed\", \"you will make\", \"you can make\", \"you'll earn\" or \"six figures\", and never put an amount per month or year next to make, earn, income or revenue, even in an example, unless it is an honest range such as \"some creators earn between X and Y, and it varies\". "
            . "Name no people at all, real or invented: no founders, no experts, no example creators with names; say \"a fitness creator\" or \"one creator\". "
            . "Plan prices, the platform fee and the refund rule: most pieces need none of them. Where the topic genuinely needs one, state it once, in your own words, with exactly the figures and plan names in the context, in one paragraph that also says why it matters to the reader. Never copy a sentence from the context word for word, and never attach a fee to the Free plan. "
            . "Never use an em dash or an en dash; use a comma, a colon or a period. Never mention being an AI. Never name the payment processor (no \"Stripe\"); say \"payouts to your bank\". Mention $site naturally at most three times, only where it genuinely helps, and link to its pages using the relative paths given. "
            . "Sources: cite at least " . self::MIN_CITATIONS . " specific facts from " . self::MIN_CITATIONS . " different independent primary sources on this list (official documentation, government guidance, platform terms and help pages, published studies; never a blog or news piece that summarises one), as full https URLs to pages you know exist; never invent a URL, and cite nothing outside the list: " . implode(', ', self::CITATION_DOMAINS) . ". At most four citations, each where it backs a claim. "
            . "Shape the piece for the question, not for a template. The request names the format and its structure, the heading style, how the section lengths vary and whether there is a FAQ; follow it exactly, so no two pieces share a layout. Whatever the format, at least two \"## \" headings are questions a creator actually asks, ending in a question mark, each answered in the first sentence under it. No obligatory introduction, takeaways, summary or conclusion. Vary transitions as well: never open two sections with the same phrase, and never end a section with a one-line summary of itself. "
            . "Product facts: the piece must say at least two things only this product can, taken exactly from the context (a plan's fee or price, the included AI credits or storage, the payout minimum, the credit rate, a named feature as it works here), in the article's own words. A piece that could be published by any platform is rejected. "
            . "Write sentences of 10 to 25 words and paragraphs of at most six sentences, one idea per paragraph. The opening paragraph is a direct answer to the title in 40 to 60 words, starting with a one-sentence definition of its subject in the shape \"X is ...\" (no metaphor or anecdote first); context and caveats come after it. The first sentence of every paragraph names its subject outright (the product, the tool, the number, the timeframe or the reader's role) and contains no pronoun that points back to an earlier paragraph: not \"it\", \"they\", \"this\", \"that\", \"these\", \"those\", \"he\", \"she\" or \"such\". Never reuse a stock sentence from another article; word every point for this piece. The first sentence under each heading names the subject of that section, repeating the heading's key noun, and gives its answer. "
            . "Output ONLY a JSON object with keys: title (30-60 chars, sentence case), slug (lowercase-hyphenated, <=80 chars), meta_description (110-155 chars saying what the reader gets, no adjectives), excerpt (one or two sentences), body_md (Markdown at the length the request gives, the \"## \" sections the request asks for, relative links from the allowed list where they help, no H1, no raw HTML), faq (array: empty unless the request asks for a FAQ, then 2-6 {\"q\",\"a\"} objects answering real search questions), secondary_keywords (array of 3-6 short phrases).";
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
    /** The four signup lines: one per article (by id), and never a heading, so the same sentence does not repeat across the whole blog. */
    const CTA_LINES = array(
        'Your page, your prices, paid to your bank.',
        'Sell memberships, posts and services from one page.',
        'One page for everything you sell.',
        'Put your work behind a price you set.',
    );

    public static function cta_html(int $article_id = 0): string {
        $site = htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8');
        $line = self::CTA_LINES[$article_id % count(self::CTA_LINES)];
        return '<aside class="gd-cta">'
            . '<p class="gd-cta__title">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p class="gd-cta__text">' . $site . ' gives you one public page with memberships, pay-per-view posts, bundles, services and events, and pays out to your bank.</p>'
            . '<a class="gd-cta__btn" href="/?auth=register">Create Your Account</a>'
            . '</aside>';
    }

    /**
     * The signup aside placed into an article's rendered HTML. Its position follows the article id: after the body,
     * after the first section, or before the last section, so the block is not in the same place on every page.
     * An article with fewer than two sections keeps it at the end.
     */
    public static function place_cta(string $html, int $article_id): string {
        $cta = self::cta_html($article_id);
        $parts = preg_split('/(?=<h2\b)/', $html);   // [0] = text before the first heading, then one part per section
        $n = count($parts); $where = $article_id % 3;
        if ($n < 3 || $where === 0) { return $html . $cta; }
        if ($where === 1) { $parts[1] .= $cta; }                   // after the first section
        else { $parts[$n - 1] = $cta . $parts[$n - 1]; }          // before the last section
        return implode('', $parts);
    }

    /**
     * Strips the sentences the old publisher injected (SeoDrafter::boilerplate_sentences, in any plan/price values), the
     * half-sentence fragments that injection left behind (a paragraph or clause starting with ", with card processing
     * separate." or ". Free accounts ..."), and splits any paragraph over PARAGRAPH_MAX_SENTENCES at a sentence boundary.
     * Headings, lists, tables and quotes are left alone. Returns the cleaned Markdown; equal to the input when nothing applied.
     */
    /** The injected sentences as regexes (clean_boilerplate, and the FAQ answers in cron/seo_fact_check.php --fix-rules). */
    public static function boilerplate_patterns(): array {
        $site = preg_quote(Main::site_name(), '/');
        return array(
            // "Creator Link Studio's platform fee is 10% of each sale on the Creator plan ($49/month) and 3% ... Free accounts ... Card processing fees are separate." (any prices, possibly doubled lead-in)
            '/(?:' . $site . '\'s platform fee is )+\d+% of each sale on the \w+ plan \(\$[\d,]+\/month\)(?:(?:,| and) \d+% of each sale on the \w+ plan \(\$[\d,]+\/month\))*\.(?:\s*Free accounts are for fans and cannot sell, so they pay no platform fee\.)?(?:\s*Card processing fees are separate\.)?/u',
            // "Free $0, Creator $49, Studio $199, all per month."
            '/(?:\b[A-Z]\w+ \$[\d,]+, )+[A-Z]\w+ \$[\d,]+, all per month\./u',
            // the refund rule, wherever it was appended
            '/' . preg_quote(PagesController::FINAL_NOTE, '/') . '/u',
            // the Free-account sentence in every variant ("..., so they pay no platform fee." / "..., so inbox automation is not included there.")
            '/Free accounts are for fans and cannot sell[^.!?\n]*[.!?]/u',
        );
    }

    /** Plain text (a FAQ answer, an excerpt) with the injected sentences removed. */
    public static function strip_boilerplate_text(string $text): string {
        return trim(preg_replace('/\s{2,}/', ' ', preg_replace(self::boilerplate_patterns(), '', $text)));
    }

    public static function clean_boilerplate(string $md): string {
        $patterns = self::boilerplate_patterns();
        $out = array();
        foreach (preg_split('/\n\s*\n/', $md) as $p) {
            $orig = trim($p); $t = $orig;
            if ($t === '' || preg_match('/^(#|[-*+]\s|>|\||`|\d+\.\s)/', $t)) { $out[] = $t; continue; }   // headings, lists, quotes, tables, code; a paragraph opening in **bold** is prose
            $t = trim(preg_replace('/\s{2,}/', ' ', preg_replace($patterns, '', $t)));
            // a paragraph that opens with punctuation or a lowercase clause (", with card processing separate.") is a fragment left where a token sat
            $fragment = (bool) preg_match('/^[,.;:]\s*|^\p{Ll}/u', $t);
            if ($t === $orig && !$fragment) { foreach (self::split_long_paragraph($t) as $piece) { $out[] = $piece; } continue; }   // untouched prose: only the length rule applies
            if (preg_match('/^[,.;:]\s*/', $t)) { $t = preg_replace('/^[,.;:]\s*/', '', $t); }
            if ($t !== '' && preg_match('/^\p{Ll}/u', $t)) { $t = trim(preg_replace('/^[^.!?]*[.!?]\s*/u', '', $t)); if (preg_match('/^\p{Ll}/u', $t)) { $t = ''; } }   // drop the clause up to its first sentence end; no sentence end = nothing to keep
            // a sentence whose verb runs straight into punctuation ("the platform fee is , card processing is separate") lost its object to the removal
            $kept = array(); foreach (preg_split('/(?<=[.!?])\s+/', $t) as $s) { if ($s !== '' && !preg_match('/\b(?:is|are|was|were|be|costs?|takes?)\s*[,.;:]/i', $s)) { $kept[] = $s; } } $t = trim(implode(' ', $kept));
            // a lead-in left dangling after the removal ("Fees shape the arithmetic. On Creator Link Studio, ")
            if ($t !== '' && !preg_match('/[.!?)"\']$/u', $t)) { $t = trim(preg_replace('/(?<=[.!?])\s+[^.!?]*$/u', '', $t)); if (!preg_match('/[.!?)"\']$/u', $t)) { $t = ''; } }
            if ($t === '' || str_word_count(preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $t)) < 4) { continue; }
            foreach (self::split_long_paragraph($t) as $piece) { $out[] = $piece; }
        }
        return trim(implode("\n\n", $out)) . "\n";
    }

    /** A paragraph over PARAGRAPH_MAX_SENTENCES becomes two or more, split at the sentence boundary nearest the middle. */
    public static function split_long_paragraph(string $p): array {
        $n = self::sentence_count($p);
        if ($n <= self::PARAGRAPH_MAX_SENTENCES) { return array($p); }
        $sentences = preg_split('/(?<=[.!?])\s+(?=[^a-z])/', $p);   // never splits inside "$1.50 a month" or after an abbreviation followed by a lowercase word
        if (count($sentences) < 2) { return array($p); }
        $half = (int) ceil(count($sentences) / 2);
        $a = trim(implode(' ', array_slice($sentences, 0, $half))); $b = trim(implode(' ', array_slice($sentences, $half)));
        return array_merge(self::split_long_paragraph($a), self::split_long_paragraph($b));
    }

    /**
     * The corpus-shape pass: which published articles to regenerate so the blog does not read as one template. The per-article
     * rules cannot see this; the audit scores it (a_ai_slop). Two shares are held: at most FAQ_SHARE of articles end in a
     * FAQ, and no single section count covers more than BUCKET_SHARE of the corpus. The oldest articles in the excess are
     * chosen first. $rows: id, slug, body_md, faq, published_at. Returns slug => ['reason' => why, 'avoid' => what the
     * rewrite must not reproduce ('faq' => true, 'sections' => n)], for draft() and the nightly report.
     */
    const FAQ_SHARE = 0.35;
    const BUCKET_SHARE = 0.4;

    public static function corpus_shape_flags(array $rows): array {
        $n = count($rows); $flags = array();
        if ($n < 5) { return $flags; }   // too small a corpus to have a shape
        usort($rows, function ($a, $b) { return strcmp((string) ($a['published_at'] ?? ''), (string) ($b['published_at'] ?? '')) ?: ((int) $a['id'] <=> (int) $b['id']); });   // oldest first
        $shape = array();
        foreach ($rows as $r) {
            $faq = json_decode((string) ($r['faq'] ?? '[]'), true); $has_faq = false;
            foreach ((array) $faq as $f) { if (is_array($f) && trim((string) ($f['q'] ?? '')) !== '') { $has_faq = true; break; } }
            $shape[(string) $r['slug']] = array('faq' => $has_faq, 'sections' => count(self::h2_texts((string) ($r['body_md'] ?? ''))));
        }
        $with_faq = count(array_filter($shape, function ($s) { return $s['faq']; }));
        $excess = (int) ceil($with_faq - self::FAQ_SHARE * $n);
        foreach ($shape as $slug => $s) {
            if ($excess <= 0) { break; }
            if (!$s['faq']) { continue; }
            $flags[$slug] = array('reason' => 'ends in a FAQ like ' . (int) round(100 * $with_faq / $n) . '% of the blog; rewrite without one', 'avoid' => array('faq' => true));
            $excess--;
        }
        $buckets = array();
        foreach ($shape as $slug => $s) { $buckets[$s['sections']][] = $slug; }
        foreach ($buckets as $count => $slugs) {
            $over = (int) ceil(count($slugs) - self::BUCKET_SHARE * $n);
            if ($over <= 0) { continue; }
            foreach ($slugs as $slug) {   // oldest first; one already flagged for its FAQ also avoids this section count
                if ($over <= 0) { break; }
                $why = 'has ' . $count . ' sections like ' . (int) round(100 * count($slugs) / $n) . '% of the blog; rewrite with a different number';
                if (isset($flags[$slug])) { $flags[$slug]['reason'] .= '; ' . $why; $flags[$slug]['avoid']['sections'] = (int) $count; }
                else { $flags[$slug] = array('reason' => $why, 'avoid' => array('sections' => (int) $count)); }
                $over--;
            }
        }
        return $flags;
    }

    /**
     * $dry_run: one Claude call, no cover, saved as a 'draft' article (even when it fails a rule, so it can be read), never published.
     * $avoid (a rewrite for corpus shape): 'faq' => true forbids a FAQ, 'sections' => n makes the draw pick another section count.
     */
    public static function draft(array $kw, string $note = '', int $existing_article_id = 0, bool $dry_run = false, array $avoid = array(), string $format = ''): array {
        $keywords = new SeoKeywordsModel(); $articles = new SeoArticlesModel();
        $kid = (int) $kw['id']; $keyword = (string) $kw['keyword'];
        $existing = $existing_article_id > 0 ? $articles->get($existing_article_id) : null;
        $prev = $keywords->get($kid); $prev_status = (string) ($prev['status'] ?? 'queued');   // restored if a rewrite fails
        $keywords->set_status($kid, 'drafting');
        $cluster = self::cluster($existing['cluster'] ?? ($kw['cluster'] ?? ''));
        if ($cluster === '') {   // no cluster = no link allow-list: never drafted, and no Claude call spent
            $keywords->set_status($kid, $existing_article_id > 0 ? $prev_status : 'skipped', null, 'no cluster: set one in /admin');
            return array('ok' => false, 'article_id' => 0, 'error' => 'no cluster');
        }
        $feature = self::feature_for($cluster);
        $targets = self::link_targets($cluster, $existing_article_id);
        $intent = self::intent_for($keyword, $cluster); $len = self::LENGTHS[$intent];
        if ($format === '' && !empty($existing['format']) && isset(self::FORMATS[(string) $existing['format']])) { $format = (string) $existing['format']; }   // a rewrite keeps the layout it was given (a restructured glossary stays a glossary); only an explicit --format changes it (2026-10-12)
        if ($format === '' || !isset(self::FORMATS[$format])) {   // the keyword's format, never the one the last published article used
            $last = ''; try { $last = (string) ($articles->last_published_format()); } catch (\Throwable $e) {}
            $format = self::format_for($keyword, $cluster, $last);
        }
        if ($format === 'glossary') { $intent = 'quick'; $len = self::LENGTHS['quick']; }
        $user = "Target keyword: \"$keyword\"" . (!empty($kw['volume']) ? " (about {$kw['volume']} searches/month)" : '') . ".";
        if ($cluster !== '') { $user .= "\nTopic cluster: " . self::CLUSTERS[$cluster]['label'] . "."; }
        // a different target inside the band, and a different shape, each time: the corpus stops landing on one length and one layout
        $target = random_int((int) $len[0], (int) $len[1]);
        $shapes = array('the direct answer in the first paragraph, then the detail', 'a walkthrough in the order the reader does it', 'a checklist the reader can work through',
                        'a comparison of the two or three ways to do it', 'the common mistake first, then the fix', 'a short piece that answers one question and stops',
                        'a worked example followed from start to finish', 'the objections a reader has, answered one by one');
        // the structure is drawn per draft (section count by length band, heading style, length rhythm, FAQ or none), so the corpus never settles on one layout
        $section_bands = array('quick' => array(1, 3), 'howto' => array(2, 5), 'guide' => array(3, 7));
        $sections = random_int($section_bands[$intent][0], $section_bands[$intent][1]);
        if (!empty($avoid['sections_exact'])) { $sections = (int) $avoid['sections_exact']; }   // a restructure names its section count outright
        for ($i = 0; isset($avoid['sections']) && $sections === (int) $avoid['sections'] && $i < 8; $i++) { $sections = random_int($section_bands[$intent][0], $section_bands[$intent][1]); }
        if (isset($avoid['sections']) && $sections === (int) $avoid['sections']) { $sections = $sections > $section_bands[$intent][0] ? $sections - 1 : $sections + 1; }
        $heading_styles = array('short noun phrases ("The first paid post")', 'the exact questions a reader would type, ending in a question mark', 'imperative steps ("Pick the entry price first")',
                                'a mix: one or two questions, the rest plain statements', 'numbered stages ("1. Choose the tier")', 'contrasts ("Memberships or pay-per-view")', 'the reader\'s situation ("When your audience is under a hundred people")');
        $rhythms = array('the first section short and the rest longer', 'one long section and the rest short', 'roughly even sections', 'the longest section in the middle', 'sections that get shorter towards the end', 'two long sections and the rest one paragraph each', 'alternating one-paragraph and four-paragraph sections');
        if (!empty($avoid['rhythm'])) { $rhythms = array((string) $avoid['rhythm']); }
        $faq_hint = (empty($avoid['faq']) && random_int(1, 10) <= 3) ? 'End with a FAQ of ' . random_int(2, 5) . ' real follow-up questions.' : 'No FAQ.';
        $user .= "\nLength: about $target words of body_md (a " . $len[2] . '; stay between ' . $len[0] . ' and ' . $len[1] . ').';
        $user .= "\nShape: " . $shapes[random_int(0, count($shapes) - 1)] . '.';
        $user .= "\nSections: exactly $sections \"## \" headings" . (!empty($avoid['sections_exact']) ? ', not one more or fewer' : '') . '.';   // the count was drawn but never told to the model before 2026-10-12, so every rewrite kept its old layout
        $user .= "\nFormat: " . self::FORMATS[$format]['label'] . '. Structure: ' . self::FORMATS[$format]['structure'];
        $user .= "\nHeadings written as " . $heading_styles[random_int(0, count($heading_styles) - 1)] . ' where the format allows, with ' . $rhythms[random_int(0, count($rhythms) - 1)] . '. At least two headings are questions a creator asks.';
        $user .= "\n" . $faq_hint;
        if ($cluster !== '') { $user .= "\nThe only product pages you may link to: " . implode(', ', self::cluster_links($cluster)) . '.'; }
        $user .= "\n\nLink to at least THREE of these published articles, each where it genuinely helps the reader, using their exact paths:\n";
        foreach ($targets as $t) { $user .= '- /blog/' . $t['slug'] . ': ' . $t['title'] . "\n"; }
        if (empty($targets)) { $user .= "(none published yet, link to the product pages instead)\n"; }
        $user .= "Link exactly once to the feature page $feature, where it fits the topic.\n";
        $stock = array(); try { $all = self::stock_phrases($existing_article_id); arsort($all); foreach (array_keys($all) as $sh) { if (!self::unavoidable_phrase($sh)) { $stock[] = $sh; } } $stock = array_slice(self::stock_runs($stock), 0, 60); } catch (\Throwable $e) {}   // every repeated phrase the audit counts, chained into readable runs, most shared first (2026-10-12: product facts and titles included, worded afresh each time)
        if ($stock) { $user .= "\n\nPhrases other articles already use; do not write any of them, word the point differently (a product fact keeps its numbers but changes its wording; a link to another article uses a short descriptive anchor of two to five words, never that article's title): \"" . implode('", "', $stock) . '"'; }
        $user .= "\n\nBefore you answer, check your own draft against this list and fix anything that misses: (1) the opening paragraph is 40 to 60 words, count them; (2) the first sentence of every paragraph, and the subtitle, contain none of these words anywhere: it, its, they, them, their, this, these, those, he, she, such (write the noun instead); (3) at least two \"## \" headings end with a question mark and the sentence under each answers it; (4) no paragraph has more than six sentences; (5) at least two exact product facts from the context, one with a number; (6) at least two citations to two different sources on the list; (7) none of the phrases listed above appears.";
        $user .= "\nContext (the only facts you may use):\n" . self::context();
        if ($note !== '') { $user .= "\n\nEditor's note for this rewrite: $note"; }
        if ($existing) {
            $user .= "\n\nCurrent draft to revise (keep what works, apply the editor's note, return the full article):\nTitle: " . (string) $existing['title'] . "\n\n" . (string) $existing['body_md'];
        }
        $errors = array(); $data = null; $model = ClaudeService::model(); $raw = '';
        for ($attempt = 1; $attempt <= ($dry_run ? 1 : self::DRAFT_ATTEMPTS); $attempt++) {
            $msg = $user . ($attempt > 1 && !empty($errors) ? "\n\nYour previous draft failed these checks; fix every one and return the full JSON again:\n- " . implode("\n- ", $errors) : '');
            $r = ClaudeService::chat(self::system_prompt(), array(array('role' => 'user', 'content' => $msg)), 8000, 240, 'medium');
            if (empty($r['ok'])) { $errors = array('Claude: ' . (string) ($r['error'] ?? 'request failed')); continue; }
            $raw = (string) $r['text'];
            $data = self::parse_json($r['text']);
            if ($data === null) { $errors = array('reply was not valid JSON'); continue; }
            $data['slug'] = $existing ? (string) $existing['slug'] : self::slugify((string) ($data['slug'] ?? $data['title'] ?? $keyword));   // a rewrite never moves the URL
            if ($existing_article_id === 0) { $base = $data['slug']; $i = 2; while ((new SeoArticlesModel())->slug_exists($data['slug'])) { $data['slug'] = $base . '-' . $i++; } }
            $data['cluster'] = $cluster; $data['intent'] = $intent; $data['format'] = $format;
            $data = self::fit($data);
            $errors = self::validate($data, $existing_article_id, count($targets) >= 3);
            if (empty($errors) && $existing_article_id === 0) {
                $dupes = self::duplicates((string) $data['title'], $keyword, $existing_article_id);
                if (!empty($dupes)) {
                    $errors = array('this topic is already covered (' . $dupes[0]['why'] . '): "' . $dupes[0]['title'] . '". Cover a different angle with a different title and primary keyword.');
                }
            }
            if (empty($errors)) { break; }
            if (self::split_errors($errors)[0] === array() && !preg_grep('/already covered|Claude:|not valid JSON/', $errors)) { break; }   // every factual check passed: publish now with the style notes attached; style is never worth a second call
        }
        $style_notes = array();
        if (!empty($errors) && $data !== null) {   // only style notes left after the last attempt: the draft publishes and carries them
            list($hard, $soft) = self::split_errors($errors);
            if ($hard === array() && !preg_grep('/already covered|Claude:|not valid JSON/', $errors)) { $style_notes = $soft; $errors = array(); }
        }
        if ($dry_run && $data !== null && $existing_article_id === 0) {   // keep what came back, as a draft, for reading
            $aid = $articles->create(self::article_fields($data, $keyword, $cluster, $model, 'draft'));
            $keywords->set_status($kid, empty($errors) ? 'drafted' : $prev_status, empty($errors) ? $aid : null, empty($errors) ? null : implode('; ', $errors));
            return array('ok' => empty($errors), 'article_id' => $aid, 'error' => implode('; ', $errors));
        }
        if (!empty($errors) || $data === null) {
            if ($dry_run) { $keywords->set_status($kid, $prev_status, null, implode('; ', $errors)); return array('ok' => false, 'article_id' => 0, 'error' => implode('; ', $errors)); }
            if ($raw !== '') {   // the reply that failed the last attempt, for reading
                $dump = sys_get_temp_dir() . '/cls-seo-draft-fail-' . preg_replace('/[^a-z0-9-]+/', '-', strtolower($existing ? (string) $existing['slug'] : $keyword)) . '-' . gmdate('Ymd-His') . '.txt';
                if (@file_put_contents($dump, "Errors:\n- " . implode("\n- ", $errors) . "\n\nReply:\n" . $raw) !== false) { $errors[] = 'reply saved: ' . $dump; }
            }
            $dupe = (bool) preg_grep('/already covered/', $errors);
            if ($existing_article_id === 0 && $data !== null && !$dupe) {   // the draft waits in Unpublished with the reason shown in admin; it never goes live
                $fields = self::article_fields($data, $keyword, $cluster, $model, 'review');
                $fields['rewrite_note'] = 'Checks failed: ' . mb_substr(implode('; ', $errors), 0, 900);
                $aid = $articles->create($fields);
                $keywords->set_status($kid, 'drafted', $aid, implode('; ', $errors));
                return array('ok' => false, 'article_id' => $aid, 'error' => implode('; ', $errors));
            }
            if ($existing_article_id > 0) { $keywords->set_status($kid, $prev_status === 'drafting' ? 'drafted' : $prev_status, null, implode('; ', $errors)); }
            elseif ($dupe) { $keywords->set_status($kid, 'skipped', null, implode('; ', $errors)); }   // covered already: retire the keyword instead of publishing a near-duplicate
            else { $keywords->set_status($kid, 'queued', null, implode('; ', $errors)); $keywords->set_priority($kid, (int) ($prev['priority'] ?? $kw['priority'] ?? 100) + 50); }   // one bad keyword can't block the queue daily
            return array('ok' => false, 'article_id' => 0, 'error' => implode('; ', $errors));
        }
        $fields = self::article_fields($data, $keyword, $cluster, $model, 'review');
        $fields['rewrite_note'] = $style_notes ? 'Style notes: ' . mb_substr(implode('; ', $style_notes), 0, 900) : null;   // advisory, shown in admin and the nightly report
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

    /** The seo_articles row for a drafted article. Author is always the team, never a person. */
    private static function article_fields(array $data, string $keyword, string $cluster, $model, string $status): array {
        return array(
            'slug' => $data['slug'], 'title' => trim((string) $data['title']), 'meta_description' => trim((string) $data['meta_description']),
            'excerpt' => trim((string) ($data['excerpt'] ?? '')), 'body_md' => (string) $data['body_md'],
            'body_html' => self::render_body((string) $data['body_md']),
            'target_keyword' => $keyword, 'cluster' => $cluster, 'format' => (string) ($data['format'] ?? ''), 'author' => Main::site_name() . ' team', 'secondary_keywords' => json_encode(array_values((array) ($data['secondary_keywords'] ?? array())), JSON_UNESCAPED_UNICODE),
            'faq' => json_encode(array_values((array) ($data['faq'] ?? array())), JSON_UNESCAPED_UNICODE),
            'reading_minutes' => self::reading_minutes((string) $data['body_md']), 'status' => $status,
            'rewrite_note' => null, 'model' => $model, 'prompt_version' => self::PROMPT_VERSION,
        );
    }
}
