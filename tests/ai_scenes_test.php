<?php
/**
 * Creator-owned scene templates (Studio, Scenes): a creator adds, edits, turns off and deletes their own
 * scenes beside the read-only platform ones. Dev DB (creator #1 "admin" with a trained influencer; #27 is
 * another test account). Nothing is sent to fal: every run exercised here is refused before a job starts.
 *   APPLICATION_ENV=development php tests/ai_scenes_test.php
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

$cid = 1; $other = 27;
$sm = new SceneTemplatesModel();
$owner = array('user_id' => $cid, 'adult_content_enabled' => 1);
$stranger = array('user_id' => $other, 'adult_content_enabled' => 1);
$ids = function ($user) { return array_column(SceneTemplates::for_user($user)['templates'], 'id'); };
$row = function ($user, $id) { foreach (SceneTemplates::for_user($user)['templates'] as $t) { if ($t['id'] === $id) { return $t; } } return null; };
$made = array();
$clean = function () use ($sm, &$made) { foreach ($made as $id) { $sm->sql("DELETE FROM scene_templates WHERE id = :id", array(':id' => (int) $id)); } $made = array(); };

$infl = null;
foreach ((new InfluencersModel())->list_ready($cid) as $r) { $infl = (new InfluencersModel())->get_one($cid, (int) $r['id']); break; }
check('fixture: creator #1 has a trained influencer', (bool) $infl);
if (!$infl) { echo "1 FAILED\n"; exit(1); }

/* ---- validation ---- */
$r = SceneTemplates::save($cid, 0, array('title' => 'No Subject', 'base_prompt' => 'photo of a woman at a cafe', 'default_aspect' => '4:5'));
check('a prompt without {subject} is refused',          empty($r['ok']) && $r['errors'][0]['input'] === 'base_prompt', json_encode($r));
$r = SceneTemplates::save($cid, 0, array('title' => '', 'base_prompt' => 'photo of a {subject}', 'default_aspect' => '4:5'));
check('a title is required',                            empty($r['ok']) && $r['errors'][0]['input'] === 'title');
$r = SceneTemplates::save($cid, 0, array('title' => 'Bad Shape', 'base_prompt' => 'photo of a {subject}', 'default_aspect' => 'wide'));
check('an unknown shape is refused',                    empty($r['ok']) && $r['errors'][0]['input'] === 'default_aspect');

/* ---- create ---- */
// clean_post_data() html-encodes POST text; the controller decodes it before the save (one round trip here).
$title = html_entity_decode(htmlspecialchars("Dan's Rooftop Cafe", ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
$r = SceneTemplates::save($cid, 0, array('title' => $title, 'category' => 'Test Own', 'base_prompt' => 'photo of a {subject} at a rooftop cafe', 'default_aspect' => '4:5', 'is_adult' => 0));
check('the owner creates a scene',                      !empty($r['ok']) && $r['id'] > 0 && !empty($r['scene']['mine']) && $r['scene']['is_active'] === 1, json_encode($r));
$id = (int) ($r['id'] ?? 0); if ($id > 0) { $made[] = $id; }
check('the title survives the POST round trip',         (string) $sm->get_own($cid, $id)['title'] === "Dan's Rooftop Cafe" && $r['scene']['title'] === "Dan's Rooftop Cafe");
check('the row belongs to the creator',                 (int) $sm->get_own($cid, $id)['creator_id'] === $cid && $sm->get_platform($id) === null);
$list = SceneTemplates::for_user($owner);
check('the owner sees it listed first, with mine',      !empty($list['templates']) && $list['templates'][0]['id'] === $id && $list['templates'][0]['mine'] === true && $list['templates'][0]['is_active'] === 1);
check('the owner gets the prompt back for editing',     $list['templates'][0]['base_prompt'] === 'photo of a {subject} at a rooftop cafe' && in_array('Test Own', $list['categories'], true));
check('the list JSON carries mine and is_active for every row', !array_filter($list['templates'], function ($t) { return !array_key_exists('mine', $t) || !array_key_exists('is_active', $t); }));

/* ---- another creator ---- */
check('another creator does not see it',                !in_array($id, $ids($stranger), true));
check('another creator cannot edit it',                 empty(SceneTemplates::save($other, $id, array('title' => 'Hijack', 'base_prompt' => 'photo of a {subject}', 'default_aspect' => '4:5'))['ok']));
check('another creator cannot turn it off',             empty(SceneTemplates::set_active($other, $id, false)['ok']) && (int) $sm->get_own($cid, $id)['is_active'] === 1);
check('another creator cannot delete it',               empty(SceneTemplates::delete($other, $id)['ok']) && $sm->get_own($cid, $id) !== null);
check('another creator cannot run it',                  empty(($r = SceneTemplates::run($other, $stranger, $infl, $id))['ok']) && $r['error'] === 'That scene is not available.', json_encode($r));
check('the stranger\'s title edit did not land',        (string) $sm->get_own($cid, $id)['title'] === "Dan's Rooftop Cafe");

/* ---- edit ---- */
$r = SceneTemplates::save($cid, $id, array('title' => 'Rooftop Cafe, Golden Hour', 'category' => 'Test Own', 'base_prompt' => 'photo of a {subject} at a rooftop cafe at golden hour', 'default_aspect' => '3:4', 'is_adult' => 0));
check('the owner edits it in place',                    !empty($r['ok']) && $r['id'] === $id && $r['scene']['title'] === 'Rooftop Cafe, Golden Hour' && $r['scene']['default_aspect'] === '3:4' && $r['scene']['is_active'] === 1, json_encode($r));
check('the edit is not a new row',                      count(array_filter($ids($owner), function ($x) use ($id) { return $x === $id; })) === 1);

/* ---- turn off ---- */
$r = SceneTemplates::set_active($cid, $id, false);
check('the owner turns it off',                         !empty($r['ok']) && $r['scene']['is_active'] === 0 && $r['scene']['mine'] === true);
check('an off scene is still listed for the owner',     ($t = $row($owner, $id)) !== null && $t['is_active'] === 0);
check('an off scene is not listed for others',          !in_array($id, $ids($stranger), true));
check('an off scene cannot be run, even by the owner',  empty(($r = SceneTemplates::run($cid, $owner, $infl, $id))['ok']) && $r['error'] === 'That scene is not available.', json_encode($r));
$r = SceneTemplates::set_active($cid, $id, true);
check('the owner turns it back on',                     !empty($r['ok']) && $r['scene']['is_active'] === 1 && $row($owner, $id)['is_active'] === 1);
check('editing keeps the on/off state',                 !empty(SceneTemplates::save($cid, $id, array('title' => 'Rooftop Cafe', 'base_prompt' => 'photo of a {subject}', 'default_aspect' => '3:4'))['ok']) && (int) $sm->get_own($cid, $id)['is_active'] === 1);

/* ---- the platform library beside it ---- */
$pid = (int) $sm->add(array('title' => 'Test Platform', 'category' => 'Test Platform', 'base_prompt' => 'photo of a {subject} on a beach', 'is_adult' => 0, 'default_aspect' => '3:4', 'is_active' => 1));
$made[] = $pid;
check('a platform scene is listed after the creator\'s own, not mine', ($t = $row($owner, $pid)) !== null && $t['mine'] === false && array_search($pid, $ids($owner), true) > array_search($id, $ids($owner), true));
check('a platform scene is in the admin list, the creator\'s is not', in_array($pid, array_column($sm->list_all(), 'id'), true) && !in_array($id, array_column($sm->list_all(), 'id'), true));
check('a creator cannot edit a platform scene',         empty(SceneTemplates::save($cid, $pid, array('title' => 'Mine Now', 'base_prompt' => 'photo of a {subject}', 'default_aspect' => '3:4'))['ok']) && empty(SceneTemplates::delete($cid, $pid)['ok']) && empty(SceneTemplates::set_active($cid, $pid, false)['ok']));
check('the admin scope cannot touch a creator\'s scene', $sm->set_active($id, false) === 0 && $sm->soft_delete($id) === 0 && (int) $sm->get_own($cid, $id)['is_active'] === 1);
check('the platform scene prompt still follows the influencer', SceneTemplates::prompt_for($sm->get_one($pid), $infl) === 'photo of a ' . InfluencerService::noun($infl) . ' on a beach');

/* ---- the connector ---- */
$r = McpTools::call('create_scene_template', $cid, array('title' => 'Connector Scene', 'category' => 'Test Own', 'prompt' => 'photo of a {subject} in a studio', 'aspect' => '1:1'));
check('create_scene_template saves an own scene',       !empty($r['scene']['mine']) && $r['scene']['default_aspect'] === '1:1' && $r['id'] > 0, json_encode($r));
if (!empty($r['id'])) { $made[] = (int) $r['id']; }
$threw = '';
try { McpTools::call('create_scene_template', $cid, array('title' => 'Connector Scene', 'prompt' => 'photo of a woman')); } catch (\Throwable $e) { $threw = $e->getMessage(); }
check('create_scene_template refuses a prompt without {subject}', strpos($threw, '{subject}') !== false, $threw);
$mcp = McpTools::call('list_scene_templates', $cid, array());
check('list_scene_templates includes the creator\'s own first', !empty($mcp['templates']) && $mcp['templates'][0]['mine'] === true);
$defs = array_column(McpTools::definitions(), null, 'name');
check('the connector lists create_scene_template',      isset($defs['create_scene_template']) && in_array('prompt', $defs['create_scene_template']['inputSchema']['required'], true));

/* ---- the page: Scenes lives under Influencers, Generate Images (route + view + script), not in the Studio ---- */
require_once $root . '/libs/Classes/Controller.php';
require_once $root . '/app/controllers/InfluencersController.php';
check('InfluencersController serves /influencers/scenes/<id>', method_exists('InfluencersController', 'scenesAction'));
check('the Scenes view and script exist',                is_file($root . '/app/views/influencers/scenes.php') && is_file($root . '/public/js/influencers-scenes.js'));
check('the image-mode row lists Scenes',                 strpos((string) file_get_contents($root . '/app/views/influencers/_top.php'), "'scenes' => 'Scenes'") !== false);
check('the Studio no longer has a Scenes tab',           !is_file($root . '/app/views/studio/_scenes.php') && strpos((string) file_get_contents($root . '/app/views/studio/index.php'), 'csTabScenes') === false);

/* ---- delete ---- */
$r = SceneTemplates::delete($cid, $id);
check('the owner deletes it',                           !empty($r['ok']) && $r['id'] === $id);
check('it is gone from the list and lookups',           !in_array($id, $ids($owner), true) && $sm->get_own($cid, $id) === null && $sm->get_one($id) === null);
check('deleting it twice is refused',                   empty(SceneTemplates::delete($cid, $id)['ok']));

$clean();
check('test rows are removed',                          !count((array) $sm->select("SELECT id FROM scene_templates WHERE category IN ('Test Own', 'Test Platform')")));

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
