<?php
/**
 * Sample content shown BEHIND the upgrade cover (Plan::COVERS) when a Free account opens a creator page, so the
 * page reads as a working tool instead of an empty shell. Nothing here is stored, sold or reachable: the page is
 * inert under the cover and the API still refuses every creator action for these accounts.
 *
 * Shapes mirror what each page really loads (AnalyticsModel, AudienceModel, EventsModel, ServicesModel, and the
 * posts_list / media_list / collections_list / influencer_list API answers). If one of those gains a field the
 * page depends on, add it here too.
 */
class PlanCoverSample {

    /** Captions used across the sample posts. */
    const CAPTIONS = array(
        'Behind the scenes from this morning\'s shoot.',
        'New tutorial is up: lighting a small room with one lamp.',
        'Golden hour walk, quick vlog.',
        'Full workout routine, 20 minutes, no equipment.',
        'Answering your questions from last week.',
        'Studio tour: everything on my desk.',
        'Weekend recap and what is coming next.',
        'Members-only: the full edit, start to finish.',
    );

    /** A soft two-tone tile standing in for a photo (inline SVG, no file needed). */
    public static function image($i, $w = 600, $h = 600){
        $tones = array(array('#FFB380', '#FF6A13'), array('#F5D0B5', '#C2410C'), array('#FFD9BF', '#E85A06'), array('#E9E4DF', '#A8A29E'),
                       array('#FFC49B', '#9A3412'), array('#F3EEE9', '#D6CFC7'), array('#FFE4D1', '#FB923C'), array('#D6D3D1', '#78716C'));
        $t = $tones[((int) $i) % count($tones)];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '">'
             . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $t[0] . '"/><stop offset="1" stop-color="' . $t[1] . '"/></linearGradient></defs>'
             . '<rect width="100%" height="100%" fill="url(#g)"/>'
             . '<circle cx="' . (int) ($w * (0.3 + 0.1 * ($i % 4))) . '" cy="' . (int) ($h * 0.42) . '" r="' . (int) ($w * 0.18) . '" fill="#fff" fill-opacity=".28"/>'
             . '<rect x="0" y="' . (int) ($h * 0.72) . '" width="100%" height="' . (int) ($h * 0.28) . '" fill="#000" fill-opacity=".1"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** A daily series ({date, value}) of $days points ending today, shaped like AnalyticsModel's. */
    private static function series($days, $base, $swing, $seed){
        $out = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $n = $days - $i;
            $v = $base + (int) round($swing * (0.5 + 0.5 * sin(($n + $seed) / 2.3)) + ($n * $base / (3 * $days)));
            if (($n + $seed) % 7 === 0) { $v = (int) round($v * 1.6); }
            $out[] = array('date' => gmdate('Y-m-d', time() - $i * 86400), 'value' => max(0, $v));
        }
        return $out;
    }

    /** View variables for /dashboard. */
    public static function dashboard($range){
        $range = (int) $range;
        $rev   = self::series($range, 2600, 5200, 3);
        $total = array_sum(array_map(function ($p) { return (int) $p['value']; }, $rev));
        $break = array('ppv_cents' => (int) ($total * .34), 'bundle_cents' => (int) ($total * .12), 'message_cents' => (int) ($total * .09), 'event_cents' => (int) ($total * .16),
                       'service_cents' => (int) ($total * .14), 'tip_cents' => (int) ($total * .07), 'replay_cents' => (int) ($total * .08));
        $break['total_cents'] = array_sum($break);
        $life = array(); foreach ($break as $k => $v) { $life[$k] = $v * 6; }

        $grid = array();
        for ($d = 0; $d < 7; $d++) { $grid[$d] = array(); for ($h = 0; $h < 24; $h++) {
            $peak = max(0, 10 - abs($h - (($d === 0 || $d === 6) ? 14 : 20)) * 2);
            $grid[$d][$h] = ($h < 6) ? 0 : (int) round($peak * (1 + ($d % 3) * .25) + (($d + $h) % 3));
        } }
        $hmax = 1; foreach ($grid as $row) { $hmax = max($hmax, max($row)); }

        $posts = array(); $top = array();
        $aud = array('free', 'subscribers', 'ppv', 'free', 'subscribers', 'ppv', 'free', 'subscribers');
        foreach (self::CAPTIONS as $i => $cap) {
            $views = 1840 - $i * 190; $unlocks = $aud[$i] === 'ppv' ? 64 - $i * 6 : 0;
            $posts[] = array('id' => $i + 1, 'caption' => $cap, 'audience' => $aud[$i], 'state' => 'published', 'published_at' => gmdate('Y-m-d H:i:s', time() - ($i * 2 + 1) * 86400),
                'scheduled_at' => null, 'views' => $views, 'likes' => (int) ($views * .11), 'comments' => (int) ($views * .02), 'earnings_cents' => $unlocks * 500,
                'ppv_price_credits' => $aud[$i] === 'ppv' ? 50 : null, 'fanvue_post_uuid' => null, 'views_period' => (int) ($views * .8), 'unlocks' => $unlocks, 'platforms' => array(),
                'share_status' => '', 'social' => null, 'unlock_rate' => $aud[$i] === 'ppv' ? round($unlocks / max(1, $views) * 100, 1) : null, 'engagement' => 13.0 - $i);
            if ($i < 5) { $top[] = array('id' => $i + 1, 'caption' => $cap, 'audience' => $aud[$i], 'views' => $views, 'likes' => (int) ($views * .11), 'comments' => (int) ($views * .02),
                'earnings_cents' => $unlocks * 500, 'published_at' => gmdate('Y-m-d H:i:s', time() - ($i * 2 + 1) * 86400)); }
        }

        $sales = array();
        foreach (array(array('ppv', 50, 'Pay-per-view post'), array('tip', 100, 'Tip on a post'), array('event', 150, 'Event ticket'), array('service', 1200, 'One-to-one session'),
                       array('bundle', 300, 'Content bundle'), array('message', 80, 'Paid message')) as $i => $s) {
            $sales[] = array('kind' => $s[0], 'credits' => $s[1], 'created_at' => gmdate('Y-m-d H:i:s', time() - ($i * 5 + 1) * 3600), 'item' => $s[2]);
        }

        $cur  = array('revenue_cents' => $total, 'views' => 18420, 'followers' => 312, 'subscribers' => 46, 'posts' => 14, 'likes' => 2105, 'comments' => 388, 'unlocks' => 231, 'ppv_views' => 2960, 'engagement' => 13.5, 'unlock_rate' => 7.8);
        $prev = array('revenue_cents' => (int) ($total * .78), 'views' => 15110, 'followers' => 248, 'subscribers' => 39, 'posts' => 12, 'likes' => 1790, 'comments' => 301, 'unlocks' => 188, 'ppv_views' => 2610, 'engagement' => 12.1, 'unlock_rate' => 7.2);
        $delta = array(); foreach ($cur as $k => $v) { $delta[$k] = $prev[$k] > 0 ? (int) round(($v - $prev[$k]) / $prev[$k] * 100) : null; }

        return array(
            'needs_plan'        => false,
            'stats'             => array('published_posts' => 86, 'ppv_cents' => $life['ppv_cents'], 'views' => 104300, 'likes' => 11840, 'comments' => 2190, 'subscribers' => 184, 'mrr_cents' => 184 * 900,
                                         'followers' => 2460, 'unlocks' => 1320, 'unique_visitors' => 38200),
            'compare'           => array('current' => $cur, 'previous' => $prev, 'delta' => $delta, 'start_utc' => gmdate('Y-m-d H:i:s', time() - $range * 86400)),
            'views_series'      => self::series($range, 420, 380, 1),
            'revenue_series'    => $rev,
            'follower_series'   => self::series($range, 6, 9, 5),
            'revenue_breakdown' => $break,
            'revenue_lifetime'  => $life,
            'top_posts'         => $top,
            'recent_sales'      => $sales,
            'customers'         => array('customers' => 412, 'repeat' => 168, 'repeat_pct' => 41, 'arpu_cents' => 2350, 'gross_cents' => 412 * 2350),
            'sub_movement'      => array('new' => 12, 'churned' => 5, 'net' => 7),
            'profile_views'     => array('views' => 9240, 'unique' => 6130),
            'link_clicks'       => array('total' => 1284, 'top' => array(array('link_id' => 1, 'clicks' => 640, 'title' => 'Website', 'url' => 'https://example.com'),
                                         array('link_id' => 2, 'clicks' => 411, 'title' => 'Newsletter', 'url' => 'https://example.com/newsletter'))),
            'heatmap'           => array('grid' => $grid, 'max' => $hmax),
            'content_mix'       => array('free' => array('posts' => 38, 'views' => 61200, 'earnings' => 0), 'subscribers' => array('posts' => 31, 'views' => 29800, 'earnings' => 0),
                                         'ppv' => array('posts' => 17, 'views' => 13300, 'earnings' => $life['ppv_cents'])),
            'posts_table'       => $posts,
            'nudge'             => null,
        );
    }

    /** Rows for /audience (AudienceModel::list_for_creator shape). */
    public static function audience(){
        $people = array(array('Maya Chen', 'mayachen', 1, 1, 1, 1840, 9, array('VIP')), array('Jordan Ellis', 'jordanellis', 1, 1, 0, 0, 0, array()), array('Priya Nair', 'priyanair', 1, 0, 1, 620, 4, array('Events')),
                        array('Sam Okafor', 'samokafor', 1, 1, 1, 2950, 14, array('VIP', 'Coaching')), array('Lena Fischer', 'lenafischer', 1, 0, 0, 0, 0, array()), array('Diego Ramos', 'diegoramos', 0, 0, 1, 150, 1, array()),
                        array('Aiko Tanaka', 'aikotanaka', 1, 1, 1, 980, 6, array('Coaching')), array('Noah Bennett', 'noahbennett', 1, 0, 1, 300, 2, array()));
        $rows = array();
        foreach ($people as $i => $p) {
            $at = gmdate('Y-m-d H:i:s', time() - ($i * 9 + 3) * 86400);
            $rows[] = array('id' => $i + 1, 'is_follower' => (bool) $p[2], 'is_subscriber' => (bool) $p[3], 'is_buyer' => (bool) $p[4], 'spend_credits' => $p[5], 'purchases' => $p[6],
                'followed_at' => $p[2] ? $at : null, 'subscribed_at' => $p[3] ? $at : null, 'last_at' => $at, 'tags' => $p[7], 'note' => '', 'handle' => $p[1], 'name' => $p[0], 'avatar' => '', 'is_creator' => false);
        }
        return $rows;
    }

    /** Rows for /events (EventsModel::list_for_creator shape). */
    public static function events(){
        $base = array('creator_id' => 0, 'description' => '', 'timezone' => 'America/New_York', 'reminders' => '1440', 'tier_id' => null, 'external_url' => '', 'access_instructions' => '', 'status' => 'published',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'), 'venue_name' => '', 'street' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'call_password' => null,
            'call_waiting_room' => 0, 'call_screen_share' => 'host', 'call_attendees' => 'talk', 'call_chat' => 1, 'replay_recording_id' => null, 'replay_price_credits' => 0, 'replay_free_attendees' => 1);
        $list = array(array('Live Q&A: Growing Your First 1,000 Fans', 5, 'paid', 100, 100, 'cls_video', '', 62), array('Members Workshop: Editing in an Hour', 12, 'subscribers', 0, 40, 'cls_video', '', 31),
                      array('Creator Meetup', 26, 'paid', 150, 25, 'in_person', 'Downtown', 18), array('Open Office Hours', 33, 'free', 0, 0, 'cls_video', '', 44));
        $rows = array();
        foreach ($list as $i => $e) {
            $start = time() + $e[1] * 86400;
            $rows[] = array_merge($base, array('id' => $i + 1, 'title' => $e[0], 'start_at' => gmdate('Y-m-d 23:00:00', $start), 'end_at' => gmdate('Y-m-d 23:59:00', $start), 'access_type' => $e[2],
                'price_credits' => $e[3], 'capacity' => $e[4], 'format' => $e[5], 'location' => $e[6], 'attendees' => $e[7]));
        }
        return $rows;
    }

    /** Rows for /services (ServicesModel::list_for_creator shape). */
    public static function services(){
        $list = array(array('One-to-One Strategy Call', 1200, 45, 'cls_video', 'Consulting', 27), array('Profile and Content Review', 600, 30, 'cls_video', 'Review', 41), array('Half-Day Coaching Session', 3500, 180, 'in_person', 'Coaching', 6));
        $rows = array();
        foreach ($list as $i => $s) {
            $rows[] = array('id' => $i + 1, 'creator_id' => 0, 'name' => $s[0], 'description' => '', 'price_credits' => $s[1], 'duration_min' => $s[2], 'delivery_method' => $s[3], 'scheduling_url' => '',
                'delivery_details' => '', 'capacity' => 0, 'category' => $s[4], 'refund_policy' => '', 'status' => 'published', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'), 'purchases' => $s[5]);
        }
        return $rows;
    }

    /**
     * Answers for the list endpoints a page's script asks for while it loads, keyed by endpoint. site_header.php hands
     * these to the page when the cover is up; every other call still goes to the server (and is refused).
     */
    public static function api($controller){
        if ($controller === 'studio') {
            $posts = array(); $states = array('published', 'scheduled', 'published', 'draft', 'published', 'published', 'scheduled', 'published'); $aud = array('free', 'subscribers', 'ppv', 'free', 'subscribers', 'ppv', 'free', 'subscribers');
            foreach (self::CAPTIONS as $i => $cap) {
                $st = $states[$i]; $views = $st === 'published' ? 1840 - $i * 190 : 0;
                $posts[] = array('id' => $i + 1, 'state' => $st, 'audience' => $aud[$i], 'caption' => $cap, 'cover_url' => self::image($i), 'cover_type' => $i % 3 === 0 ? 'video' : 'image', 'asset_count' => 1 + ($i % 3),
                    'media_missing' => 0, 'when_label' => $st === 'published' ? 'Published' : ($st === 'scheduled' ? 'Scheduled' : 'Edited'),
                    'when' => gmdate('M j, Y', time() + ($st === 'scheduled' ? 1 : -1) * ($i + 1) * 86400) . ' · 6:00 PM', 'views' => $views, 'likes' => (int) ($views * .11), 'comments' => (int) ($views * .02),
                    'earnings_cents' => $aud[$i] === 'ppv' && $st === 'published' ? 21000 - $i * 1500 : 0, 'ppv_price_credits' => $aud[$i] === 'ppv' ? 50 : null, 'ppv_unlocks' => $aud[$i] === 'ppv' && $st === 'published' ? 42 - $i * 3 : 0,
                    'moderation' => 'ok', 'shared_count' => $i % 2);
            }
            $assets = array();
            for ($i = 0; $i < 18; $i++) {
                $assets[] = array('id' => $i + 1, 'type' => $i % 4 === 0 ? 'video' : 'image', 'status' => 'ready', 'name' => 'Sample ' . ($i + 1), 'filename' => 'sample-' . ($i + 1) . '.jpg', 'description' => '', 'tags' => array(),
                    'duration' => $i % 4 === 0 ? 12 : 0, 'width' => 1080, 'height' => 1080, 'bytes' => 480000, 'watermark_applied' => 0, 'usage_count' => $i % 3, 'failure_reason' => '', 'moderation' => 'ok',
                    'created_at' => gmdate('Y-m-d H:i:s', time() - $i * 86400), 'thumb_url' => self::image($i), 'video_url' => '');
            }
            return array(
                'posts_list'       => array('success' => true, 'posts' => $posts, 'counts' => array('all' => 8, 'draft' => 1, 'scheduled' => 2, 'published' => 5, 'archived' => 0)),
                'media_list'       => array('success' => true, 'assets' => $assets, 'total' => count($assets)),
                'collections_list' => array('success' => true, 'collections' => array()),
                'heartbeat'        => array('success' => true),
                'set_timezone'     => array('success' => true),
            );
        }
        if ($controller === 'influencers') {
            $list = array();
            foreach (array('Nova', 'Ari', 'Sol') as $i => $name) {
                $list[] = array('id' => $i + 1, 'name' => $name, 'gender' => 'woman', 'status' => 'ready', 'path' => 'reference', 'input_method' => 'text', 'is_public' => 0, 'source_description' => '', 'reference_model_key' => '',
                    'steer_text' => '', 'prompt_defaults' => '', 'negative_prompt' => '', 'face_asset_id' => 0, 'reference_asset_id' => 0, 'training_set_group' => '', 'active_model_id' => $i + 1, 'pending_model_id' => 0,
                    'trigger_word' => '', 'trained_at' => gmdate('Y-m-d H:i:s'), 'share_accounts' => array(), 'locked' => false, 'last_error' => '', 'wizard_step' => 'done',
                    'counts' => array('upload' => 0, 'training' => 10, 'generated' => 24 - $i * 6, 'video' => 4 - $i, 'training_done' => 10), 'cover_url' => self::image($i + 2, 600, 750),
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'));
            }
            return array('influencer_list' => array('success' => true, 'influencers' => $list));
        }
        return array();
    }
}
