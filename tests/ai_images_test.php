<?php
/**
 * Stage 2 image tools: angle set, Replicate prompts and masking, Edit lineage, Carousel, scene templates.
 * Dev DB (creator #1 "admin" with a trained influencer) and a fake provider: nothing is sent to fal.
 * Claude is not called: the two actions that use it (replicate_prepare, carousel_plan) are exercised with
 * their inputs prepared here.
 *   APPLICATION_ENV=development php tests/ai_images_test.php
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

class FakeImgProvider implements InfluencerProvider {
    public static $seen = array();
    public static function key(): string { return 'fake'; }
    public static function capabilities(): array { return array('ops' => array('edit' => true, 'replicate' => true, 'angle' => true)); }
    private static function submit(array $req): array {
        self::$seen[] = $req; $id = 'fake_' . bin2hex(random_bytes(6));
        return array('ok' => true, 'error' => '', 'error_code' => '', 'retryable' => false, 'http_code' => 200,
            'handle' => array('provider' => 'fake', 'provider_job_id' => $id, 'status_url' => 'fake://s/' . $id, 'response_url' => 'fake://r/' . $id, 'cancel_url' => ''));
    }
    public static function generate_image(array $req): array { return self::submit($req); }
    public static function generate_video(array $req): array { return self::submit($req); }
    public static function train_model(array $req): array { return self::submit($req); }
    public static function get_job_status(array $handle): array { return array('ok' => true, 'state' => 'running', 'error' => '', 'error_code' => '', 'retryable' => false, 'raw' => array()); }
    public static function fetch_result(array $handle): array { return array('ok' => false, 'error' => 'none', 'error_code' => 'provider', 'retryable' => false, 'outputs' => array()); }
    public static function cancel(array $handle): bool { return true; }
    public static function output_url_allowed($url): bool { return false; }
}
InfluencerConfig::register_provider('fake', 'FakeImgProvider');
foreach (array('edit', 'replicate', 'angle') as $op) { InfluencerConfig::set_override('providers_' . $op, 'fake'); }
foreach (array('grok_edit', 'nano_banana_edit', 'nano_banana_pro_edit', 'seedream_45_edit') as $k) { InfluencerConfig::set_override('endpoint_' . $k . '_fake', 'fake/' . $k); }

$cid = 1;
$jobs = new InfluencerJobsModel(); $media = new MediaAssetsModel(); $credits = new AiCreditsModel();
$bal = function () use ($credits, $cid) { return (int) $credits->get_balance($cid); };
$queue_clean = function ($job_id) { (new JobsModel())->sql("DELETE FROM jobs WHERE dedupe_key LIKE :k", array(':k' => 'infl_job:' . (int) $job_id . ':%')); };
/** Cancel (refund) and remove a test job; the queue row goes first so the worker never runs it against fal. */
$drop = function ($job_id) use ($cid, $jobs, $queue_clean) {
    $queue_clean($job_id);
    InfluencerJobService::cancel_job($cid, (int) $job_id);
    $jobs->sql("DELETE FROM influencer_jobs WHERE id = :id AND creator_id = :c", array(':id' => (int) $job_id, ':c' => $cid));
};

$infl = null;
foreach ((new InfluencersModel())->list_ready($cid) as $r) { $infl = (new InfluencersModel())->get_one($cid, (int) $r['id']); break; }
check('fixture: creator #1 has a trained influencer', (bool) $infl);
if (!$infl) { echo "1 FAILED\n"; exit(1); }
$start = $bal();

/* ---- identity references ---- */
$base = InfluencerService::base_reference($cid, $infl);
$refs = InfluencerService::identity_refs($cid, $infl);
check('she has a base reference image',                 $base > 0 && $refs[0] === $base);
check('all eight angle slots are defined',              count(InfluencerService::ANGLES) === 8 && count(array_filter(array_keys(InfluencerService::ANGLES), function ($k) { return strpos($k, 'front_close') === 0; })) === 3);
check('every angle has a prompt and a renderable shape', !array_filter(array_keys(InfluencerService::ANGLES), function ($k) use ($infl) { return InfluencerService::angle_prompt($infl, $k) === '' || !Aspect::valid(InfluencerService::ANGLES[$k]['aspect']); }));

/* ---- angle set ---- */
$st = InfluencerImageActions::angle_set_status($cid, $infl);
check('angle status lists eight slots with a price',    !empty($st['ok']) && count($st['slots']) === 8 && $st['price_each'] === 30);
$r = InfluencerImageActions::angle_set_generate($cid, $infl, array('left_profile', 'nonsense'));
check('generating one slot makes one job',              !empty($r['ok']) && count($r['job_ids']) === 1, json_encode($r));
$aj = $jobs->get_by_id($r['job_ids'][0]); $queue_clean($aj['id']);
check('the angle job carries its slot and shape',       (string) $aj['type'] === 'angle' && InfluencerJobsModel::params($aj)['angle'] === 'left_profile' && (int) $aj['input_asset_id'] === $base);
// An angle that already has an image is made again at no charge (twice per angle); a first one, or one past its free redos, costs 30.
$slot_now = null; foreach ($st['slots'] as $sl) { if ($sl['slot'] === 'left_profile') { $slot_now = $sl; } }
$want = !empty($slot_now['redo_free']) ? 0 : 30;
check('an angle costs 30 credits, or nothing when it is a free redo', (int) $aj['credits_charged'] === $want && $bal() === $start - $want, 'charged ' . $aj['credits_charged'] . ', expected ' . $want);
InfluencerJobService::step($aj['id'], array('inline' => true));
$sent = end(FakeImgProvider::$seen);
check('her reference is the image sent',                count($sent['image_urls']) === 1 && $sent['aspect_ratio'] === '3:4' && $sent['aspect_value'] === '3:4');
$st = InfluencerImageActions::angle_set_status($cid, $infl);
$lp = array_values(array_filter($st['slots'], function ($s) { return $s['slot'] === 'left_profile'; }))[0];
check('the slot shows as working',                      $lp['status'] === 'working' && $st['active'] === 1);
$again = InfluencerImageActions::angle_set_generate($cid, $infl, array('left_profile'));
check('a slot already generating is not started twice', empty($again['ok']));
$drop($aj['id']);
check('cancelled angle run is refunded',                $bal() === $start);
check('approving a non-angle image is refused',         empty(InfluencerImageActions::angle_approve($cid, $infl, $base)['ok']));

/* ---- replicate: prompts ---- */
$seen = array('features' => 'long dark wavy hair, brown eyes, light olive skin', 'outfit' => 'a white linen shirt and blue jeans.', 'setting' => 'a beach boardwalk at sunrise, soft warm light');
$sp = InfluencerImageActions::style_prompt($infl, $seen, 'make it golden hour');
check('Style prompt follows the template',              strpos($sp, 'Recreate the scene and pose from @img1 featuring the exact face and physical features of the model in @img2 (long dark wavy hair') === 0
    && strpos($sp, 'She is posing EXACTLY like the model in @img1.') !== false && strpos($sp, 'She is wearing a white linen shirt and blue jeans.') !== false
    && strpos($sp, 'The camera angle is exactly the same as @img1.') !== false && strpos($sp, 'Setting: a beach boardwalk at sunrise, soft warm light.') !== false
    && strpos($sp, 'Photorealistic, UGC style, raw unedited photo, natural skin texture.') !== false && substr($sp, -21) === ' make it golden hour.', $sp);
$loose = InfluencerImageActions::style_prompt($infl, array('features' => 'A young woman with long dark hair and brown eyes.', 'outfit' => 'She wears a green dress.', 'setting' => 'She sits on stone steps in an old town.'));
check('loose descriptions still read as one sentence',  strpos($loose, 'model in @img2 (long dark hair and brown eyes).') !== false && strpos($loose, 'She is wearing a green dress.') !== false && strpos($loose, 'Setting: stone steps in an old town.') === false && strpos($loose, 'She is wearing She') === false, $loose);
check('a man gets "He"',                                strpos(InfluencerImageActions::style_prompt(array('gender' => 'man') + $infl, $seen), 'He is posing EXACTLY') !== false);
$ep = InfluencerImageActions::exact_prompt($infl, $seen);
check('Exact prompt swaps and keeps the composition',   strpos($ep, 'Replace the person in @img1 with the model in @img2') === 0 && strpos($ep, 'Keep the pose, outfit, composition, background, lighting and camera angle of @img1 exactly') !== false);

/* ---- replicate: face mask ---- */
check('a face box is clamped to the image',             FaceMask::clean_box(array('x' => 0.9, 'y' => 0.9, 'w' => 0.5, 'h' => 0.5)) === array('x' => 0.9, 'y' => 0.9, 'w' => 0.1, 'h' => 0.1));
check('a nonsense box is rejected',                     FaceMask::clean_box(array('x' => 'a')) === null && FaceMask::clean_box(null) === null && FaceMask::clean_box(array('x' => 0.2, 'y' => 0.2, 'w' => 0, 'h' => 0.3)) === null);
$im = imagecreatetruecolor(200, 300); imagefill($im, 0, 0, imagecolorallocate($im, 255, 0, 0)); ob_start(); imagepng($im); $red = ob_get_clean(); imagedestroy($im);
$masked = FaceMask::apply($red, array('x' => 0.4, 'y' => 0.2, 'w' => 0.2, 'h' => 0.2));
$mi = imagecreatefromstring($masked);
$c_face = imagecolorat($mi, 100, 90); $c_out = imagecolorat($mi, 10, 280);
check('the face region is painted grey',                abs((($c_face >> 16) & 0xFF) - 128) < 12 && abs(($c_face & 0xFF) - 128) < 12);
check('the rest of the photo is untouched',             (($c_out >> 16) & 0xFF) > 230 && ($c_out & 0xFF) < 30);
check('the mask keeps the image size',                  imagesx($mi) === 200 && imagesy($mi) === 300);
imagedestroy($mi);
$bm = imagecreatefromstring(FaceMask::apply($red, null, array(array('x' => 0.5, 'y' => 0.5, 'r' => 0.1))));
$c_b = imagecolorat($bm, 100, 150); imagedestroy($bm);
check('a brush stroke masks where it was painted',      abs((($c_b >> 16) & 0xFF) - 128) < 12);
check('nothing to mask returns nothing',                FaceMask::apply($red, null, array()) === '');
check('only the creator\'s own keys can be signed',     FaceMask::owns_key(1, 'vault/1/tmp/mask_a.jpg') && !FaceMask::owns_key(1, 'vault/2/tmp/mask_a.jpg') && !FaceMask::owns_key(1, 'vault/1/../2/x.jpg'));

/* ---- replicate: the job ---- */
$src = 0;
foreach ((array) $media->get_for_creator($cid, array('type' => 'image')) as $a) { if ((string) $a['status'] === 'ready' && (string) $a['moderation_status'] !== 'blocked' && (int) $a['id'] !== $base && !in_array((int) $a['id'], $refs, true)) { $src = (int) $a['id']; break; } }
$r = InfluencerImageActions::replicate($cid, $infl, array('source_asset_id' => $src, 'mode' => 'style', 'prompt' => $sp, 'face' => null, 'mask' => false, 'aspect' => '9:16', 'num_images' => 2));
check('a replica job starts from a prepared prompt',    !empty($r['ok']) && $r['job_id'] > 0 && $r['masked'] === false, json_encode(array_diff_key($r, array('job' => 1))));
$rj = $jobs->get_by_id($r['job_id']); $queue_clean($rj['id']);
check('two replicas cost two images',                   (int) $rj['credits_charged'] === 220 && $bal() === $start - 220);
InfluencerJobService::step($rj['id'], array('inline' => true));
$sent = end(FakeImgProvider::$seen);
check('the source photo leads, her references follow',  count($sent['image_urls']) === 1 + count($refs) && $sent['num_images'] === 2 && $sent['aspect_value'] === '9:16');
$drop($rj['id']);
$r = InfluencerImageActions::replicate($cid, $infl, array('source_asset_id' => $src, 'mode' => 'exact', 'prompt' => $ep, 'face' => array('x' => 0.4, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2)));
check('a detected face is masked before sending',       !empty($r['ok']) && $r['masked'] === true && strpos($r['prompt'], 'covered by a grey mask') !== false, json_encode(array_diff_key($r, array('job' => 1))));
$rj = $jobs->get_by_id($r['job_id']); $queue_clean($rj['id']);
$key = (string) (InfluencerJobsModel::params($rj)['source_key'] ?? '');
check('the masked copy is stored under her vault',      FaceMask::owns_key($cid, $key) && (int) $rj['input_asset_id'] === $src);
$drop($rj['id']); if ($key !== '') { S3Service::delete_key($key); }
check('a missing source is refused',                    empty(InfluencerImageActions::replicate($cid, $infl, array('source_asset_id' => 0, 'prompt' => 'x', 'mask' => false))['ok']));

/* ---- edit ---- */
check('the instruction is wrapped as specified',        InfluencerImageActions::edit_prompt('make the shirt red') === 'Maintain the photo exactly as shown in the reference. Only change the following: make the shirt red');
check('an empty instruction is refused',                empty(InfluencerImageActions::edit_image($cid, $src, '  ')['ok']));
$r = InfluencerImageActions::edit_image($cid, $src, 'make the shirt red');
check('an edit job starts',                             !empty($r['ok']));
$ej = $jobs->get_by_id($r['job_id']); $queue_clean($ej['id']);
InfluencerJobService::step($ej['id'], array('inline' => true));
$sent = end(FakeImgProvider::$seen);
check('an edit keeps the source shape',                 !isset($sent['aspect_value']) && $sent['aspect_ratio'] === 'auto' && count($sent['image_urls']) === 1);
$em = InfluencerImageActions::edit_models(array('width' => 896, 'height' => 1120));
check('for a 4:5 image the shape-keeping model comes first', $em[0]['key'] === 'nano_banana_edit' && $em[0]['keeps_shape'] && $em[1]['key'] === 'grok_edit' && !$em[1]['keeps_shape'] && $em[1]['reshapes_to'] === '3:4', json_encode($em));
$em = InfluencerImageActions::edit_models(array('width' => 768, 'height' => 1024));
check('for a 3:4 image the catalog order stands',       $em[0]['key'] === 'grok_edit' && $em[0]['keeps_shape'] && $em[1]['keeps_shape']);
check('an edit will be recorded as a new version',      InfluencerJobService::provenance_for('edit') === 'edited' && InfluencerJobService::provenance_for('replicate') === 'generated');
$drop($ej['id']);
// version chain on rows of its own: original -> edit -> edit of the edit
$v0 = (int) $media->add($cid, 'image', 'test v0.png', 'image/png', 'failed', 'uploaded');
$v1 = (int) $media->add($cid, 'image', 'test v1.png', 'image/png', 'failed', 'edited');
$v2 = (int) $media->add($cid, 'image', 'test v2.png', 'image/png', 'failed', 'edited');
$media->set_lineage($cid, $v1, array('parent_asset_id' => $v0, 'source_asset_id' => $v0, 'prompt' => InfluencerImageActions::edit_prompt('red shirt')));
$media->set_lineage($cid, $v2, array('parent_asset_id' => $v1, 'source_asset_id' => $v1, 'prompt' => InfluencerImageActions::edit_prompt('add sunglasses')));
$vs = InfluencerImageActions::versions($cid, $v2)['versions'];
check('version history walks back to the original',     array_map(function ($v) { return $v['id']; }, $vs) === array($v0, $v1, $v2), json_encode(array_column($vs, 'id')));
check('each version shows the change that made it',     $vs[1]['change'] === 'red shirt' && $vs[2]['change'] === 'add sunglasses' && $vs[2]['current'] === true && $vs[0]['change'] === '');
check('history is the same from any version',           array_column(InfluencerImageActions::versions($cid, $v0)['versions'], 'id') === array($v0, $v1, $v2));
foreach (array($v0, $v1, $v2) as $v) { $media->sql("DELETE FROM media_assets WHERE id = :id AND creator_id = :c", array(':id' => $v, ':c' => $cid)); }

/* ---- carousel ---- */
check('carousel size is 2 to 10',                       empty(InfluencerImageActions::carousel_start($cid, $infl, array('count' => 1, 'seed_text' => 'x'))['ok']) && empty(InfluencerImageActions::carousel_start($cid, $infl, array('count' => 11, 'seed_text' => 'x'))['ok']));
check('a carousel needs a seed',                        empty(InfluencerImageActions::carousel_start($cid, $infl, array('count' => 3))['ok']));
check('shots without her need a seed image',            empty(InfluencerImageActions::carousel_start($cid, $infl, array('count' => 3, 'focus' => 'without_her', 'seed_text' => 'a kitchen'))['ok']));
check('all five focuses exist',                         array_keys(InfluencerImageActions::CAROUSEL_FOCUS) === array('angles', 'expressions', 'poses', 'details', 'without_her'));
check('nothing was charged by refused requests',        $bal() === $start);
$to = InfluencerImageActions::carousel_to_post($cid, array($src, $base, $src));
check('Use In Post makes a draft in the given order',   !empty($to['ok']) && $to['asset_ids'] === array($src, $base));
if (!empty($to['ok'])) {
    $pa = (new PostsModel())->get_assets($to['post_id']);
    check('the draft holds the images, cover first',    array_map(function ($a) { return (int) $a['asset_id']; }, $pa) === array($src, $base) && (int) $pa[0]['is_cover'] === 1);
    (new PostsModel())->sql("DELETE FROM post_assets WHERE post_id = :p", array(':p' => (int) $to['post_id']));
    (new PostsModel())->sql("DELETE FROM posts WHERE id = :p AND creator_id = :c", array(':p' => (int) $to['post_id'], ':c' => $cid));
}

/* ---- scene templates ---- */
$sm = new SceneTemplatesModel();
$t1 = $sm->add(array('title' => 'Test Cafe', 'category' => 'Test', 'base_prompt' => 'photo of a {subject} at a cafe', 'is_adult' => 0, 'default_aspect' => '4:5', 'is_active' => 1));
$t2 = $sm->add(array('title' => 'Test Adult', 'category' => 'Test', 'base_prompt' => 'photo of a {subject}', 'is_adult' => 1, 'default_aspect' => 'portrait', 'is_active' => 1));
$ids = function ($user) { return array_column(SceneTemplates::for_user($user)['templates'], 'id'); };
check('an SFW account sees only SFW templates',         in_array($t1, $ids(array('adult_content_enabled' => 0)), true) && !in_array($t2, $ids(array('adult_content_enabled' => 0)), true));
check('an adult-enabled account sees both',             in_array($t1, $ids(array('adult_content_enabled' => 1)), true) && in_array($t2, $ids(array('adult_content_enabled' => 1)), true));
check('{subject} follows the influencer',               SceneTemplates::prompt_for($sm->get_one($t1), $infl) === 'photo of a ' . InfluencerService::noun($infl) . ' at a cafe');
check('an older shape name is stored as a ratio',       (string) $sm->get_one($t2)['default_aspect'] === '3:4');
check('an adult template cannot be run by an SFW account', empty(SceneTemplates::run($cid, array('user_id' => $cid, 'adult_content_enabled' => 0), $infl, $t2)['ok']));
$sm->set_active($t1, false);
check('an inactive template is not listed or runnable', !in_array($t1, $ids(array('adult_content_enabled' => 1)), true) && empty(SceneTemplates::run($cid, array('user_id' => $cid), $infl, $t1)['ok']));
check('voting on an image not from a scene is refused', empty(SceneTemplates::vote($cid, $src, 1)['ok']));
foreach (array($t1, $t2) as $t) { $sm->sql("DELETE FROM scene_templates WHERE id = :id", array(':id' => $t)); }

check('balance is back where it started',               $bal() === $start, $bal() . ' vs ' . $start);
check('no test jobs left behind',                       count(array_filter($jobs->list_for_influencer($cid, (int) $infl['id'], 'angle,replicate,edit,carousel', 50), function ($j) { return (string) $j['provider'] === 'fake'; })) === 0);

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
