<?php
/**
 * The free tools' background run (queued by /api/tool_run, handled by cron/queue_worker.php as 'tool_run').
 *   fan-questions: Reddit (official API, client-credentials app) finds the niche's communities and the questions people
 *                  ask there; Claude turns them into post ideas by theme plus subreddits to watch.
 *   persona-tool:  Claude writes five AI influencer persona concepts.
 * A public run ({lead_id}) emails the result to the lead; an Ideas run ({run_id}) stores it on tool_runs for /ideas.
 * app.ini [global]: reddit_client_id, reddit_client_secret, reddit_user_agent (a "script" app at reddit.com/prefs/apps).
 * Without them, outside production, a marked fixture (tests/fixtures/reddit_questions.json) stands in.
 */
class ToolRunJob {

    const REDDIT_AUTH = 'https://www.reddit.com/api/v1/access_token';
    const REDDIT_API  = 'https://oauth.reddit.com';
    const FIXTURE     = '/tests/fixtures/reddit_questions.json';
    const MAX_QUESTIONS = 150;

    /** Where both emails send people: sign up as a creator, tagged with the tool. */
    public static function start_url(string $ref): string {
        return rtrim(Main::get_base_domain(), '/') . '/?auth=register&role=creator&ref=' . rawurlencode($ref);
    }

    public static function queue(array $payload): int {
        return (new DatabaseJobQueue())->dispatch('tool_run', $payload);
    }

    public static function handle(array $payload): string {
        $model = new LeadsModel();
        $run_id = (int) ($payload['run_id'] ?? 0);
        if ($run_id > 0) {   // an Ideas run: the page polls tool_runs, so a failure is stored, not retried
            $run = $model->run_get($run_id);
            if (!$run) { return 'run gone'; }
            $model->run_set($run_id, 'running');
            try {
                $r = self::fan_questions((string) $run['niche']);
                $model->run_set($run_id, 'done', $r['result']);
                return 'ideas run ' . $run_id . ': ' . $r['log'];
            } catch (\Throwable $e) {
                $model->run_set($run_id, 'failed', array('error' => 'We could not finish this run. Try again in a minute.'));
                return 'ideas run ' . $run_id . ' failed: ' . $e->getMessage();
            }
        }

        $lead = $model->get((int) ($payload['lead_id'] ?? 0));
        if (!$lead) { return 'lead gone'; }
        if ((string) ($lead['emailed_at'] ?? '') !== '') { return 'lead ' . (int) $lead['id'] . ' already emailed'; }   // a retry never re-sends (or re-calls Reddit and Claude)
        $niche = (string) $lead['niche'];
        // what the email echoes back: the person's own words, cut down so the tool can't relay a message to a stranger
        $name_echo  = self::echo_text((string) $lead['first_name'], 40);
        $niche_echo = self::echo_text($niche, 60);
        if ($lead['source'] === 'persona-tool') {
            $extra = (array) json_decode((string) ($lead['extra'] ?? ''), true);
            $personas = self::personas($niche, (string) ($extra['vibe'] ?? ''), (string) ($extra['audience'] ?? ''));
            $subject = 'Your 5 persona concepts';
            $html = self::persona_email($name_echo, $niche_echo, $personas);
            $log = count($personas) . ' personas';
        } else {
            $r = self::fan_questions($niche);
            $subject = 'Your fan questions from ' . Main::site_name();
            $html = self::ideas_email($name_echo, $niche_echo, $r['result']);
            $log = $r['log'];
        }
        if (!$model->claim_email((int) $lead['id'])) { return 'lead ' . (int) $lead['id'] . ' already emailed'; }   // set emailed_at first: only one sender wins
        if (!(new Notifications())->send_tool_result((string) $lead['email'], $name_echo, $subject, $html)) {
            $model->release_email((int) $lead['id']);
            throw new RuntimeException('email not sent');   // retried by the queue
        }
        return 'lead ' . (int) $lead['id'] . ' ' . $lead['source'] . ': ' . $log . ', emailed';
    }

    // ---- fan questions ----

    /** Reddit questions for the niche, turned into ideas. Returns ['result' => [...], 'log' => string]. */
    public static function fan_questions(string $niche): array {
        $src = self::reddit($niche);
        if (empty($src['questions'])) { throw new RuntimeException('reddit: no questions found (' . $src['log'] . '), retry later'); }   // never ask Claude to invent them
        $result = self::ideas($niche, $src['subreddits'], $src['questions']);
        $result['niche'] = $niche;
        $result['questions_read'] = count($src['questions']);
        $result['communities'] = count($src['subreddits']);
        $ideas = 0; foreach ($result['themes'] as $t) { $ideas += count($t['ideas']); }
        return array('result' => $result, 'log' => $src['log'] . ', ' . count($src['questions']) . ' questions, ' . $ideas . ' ideas');
    }

    /** Reddit keys are set in app.ini. */
    public static function reddit_keys(): bool {
        $cfg = Main::get_config();
        return trim((string) ($cfg['global']['reddit_client_id'] ?? '')) !== '' && trim((string) ($cfg['global']['reddit_client_secret'] ?? '')) !== '';
    }

    /** The fixture stands in only on a development box without keys (app.ini env, when set, must agree). */
    private static function fixture_allowed(): bool {
        $cfg = Main::get_config();
        $ini = (string) ($cfg['global']['env'] ?? '');
        return !self::reddit_keys() && Main::get_environment() === 'development' && ($ini === '' || $ini === 'development');
    }

    /** Fan Questions can run: keys set, or the dev fixture. Otherwise admins hear about it (once a day). */
    public static function fan_questions_ready(): bool {
        if (self::reddit_keys() || self::fixture_allowed()) { return true; }
        self::alert_missing_keys();
        return false;
    }

    /** One "Reddit credentials missing" notification per day to every admin (marker row in login_attempts). */
    public static function alert_missing_keys(): void {
        try {
            $la = new LoginAttemptsModel();
            if ($la->count_recent_for('system', 'alert:reddit', 1440) > 0) { return; }
            $la->record('', 'system', 'alert:reddit');
            foreach ((new UsersModel())->admin_ids() as $aid) {
                Notify::send((int) $aid, 'system', 'Reddit credentials missing', 'Add reddit_client_id, reddit_client_secret and reddit_user_agent to app.ini. The Fan Question Finder and Ideas are off until then.', '/admin', 'fa-triangle-exclamation');
            }
        } catch (\Throwable $e) { error_log('[tool_run] alert: ' . $e->getMessage()); }
    }

    /** ['subreddits' => [[name, members, description]], 'questions' => [titles], 'log' => string] */
    private static function reddit(string $niche): array {
        $cfg = Main::get_config();
        $id = trim((string) ($cfg['global']['reddit_client_id'] ?? ''));
        $secret = trim((string) ($cfg['global']['reddit_client_secret'] ?? ''));
        if ($id === '' || $secret === '') {
            if (!self::fixture_allowed()) { self::alert_missing_keys(); throw new RuntimeException('reddit: no credentials (set reddit_client_id and reddit_client_secret in app.ini)'); }
            error_log('[tool_run] reddit: no credentials, fixture used');
            $f = json_decode((string) @file_get_contents(Main::app_path() . self::FIXTURE), true);
            if (!is_array($f)) { throw new RuntimeException('reddit: no credentials and no fixture'); }
            return array('subreddits' => (array) $f['subreddits'], 'questions' => array_slice((array) $f['questions'], 0, self::MAX_QUESTIONS), 'log' => 'reddit: no credentials, fixture used');
        }
        $ua = trim((string) ($cfg['global']['reddit_user_agent'] ?? ''));
        if ($ua === '') { $ua = 'web:creatorlinkstudio:1.0 (lead tools)'; }

        $tok = self::http(self::REDDIT_AUTH, $ua, array('post' => 'grant_type=client_credentials', 'auth' => $id . ':' . $secret));
        $token = (string) ($tok['access_token'] ?? '');
        if ($token === '') { throw new RuntimeException('reddit: token request failed'); }

        $subs = array();
        $found = self::http(self::REDDIT_API . '/subreddits/search?raw_json=1&limit=25&include_over_18=false&q=' . rawurlencode($niche), $ua, array('token' => $token));
        foreach ((array) ($found['data']['children'] ?? array()) as $c) {
            $d = (array) ($c['data'] ?? array());
            if (!empty($d['over18']) || ($d['subreddit_type'] ?? '') !== 'public' || (int) ($d['subscribers'] ?? 0) < 1000) { continue; }
            $subs[] = array('name' => (string) $d['display_name'], 'members' => (int) $d['subscribers'], 'description' => mb_substr(trim((string) ($d['public_description'] ?? '')), 0, 200));
            if (count($subs) >= 6) { break; }
        }

        $questions = array(); $seen = array();
        foreach ($subs as $s) {
            foreach (array('/new?limit=50', '/top?t=week&limit=50') as $list) {
                $page = self::http(self::REDDIT_API . '/r/' . rawurlencode($s['name']) . $list . '&raw_json=1', $ua, array('token' => $token));
                foreach ((array) ($page['data']['children'] ?? array()) as $c) {
                    $t = trim(preg_replace('/\s+/', ' ', (string) ($c['data']['title'] ?? '')));
                    $k = mb_strtolower($t);
                    if ($t === '' || isset($seen[$k]) || !empty($c['data']['over_18']) || !self::is_question($t)) { continue; }
                    $seen[$k] = 1; $questions[] = $t;
                    if (count($questions) >= self::MAX_QUESTIONS) { break 3; }
                }
            }
        }
        return array('subreddits' => $subs, 'questions' => $questions, 'log' => 'reddit: ' . count($subs) . ' subreddits');
    }

    private static function is_question(string $t): bool {
        return strpos($t, '?') !== false
            || (bool) preg_match('/^(how|what|why|when|where|which|who|is|are|can|could|should|does|do|did|has|have|any|anyone|would|will|tips|help)\b/i', $t);
    }

    /** GET (or POST with 'post') JSON from Reddit. $o: post, auth (basic user:pass), token (bearer). */
    private static function http(string $url, string $ua, array $o): array {
        $ch = curl_init($url);
        $headers = array('Accept: application/json');
        if (!empty($o['token'])) { $headers[] = 'Authorization: bearer ' . $o['token']; }
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => $headers));
        if (isset($o['post'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $o['post']); }
        if (!empty($o['auth'])) { curl_setopt($ch, CURLOPT_USERPWD, (string) $o['auth']); }
        $wait = array();
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$wait) {
            if (stripos($line, 'retry-after:') === 0) { $wait[] = (int) trim(substr($line, 12)); }
            return strlen($line);
        });
        // a 429 or 5xx (or a dropped connection) is retried up to 3 times: 2s, 4s, 8s, or Retry-After when Reddit sends it (capped at 30s);
        // one run stops retrying after 120s of waiting in total so a bad hour at Reddit cannot pin the queue worker
        static $slept = 0;
        for ($try = 0; $try <= 3; $try++) {
            $wait = array();
            $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $retry = $raw === false || $code === 429 || $code >= 500;
            if (!$retry || $try === 3 || $slept >= 120) { break; }
            $pause = !empty($wait) && $wait[0] > 0 ? min(30, $wait[0]) : (2 << $try);
            $slept += $pause;
            error_log('[tool_run] reddit http ' . $code . ' ' . preg_replace('/\?.*/', '', $url) . ', retry in ' . $pause . 's');
            sleep($pause);
        }
        curl_close($ch);
        if ($raw === false || $code >= 400) { error_log('[tool_run] reddit http ' . $code . ' ' . preg_replace('/\?.*/', '', $url)); return array(); }
        $d = json_decode((string) $raw, true);
        return is_array($d) ? $d : array();
    }

    /** Claude: 30 to 40 ideas in 4 to 6 themes, and why each community is worth watching. */
    private static function ideas(string $niche, array $subs, array $questions): array {
        $system = 'You help a content creator plan posts. You turn real questions people ask online into short post ideas. '
                . 'Plain, friendly language. Never promise income, followers or results. Never use em dashes or en dashes. Reply with JSON only.';
        $user = "Niche: $niche\n\nCommunities (name, members, description):\n";
        foreach ($subs as $s) { $user .= '- r/' . $s['name'] . ' (' . number_format((int) $s['members']) . ' members): ' . $s['description'] . "\n"; }
        $user .= "\nRecent questions people asked there:\n";
        foreach ($questions as $q) { $user .= '- ' . $q . "\n"; }
        $user .= "\nWrite 30 to 40 post ideas a creator in this niche could make, grouped into 4 to 6 themes. Each idea is one line under 120 characters, "
               . "written as the post itself (a title or hook), answering or building on the questions above. "
               . "Then, for each community listed above (and only those), one sentence on why it is worth watching.\n"
               . 'JSON shape: {"themes":[{"name":"Theme name","ideas":["..."]}],"subreddits":[{"name":"exact name without r/","why":"..."}]}';
        $data = null;
        for ($try = 1; $try <= 2 && $data === null; $try++) {
            $r = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $user)), 4000, 150, 'low');
            if (empty($r['ok'])) { continue; }
            $d = SeoDrafter::parse_json($r['text']);
            if (is_array($d) && !empty($d['themes'])) { $data = $d; }
        }
        if ($data === null) { throw new RuntimeException('claude: no usable ideas'); }

        $themes = array(); $left = 40;   // never more than 40 ideas, whatever the model sent
        foreach (array_slice((array) $data['themes'], 0, 6) as $t) {
            $ideas = array();
            foreach ((array) ($t['ideas'] ?? array()) as $i) { $i = self::clean($i); if ($i !== '' && $left > 0) { $ideas[] = $i; $left--; } }
            if (!empty($ideas) && self::clean($t['name'] ?? '') !== '') { $themes[] = array('name' => self::clean($t['name']), 'ideas' => $ideas); }
        }
        $why = array();
        foreach ((array) ($data['subreddits'] ?? array()) as $s) { $why[mb_strtolower(preg_replace('#^/?r/#i', '', (string) ($s['name'] ?? '')))] = self::clean($s['why'] ?? ''); }
        $watch = array();
        foreach ($subs as $s) {   // members come from Reddit, never from the model
            $watch[] = array('name' => (string) $s['name'], 'members' => (int) $s['members'], 'why' => $why[mb_strtolower((string) $s['name'])] ?? '');
        }
        return array('themes' => $themes, 'subreddits' => $watch);
    }

    /** Model text for an email or the page: one line, no dashes the house style forbids. */
    private static function clean($s): string {
        $s = preg_replace('/\S+@\S+/u', '', (string) $s);                                                  // email addresses
        $s = preg_replace('#(https?://|www\.)\S*#iu', '', $s);                                             // links
        $s = preg_replace('/\b[\p{L}\p{N}-]+(\.[\p{L}\p{N}-]+)*\.(com|net|org|io|co|ly|me|app|xyz|info|biz|us|uk|link|site|online|shop|ru|cn)\b\S*/iu', '', $s);   // bare domains
        $s = preg_replace_callback('/\+?\d[\d\s().-]{4,}\d/u', function ($m) { return strlen(preg_replace('/\D/', '', $m[0])) >= 6 ? '' : $m[0]; }, $s);   // digit runs of 6+ (phone numbers, codes)
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return trim(str_replace(array(" \u{2014} ", "\u{2014}", " \u{2013} ", "\u{2013}"), array(', ', ', ', ', ', '-'), $s));
    }

    /** A visitor's own words shown back in an email: letters, digits, spaces and basic punctuation only, no links or numbers, cut to $max. */
    public static function echo_text(string $s, int $max): string {
        $s = self::clean(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));   // links, addresses and long numbers out first
        $s = trim(preg_replace('/\s+/', ' ', preg_replace("/[^\\p{L}\\p{N} .,'&!?-]/u", ' ', $s)));
        return trim(mb_substr($s, 0, $max));
    }

    // ---- persona tool ----

    /** Claude: five persona concepts. Each: name, handles (3), bio, traits, pillars, image_prompt. */
    private static function personas(string $niche, string $vibe, string $audience): array {
        $system = 'You design AI influencer personas for creators. Original characters only: never a real person, never a celebrity likeness. '
                . 'Plain language, no income promises, no em dashes or en dashes. Reply with JSON only.';
        $user = "Niche: $niche\n" . ($vibe !== '' ? "Vibe: $vibe\n" : '') . ($audience !== '' ? "Audience: $audience\n" : '')
              . "\nWrite 5 different persona concepts. For each: a first name, 3 social handle ideas (lowercase, no @), a bio under 150 characters, "
              . "4 personality traits (one or two words each), 4 content pillars (a few words each), and a starter image prompt: one or two sentences "
              . "describing her or him (age, look, hair, setting, outfit, light) as a candid photo taken on a phone. Name the subject, no celebrity likeness.\n"
              . 'JSON shape: {"personas":[{"name":"","handles":["","",""],"bio":"","traits":["","","",""],"pillars":["","","",""],"image_prompt":""}]}';
        $out = array();
        for ($try = 1; $try <= 2 && count($out) < 5; $try++) {
            $r = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $user)), 3000, 150, 'low');
            if (empty($r['ok'])) { continue; }
            $d = SeoDrafter::parse_json($r['text']);
            $out = array();
            foreach ((array) ($d['personas'] ?? array()) as $p) {
                if (self::clean($p['name'] ?? '') === '') { continue; }
                $prompt = rtrim(self::clean($p['image_prompt'] ?? ''), ' .');
                $out[] = array(
                    'name'     => self::clean($p['name']),
                    'handles'  => array_slice(array_values(array_filter(array_map(function ($h) { return ltrim(self::clean($h), '@'); }, (array) ($p['handles'] ?? array())))), 0, 3),
                    'bio'      => self::clean($p['bio'] ?? ''),
                    'traits'   => array_values(array_filter(array_map(array(self::class, 'clean'), (array) ($p['traits'] ?? array())))),
                    'pillars'  => array_values(array_filter(array_map(array(self::class, 'clean'), (array) ($p['pillars'] ?? array())))),
                    'image_prompt' => $prompt !== '' ? $prompt . '. ' . InfluencerService::REALISM : '',   // the realistic phone-photo direction the Influencers tools use
                );
            }
            $out = array_slice($out, 0, 5);
        }
        if (count($out) < 5) { throw new RuntimeException('claude: no usable personas'); }
        return $out;
    }

    // ---- email bodies (wrapped by Notifications::send_tool_result) ----

    const P  = 'margin:0 0 14px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.6; color:#4b4863;';
    const H  = 'margin:22px 0 8px; font-family:Arial,Helvetica,sans-serif; font-size:17px; font-weight:700; color:#1c1830;';
    const LI = 'margin:0 0 6px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.5; color:#2b2940;';

    private static function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

    private static function button(string $ref): string {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 4px;"><tr><td style="border-radius:8px; background:#CD4C00;">'
             . '<a href="' . self::h(self::start_url($ref)) . '" target="_blank" style="display:inline-block; padding:13px 26px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:600; line-height:1; color:#ffffff; text-decoration:none; border-radius:8px;">Start Free</a>'
             . '</td></tr></table>';
    }

    public static function ideas_email(string $name, string $niche, array $r): string {
        $o = '<p style="' . self::P . '">' . ($name !== '' ? 'Hi ' . self::h($name) . ', h' : 'H') . 'ere are post ideas for <strong>' . self::h($niche) . '</strong>, built from '
           . (int) ($r['questions_read'] ?? 0) . ' recent questions people asked in ' . (int) ($r['communities'] ?? 0) . ' online communities.</p>';
        foreach ((array) $r['themes'] as $t) {
            $o .= '<p style="' . self::H . '">' . self::h($t['name']) . '</p><ul style="margin:0 0 6px; padding-left:20px;">';
            foreach ((array) $t['ideas'] as $i) { $o .= '<li style="' . self::LI . '">' . self::h($i) . '</li>'; }
            $o .= '</ul>';
        }
        if (!empty($r['subreddits'])) {
            $o .= '<p style="' . self::H . '">Subreddits To Watch</p><ul style="margin:0 0 6px; padding-left:20px;">';
            foreach ((array) $r['subreddits'] as $s) {
                $o .= '<li style="' . self::LI . '"><a href="https://www.reddit.com/r/' . rawurlencode($s['name']) . '/" style="color:#C2410C;">r/' . self::h($s['name']) . '</a>, '
                    . number_format((int) $s['members']) . ' members' . ((string) $s['why'] !== '' ? '. ' . self::h($s['why']) : '') . '</li>';
            }
            $o .= '</ul>';
        }
        $o .= '<p style="' . self::P . ' margin-top:22px;">Want this every week? Creators on ' . self::h(Main::site_name()) . ' run it from Ideas in their studio and turn an idea into a post in one click.</p>';
        return $o . self::button('fan-questions');
    }

    public static function persona_email(string $name, string $niche, array $personas): string {
        $o = '<p style="' . self::P . '">' . ($name !== '' ? 'Hi ' . self::h($name) . ', h' : 'H') . 'ere are five AI influencer persona ideas for <strong>' . self::h($niche) . '</strong>.</p>';
        $lbl = 'margin:10px 0 2px; font-family:Arial,Helvetica,sans-serif; font-size:12px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; color:#8a8797;';
        foreach ($personas as $i => $p) {
            $o .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0; border:1px solid #ecebf3; border-radius:10px; border-collapse:separate;"><tr><td style="padding:16px 18px;">'
                . '<p style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:18px; font-weight:700; color:#1c1830;">' . ($i + 1) . '. ' . self::h($p['name']) . '</p>'
                . '<p style="margin:4px 0 0; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#6f6c7b;">@' . implode(' &middot; @', array_map(array(self::class, 'h'), $p['handles'])) . '</p>'
                . '<p style="' . self::P . ' margin-top:10px;">' . self::h($p['bio']) . '</p>'
                . '<p style="' . $lbl . '">Traits</p><p style="' . self::LI . '">' . self::h(implode(', ', $p['traits'])) . '</p>'
                . '<p style="' . $lbl . '">Content Pillars</p><p style="' . self::LI . '">' . self::h(implode(', ', $p['pillars'])) . '</p>'
                . '<p style="' . $lbl . '">Starter Image Prompt</p><p style="margin:0; padding:10px 12px; background:#fbfaf8; border-radius:8px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.5; color:#2b2940;">' . self::h($p['image_prompt']) . '</p>'
                . '</td></tr></table>';
        }
        $o .= '<p style="' . self::P . ' margin-top:18px;">Ready to bring one to life? On ' . self::h(Main::site_name()) . ' you create an AI influencer from a persona like these and generate realistic photos and videos for your posts.</p>';
        return $o . self::button('persona-tool');
    }
}
