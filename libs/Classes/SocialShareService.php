<?php
/**
 * Cross-post the PROMOTIONAL version of a post to selected connected social accounts.
 * Best-effort — never throws. Always sends the public caption + a SAFE preview image
 * (blurred variant for subscriber posts) — never the subscriber media. Free posts send all
 * their media as a carousel, trimmed per platform (see media_for). No link is
 * appended: outbound links suppress reach on X and friends; the profile URL lives in the bio.
 *
 * Extracted from ApiCreatorStudioController so both the manual publish/schedule flow and the
 * Scheduler worker can share one implementation.
 */
class SocialShareService {

    /**
     * Returns array('ok'=>bool, 'shared'=>int, 'error'=>string) so callers can surface the outcome.
     * The pseudo id 'fanvue' in $account_ids routes to FanvueShareService (full-post mirror);
     * everything else is a Post for Me social account id.
     */
    public static function share(array $user, array $post, array $account_ids, $scheduled_iso = null, array $opts = array()){
        $account_ids = array_values(array_map('strval', $account_ids));
        $fanvue      = in_array(FanvueShareService::ACCOUNT_ID, $account_ids, true);
        $account_ids = array_values(array_diff($account_ids, array(FanvueShareService::ACCOUNT_ID)));

        $res = self::share_social($user, $post, $account_ids, $scheduled_iso, $opts);
        if ($fanvue && class_exists('DatabaseJobQueue') && FanvueShareService::has_video($post)) {
            // Videos upload in the background so publishing doesn't wait on them; a failure arrives as a notification.
            (new DatabaseJobQueue())->dispatch('fanvue_share', array('user_id' => (int) $user['user_id'], 'post_id' => (int) $post['id'], 'scheduled_iso' => $scheduled_iso), 'fanvue_share:' . (int) $post['id']);
            $res['ok']     = empty($account_ids) ? true : $res['ok'];
            $res['shared'] = (int) $res['shared'] + 1;
        } elseif ($fanvue) {
            $fv = FanvueShareService::share($user, $post, $scheduled_iso);
            if (!empty($fv['ok'])) {
                $res['ok']     = empty($account_ids) ? true : $res['ok'];
                $res['shared'] = (int) $res['shared'] + 1;
            } else {
                $res['ok']    = false;
                $res['error'] = trim((string) $res['error'] . ' ' . (string) $fv['error']);
            }
        }
        return $res;
    }

    private static function share_social(array $user, array $post, array $account_ids, $scheduled_iso = null, array $opts = array()){
        try {
            if (empty($account_ids)) { return array('ok' => true, 'shared' => 0, 'error' => ''); }
            if (!Plan::can_social_post($user)) { return array('ok' => false, 'shared' => 0, 'error' => 'Your plan does not include social posting.'); }
            // Post for Me refuses a post with no caption (HTTP 400 "caption is required"), so say so instead of sending it.
            if (trim((string) ($post['caption'] ?? '')) === '') { return array('ok' => false, 'shared' => 0, 'error' => 'The post has no caption. Add one to share it to social accounts.'); }
            $valid = array(); $req = array_map('strval', $account_ids);
            $accounts = array();
            foreach ((new SocialAccountsModel())->get_connected_for_user((int) $user['user_id']) as $a) {
                $pfm = (string) $a['post_for_me_social_account_id'];
                if (in_array($pfm, $req, true)) {
                    $valid[] = $pfm;
                    $accounts[] = array('id' => $pfm, 'platform' => (string) $a['platform'], 'ai_disclosure_text' => (string) ($a['ai_disclosure_text'] ?? ''));
                }
            }
            if (empty($valid)) { return array('ok' => false, 'shared' => 0, 'error' => 'None of the selected social accounts are connected.'); }

            $promo = trim((string) $post['caption']);
            $assets = (new PostsModel())->get_assets((int) $post['id']);
            $items = self::items_for_post($post, $assets);
            $cid = (int) $user['user_id'];

            // Every image each destination would get passes the platform's rules first (MediaCompliance::resolve, the
            // authoritative check): the original when it fits, a technical version made without asking, or a reshaped
            // one only once approved. A destination still waiting for an approval is held, never sent unapproved.
            $place = self::placements($post['story_accounts'] ?? '');
            $uploaded = array(); $notes = array(); $held = array(); $media_by = array('feed' => array(), 'story' => array());
            $ok_feed = array(); $ok_story = array();
            foreach ($accounts as $acc) {
                $id = (string) $acc['id']; $platform = strtolower((string) $acc['platform']);
                $story_ok = in_array($platform, AiDisclosure::STORY_PLATFORMS, true);
                $places = ($story_ok && in_array($id, $place['story'], true)) ? array('story') : (($story_ok && in_array($id, $place['both'], true)) ? array('feed', 'story') : array('feed'));
                if (isset($opts['places'][$id])) { $places = array_values(array_intersect($places, (array) $opts['places'][$id])); }   // a held share sends only what was held
                foreach ($places as $pl) {
                    $list = ($pl === 'story') ? array_slice($items, 0, 1) : self::media_for($platform, $items);
                    $sent = array(); $stop = '';
                    foreach ($list as $it) {
                        $r = MediaCompliance::resolve($cid, $it['asset'], $it['teaser'], $platform, $pl, $it['video']);
                        if ($r['state'] === 'held' || $r['state'] === 'unsupported') { $stop = $r['state'] . ':' . $r['reason']; break; }
                        if (!isset($uploaded[$r['key']])) {
                            // dry_run (tests): nothing leaves the server; the S3 key stands in for the uploaded URL
                            $up = !empty($opts['dry_run']) ? array(array('url' => 's3:' . $r['key'], 'video' => $it['video'], 'key' => $r['key']))
                                : self::push_item($r['key'], $it['video'] ? 'video/mp4' : 'image/jpeg', $it['video'], array());
                            $uploaded[$r['key']] = $up ? $up[0] : null;
                        }
                        if ($uploaded[$r['key']]) { $sent[] = $uploaded[$r['key']] + array('tall' => $pl === 'story'); }
                    }
                    $label = MediaCompliance::name($platform) . ($pl === 'story' ? ' Story' : '');
                    if ($stop !== '') {
                        list($why, $reason) = explode(':', $stop, 2);
                        if ($why === 'held') { $held[$id][] = $pl; $notes[] = $label . ' is held: ' . $reason . '. It is published once you approve it.'; }
                        else { $notes[] = $label . ' was skipped: ' . $reason; }
                        continue;
                    }
                    if (empty($sent) && !empty($list)) { $notes[] = $label . ' was skipped: the media could not be uploaded.'; continue; }
                    $media_by[$pl][$platform] = $sent;
                    if ($pl === 'story') { $ok_story[] = $id; } else { $ok_feed[] = $id; }
                }
            }

            // Captions, the carousel slice, the AI label or disclosure line, and Stories: built without the network.
            // One Post for Me post takes each account once, so accounts set to Feed and Story get a second post for the Story.
            $live = array_filter($assets, function ($a) { return empty($a['deleted_at']); });
            $ai = AiDisclosure::applies($post['ai_disclosure'] ?? null, $live);
            // A Story for a Feed + Story account that goes alone (its feed post was sent earlier) runs like a Story-only one.
            $story_only = array_values(array_diff(array_merge(array_intersect($ok_story, $place['story']), array_diff(array_intersect($ok_story, $place['both']), $ok_feed)), $ok_feed));
            $both_story = array_values(array_intersect($ok_story, $place['both'], $ok_feed));
            $by_id = array(); foreach ($accounts as $acc) { $by_id[(string) $acc['id']] = $acc; }
            $pick = function (array $ids) use ($by_id) { $o = array(); foreach (array_unique($ids) as $i) { if (isset($by_id[$i])) { $o[] = $by_id[$i]; } } return $o; };
            $first = function (array $by) { foreach ($by as $l) { return $l; } return array(); };
            $base = array('ai' => $ai, 'platform_items' => $media_by['feed'], 'story_media_by' => $media_by['story']);
            $runs = array();
            $main = $pick(array_merge($ok_feed, $story_only));
            if (!empty($main)) { $runs[] = array($main, array('stories' => $story_only) + $base, $first($media_by['feed']) ?: $first($media_by['story'])); }
            if (!empty($both_story)) { $runs[] = array($pick($both_story), array('stories' => $both_story) + $base, $first($media_by['story'])); }

            $shared = 0; $errors = array(); $dry = array();
            foreach ($runs as $run) {
                $plan = self::plan($promo, $run[0], (array) $run[2], $run[1]);
                $notes = array_merge($notes, $plan['notes']);
                if (empty($plan['accounts'])) { continue; }
                if (!empty($opts['dry_run'])) { $dry[] = $plan; $shared = max($shared, count($plan['accounts'])); continue; }
                $res = PostForMeService::create_post($plan['accounts'], $promo, $plan['media'], $scheduled_iso, false, $plan['platform_configurations'], $plan['account_configurations']);
                if (is_array($res) && isset($res['id'])) {
                    (new SocialPostsModel())->create(
                        (int) $user['user_id'], (string) $res['id'], $promo,
                        (string) ($res['status'] ?? 'scheduled'), $scheduled_iso, $plan['accounts'], (int) $post['id']
                    );
                    SocialResultsJob::queue((int) $user['user_id'], (int) $post['id'], (string) $res['id'], $scheduled_iso);   // a platform that refuses it later is freed to send again
                    $shared = max($shared, count($plan['accounts']));
                } else {
                    $err = is_array($res) ? (string) ($res['_error'] ?? 'unknown error') : 'no response from Post for Me';
                    error_log('[social share] create_post failed: ' . $err);
                    $errors[] = $err;
                }
            }
            // Held destinations: published by ShareHeldJob as soon as their images are approved; the creator is asked to review.
            if (!empty($held) && empty($opts['from_held']) && empty($opts['dry_run'])) {
                ShareHeldJob::queue((int) $user['user_id'], (int) $post['id'], $held, $scheduled_iso);
                $names = array(); foreach (array_keys($held) as $hid) { $names[] = MediaCompliance::name($by_id[$hid]['platform'] ?? ''); }
                MediaCompliance::notify_review((int) $user['user_id'], (int) $post['id'], $names);
            }
            $notes = array_values(array_unique($notes));
            $out = array('held' => array_map('strval', array_keys($held)), 'held_places' => $held) + (!empty($opts['dry_run']) ? array('plans' => $dry) : array());
            // Held is not a failure: it goes out once approved. Nothing sent and nothing held is.
            return $out + array('ok' => empty($errors) && ($shared > 0 || !empty($held)), 'shared' => $shared, 'error' => trim(implode(' ', array_merge($errors, $notes))));
        } catch (\Throwable $e) {
            error_log('[social share] failed: ' . $e->getMessage());
            return array('ok' => false, 'shared' => 0, 'error' => $e->getMessage());
        }
    }

    /**
     * The media a cross-post of $post sends, in order (cover first), before any upload: [['asset' => row (with 'id'),
     * 'teaser' => bool (the blurred still: a members-only post, or adult / not-yet-cleared media), 'video' => bool]].
     * Free posts send the rest of their cleared media as a carousel.
     */
    public static function items_for_post(array $post, array $assets): array {
        $cover = null;
        foreach ($assets as $a) { if ((int) $a['is_cover'] === 1 && empty($a['deleted_at'])) { $cover = $a; break; } }
        if (!$cover) { foreach ($assets as $a) { if (empty($a['deleted_at'])) { $cover = $a; break; } } }
        if (!$cover) { return array(); }
        $row = function ($a, $teaser) { $a['id'] = (int) $a['asset_id']; return array('asset' => $a, 'teaser' => (bool) $teaser, 'video' => !$teaser && (string) $a['type'] === 'video'); };
        $teaser = ((string) ($post['audience'] ?? 'free') === 'subscribers') || !self::sfw($cover);
        $items = array($row($cover, $teaser));
        if ((string) ($post['audience'] ?? 'free') === 'free') {
            foreach ($assets as $a) {
                if (count($items) >= self::MAX_MEDIA) { break; }
                if ((int) $a['asset_id'] === (int) $cover['asset_id'] || !empty($a['deleted_at']) || !self::sfw($a)) { continue; }
                $items[] = $row($a, false);
            }
        }
        return $items;
    }

    /**
     * What one cross-post sends, worked out without touching the network (so it can be tested).
     *
     * $accounts: rows of id, platform, ai_disclosure_text. $items: uploaded media, cover first, each
     * array('url', 'video' => bool, 'tall' => bool (9:16)). $opts: 'ai' => disclose this post as AI media,
     * 'stories' => account ids that get it as a Story, 'story_media' => the cover fitted into 9:16 (used when no item
     * is 9:16), 'story_media_by' => platform => Story items, 'platform_items' => platform => feed items in place of $items (both checked by MediaCompliance).
     *
     * Returns accounts (the ids that are sent to), media (urls), platform_configurations,
     * account_configurations and notes (what was left out, and why).
     *
     *  - AI disclosure: TikTok and YouTube take their own AI flag; every other platform gets the
     *    account's disclosure line as the last line of the caption, inside that platform's limit.
     *  - Stories (Instagram, Facebook): placement "stories" with only the 9:16 media. A Story carries
     *    no caption, so it cannot carry the disclosure line. An account with no 9:16 media is skipped.
     */
    public static function plan($caption, array $accounts, array $items, array $opts = array()): array {
        $caption = trim((string) $caption);
        $ai      = !empty($opts['ai']);
        $stories = array_map('strval', (array) ($opts['stories'] ?? array()));
        $media   = array_column($items, 'url');
        $out     = array('accounts' => array(), 'media' => $media, 'platform_configurations' => array(), 'account_configurations' => array(), 'notes' => array());
        $tall    = array_values(array_filter($items, function ($i) { return !empty($i['tall']); }));
        if (empty($tall)) { $tall = array_values((array) ($opts['story_media'] ?? array())); }   // the cover fitted into 9:16

        $feed = array();   // platform => accounts posting to the feed
        foreach ($accounts as $a) {
            $id = (string) $a['id']; $platform = strtolower((string) $a['platform']);
            if (in_array($id, $stories, true) && in_array($platform, AiDisclosure::STORY_PLATFORMS, true)) {
                $story = isset($opts['story_media_by'][$platform]) ? array_values((array) $opts['story_media_by'][$platform]) : $tall;   // checked per platform (MediaCompliance)
                if (empty($story)) { $out['notes'][] = 'The ' . ucfirst($platform) . ' Story was skipped: Stories need 9:16 media.'; continue; }
                // The Story still goes out; the creator is told it cannot carry the disclosure line.
                if ($ai) { $out['notes'][] = 'Stories have no caption, so the ' . ucfirst($platform) . ' Story carries no AI disclosure line.'; }
                $out['accounts'][] = $id;
                $out['account_configurations'][] = array('social_account_id' => $id, 'configuration' => array(
                    'placement' => 'stories',
                    'media'     => array_map(function ($i) { return array('url' => $i['url']); }, $story),
                ));
                continue;
            }
            $out['accounts'][] = $id;
            $feed[$platform][] = $a;
        }

        foreach ($feed as $platform => $list) {
            $cfg  = array();
            $flag = $ai ? AiDisclosure::flag_for($platform) : '';
            if ($flag !== '') { $cfg[$flag] = true; }
            $fit = self::caption_for($platform, $caption);
            if ($fit !== $caption) { $cfg['caption'] = $fit; }
            $slice = array_column(self::media_for($platform, $opts['platform_items'][$platform] ?? $items), 'url');   // Instagram: pictures fitted to its shapes
            if ($slice !== $media) { $cfg['media'] = array_map(function ($u) { return array('url' => $u); }, $slice); }
            if (!empty($cfg)) { $out['platform_configurations'][$platform] = $cfg; }
            if (!$ai || $flag !== '') { continue; }
            // No AI label on this platform: the disclosure line goes on the caption. Accounts can each have their own line.
            $lines = array();
            foreach ($list as $a) { $lines[(string) $a['id']] = AiDisclosure::line($a['ai_disclosure_text'] ?? ''); }
            if (count(array_unique($lines)) === 1) {
                $out['platform_configurations'][$platform]['caption'] = self::caption_with_line($platform, $caption, reset($lines));
            } else {
                foreach ($lines as $id => $line) {
                    $out['account_configurations'][] = array('social_account_id' => (string) $id, 'configuration' => array('caption' => self::caption_with_line($platform, $caption, $line)));
                }
            }
        }
        return $out;
    }

    /** The caption with the disclosure line as its last line, the caption shortened so both fit the platform's limit. */
    public static function caption_with_line($platform, $caption, $line): string {
        $platform = strtolower((string) $platform); $caption = trim((string) $caption); $line = AiDisclosure::line($line);
        if ($caption === '') { return $line; }
        if (mb_stripos($caption, $line) !== false) { return self::caption_for($platform, $caption); }   // already says it
        $tail = "\n\n" . $line;
        return self::caption_for($platform, $caption, self::length_for($platform, $tail)) . $tail;
    }

    /**
     * Where each Story-capable account gets the post, from posts.story_accounts: "<id>" = Story only,
     * "both:<id>" = feed post and Story. Accounts not listed get a feed post. Returns array('story' => ids, 'both' => ids).
     */
    public static function placements($stored): array {
        $out = array('story' => array(), 'both' => array());
        foreach (explode(',', (string) $stored) as $v) {
            $v = trim($v);
            if ($v === '') { continue; }
            if (strpos($v, 'both:') === 0) { $out['both'][] = substr($v, 5); } else { $out['story'][] = $v; }
        }
        $out['story'] = array_values(array_diff(array_unique($out['story']), $out['both']));
        $out['both']  = array_values(array_unique($out['both']));
        return $out;
    }

    /** Hard post-length limits per platform (Post for Me platform keys). Everything else is effectively unlimited. */
    const LIMITS = array('x' => 280, 'bluesky' => 300, 'threads' => 500);

    /** URLs count as a fixed 23 characters on X (t.co wrapping), whatever their real length. */
    const X_URL_WEIGHT = 23;

    /**
     * The caption for one platform: shortened at a word boundary (with an ellipsis) when it
     * exceeds the platform's limit. Platforms without a limit get the text unchanged.
     */
    public static function caption_for($platform, $caption, $reserve = 0): string {
        $platform = strtolower((string) $platform);
        $caption  = trim((string) $caption);
        if (!isset(self::LIMITS[$platform])) { return $caption; }
        $limit = max(1, self::LIMITS[$platform] - (int) $reserve);   // $reserve: room kept free for text added after the caption
        if (self::length_for($platform, $caption) <= $limit) { return $caption; }

        $budget = $limit - self::length_for($platform, '…');

        // Take whole words while they fit, then cut mid-word only if the first word alone is too long.
        $out = '';
        foreach (preg_split('/(\s+)/u', $caption, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            if (self::length_for($platform, $out . $piece) > $budget) { break; }
            $out .= $piece;
        }
        $out = rtrim($out, " \n\t.,;:!?-–—");
        if ($out === '') {
            $chars = preg_split('//u', $caption, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($chars as $ch) {
                if (self::length_for($platform, $out . $ch) > $budget) { break; }
                $out .= $ch;
            }
        }
        return $out . '…';
    }

    /** Length the platform will count: X uses weighted characters with URLs at 23; others count code points. */
    public static function length_for($platform, $text): int {
        $text = (string) $text;
        if ($platform !== 'x') { return mb_strlen($text, 'UTF-8'); }
        $n = 0;
        // Each URL counts as 23 regardless of length.
        $text = preg_replace_callback('~https?://\S+~iu', function ($m) use (&$n) { $n += self::X_URL_WEIGHT; return ''; }, $text);
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            $light = ($cp <= 0x10FF) || ($cp >= 0x2000 && $cp <= 0x200D) || ($cp >= 0x2010 && $cp <= 0x201F) || ($cp >= 0x2032 && $cp <= 0x2037);
            $n += $light ? 1 : 2;
        }
        return $n;
    }

    /** Most media items sent with one cross-post (Instagram's carousel limit). */
    const MAX_MEDIA = 10;

    /** Per-platform item limits; platforms not listed take one item. */
    const MEDIA_LIMITS = array(
        'instagram' => 10, 'facebook' => 10, 'threads' => 10,
        'x' => 4, 'bluesky' => 4, 'linkedin' => 9, 'tiktok' => 10, 'tiktok_business' => 10,
    );

    /** Platforms whose carousels can mix photos and videos; the rest take several photos or one video. */
    const MIXED_MEDIA = array('instagram', 'facebook', 'threads');

    /** Platforms that refuse a post without media (Post for Me platform key => name shown to the creator). */
    const NEEDS_MEDIA = array('instagram' => 'Instagram', 'tiktok' => 'TikTok', 'tiktok_business' => 'TikTok', 'youtube' => 'YouTube', 'pinterest' => 'Pinterest');

    public static function needs_media($platform): bool { return isset(self::NEEDS_MEDIA[strtolower((string) $platform)]); }

    /** The slice of the uploaded items one platform accepts (the cover is always first). */
    public static function media_for($platform, array $items): array {
        $platform = strtolower((string) $platform);
        $limit    = self::MEDIA_LIMITS[$platform] ?? 1;
        if (count($items) > 1 && !in_array($platform, self::MIXED_MEDIA, true) && in_array(true, array_column($items, 'video'), true)) {
            $limit = 1;
        }
        return array_slice($items, 0, $limit);
    }

    /** push_media, keeping track of whether the item is a video. */
    private static function push_item($key, $mime, $is_video, array $items){
        $urls = self::push_media($key, $mime, array());
        if (!empty($urls)) { $items[] = array('url' => $urls[0], 'video' => (bool) $is_video, 'key' => (string) $key, 'mime' => (string) $mime); }
        return $items;
    }

    /** Upload one S3 object to Post for Me's media store; returns $media_urls with the new media_url appended on success. */
    private static function push_media($key, $mime, array $media_urls){
        if ($key === '') { return $media_urls; }
        $tmp = tempnam(sys_get_temp_dir(), 'share');
        if ($tmp === false || !S3Service::get_private_to_file($key, $tmp)) { @unlink($tmp); return $media_urls; }
        $url = self::push_file($tmp, $mime);
        @unlink($tmp);
        if ($url !== '') { $media_urls[] = $url; }
        return $media_urls;
    }

    /** Upload a local file to Post for Me's media store; returns its media_url, or '' on failure. The file is left in place. */
    private static function push_file($tmp, $mime): string{
        $size = (int) @filesize($tmp);
        if ($size <= 0) { return ''; }
        $up = PostForMeService::create_upload_url();
        if (!is_array($up) || count($up) !== 2) { return ''; }
        list($media_url, $upload_url) = $up;
        $fh = fopen($tmp, 'rb');
        $ch = curl_init($upload_url);
        curl_setopt_array($ch, array(
            CURLOPT_PUT => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => $size,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => array('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream')),
        ));
        curl_exec($ch); $ucode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        fclose($fh);
        if ($ucode >= 200 && $ucode < 300) { return (string) $media_url; }
        error_log('[social share] media upload HTTP ' . $ucode . ' ' . $err);
        return '';
    }

    /** Safe for mainstream socials: media (image, or a video's poster frame) cleared as non-adult. */
    private static function sfw(array $a): bool{
        $st = (string) ($a['moderation_status'] ?? '');
        if ($st === 'n_a') { return true; }   // video uploaded before videos were moderated
        return $st === 'approved' && empty($a['is_adult']);
    }

}
