<?php
/*
 * Admin > Growth > Ads by campaign (AdminModel::ad_funnel): seeds throwaway accounts with an ad campaign of their own,
 * plan charges, a founding claim, setup steps and landing visits, checks every column for 30 and 90 days, then deletes
 * everything it made. php tests/ads_report_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }

$db = new class extends Model {
    public function run($sql, array $p = array()) { return parent::sql($sql, $p); }
    public function add($table, array $d) { return (int) parent::insert($table, $d); }
};
$tag  = (string) time();
$camp = 'zzads_' . $tag;
$now  = gmdate('Y-m-d H:i:s'); $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
$mk = function ($t, $content, array $extra = array()) use ($db, $tag, $camp, $now) {
    return $db->add('user_accounts', $extra + array('u_name' => 'zzads' . $t . $tag, 'user_email' => '', 'first_name' => 'Ads', 'last_name' => 'Test', 'p_word' => 'x',
        'role_id' => 2, 'user_status' => 'Active', 'email_verified' => 0, 'created_at' => $now, 'ad_source' => 'tiktok', 'ad_campaign' => $camp, 'ad_content' => $content));
};
$n = 0;
$charge = function ($uid, $kind, $cents, $status = 'succeeded') use ($db, $tag, &$n) {
    $n++;
    return $db->add('billing_charges', array('user_id' => $uid, 'kind' => $kind, 'amount_cents' => $cents, 'line_items' => '[]', 'effects' => '{}',
        'idempotency_key' => 'adstest-' . $tag . '-' . $n, 'status' => $status, 'applied' => $status === 'succeeded' ? 1 : 0));
};
$steps = function ($uid, $k) use ($db, $now) {
    $s = array(); foreach (array_slice(array('profile', 'brand', 'tiers', 'payouts'), 0, $k) as $x) { $s[$x] = $now; }
    $db->add('creator_setup', array('user_id' => $uid, 'steps_json' => json_encode($s), 'created_at' => $now, 'updated_at' => $now));
};
$visit = function ($ts, $landing = 1) use ($db, $camp) {
    $db->add('analytics_events', array('ts' => $ts, 'kind' => 'pageview', 'path' => '/start', 'utm_source' => 'tiktok', 'utm_campaign' => $camp, 'landing' => '/start', 'is_landing' => $landing));
};
$uids = array();
try {
    $u1 = $uids[] = $mk('1', 'ad_a', array('email_verified' => 1));
    $steps($u1, 3);
    $db->add('founding_claims', array('user_id' => $u1, 'claimed_at' => $now, 'activated_at' => $now, 'status' => 'active', 'created_at' => $now));
    $charge($u1, 'subscribe', 0); $charge($u1, 'renewal', 4900);
    $u2 = $uids[] = $mk('2', 'ad_a', array('email_verified' => 1));
    $charge($u2, 'subscribe', 0); $charge($u2, 'renewal', 4900, 'failed');
    $u3 = $uids[] = $mk('3', 'ad_b');
    $steps($u3, 2);
    $charge($u3, 'subscribe', 4900); $charge($u3, 'slots', 1500);
    $uids[] = $mk('4', 'ad_b', array('is_demo' => 1));                 // demo: left out
    $u5 = $uids[] = $mk('5', 'ad_a', array('created_at' => $old));     // signed up 40 days ago: only in 90 days
    $visit($now); $visit($now); $visit($now); $visit($now, 0); $visit($old);

    $find = function ($days) use ($camp) {
        foreach ((new AdminModel())->ad_funnel($days)['campaigns'] as $c) { if ($c['campaign'] === $camp) { return $c; } }
        return null;
    };
    $c = $find(30);
    check('30d: campaign row with its source', $c !== null && $c['source'] === 'tiktok');
    check('30d: visits = landing views only, in period', $c && $c['visits'] === 3);
    check('30d: signups (demo + older left out)', $c && $c['signups'] === 3);
    check('30d: verified', $c && $c['verified'] === 2);
    check('30d: built (3+ setup steps)', $c && $c['built'] === 1);
    check('30d: trial started (claim or $0 start)', $c && $c['trial'] === 2);
    check('30d: paid (succeeded charge > $0)', $c && $c['paid'] === 2);
    check('30d: revenue = succeeded plan charges', $c && $c['revenue_cents'] === 4900 + 4900 + 1500);
    $ads = array(); foreach ((array) ($c['ads'] ?? array()) as $a) { $ads[$a['content']] = $a; }
    check('30d: drill-down by ad', isset($ads['ad_a'], $ads['ad_b']) && count($ads) === 2);
    check('30d: ad_a numbers', isset($ads['ad_a']) && $ads['ad_a']['signups'] === 2 && $ads['ad_a']['trial'] === 2 && $ads['ad_a']['paid'] === 1 && $ads['ad_a']['revenue_cents'] === 4900 && $ads['ad_a']['built'] === 1);
    check('30d: ad_b numbers', isset($ads['ad_b']) && $ads['ad_b']['signups'] === 1 && $ads['ad_b']['verified'] === 0 && $ads['ad_b']['built'] === 0 && $ads['ad_b']['paid'] === 1 && $ads['ad_b']['revenue_cents'] === 6400);
    $c90 = $find(90);
    check('90d: the older signup and visit count', $c90 && $c90['signups'] === 4 && $c90['visits'] === 4);
    $t = (new AdminModel())->ad_funnel(30)['total'];
    check('totals include the campaign', $t['signups'] >= 3 && $t['revenue_cents'] >= 11300 && $t['visits'] >= 3);
} finally {
    $in = implode(',', array_map('intval', $uids)) ?: '0';
    $db->run("DELETE FROM billing_charges WHERE idempotency_key LIKE :k", array('k' => 'adstest-' . $tag . '-%'));
    $db->run("DELETE FROM founding_claims WHERE user_id IN ($in)");
    $db->run("DELETE FROM creator_setup WHERE user_id IN ($in)");
    $db->run("DELETE FROM analytics_events WHERE utm_campaign = :c", array('c' => $camp));
    $db->run("DELETE FROM user_accounts WHERE user_id IN ($in)");
}
echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
