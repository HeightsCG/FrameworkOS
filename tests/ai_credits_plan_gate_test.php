<?php
/** One-time AI credit purchases are for Creator and Studio only (2026-10-13): the endpoint turns a Free creator away and the Billing page shows Free no purchase controls. */
$root = dirname(__DIR__);
putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/controllers/$c.php", "$root/app/controllers/api/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });
$fail = 0;
function check($label, $cond) { global $fail; echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$cond) { $fail++; } }
$api = (string) file_get_contents("$root/app/controllers/api/ApiBillingController.php");
check('buy_ai_credits requires a paid plan', (bool) preg_match('/function buy_ai_creditsAction\(\)\{\s*\$user\s*=\s*\$this->require_creator\(\'owner\', true, \'buy AI credits\'\)/', $api));
check('confirm_ai_credit_purchase keeps the paid-plan gate', (bool) preg_match('/function confirm_ai_credit_purchaseAction\(\)\{\s*\$user\s*=\s*\$this->require_creator\(\'owner\'\)/', $api));
check('the pack quote turns Free away', strpos($api, "'Choose a plan to buy AI credits.'") !== false);
$m = new UsersModel(); $rid = $m->get_role_id_by_name('Creator');
$row = $m->select('SELECT * FROM user_accounts WHERE role_id = :r ORDER BY user_id ASC LIMIT 1', array('r' => $rid)); $row = $row[0] ?? array('role_id' => $rid);
$free = $row; $free['subscription_status'] = '';
check('a creator with no paid plan cannot sell, so the gate rejects', Plan::can_sell($free) === false && Plan::can_use_ai($free) === false);
$paid = $row; $paid['subscription_status'] = 'active'; $paid['subscription_cancel_at_period_end'] = 0;
check('a creator on a paid plan passes', Plan::can_sell($paid) === true);
$view = (string) file_get_contents("$root/app/views/account/billing.php");
check('Billing shows the purchase controls and the modal only with a plan', substr_count($view, '<?php if ($has_plan): ?>') >= 2 && (bool) preg_match('/<\?php if \(\$has_plan\): \?>\s*<div class="modal fade" id="credits_modal"/', $view) && (bool) preg_match('/Buy AI Credits<\/h2>\s*<\?php if \(\$has_plan\): \?>/', $view));
$terms = (string) file_get_contents("$root/app/views/pages/legal-terms.php");
check('/terms says Creator and Studio, not any plan', strpos($terms, 'on the Creator and Studio plans only') !== false && stripos($terms, 'on any plan') === false);
echo $fail ? "FAILED $fail\n" : "ALL OK\n"; exit($fail ? 1 : 0);
