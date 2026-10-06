<?php
/**
 * Launch Campaign: a run of posts and matching messages around one launch moment.
 *
 *   announcement  post + message, as soon as the campaign is confirmed
 *   anticipation  one post a day on the days before the launch
 *   countdown     post + message an hour before
 *   live          post + message at the launch time
 *
 * draft() works out the times and has the writer fill in the text (in the influencer's persona when one is
 * chosen); nothing is created. confirm() takes the edited drafts and creates everything in one go: scheduled
 * posts (cross-posted to the chosen accounts), scheduled messages, and an optional promo code. Posts can also
 * go to Fanvue; messages are always Creator Link Studio messages.
 */
class LaunchCampaign {

    const MAX_DAYS     = 7;
    const COUNTDOWN    = 3600;    // seconds before launch
    const LEAD         = 600;     // the announcement goes out this many seconds after confirming
    const KINDS        = array('announcement' => 'Announcement', 'anticipation' => 'Anticipation', 'countdown' => 'Countdown', 'live' => 'Live');

    private static function fail($m, array $x = array()){ return array_merge(array('success' => false, 'message' => $m), $x); }
    private static function okr(array $x = array()){ return array_merge(array('success' => true), $x); }

    /**
     * The slots of a campaign, in time order: array of key, kind, type (post|message), at (unix time).
     * Pure: $now is passed in. Slots that would fall before the announcement are left out.
     */
    public static function slots($launch_ts, $days, $now){
        $launch_ts = (int) $launch_ts; $now = (int) $now;
        $days  = max(0, min(self::MAX_DAYS, (int) $days));
        $first = $now + self::LEAD;
        $out   = array(
            array('key' => 'announcement_post', 'kind' => 'announcement', 'type' => 'post', 'at' => $first),
            array('key' => 'announcement_message', 'kind' => 'announcement', 'type' => 'message', 'at' => $first),
        );
        for ($d = $days; $d >= 1; $d--) {
            $at = $launch_ts - $d * 86400;
            if ($at <= $first + 3600) { continue; }   // too close to the announcement to be its own post
            $out[] = array('key' => 'anticipation_' . $d, 'kind' => 'anticipation', 'type' => 'post', 'at' => $at, 'days_left' => $d);
        }
        $cd = $launch_ts - self::COUNTDOWN;
        if ($cd > $first + 1800) {
            $out[] = array('key' => 'countdown_post', 'kind' => 'countdown', 'type' => 'post', 'at' => $cd);
            $out[] = array('key' => 'countdown_message', 'kind' => 'countdown', 'type' => 'message', 'at' => $cd);
        }
        $out[] = array('key' => 'live_post', 'kind' => 'live', 'type' => 'post', 'at' => $launch_ts);
        $out[] = array('key' => 'live_message', 'kind' => 'live', 'type' => 'message', 'at' => $launch_ts);
        return $out;
    }

    /** A local date-time ('Y-m-d H:i' or 'Y-m-dTH:i') in $tz as a unix time; 0 when it cannot be read. */
    public static function to_ts($local, $tz){
        $local = str_replace('T', ' ', trim((string) $local));
        if ($local === '') { return 0; }
        try { $z = new DateTimeZone($tz !== '' ? $tz : 'UTC'); } catch (\Throwable $e) { $z = new DateTimeZone('UTC'); }
        try { $d = new DateTime($local, $z); } catch (\Throwable $e) { return 0; }
        return (int) $d->getTimestamp();
    }

    public static function local($ts, $tz, $format = 'Y-m-d\TH:i'){
        try { $z = new DateTimeZone($tz !== '' ? $tz : 'UTC'); } catch (\Throwable $e) { $z = new DateTimeZone('UTC'); }
        $d = new DateTime('@' . (int) $ts); $d->setTimezone($z);
        return $d->format($format);
    }

    /** The text used when the writer is unavailable: plain, and ready to edit. */
    public static function fallback(array $slot, $what, $code = ''){
        $w = rtrim(trim((string) $what), '.');
        $promo = ($code !== '') ? ' Use code ' . $code . '.' : '';
        switch ($slot['kind']) {
            case 'announcement': return 'Something new is coming: ' . $w . '.' . ($slot['type'] === 'message' ? ' You are hearing it first.' : '');
            case 'anticipation': $d = (int) ($slot['days_left'] ?? 1); return $d . ' ' . ($d === 1 ? 'day' : 'days') . ' to go: ' . $w . '.';
            case 'countdown':    return 'One hour to go: ' . $w . '.';
            default:             return 'It is live: ' . $w . '.' . $promo;
        }
    }

    /**
     * Work out the campaign and write its drafts. $in: what, launch_at (local), days, influencer_id, promo_code.
     * Returns items: key, kind, label, type, at (local, for a datetime-local input), at_label, text.
     */
    public static function draft($cid, array $user, array $in){
        $tz   = (string) ($user['content_timezone'] ?? 'UTC');
        $what = mb_substr(trim((string) ($in['what'] ?? '')), 0, 600);
        if ($what === '') { return self::fail('Say what you are launching.'); }
        $launch = self::to_ts($in['launch_at'] ?? '', $tz);
        if ($launch <= time() + 1800) { return self::fail('Pick a launch time at least 30 minutes from now.'); }
        $days = max(0, min(self::MAX_DAYS, (int) ($in['days'] ?? 3)));
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['promo_code'] ?? '')));
        $infl = ((int) ($in['influencer_id'] ?? 0) > 0) ? (new InfluencersModel())->get_one($cid, (int) $in['influencer_id']) : null;

        $slots = self::slots($launch, $days, time());
        $texts = self::write($slots, $what, $code, $infl, $tz, $launch);
        $items = array();
        foreach ($slots as $s) {
            $t = trim((string) ($texts[$s['key']] ?? ''));
            $items[] = array(
                'key' => $s['key'], 'kind' => $s['kind'], 'type' => $s['type'],
                'label' => self::KINDS[$s['kind']] . ($s['kind'] === 'anticipation' ? ' · ' . (int) $s['days_left'] . ' ' . ((int) $s['days_left'] === 1 ? 'Day' : 'Days') . ' Out' : '') . ($s['type'] === 'message' ? ' Message' : ' Post'),
                'at' => self::local($s['at'], $tz), 'at_label' => self::local($s['at'], $tz, 'D, M j · g:i A'),
                'text' => $t !== '' ? $t : self::fallback($s, $what, $code),
            );
        }
        return self::okr(array('items' => $items, 'timezone' => $tz, 'written' => !empty($texts)));
    }

    /** Ask the writer for every slot's text in one call. Returns key => text, or array() when it fails. */
    private static function write(array $slots, $what, $code, $infl, $tz, $launch){
        if (!ClaudeService::configured()) { return array(); }
        $lines = array();
        foreach ($slots as $s) {
            $lines[] = '- ' . $s['key'] . ': ' . ($s['type'] === 'message' ? 'a direct message to fans' : 'a social post caption') . ', ' . $s['kind']
                . ($s['kind'] === 'anticipation' ? ' (' . (int) $s['days_left'] . ' day(s) before launch)' : '') . ', goes out ' . self::local($s['at'], $tz, 'l g:i A');
        }
        $persona = InfluencerService::persona_block($infl);
        $system = ($persona !== '' ? $persona . "\n\n" : '')
            . 'You write a launch campaign for a creator, in the first person. Each piece builds on the one before: the announcement opens a loop, '
            . 'the anticipation posts add one new detail each and never repeat each other, the countdown is short and urgent, and the live piece says plainly that it is out now. '
            . 'Posts are 1 to 3 sentences. Messages are 1 to 2 sentences and read like a personal note, not an advert. No hashtags, no links, no placeholders in brackets. '
            . 'Keep it within what a mainstream social platform allows.'
            . ($code !== '' ? ' The promo code ' . $code . ' is mentioned only in the live post and the live message.' : ' Do not invent a promo code or a discount.')
            . ' Answer with ONLY a JSON object mapping each key to its text.';
        $ask = 'What is launching: ' . $what . "\nLaunch time: " . self::local($launch, $tz, 'l, F j \a\t g:i A') . "\nPieces to write:\n" . implode("\n", $lines);
        $r = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $ask)), 1800, 60, 'low');
        if (empty($r['ok'])) { error_log('[launch campaign] writer failed: ' . (string) ($r['error'] ?? '')); return array(); }
        $raw = (string) $r['text'];
        $a = strpos($raw, '{'); $b = strrpos($raw, '}');
        $j = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
        if (!is_array($j)) { return array(); }
        $out = array();
        foreach ($j as $k => $v) { if (is_string($v)) { $out[(string) $k] = BrandService::unquote($v); } }
        return $out;
    }

    /**
     * Check edited drafts. Returns array(clean items, error). Each clean item: key, kind, type, at (unix), text.
     * Pure apart from the clock passed in.
     */
    public static function clean_items($items, $tz, $now){
        $out = array();
        foreach ((array) $items as $it) {
            if (!is_array($it)) { continue; }
            $type = (($it['type'] ?? '') === 'message') ? 'message' : 'post';
            $kind = isset(self::KINDS[(string) ($it['kind'] ?? '')]) ? (string) $it['kind'] : 'anticipation';
            $text = trim((string) ($it['text'] ?? ''));   // the web action decodes POST entities before this, like the other actions
            if ($text === '') { continue; }   // a piece left blank is not created
            $at = self::to_ts($it['at'] ?? '', $tz);
            if ($at <= 0) { return array(array(), 'One of the pieces has no time.'); }
            if ($at < (int) $now + 60) { $at = (int) $now + self::LEAD; }   // already due: it goes out with the first batch
            $out[] = array('key' => (string) ($it['key'] ?? ''), 'kind' => $kind, 'type' => $type, 'at' => $at,
                'text' => mb_substr($text, 0, $type === 'message' ? 2000 : 3000));
        }
        if (empty($out)) { return array(array(), 'There is nothing to schedule.'); }
        if (count($out) > 40) { return array(array(), 'That is too many pieces for one campaign.'); }
        return array($out, '');
    }

    /**
     * The names of the platforms among $shares (social account ids) that refuse a post without media, each once.
     * $accounts: the creator's connected rows (platform, post_for_me_social_account_id). Pure.
     */
    public static function media_needed(array $accounts, array $shares){
        $shares = array_map('strval', $shares); $out = array();
        foreach ($accounts as $a) {
            $platform = strtolower((string) ($a['platform'] ?? ''));
            if (in_array((string) ($a['post_for_me_social_account_id'] ?? ''), $shares, true) && SocialShareService::needs_media($platform)) {
                $out[SocialShareService::NEEDS_MEDIA[$platform]] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Create the campaign from edited drafts. $in: items, launch_at (local), days, influencer_id, destination (cls|fanvue),
     * share_accounts (social account ids for the posts), segments (who gets the messages), asset_id (optional media for
     * every post), promo: promo_code, promo_percent, promo_days (valid this many days after launch).
     */
    public static function confirm($cid, array $user, array $in){
        $tz = (string) ($user['content_timezone'] ?? 'UTC');
        list($items, $err) = self::clean_items($in['items'] ?? array(), $tz, time());
        if ($err !== '') { return self::fail($err); }
        $launch = self::to_ts($in['launch_at'] ?? '', $tz);
        if ($launch <= time()) { return self::fail('Pick a launch time in the future.'); }
        $dest   = (($in['destination'] ?? 'cls') === 'fanvue') ? 'fanvue' : 'cls';
        $shares = array_values(array_filter(array_map('strval', (array) ($in['share_accounts'] ?? array())), 'strlen'));
        if ($dest === 'fanvue' && !in_array(FanvueShareService::ACCOUNT_ID, $shares, true)) { $shares[] = FanvueShareService::ACCOUNT_ID; }
        $segments = BroadcastsModel::clean_segments($in['segments'] ?? array('followers'));
        if (empty($segments)) { $segments = array('followers'); }

        // Optional media used on every post.
        $asset_id = (int) ($in['asset_id'] ?? 0);
        if ($asset_id > 0) {
            $a = (new MediaAssetsModel())->get_one($cid, $asset_id);
            if (!$a || (string) $a['status'] !== 'ready' || !empty($a['deleted_at'])) { return self::fail('That media is not ready to post.'); }
            if ((string) ($a['moderation_status'] ?? '') === 'blocked') { return self::fail('That media was blocked by our content check.'); }
        } elseif (!empty($shares)) {
            // Text-only posts cannot go to platforms that take media only.
            $need = self::media_needed((new SocialAccountsModel())->get_connected_for_user($cid), $shares);
            if (!empty($need)) { return self::fail('Add an image to the campaign: ' . implode(', ', $need) . ' posts need media.'); }
        }

        // The persona the campaign is written as: only an influencer of this creator.
        $infl_id = (int) ($in['influencer_id'] ?? 0);
        if ($infl_id > 0 && !(new InfluencersModel())->get_one($cid, $infl_id)) { $infl_id = 0; }

        // Optional promo code, valid until some days after the launch.
        $promo_id = 0; $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['promo_code'] ?? '')));
        if ($code !== '') {
            $pct = (int) ($in['promo_percent'] ?? 0);
            if (strlen($code) < 3 || strlen($code) > 24) { return self::fail('A promo code is 3 to 24 letters and numbers.'); }
            if ($pct < 1 || $pct > 100) { return self::fail('Set the promo discount between 1 and 100 percent.'); }
            $pm = new CreatorPromoCodesModel();
            foreach ((array) $pm->get_for_user($cid) as $have) { if (strtoupper((string) $have['code']) === $code) { return self::fail('You already have a promo code called ' . $code . '.'); } }
            $expires = gmdate('Y-m-d H:i:s', $launch + max(1, min(60, (int) ($in['promo_days'] ?? 7))) * 86400);
            try {
                $promo_id = (int) $pm->add($cid, array('code' => $code, 'percent_off' => $pct, 'applies_to' => 'all', 'expires_at' => $expires));
            } catch (\Throwable $e) { $promo_id = 0; }
            if ($promo_id <= 0) { return self::fail('Could not create the promo code ' . $code . '. It may already exist.'); }
        }

        $cm = new LaunchCampaignsModel();
        $campaign_id = $cm->create($cid, array('influencer_id' => $infl_id, 'destination' => $dest,
            'launch_at' => gmdate('Y-m-d H:i:s', $launch), 'anticipation_days' => (int) ($in['days'] ?? 0), 'promo_code_id' => $promo_id));
        if ($campaign_id <= 0) { return self::fail('Could not create the campaign.'); }

        $pm = new PostsModel(); $posts = 0; $messages = 0; $share_errors = array();
        foreach ($items as $it) {
            $utc = gmdate('Y-m-d H:i:s', $it['at']);
            if ($it['type'] === 'message') {
                if ($cm->add_broadcast($cid, $campaign_id, $segments, $it['text'], $utc) > 0) { $messages++; }
                continue;
            }
            $pid = (int) $pm->create_draft($cid, $it['text'], 'free');
            if ($pid <= 0) { continue; }
            $pm->update_fields($cid, $pid, array('caption' => $it['text'], 'audience' => 'free', 'campaign_id' => $campaign_id, 'on_cls' => ($dest === 'fanvue' && empty($in['also_cls'])) ? 0 : 1));
            if ($asset_id > 0) { $pm->set_assets($cid, $pid, array($asset_id), $asset_id); }
            $pm->set_state($cid, $pid, 'scheduled', $utc);
            $posts++;
            if (!empty($shares)) {
                $post = $pm->get_one($cid, $pid);
                $r = SocialShareService::share($user, $post, $shares, str_replace(' ', 'T', $utc) . 'Z');
                if (empty($r['ok']) && (string) ($r['error'] ?? '') !== '') { $share_errors[(string) $r['error']] = true; }
            }
        }
        return self::okr(array('campaign_id' => $campaign_id, 'posts' => $posts, 'messages' => $messages, 'promo_code' => $promo_id > 0 ? $code : '',
            'share_errors' => array_keys($share_errors),
            'message' => 'Campaign scheduled: ' . $posts . ' ' . ($posts === 1 ? 'post' : 'posts') . ' and ' . $messages . ' ' . ($messages === 1 ? 'message' : 'messages') . '.'));
    }

    /** Send the scheduled messages that are due (cron/scheduler.php). Returns how many were sent. */
    public static function send_due($limit = 50){
        $cm = new LaunchCampaignsModel(); $sent = 0;
        foreach ($cm->due_broadcasts($limit) as $b) {
            if (!$cm->claim_broadcast((int) $b['id'])) { continue; }
            try {
                list($bid, $count) = (new BroadcastsModel())->create_and_send((int) $b['creator_id'], explode(',', (string) $b['segments']), (string) $b['body']);
                // No one in the audience yet is not a failure: the message simply had no one to go to.
                $cm->finish_broadcast((int) $b['id'], (int) $bid, (int) $count);
                $sent++;
            } catch (\Throwable $e) {
                $cm->fail_broadcast((int) $b['id'], $e->getMessage());
                error_log('[launch campaign] scheduled message ' . (int) $b['id'] . ' failed: ' . $e->getMessage());
            }
        }
        return $sent;
    }
}
