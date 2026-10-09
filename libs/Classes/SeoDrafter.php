<?php
/**
 * Drafts one blog article for a keyword with Claude, validates it against hard rules, and publishes it
 * as soon as it passes (no admin review since 2026-09-22). One Claude call per draft, one retry on validation failure.
 */
class SeoDrafter {
    const PROMPT_VERSION = 'v2';
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

    /** The https links in a body that point at a CITATION_DOMAINS host. */
    public static function citations(string $body): array {
        return array_values(array_filter(Markdown::links($body), function ($l) { return preg_match('#^https?://#i', $l) && self::citation_ok($l); }));
    }

    /**
     * The rules the SEO audit scores, on title, meta, headings, citations and prose (no network): title <= 60, meta
     * 110-155, >= 2 "## " sections of which >= 2 are questions, >= MIN_CITATIONS distinct cited hosts, sentence length
     * and paragraph openers. Shared by validate() (new drafts) and the nightly fact check (so an older article that
     * breaks them is flagged and --rewrite takes it).
     */
    public static function quotability_errors(array $a): array {
        $err = array();
        $title = trim((string) ($a['title'] ?? '')); $meta = trim((string) ($a['meta_description'] ?? '')); $body = (string) ($a['body_md'] ?? '');
        if ($title !== '' && mb_strlen($title) > 60) { $err[] = 'title must be 1-60 characters'; }   // 30-60 as rendered, with the brand suffix dropped past 60
        if (mb_strlen($meta) < 110 || mb_strlen($meta) > 155) { $err[] = 'meta_description must be 110-155 characters'; }
        $h2 = self::h2_texts($body);
        if (count($h2) < 2) { $err[] = 'body needs at least 2 "## " sections'; }
        $questions = count(array_filter($h2, function ($h) { return substr($h, -1) === '?'; }));
        if (count($h2) >= 2 && $questions < 2) { $err[] = 'at least 2 "## " headings must be the reader\'s question (ending in ?)'; }
        $hosts = array_unique(array_map(function ($u) { return strtolower((string) parse_url($u, PHP_URL_HOST)); }, self::citations($body)));
        if (count($hosts) < self::MIN_CITATIONS) {
            $err[] = 'body needs at least ' . self::MIN_CITATIONS . ' citations to ' . self::MIN_CITATIONS . ' different sources on the citation list (got ' . count($hosts) . ')'
                . (self::$stripped_external ? '; links to domains not on the list were removed: ' . implode(', ', array_slice(array_unique(self::$stripped_external), 0, 4)) : '');
        }
        return array_merge($err, self::prose_errors($body));
    }

    /** The "## " headings of a Markdown body, in order. */
    public static function h2_texts(string $body): array {
        preg_match_all('/^##\s+(.+?)\s*$/m', $body, $m);
        return array_map('trim', $m[1]);
    }

    /**
     * Quotability rules the audit scores: 10-25 words per sentence on average, and paragraphs that name their subject
     * instead of opening with "this", "they", "it" or "such". Returns error strings.
     */
    public static function prose_errors(string $body): array {
        $err = array();
        $plain = preg_replace(array('/^#.*$/m', '/\[([^\]]*)\]\([^)]*\)/', '/[*_`>|-]+/'), array('', '$1', ' '), $body);
        $sentences = array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/', $plain)), function ($s) { return str_word_count($s) > 2; });
        if (count($sentences) >= 5) {
            $avg = array_sum(array_map('str_word_count', $sentences)) / count($sentences);
            if ($avg > 25) { $err[] = sprintf('sentences average %.0f words; keep the average between 10 and 25', $avg); }
            if ($avg < 10) { $err[] = sprintf('sentences average %.0f words; keep the average between 10 and 25', $avg); }
        }
        foreach (preg_split('/\n\s*\n/', $body) as $p) {
            $p = trim($p);
            if ($p === '' || preg_match('/^(#|[-*>|]|\d+\.)/', $p)) { continue; }
            if (preg_match('/^(This|These|That|Those|They|It|Such)\b/', $p, $m)) { $err[] = 'paragraph opens with "' . $m[1] . '"; name the subject instead: "' . mb_substr($p, 0, 60) . '"'; break; }
        }
        return $err;
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

    /** What the drafter writes in place of the model's tokens: fee and plan prices always come from config. */
    public static function tokens_map(): array {
        return array(
            '{{fee}}' => PagesController::fee_sentence() . ' ' . PagesController::FINAL_NOTE,
            '{{plans}}' => PagesController::plan_price_sentence() . ' ' . PagesController::FINAL_NOTE,
            '{{final_note}}' => PagesController::FINAL_NOTE,
        );
    }

    /** Does this text talk about our plans or credits (and so need the non-refundable line)? */
    private static function money_topic($text): bool {
        list($live, $retired) = self::plan_prices();
        $names = implode('|', array_map(function ($n) { return preg_quote($n, '/'); }, array_merge(array_keys($live), $retired)));
        return (bool) preg_match('/\bcredits\b|\bcredit (?:wallet|purchases?|packs?|balance)\b/i', (string) $text) || ($names !== '' && preg_match('/\b(?:' . $names . ')\s+plans?\b|\bplan (?:price|prices|charge|charges|cost|costs)\b/', (string) $text));
    }

    /**
     * Mechanical fixes applied before validate(), so a small slip doesn't stop the day's article from publishing:
     * an over-long meta description is cut at a word boundary, links outside the cluster's allow-list become plain text
     * (citations to CITATION_DOMAINS stay), {{fee}}/{{plans}}/{{final_note}} become the config sentences, a plan price
     * that is not the config value is replaced with it, dashes become commas, and the non-refundable line is added
     * where plans or credits come up without it.
     */
    public static function fit(array $a): array {
        $map = self::tokens_map();
        foreach (array('title', 'meta_description', 'excerpt', 'body_md') as $f) {
            $v = (string) ($a[$f] ?? '');
            $v = str_replace(array_keys($map), array_values($map), $v);
            $v = preg_replace('/\s*\x{2014}\s*/u', ', ', $v);                    // em dash
            $v = preg_replace('/(\d)\s*\x{2013}\s*(\d)/u', '$1-$2', $v);          // en dash in a range
            $v = preg_replace('/\s*\x{2013}\s*/u', ', ', $v);
            $a[$f] = $v;
        }
        if (isset($a['faq']) && is_array($a['faq'])) {
            foreach ($a['faq'] as $i => $f) {
                if (!is_array($f)) { continue; }
                foreach (array('q', 'a') as $k) {
                    $v = str_replace(array_keys($map), array_values($map), (string) ($f[$k] ?? ''));
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
        // plans or credits discussed without the non-refundable line: add it to the first paragraph that raises them
        if (self::money_topic($a['body_md']) && stripos($a['body_md'], 'non-refundable') === false) {
            $lines = explode("\n", $a['body_md']);
            foreach ($lines as $i => $line) {
                if (trim($line) === '' || preg_match('/^\s*(#|\||[-*]\s|\d+[.)]\s|>)/', $line) || !self::money_topic($line)) { continue; }
                $lines[$i] = rtrim($line) . ' ' . PagesController::FINAL_NOTE;
                break;
            }
            $a['body_md'] = implode("\n", $lines);
        }
        return $a;
    }

    /**
     * Content rules shared by validate() and the nightly fact check: no income promises, no people's names,
     * no em or en dashes, no payment processor, only CITATION_DOMAINS as external links, current plan prices only,
     * no leftover tokens, and the non-refundable line wherever plans or credits come up. Returns error strings.
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
        if (self::money_topic($body . "\n" . $faq_text) && stripos($all, 'non-refundable') === false) { $err[] = 'plans or credits are discussed without the non-refundable line'; }
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
        if (self::$check_citation_urls) { foreach (self::unreachable_citations(self::citations($body)) as $u) { $err[] = 'cited URL does not resolve: ' . $u; } }
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
        if ($n !== 0 && ($n < 3 || $n > 5)) { $err[] = 'faq is either empty or 3-5 question/answer pairs'; }   // optional: not every piece ends in a FAQ
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
        return "You write practical, plain-English guides for the $site blog, read by independent creators who sell content, memberships and services online. Voice: direct, specific, second person, no hype, no filler, no emoji, sentence-case headings. Never invent statistics, studies, quotes, prices, fees or competitor facts; use only the facts in the context. A worked pricing example with round, clearly hypothetical numbers (\"50 members at \$10 is \$500 before fees\") is fine; never present invented numbers as real data or averages. "
            . "No income promises: never write \"guaranteed\", \"you will make\", \"you can make\", \"you'll earn\" or \"six figures\", and never put an amount per month or year next to make, earn, income or revenue, even in an example, unless it is an honest range such as \"some creators earn between X and Y, and it varies\". Never type a fee percentage. "
            . "Name no people at all, real or invented: no founders, no experts, no example creators with names; say \"a fitness creator\" or \"one creator\". "
            . "Never type a plan price, a platform fee percentage or a credit price yourself. Where the article states the platform fee write the token {{fee}}, where it states plan prices write {{plans}}; the publisher replaces them with the current wording. Wherever plans, plan charges or credits come up, include {{final_note}} once (it says plan charges and credit purchases are final and non-refundable) unless {{fee}} or {{plans}} is already in that section. "
            . "Never use an em dash or an en dash; use a comma, a colon or a period. Never mention being an AI. Never name the payment processor (no \"Stripe\"); say \"payouts to your bank\". Mention $site naturally at most three times, only where it genuinely helps, and link to its pages using the relative paths given. "
            . "Sources: cite at least " . self::MIN_CITATIONS . " specific facts from " . self::MIN_CITATIONS . " different official sources on this list, as full https URLs to pages you know exist (a help-centre article, a published document); never invent a URL, and cite nothing outside the list: " . implode(', ', self::CITATION_DOMAINS) . ". At most four citations, each where it backs a claim. "
            . "Shape the piece for the question, not for a template: no fixed number of sections, no obligatory introduction, takeaways, summary or conclusion, and a FAQ only when the topic has real follow-up questions (then 3-5, otherwise none). Use between two and six \"## \" sections and vary their kind between pieces (a short direct answer first, a walkthrough, a checklist, a comparison, a problem-first piece). Phrase at least two \"## \" headings as the question a reader would type, ending in a question mark, and put the direct answer in the first sentence under each. "
            . "Write sentences of 10 to 25 words. Open every paragraph by naming its subject (the product, the fan, the tier, the file), never with \"this\", \"these\", \"they\", \"it\" or \"such\", so each paragraph reads correctly on its own. "
            . "Output ONLY a JSON object with keys: title (30-60 chars, sentence case), slug (lowercase-hyphenated, <=80 chars), meta_description (110-155 chars saying what the reader gets, no adjectives), excerpt (one or two sentences), body_md (Markdown at the length the request gives, 2-6 \"## \" sections, relative links from the allowed list where they help, no H1, no raw HTML), faq (array, empty or 3-5 {\"q\",\"a\"} objects answering real search questions), secondary_keywords (array of 3-6 short phrases).";
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

    /** $dry_run: one Claude call, no cover, saved as a 'draft' article (even when it fails a rule, so it can be read), never published. */
    public static function draft(array $kw, string $note = '', int $existing_article_id = 0, bool $dry_run = false): array {
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
        $user = "Target keyword: \"$keyword\"" . (!empty($kw['volume']) ? " (about {$kw['volume']} searches/month)" : '') . ".";
        if ($cluster !== '') { $user .= "\nTopic cluster: " . self::CLUSTERS[$cluster]['label'] . "."; }
        // a different target inside the band, and a different shape, each time: the corpus stops landing on one length and one layout
        $target = random_int((int) $len[0], (int) $len[1]);
        $shapes = array('the direct answer in the first paragraph, then the detail', 'a walkthrough in the order the reader does it', 'a checklist the reader can work through',
                        'a comparison of the two or three ways to do it', 'the common mistake first, then the fix', 'a short piece that answers one question and stops');
        $user .= "\nLength: about $target words of body_md (a " . $len[2] . '; stay between ' . $len[0] . ' and ' . $len[1] . ').';
        $user .= "\nShape: " . $shapes[random_int(0, count($shapes) - 1)] . '.';
        if ($cluster !== '') { $user .= "\nThe only product pages you may link to: " . implode(', ', self::cluster_links($cluster)) . '.'; }
        $user .= "\n\nLink to at least THREE of these published articles, each where it genuinely helps the reader, using their exact paths:\n";
        foreach ($targets as $t) { $user .= '- /blog/' . $t['slug'] . ': ' . $t['title'] . "\n"; }
        if (empty($targets)) { $user .= "(none published yet, link to the product pages instead)\n"; }
        $user .= "Link exactly once to the feature page $feature, where it fits the topic.\n";
        $user .= "\nContext (the only facts you may use):\n" . self::context();
        if ($note !== '') { $user .= "\n\nEditor's note for this rewrite: $note"; }
        if ($existing) {
            $user .= "\n\nCurrent draft to revise (keep what works, apply the editor's note, return the full article):\nTitle: " . (string) $existing['title'] . "\n\n" . (string) $existing['body_md'];
        }
        $errors = array(); $data = null; $model = ClaudeService::model();
        for ($attempt = 1; $attempt <= ($dry_run ? 1 : 2); $attempt++) {
            $msg = $user . ($attempt === 2 && !empty($errors) ? "\n\nYour previous draft failed these checks; fix every one and return the full JSON again:\n- " . implode("\n- ", $errors) : '');
            $r = ClaudeService::chat(self::system_prompt(), array(array('role' => 'user', 'content' => $msg)), 8000, 240, 'medium');
            if (empty($r['ok'])) { $errors = array('Claude: ' . (string) ($r['error'] ?? 'request failed')); continue; }
            $data = self::parse_json($r['text']);
            if ($data === null) { $errors = array('reply was not valid JSON'); continue; }
            $data['slug'] = $existing ? (string) $existing['slug'] : self::slugify((string) ($data['slug'] ?? $data['title'] ?? $keyword));   // a rewrite never moves the URL
            if ($existing_article_id === 0) { $base = $data['slug']; $i = 2; while ((new SeoArticlesModel())->slug_exists($data['slug'])) { $data['slug'] = $base . '-' . $i++; } }
            $data['cluster'] = $cluster; $data['intent'] = $intent;
            $data = self::fit($data);
            $errors = self::validate($data, $existing_article_id, count($targets) >= 3);
            if (empty($errors) && $existing_article_id === 0) {
                $dupes = self::duplicates((string) $data['title'], $keyword, $existing_article_id);
                if (!empty($dupes)) {
                    $errors = array('this topic is already covered (' . $dupes[0]['why'] . '): "' . $dupes[0]['title'] . '". Cover a different angle with a different title and primary keyword.');
                }
            }
            if (empty($errors)) { break; }
        }
        if ($dry_run && $data !== null && $existing_article_id === 0) {   // keep what came back, as a draft, for reading
            $aid = $articles->create(self::article_fields($data, $keyword, $cluster, $model, 'draft'));
            $keywords->set_status($kid, empty($errors) ? 'drafted' : $prev_status, empty($errors) ? $aid : null, empty($errors) ? null : implode('; ', $errors));
            return array('ok' => empty($errors), 'article_id' => $aid, 'error' => implode('; ', $errors));
        }
        if (!empty($errors) || $data === null) {
            if ($dry_run) { $keywords->set_status($kid, $prev_status, null, implode('; ', $errors)); return array('ok' => false, 'article_id' => 0, 'error' => implode('; ', $errors)); }
            $dupe = (bool) preg_grep('/already covered/', $errors);
            if ($existing_article_id > 0) { $keywords->set_status($kid, $prev_status === 'drafting' ? 'drafted' : $prev_status, null, implode('; ', $errors)); }
            elseif ($dupe) { $keywords->set_status($kid, 'skipped', null, implode('; ', $errors)); }   // covered already: retire the keyword instead of publishing a near-duplicate
            else { $keywords->set_status($kid, 'queued', null, implode('; ', $errors)); $keywords->set_priority($kid, (int) ($prev['priority'] ?? $kw['priority'] ?? 100) + 50); }   // one bad keyword can't block the queue daily
            return array('ok' => false, 'article_id' => 0, 'error' => implode('; ', $errors));
        }
        $fields = self::article_fields($data, $keyword, $cluster, $model, 'review');
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
            'target_keyword' => $keyword, 'cluster' => $cluster, 'author' => Main::site_name() . ' team', 'secondary_keywords' => json_encode(array_values((array) ($data['secondary_keywords'] ?? array())), JSON_UNESCAPED_UNICODE),
            'faq' => json_encode(array_values((array) ($data['faq'] ?? array())), JSON_UNESCAPED_UNICODE),
            'reading_minutes' => self::reading_minutes((string) $data['body_md']), 'status' => $status,
            'rewrite_note' => null, 'model' => $model, 'prompt_version' => self::PROMPT_VERSION,
        );
    }
}
