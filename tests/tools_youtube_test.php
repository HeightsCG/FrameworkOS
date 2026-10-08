<?php
/**
 * Fan Question Finder, YouTube source: http() is stubbed with YouTube Data API v3 shaped responses. No network.
 *   APPLICATION_ENV=development php tests/tools_youtube_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $extra === '' ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }

class YtStub extends ToolRunJob {
    public static $calls = array();
    public static $comments = array();   // videoId => array of comment texts, or 403
    public static $videos = array();
    protected static function http(string $url, string $ua, array $o): array {
        $u = parse_url($url); parse_str($u['query'] ?? '', $q); $path = basename($u['path']);
        self::$calls[] = $path;
        if ($path === 'search') {
            $items = array();
            foreach (self::$videos as $v) { $items[] = array('id' => array('videoId' => $v[0]), 'snippet' => array('title' => $v[1], 'channelId' => $v[2], 'channelTitle' => $v[3])); }
            return array('items' => $items);
        }
        if ($path === 'commentThreads') {
            $c = self::$comments[$q['videoId']] ?? array();
            if ($c === 403) { return array('_http_error' => 403); }
            $items = array();
            foreach ($c as $t) { $items[] = array('snippet' => array('topLevelComment' => array('snippet' => array('textOriginal' => $t)))); }
            return array('items' => $items);
        }
        if ($path === 'channels') {
            return array('items' => array(
                array('id' => 'UC1', 'snippet' => array('title' => 'Prep Kitchen', 'description' => str_repeat('d', 300)), 'statistics' => array('subscriberCount' => '125000')),
                array('id' => 'UC2', 'snippet' => array('title' => 'Quiet Cook', 'description' => 'Hidden count'), 'statistics' => array('hiddenSubscriberCount' => true)),
            ));
        }
        return array();
    }
}

ToolRunJob::$cfg_override = array('youtube_api_key' => 'test-key');
check('ready with a youtube key only', ToolRunJob::fan_questions_ready());
check('youtube_key is read', ToolRunJob::youtube_key() === 'test-key');
ToolRunJob::$cfg_override = array();
check('keyless dev box is ready through the fixture', ToolRunJob::fan_questions_ready() === (getenv('APPLICATION_ENV') === 'development'));
ToolRunJob::$cfg_override = array('youtube_api_key' => 'test-key');

YtStub::$videos = array(array('v1', 'How do I meal prep for a week?', 'UC1', 'Prep Kitchen'), array('v2', 'My week of cooking', 'UC1', 'Prep Kitchen'), array('v3', 'Bulk cooking haul', 'UC2', 'Quiet Cook'));
YtStub::$comments = array(
    'v1' => array('How long does cooked rice last in the fridge?', 'how long does cooked rice last in the fridge?', 'Great video thanks', 'Where can I buy these containers? https://shop.example/x', 'Can you email me? me@x.com?', str_repeat('a', 190) . '?', 'Is this safe for kids?', 'Why?'),
    'v2' => 403,
    'v3' => array('What oil do you use?', 'How do you keep greens fresh?'),
);
$r = YtStub::youtube('meal prep');
check('log string', $r['log'] === 'youtube: 2 channels', $r['log']);
check('video title question kept, plain title dropped', in_array('How do I meal prep for a week?', $r['questions'], true) && !in_array('My week of cooking', $r['questions'], true));
check('comment questions kept', in_array('How long does cooked rice last in the fridge?', $r['questions'], true) && in_array('Is this safe for kids?', $r['questions'], true) && in_array('What oil do you use?', $r['questions'], true));
check('case-insensitive dedupe', count(array_filter($r['questions'], function ($q) { return stripos($q, 'cooked rice') !== false; })) === 1);
check('non-questions, urls, at-signs, too long, too short are dropped', count($r['questions']) === 5, json_encode($r['questions']));
check('a 403 video is skipped without failing', count(array_keys(YtStub::$calls, 'commentThreads')) === 3);
check('two channels', count($r['subreddits']) === 2);
$c = $r['subreddits'][0];
check('channel label, url, unit', $c['label'] === 'Prep Kitchen' && $c['url'] === 'https://www.youtube.com/channel/UC1' && $c['unit'] === 'subscribers');
check('channel members and 200 char description', $c['members'] === 125000 && mb_strlen($c['description']) === 200 && $c['name'] === 'Prep Kitchen');
check('hidden subscriber count is 0', $r['subreddits'][1]['members'] === 0 && $r['subreddits'][1]['url'] === 'https://www.youtube.com/channel/UC2');

// MAX_QUESTIONS stops further commentThreads calls
$many = array(); for ($i = 0; $i < 60; $i++) { $many[] = "Question number $i about prep?"; }
YtStub::$videos = array(); for ($i = 1; $i <= 12; $i++) { YtStub::$videos[] = array("m$i", 'Plain title ' . $i, 'UC1', 'Prep Kitchen'); }
YtStub::$comments = array(); foreach (YtStub::$videos as $i => $v) { YtStub::$comments[$v[0]] = array_map(function ($q) use ($i) { return str_replace('?', " on video $i?", $q); }, array_slice($many, 0, 50)); }   // the asking sentence must differ per video
YtStub::$calls = array();
$r = YtStub::youtube('prep');
check('stops at MAX_QUESTIONS', count($r['questions']) === ToolRunJob::MAX_QUESTIONS, (string) count($r['questions']));
check('no commentThreads call once the cap is reached', count(array_keys(YtStub::$calls, 'commentThreads')) === 3, (string) count(array_keys(YtStub::$calls, 'commentThreads')));

// zero questions returns normally
YtStub::$videos = array(array('z1', 'Plain title', 'UC1', 'Prep Kitchen')); YtStub::$comments = array('z1' => array('Nice', 'Thanks for sharing'));
$r = YtStub::youtube('prep');
check('zero questions returns normally', $r['questions'] === array() && count($r['subreddits']) === 1 && $r['log'] === 'youtube: 1 channels');
YtStub::$videos = array();
$r = YtStub::youtube('prep');
check('no results at all returns empty', $r['questions'] === array() && $r['subreddits'] === array() && $r['log'] === 'youtube: 0 channels');

// email renders both the new and the pre-YouTube row shapes
$html = ToolRunJob::ideas_email('', 'prep', array('questions_read' => 5, 'communities' => 2, 'themes' => array(), 'subreddits' => array(
    array('name' => 'Prep Kitchen', 'members' => 125000, 'why' => 'Weekly prep.', 'label' => 'Prep Kitchen', 'url' => 'https://www.youtube.com/channel/UC1', 'unit' => 'subscribers'),
    array('name' => 'MealPrepSunday', 'members' => 3900000, 'why' => ''),
)));
check('email: youtube channel link, label and unit', strpos($html, 'href="https://www.youtube.com/channel/UC1"') !== false && strpos($html, '>Prep Kitchen</a>, 125,000 subscribers') !== false);
check('email: old row falls back to the reddit link', strpos($html, 'href="https://www.reddit.com/r/MealPrepSunday/"') !== false && strpos($html, '>r/MealPrepSunday</a>, 3,900,000 members') !== false);

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
