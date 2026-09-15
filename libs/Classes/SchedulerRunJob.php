<?php
/** Job handler: run one automation rule now. Dispatched by ApiCreatorStudioController::scheduler_run_nowAction. */
class SchedulerRunJob {

    public static function handle(array $payload): string {
        $rule_id    = (int) ($payload['rule_id'] ?? 0);
        $creator_id = (int) ($payload['creator_id'] ?? 0);
        $rulesM = new SchedulerRulesModel();
        $runsM  = new SchedulerRunsModel();
        $rule   = $rulesM->get_one($creator_id, $rule_id);
        if (!$rule) { throw new RuntimeException('automation ' . $rule_id . ' not found for creator ' . $creator_id); }
        $rows = (new UsersModel())->get_user_by_id($creator_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$user) { throw new RuntimeException('creator ' . $creator_id . ' not found'); }

        try {
            $res = (($rule['kind'] ?? 'post') === 'message') ? MessageBlastService::run_rule($rule, $user) : AutoPostService::run_rule($rule, $user);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'post_id' => null, 'message' => 'Worker error: ' . $e->getMessage()];
        }
        $runsM->add($rule_id, $creator_id, $res['ok'] ? 'success' : 'failed', $res['post_id'], $res['message']);
        $rulesM->set_last_run($rule_id, $res['ok'] ? 'success' : 'failed');
        return ($res['ok'] ? 'OK' : 'FAIL') . ' — ' . $res['message'];
    }
}
