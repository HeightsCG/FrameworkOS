<?php
/**
 * Sample content shown BEHIND the upgrade cover (Plan::COVERS) when a Free account opens a creator page, so the
 * page reads as a working tool instead of an empty shell. Nothing here is stored, sold or reachable: the page is
 * inert under the cover and the API still refuses every creator action for these accounts.
 *
 * Shapes mirror what each page really loads (AnalyticsModel, AudienceModel, and the
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

        $grid = array();
        for ($d = 0; $d < 7; $d++) { $grid[$d] = array(); for ($h = 0; $h < 24; $h++) {
            $peak = max(0, 10 - abs($h - (($d === 0 || $d === 6) ? 14 : 20)) * 2);
            $grid[$d][$h] = ($h < 6) ? 0 : (int) round($peak * (1 + ($d % 3) * .25) + (($d + $h) % 3));
        } }
        $hmax = 1; foreach ($grid as $row) { $hmax = max($hmax, max($row)); }

        $posts = array(); $top = array();
        foreach (self::CAPTIONS as $i => $cap) {
            $views = 1840 - $i * 190;
            $posts[] = array('id' => $i + 1, 'caption' => $cap, 'audience' => 'free', 'state' => 'published', 'published_at' => gmdate('Y-m-d H:i:s', time() - ($i * 2 + 1) * 86400),
                'scheduled_at' => null, 'views' => $views, 'likes' => (int) ($views * .11), 'comments' => (int) ($views * .02),
                'views_period' => (int) ($views * .8), 'platforms' => array(),
                'share_status' => '', 'social' => null, 'engagement' => 13.0 - $i);
            if ($i < 5) { $top[] = array('id' => $i + 1, 'caption' => $cap, 'views' => $views, 'likes' => (int) ($views * .11), 'comments' => (int) ($views * .02),
                'published_at' => gmdate('Y-m-d H:i:s', time() - ($i * 2 + 1) * 86400)); }
        }

        $cur  = array('views' => 18420, 'followers' => 312, 'posts' => 14, 'likes' => 2105, 'comments' => 388, 'engagement' => 13.5);
        $prev = array('views' => 15110, 'followers' => 248, 'posts' => 12, 'likes' => 1790, 'comments' => 301, 'engagement' => 12.1);
        $delta = array(); foreach ($cur as $k => $v) { $delta[$k] = $prev[$k] > 0 ? (int) round(($v - $prev[$k]) / $prev[$k] * 100) : null; }

        return array(
            'needs_plan'        => false,
            'stats'             => array('published_posts' => 86, 'views' => 104300, 'likes' => 11840, 'comments' => 2190,
                                         'followers' => 2460, 'unique_visitors' => 38200),
            'compare'           => array('current' => $cur, 'previous' => $prev, 'delta' => $delta, 'start_utc' => gmdate('Y-m-d H:i:s', time() - $range * 86400)),
            'views_series'      => self::series($range, 420, 380, 1),
            'follower_series'   => self::series($range, 6, 9, 5),
            'top_posts'         => $top,
            'profile_views'     => array('views' => 9240, 'unique' => 6130),
            'link_clicks'       => array('total' => 1284, 'top' => array(array('link_id' => 1, 'clicks' => 640, 'title' => 'Website', 'url' => 'https://example.com'),
                                         array('link_id' => 2, 'clicks' => 411, 'title' => 'Newsletter', 'url' => 'https://example.com/newsletter'))),
            'heatmap'           => array('grid' => $grid, 'max' => $hmax),
            'content_mix'       => array('image' => array('posts' => 52, 'views' => 61200), 'video' => array('posts' => 27, 'views' => 39800),
                                         'text' => array('posts' => 7, 'views' => 3300)),
            'posts_table'       => $posts,
        );
    }

    /** Rows for /audience (AudienceModel::list_for_creator shape). */
    public static function audience(){
        $people = array(array('Maya Chen', 'mayachen', array('VIP')), array('Jordan Ellis', 'jordanellis', array()), array('Priya Nair', 'priyanair', array('Newsletter')),
                        array('Sam Okafor', 'samokafor', array('VIP', 'Coaching')), array('Lena Fischer', 'lenafischer', array()), array('Diego Ramos', 'diegoramos', array()),
                        array('Aiko Tanaka', 'aikotanaka', array('Coaching')), array('Noah Bennett', 'noahbennett', array()));
        $rows = array();
        foreach ($people as $i => $p) {
            $at = gmdate('Y-m-d H:i:s', time() - ($i * 9 + 3) * 86400);
            $rows[] = array('id' => $i + 1, 'is_follower' => true,
                'followed_at' => $at, 'last_at' => $at, 'tags' => $p[2], 'note' => '', 'handle' => $p[1], 'name' => $p[0], 'avatar' => '', 'is_creator' => false);
        }
        return $rows;
    }

    /**
     * Answers for the list endpoints a page's script asks for while it loads, keyed by endpoint. site_header.php hands
     * these to the page when the cover is up; every other call still goes to the server (and is refused).
     */
    public static function api($controller){
        if ($controller === 'studio') {
            $posts = array(); $states = array('published', 'scheduled', 'published', 'draft', 'published', 'published', 'scheduled', 'published'); 
            foreach (self::CAPTIONS as $i => $cap) {
                $st = $states[$i]; $views = $st === 'published' ? 1840 - $i * 190 : 0;
                $posts[] = array('id' => $i + 1, 'state' => $st, 'audience' => 'free', 'caption' => $cap, 'cover_url' => self::image($i), 'cover_type' => $i % 3 === 0 ? 'video' : 'image', 'asset_count' => 1 + ($i % 3),
                    'media_missing' => 0, 'when_label' => $st === 'published' ? 'Published' : ($st === 'scheduled' ? 'Scheduled' : 'Edited'),
                    'when' => gmdate('M j, Y', time() + ($st === 'scheduled' ? 1 : -1) * ($i + 1) * 86400) . ' · 6:00 PM', 'views' => $views, 'likes' => (int) ($views * .11), 'comments' => (int) ($views * .02),
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
