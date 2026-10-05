<?php
/**
 * AI generation jobs: credit charge, refund and failure handling, against the dev DB (creator #1 "admin")
 * with a fake provider, so nothing is sent to fal and nothing is spent there.
 *   APPLICATION_ENV=development php tests/ai_jobs_test.php
 *
 * Jobs are created undispatched and stepped here, so the queue worker never sees them.
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

/** Stands in for fal: accepts or rejects on demand, never touches the network. */
class FakeAiProvider implements InfluencerProvider {
    public static $submit = 'accept';   // accept | reject | reject_retryable
    public static $status = 'running';  // running | completed | error
    public static $seen   = array();
    public static function key(): string { return 'fake'; }
    public static function capabilities(): array { return array('ops' => array('edit' => true, 'replicate' => true, 'angle' => true, 'image' => true, 'video' => true)); }
    private static function submit(array $req): array {
        self::$seen[] = $req;
        if (self::$submit === 'accept') {
            $id = 'fake_' . bin2hex(random_bytes(6));
            return array('ok' => true, 'error' => '', 'error_code' => '', 'retryable' => false, 'http_code' => 200,
                'handle' => array('provider' => 'fake', 'provider_job_id' => $id, 'status_url' => 'fake://status/' . $id, 'response_url' => 'fake://result/' . $id, 'cancel_url' => ''));
        }
        return array('ok' => false, 'error' => 'fake provider said no', 'error_code' => (self::$submit === 'reject') ? 'content_policy' : 'provider',
            'retryable' => (self::$submit !== 'reject'), 'http_code' => 422, 'handle' => null, 'outputs' => array());
    }
    public static function generate_image(array $req): array { return self::submit($req); }
    public static function generate_video(array $req): array { return self::submit($req); }
    public static function train_model(array $req): array { return self::submit($req); }
    public static function get_job_status(array $handle): array {
        if (self::$status === 'error') { return array('ok' => false, 'state' => 'failed', 'error' => 'fake render crashed', 'error_code' => 'provider', 'retryable' => false, 'raw' => null); }
        return array('ok' => true, 'state' => self::$status, 'error' => '', 'error_code' => '', 'retryable' => false, 'raw' => array());
    }
    public static function fetch_result(array $handle): array { return array('ok' => false, 'error' => 'no output', 'error_code' => 'provider', 'retryable' => false, 'outputs' => array()); }
    public static function cancel(array $handle): bool { return true; }
    public static function output_url_allowed($url): bool { return false; }
}

$creator = 1;
InfluencerConfig::register_provider('fake', 'FakeAiProvider');
InfluencerConfig::set_override('providers_edit', 'fake');
InfluencerConfig::set_override('endpoint_grok_edit_fake', 'fake/edit');
InfluencerConfig::set_override('endpoint_nano_banana_edit_fake', 'fake/edit');

$credits = new AiCreditsModel();
$jobs    = new InfluencerJobsModel();
$media   = new MediaAssetsModel();
$bal     = function () use ($credits, $creator) { return (int) $credits->get_balance($creator); };

// A ready, unblocked image of the creator's to edit.
$src = 0;
foreach ((array) $media->get_for_creator($creator, array('type' => 'image')) as $a) {
    if ((string) $a['status'] === 'ready' && (string) $a['moderation_status'] !== 'blocked') { $src = (int) $a['id']; break; }
}
check('fixture: creator #1 has a ready image', $src > 0);
if ($src <= 0) { echo "1 FAILED\n"; exit(1); }

$start = $bal();
$price = Plan::ai_price('edit', array('model_key' => 'grok_edit', 'params' => array('num_images' => 1)));
check('an edit has a price in AI credits', $price >= InfluencerConfig::CREDIT_FLOOR, 'price ' . $price);
check('fixture: creator #1 can afford the test runs', $start >= $price * 2, 'balance ' . $start);

$make = function (array $extra = array()) use ($creator, $src) {
    return InfluencerJobService::create_job($creator, 0, 'edit', array_merge(array(
        'model_key' => 'grok_edit', 'prompt' => 'Maintain the photo exactly as shown in the reference. Only change the following: test',
        'input_asset_id' => $src, 'params' => array('num_images' => 1, 'instruction' => 'test'),
    ), $extra), false);
};
$made = array();

/* ---- charge on create; the credits stay spent while the run is in flight ---- */
FakeAiProvider::$submit = 'accept'; FakeAiProvider::$status = 'running';
$id = $make(); $made[] = $id;
$job = $jobs->get_by_id($id);
check('job created without an influencer',        $id > 0 && $job && $job['influencer_id'] === null);
check('credits are taken when the job is created', $bal() === $start - $price, $bal() . ' vs ' . ($start - $price));
check('the job records what it charged',           (int) $job['credits_charged'] === $price);
$st = InfluencerJobService::step($id, array('inline' => true));
check('submit moves the job to running',           $st['status'] === 'running' && (string) $jobs->get_by_id($id)['provider'] === 'fake');
check('the source image is sent first',            !empty(FakeAiProvider::$seen) && !empty(end(FakeAiProvider::$seen)['image_urls'][0]));
check('the model family reaches the provider',     (string) (end(FakeAiProvider::$seen)['family'] ?? '') === 'grok');
InfluencerJobService::step($id, array('inline' => true));
check('still charged while running',               $bal() === $start - $price);

/* ---- a render that crashes at the provider is failed and refunded once ---- */
FakeAiProvider::$status = 'error';
$st = InfluencerJobService::step($id, array('inline' => true));
$job = $jobs->get_by_id($id);
check('provider failure fails the job',            $st['terminal'] && (string) $job['status'] === 'failed' && (string) $job['error'] !== '');
check('a failed run is refunded in full',          $bal() === $start, $bal() . ' vs ' . $start);
InfluencerJobService::step($id, array('inline' => true));
check('stepping a failed job does not refund twice', $bal() === $start);

/* ---- a retry pays again; a second failure refunds again ---- */
FakeAiProvider::$submit = 'accept'; FakeAiProvider::$status = 'running';
$r = InfluencerJobService::retry($creator, $id);
check('retry of a failed job is accepted',         !empty($r['ok']));
check('retry charges again',                       $bal() === $start - $price);
(new JobsModel())->sql("DELETE FROM jobs WHERE dedupe_key LIKE :k", array(':k' => 'infl_job:' . (int) $id . ':%'));   // retry dispatches; keep the worker away from the fake provider
FakeAiProvider::$submit = 'reject';
$st = InfluencerJobService::step($id, array('inline' => true));
check('a rejected submit fails the job',           (string) $jobs->get_by_id($id)['status'] === 'failed' && (string) $jobs->get_by_id($id)['error_code'] === 'content_policy');
check('and refunds',                               $bal() === $start);

/* ---- timeout ---- */
FakeAiProvider::$submit = 'accept'; FakeAiProvider::$status = 'running';
$id2 = $make(); $made[] = $id2;
InfluencerJobService::step($id2, array('inline' => true));
$jobs->transition($id2, 'running', array('deadline_at' => date('Y-m-d H:i:s', time() - 5)));
InfluencerJobService::step($id2, array('inline' => true));
check('a run past its deadline fails as a timeout', (string) $jobs->get_by_id($id2)['error_code'] === 'timeout');
check('a timed out run is refunded',               $bal() === $start);

/* ---- cancel ---- */
$id3 = $make(); $made[] = $id3;
check('charged before cancel',                     $bal() === $start - $price);
$c = InfluencerJobService::cancel_job($creator, $id3);
check('a queued job can be cancelled',             !empty($c['ok']) && (string) $jobs->get_by_id($id3)['status'] === 'cancelled');
check('a cancelled run is refunded',               $bal() === $start);
$c = InfluencerJobService::cancel_job($creator, $id3);
check('cancelling twice does not refund twice',    empty($c['ok']) && $bal() === $start);

/* ---- a blocked source never reaches the provider ---- */
$seen_before = count(FakeAiProvider::$seen);
$row = $media->get_one($creator, $src);
$media->set_moderation($src, 'blocked', $row['moderation_score'], array(), null);
$id4 = $make(); $made[] = $id4;
InfluencerJobService::step($id4, array('inline' => true));
$media->set_moderation($src, (string) $row['moderation_status'], $row['moderation_score'],
    array_filter(explode(',', (string) $row['moderation_labels']), 'strlen'), null);
check('a blocked source fails the job before submit', (string) $jobs->get_by_id($id4)['status'] === 'failed' && count(FakeAiProvider::$seen) === $seen_before);
check('and refunds',                               $bal() === $start);

/* ---- an unknown job type is refused, not run as an image ---- */
$bad = InfluencerJobService::create_job($creator, 0, 'not_a_type', array('model_key' => 'grok_edit', 'prompt' => 'x'), false);
check('an unknown job type is refused',            $bad === 0);
check('and costs nothing',                         $bal() === $start);

/* ---- not enough credits: nothing is created ---- */
$threw = null;
$poor  = 27;   // fan "test": no creator plan
try { InfluencerJobService::create_job($poor, 0, 'edit', array('model_key' => 'grok_edit', 'prompt' => 'x', 'input_asset_id' => $src), false); }
catch (PlanLimitException $e) { $threw = $e; }
check('an account without a plan is refused with need_plan', $threw !== null && !empty($threw->limit['need_plan']));

/* ---- tidy up: the test jobs and their ledger rows ---- */
foreach ($made as $j) {
    $jobs->sql("DELETE FROM influencer_jobs WHERE id = :id AND creator_id = :c", array(':id' => (int) $j, ':c' => $creator));
}
check('balance is back where it started',          $bal() === $start, $bal() . ' vs ' . $start);

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
