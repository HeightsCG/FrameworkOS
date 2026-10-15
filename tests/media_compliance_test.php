<?php
/**
 * Platform image checks for cross-posts (MediaCompliance): how an image is classed per destination, the versions
 * made in the background (auto vs. proposed for approval), approval, and the check publishing relies on. Builds real
 * versions from a dev Library image (S3) and removes them again; nothing is posted anywhere.
 *   APPLICATION_ENV=development php tests/media_compliance_test.php [creator_id asset_id]
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
$P = function ($w, $h, $bytes = null, $fmt = 'jpeg', $measured = true) { return array('w' => $w, 'h' => $h, 'bytes' => $bytes, 'format' => $fmt, 'measured' => $measured); };
$C = function ($props, $platform, $placement = 'feed', $video = false) { return MediaCompliance::classify($props, MediaCompliance::rules($platform, $placement), $video); };

/* ---- classing ---- */
check('a 4:5 picture fits the Instagram feed as it is',  $C($P(1080, 1350, 400000), 'instagram')['status'] === 'compatible');
$tall = $C($P(1080, 1920, 400000), 'instagram');
check('a 9:16 picture needs approval for the Instagram feed, framed 4:5', $tall['status'] === 'approval' && $tall['frame'] === array(1080, 1350) && strpos($tall['reasons'][0], '4:5') !== false);
$wide = $C($P(3000, 1000, 400000), 'instagram');
check('a 3:1 picture needs approval, framed 1.91:1',     $wide['status'] === 'approval' && $wide['frame'][0] > $wide['frame'][1]);
check('a large square is only downscaled (no approval)',  $C($P(2048, 2048, 900000), 'instagram')['status'] === 'auto' && in_array('downscale', $C($P(2048, 2048, 900000), 'instagram')['ops'], true));
check('over Bluesky\'s file cap: recompressed, no approval', $C($P(1600, 1200, 1500000), 'bluesky')['status'] === 'auto' && in_array('compress', $C($P(1600, 1200, 1500000), 'bluesky')['ops'], true));
check('a PNG for TikTok is converted, no approval',       in_array('convert', $C($P(1080, 1350, 300000, 'png'), 'tiktok')['ops'], true));
check('an image for YouTube is unsupported',              $C($P(1080, 1350, 300000), 'youtube')['status'] === 'unsupported');
check('a video for YouTube is compatible',                 $C($P(1080, 1920, null), 'youtube', 'feed', true)['status'] === 'compatible');
check('a Story needs 9:16: a square needs approval',      $C($P(1080, 1080, 300000), 'instagram', 'story')['frame'] === array(1080, 1920));
check('a 9:16 picture is a Story as it is',               $C($P(1080, 1920, 300000), 'facebook', 'story')['status'] === 'compatible');
check('a 16:9 video for a Story needs approval',          $C($P(1920, 1080), 'instagram', 'story', true)['status'] === 'approval');
check('a video for a feed is sent as it is',              $C($P(1920, 1080), 'instagram', 'feed', true)['status'] === 'compatible');
check('platforms without rules take anything',            $C($P(5000, 100, 9e9), 'fanvue')['status'] === 'compatible');
check('cache keys separate platform, placement and teaser', MediaCompliance::key('instagram', 'story', true) === 'instagram.story.t.r' . MediaCompliance::RULES_VERSION
    && MediaCompliance::key('instagram', 'feed', false) !== MediaCompliance::key('instagram', 'feed', true));

/* ---- real versions from a dev image ---- */
$cid = (int) ($argv[1] ?? 3042); $aid = (int) ($argv[2] ?? 1172);
$mm = new MediaAssetsModel();
$asset = $mm->get_one($cid, $aid);
if (!$asset || (string) $asset['type'] !== 'image') { echo "SKIP real versions: no image $aid for creator $cid\n"; echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1); }
$made = array();
$feed = MediaCompliance::make_variant($cid, $asset, false, 'instagram', 'feed');
if ($feed) { $made[] = (int) $feed['id']; }
check('Instagram feed: a downscaled version is made without approval', $feed && in_array(MediaCompliance::variant_state($feed), array('auto', 'same'), true) && max((int) $feed['width'], (int) $feed['height']) <= 1440, json_encode($feed ? array($feed['width'], $feed['height'], $feed['tags']) : null));
check('the version is hidden from the Library and linked to its original', $feed && (string) $feed['source'] === 'variant' && !empty($feed['library_hidden_at']) && (int) $feed['source_asset_id'] === $aid && empty($feed['parent_asset_id']));
$story = MediaCompliance::make_variant($cid, $asset, false, 'instagram', 'story', 'fit');
if ($story) { $made[] = (int) $story['id']; }
check('Instagram Story: a 9:16 version is made without approval', $story && MediaCompliance::variant_state($story) === 'auto' && (int) $story['width'] === 1080 && (int) $story['height'] === 1920);
$r = MediaCompliance::resolve($cid, $asset, false, 'instagram', 'story');
check('publishing sends the reshaped Story version directly', $r['state'] === 'adjusted' && $r['key'] === (string) $story['display_key']);
$crop = MediaCompliance::make_variant($cid, $asset, false, 'instagram', 'story', 'crop', 'top');
if ($crop) { $made[] = (int) $crop['id']; }
check('trying a crop replaces the version and is sent directly', $crop && MediaCompliance::variant_state($crop) === 'auto' && strpos((string) $crop['tags'], '|m:crop') !== false
    && MediaCompliance::resolve($cid, $asset, false, 'instagram', 'story')['state'] === 'adjusted' && !$mm->get_variant($cid, (int) $story['id']));
$legacy = $crop ? $crop + array() : null;
if ($legacy) { $legacy['tags'] = str_replace('|s:auto', '|s:pending', (string) $legacy['tags']); }
check('a version left pending from before is sent as it is', $legacy && MediaCompliance::variant_state($legacy) === 'auto');
$bs = MediaCompliance::make_variant($cid, $asset, false, 'bluesky', 'feed');
if ($bs) { $made[] = (int) $bs['id']; }
check('Bluesky: the version is under its file cap', $bs && (MediaCompliance::variant_state($bs) === 'same' || (int) $bs['bytes'] <= 976000), json_encode($bs ? array($bs['bytes'], $bs['tags']) : null));
$yt = MediaCompliance::resolve($cid, $asset, false, 'youtube', 'feed');
check('publishing skips YouTube for an image', $yt['state'] === 'unsupported');
$items = array(array('asset' => $asset, 'teaser' => false, 'video' => false));
$chk = MediaCompliance::check($cid, $items, array(array('id' => 'a1', 'platform' => 'instagram', 'placement' => 'story'), array('id' => 'a2', 'platform' => 'youtube', 'placement' => 'feed')), false);
check('the composer check reports adjusted and unsupported per destination, nothing to review', $chk['destinations'][0]['status'] === 'adjusted' && $chk['destinations'][1]['status'] === 'unsupported' && $chk['review'] === 0, json_encode(array_column($chk['destinations'], 'status')));

foreach ($made as $id) { $mm->soft_delete($cid, $id); }
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
