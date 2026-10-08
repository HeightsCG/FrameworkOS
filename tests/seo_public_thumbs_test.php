<?php
// Public thumbnails (PublicThumbService): a free, approved asset with a public rendition gets a permanent, unsigned
// URL; adult, unapproved or not-yet-built ones get nothing public, so callers fall back to a signed URL.
$root = dirname(__DIR__); putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/controllers/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });
$fail = 0; function check($l, $c) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $l . "\n"; if (!$c) { $fail++; } }
$signed = function ($u) { return strpos((string) $u, 'X-Amz-Signature=') !== false; };

$base = array('id' => 1, 'creator_id' => 1, 'type' => 'image', 'moderation_status' => 'approved', 'is_adult' => 0,
              'thumb_key' => 'vault/1/media/1/thumb.jpg', 'blurred_key' => 'vault/1/media/1/blurred.jpg',
              'public_thumb_key' => 'creator/1/media/1/public_480_abc123abc123.webp', 'public_display_key' => 'creator/1/media/1/public_960_abc123abc123.webp',
              'public_width' => 960, 'public_height' => 720);
$pub = PublicThumbService::url($base, 'grid');
check('public thumb url is set', $pub !== '');
check('public thumb url is unsigned', !$signed($pub) && strpos($pub, '?') === false);
check('public thumb lives under creator/ (public-read prefix)', strpos($pub, '.amazonaws.com/creator/') !== false);
check('feed size uses the 960 rendition', strpos(PublicThumbService::url($base, 'feed'), 'public_960_') !== false);
check('grid size is 480 wide, same aspect', PublicThumbService::size($base, 'grid') === array(480, 360));

$adult = array_merge($base, array('moderation_status' => 'flagged', 'is_adult' => 1));
check('adult asset gets no public url', PublicThumbService::url($adult, 'grid') === '');
check('pending asset gets no public url', PublicThumbService::url(array_merge($base, array('moderation_status' => 'pending')), 'grid') === '');
check('asset without a rendition gets no public url', PublicThumbService::url(array_merge($base, array('public_thumb_key' => null)), 'grid') === '');
if (S3Service::configured()) {
    check('gated/adult fallback is a signed url', $signed(MediaService::signed_variant($adult, 'blurred', 900)));
} else {
    echo "skip gated/adult fallback is a signed url (s3 not configured)\n";
}

check('alt is the caption, first 100 chars', mb_strlen(PublicThumbService::alt(str_repeat('a', 300), 'Name')) === 100);
check('alt falls back to the creator name', PublicThumbService::alt('  ', 'Lexi Vaughn') === 'Lexi Vaughn');
check('blog cover small sibling', PublicThumbService::cover_small('https://b.s3.x.amazonaws.com/creator/blog/a-1-960.webp') === 'https://b.s3.x.amazonaws.com/creator/blog/a-1-480.webp');

// the profile grid only reaches for the public copy on free posts.
$ctl = file_get_contents("$root/app/controllers/ProfileController.php");
check("profile uses the public copy only for audience 'free'", strpos($ctl, "(\$cover && \$audience === 'free') ? PublicThumbService::url(\$cover, 'grid')") !== false);
check('blog cover fit_webp stays under 80 KB by shrinking (noise image)', (function () {
    $i = imagecreatetruecolor(2000, 1500);
    for ($y = 0; $y < 1500; $y += 2) { for ($x = 0; $x < 2000; $x += 2) { imagesetpixel($i, $x, $y, mt_rand(0, 0xffffff)); } }
    $ok = strlen(PublicThumbService::fit_webp($i, 960, PublicThumbService::COVER_MAX_BYTES)) <= PublicThumbService::COVER_MAX_BYTES;
    imagedestroy($i); return $ok;
})());

// creator rule: a demo account's free posts (demoaccount 3042, post 273) never qualify for public copies.
$demo_ids = array_column((array) (new Model())->select("SELECT ma.id FROM media_assets ma JOIN user_accounts ua ON ua.user_id = ma.creator_id WHERE ua.is_demo = 1 AND " . MediaAssetsModel::public_ok_sql()), 'id');
check('no demo account media qualifies for a public copy', empty($demo_ids));

// Controller level, against the dev site (fixtures: @admin's free posts 122-127 and 143, whose media have public copies).
// Post 143 is switched to subscribers straight in the DB for the request (no purge hook, so its public copy still
// exists) and put back afterwards: the gated card must still be signed, the free ones public and unsigned.
$db = new Model();
$fixture = $db->select("SELECT audience FROM posts WHERE id = 143 AND creator_id = 1 AND state = 'published'");
if (empty($fixture) || $fixture[0]['audience'] !== 'free') {
    echo "skip controller check (fixture post 143 missing or not free)\n";
} else {
    $db->sql("UPDATE posts SET audience = 'subscribers' WHERE id = 143");
    register_shutdown_function(function () use ($db) { $db->sql("UPDATE posts SET audience = 'free' WHERE id = 143"); });
    $html = (string) @file_get_contents('http://framework.contentos.cvk/@admin');
    $db->sql("UPDATE posts SET audience = 'free' WHERE id = 143");
    $cards = array();
    if (preg_match('/window\.PROFILE_POSTS = (\[.*?\]);<\/script>/s', $html, $m)) { foreach ((array) json_decode($m[1], true) as $c) { $cards[(int) $c['id']] = $c; } }
    check('profile page renders the fixture cards', isset($cards[143], $cards[122]));
    if (isset($cards[143], $cards[122])) {
        check('gated card (post 143, subscribers) cover is signed', $signed($cards[143]['cover']) && strpos($cards[143]['cover'], 'public_') === false);
        check('free card (post 122) cover is the unsigned public copy', !$signed($cards[122]['cover']) && strpos($cards[122]['cover'], '/public_480_') !== false);
    }
}
exit($fail ? 1 : 0);
