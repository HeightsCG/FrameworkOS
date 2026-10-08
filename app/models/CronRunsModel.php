<?php
/** Persistence for `cron_runs`: one row per scheduled script, the last start and the last finish (see CronRuns). */
class CronRunsModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** A run began; the previous finish stays until this one ends, so a running job still shows when it last completed. */
    public function started(string $name, string $now): void {
        parent::sql("INSERT INTO cron_runs (name, started_at) VALUES (:n, :t) ON DUPLICATE KEY UPDATE started_at = VALUES(started_at)",
            array(':n' => $name, ':t' => $now));
    }

    public function finished(string $name, string $now, bool $ok, string $note): void {
        parent::sql("INSERT INTO cron_runs (name, started_at, finished_at, ok, note) VALUES (:n, :t, :t2, :ok, :note)
                     ON DUPLICATE KEY UPDATE finished_at = VALUES(finished_at), ok = VALUES(ok), note = VALUES(note)",
            array(':n' => $name, ':t' => $now, ':t2' => $now, ':ok' => $ok ? 1 : 0, ':note' => mb_substr($note, 0, 255)));
    }

    /**
     * The run lock for one script: a MySQL named lock held by this model's connection for the rest of the process, so it
     * works whatever OS user runs the script (a root-owned /tmp lock file locked out manual runs from the shell).
     */
    public function run_lock(string $name): bool {
        $got = parent::select("SELECT GET_LOCK(:k, 0) AS l", array('k' => 'cls_cron_' . $name));
        return !empty($got[0]['l']);
    }

    /** name => row */
    public function all(): array {
        $out = array();
        foreach ((array) parent::select("SELECT name, started_at, finished_at, ok, note FROM cron_runs") as $r) { $out[(string) $r['name']] = $r; }
        return $out;
    }
}
