<?php
/**
 * Last-run bookkeeping for the scheduled cron scripts, shown in the Jobs box on /admin. Each script calls
 * CronRuns::start('<name>') after its bootstrap and CronRuns::finish('<name>', $ok, $note) on every way out,
 * early exits included. PHP cannot see the exit code at shutdown, so a run that ends without finish() (a fatal
 * error, an uncaught exception, a missed exit path) is recorded as failed by a shutdown fallback that start() registers. Bookkeeping never breaks a job: every DB error is logged and ignored.
 */
class CronRuns {

    /** Expected seconds between runs (the prod crontab in docs/ops.md). A job is stale when its last finish is older than twice this. */
    const INTERVALS = array(
        'scheduler'      => 60,
        'queue_worker'   => 60,
        'moderate'       => 120,
        'billing'        => 900,
        'social_metrics' => 21600,
        'domains'        => 86400,
        'seo_draft'      => 86400,
        'seo_fact_check' => 86400,
        'error_digest'   => 86400,
        'db_backup'      => 86400,
    );

    private static $open = array();

    public static function start(string $name): void {
        self::$open[$name] = true;
        try { (new CronRunsModel())->started($name, gmdate('Y-m-d H:i:s')); }
        catch (\Throwable $e) { error_log('[cron_runs] start ' . $name . ': ' . $e->getMessage()); }
        register_shutdown_function(function () use ($name) {
            if (empty(self::$open[$name])) { return; }
            $err   = error_get_last();
            $fatal = is_array($err) && in_array((int) $err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true);
            self::finish($name, false, $fatal ? 'fatal: ' . (string) $err['message'] : 'ended without finish');
        });
    }

    public static function finish(string $name, bool $ok, string $note = ''): void {
        unset(self::$open[$name]);
        try { (new CronRunsModel())->finished($name, gmdate('Y-m-d H:i:s'), $ok, $note); }
        catch (\Throwable $e) { error_log('[cron_runs] finish ' . $name . ': ' . $e->getMessage()); }
    }

    /** Every scheduled job (known ones first, in INTERVALS order) with its last run and a stale flag, for /admin. */
    public static function status(): array {
        $rows = array();
        try { $rows = (new CronRunsModel())->all(); }
        catch (\Throwable $e) { error_log('[cron_runs] status: ' . $e->getMessage()); }   // /admin still loads before the SQL runs
        $out = array();
        foreach (array_unique(array_merge(array_keys(self::INTERVALS), array_keys($rows))) as $name) {
            $r   = $rows[$name] ?? array();
            $fin = (string) ($r['finished_at'] ?? '');
            $int = (int) (self::INTERVALS[$name] ?? 0);
            $out[] = array(
                'name'        => (string) $name,
                'finished_at' => $fin,
                'ok'          => $fin !== '' && (int) ($r['ok'] ?? 0) === 1,
                'note'        => (string) ($r['note'] ?? ''),
                'stale'       => $int > 0 && ($fin === '' || time() - strtotime($fin . ' UTC') > 2 * $int),
            );
        }
        return $out;
    }
}
