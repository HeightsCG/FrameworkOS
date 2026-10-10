<?php include __DIR__ . '/_shell.php'; ?>
<?php $every = function ($s) { $s = (int) $s; return $s >= 86400 ? 'Daily' : ($s >= 3600 ? 'Every ' . ($s / 3600) . ' h' : 'Every ' . ($s / 60) . ' min'); }; ?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Jobs</h1><p class="adm-head__sub">Scheduled jobs and their last run · overdue means the last finish is older than twice the interval</p></div>
</header>
<section class="adm-box">
    <header class="adm-box__h"><h2 class="adm-box__t">Scheduled jobs <b class="adm-count"><?php echo count((array) $this->cron_jobs); ?></b></h2></header>
    <table class="adm-t" id="admJobs" data-sortable>
        <thead><tr><th data-sort="text">Job</th><th data-sort="text">Status</th><th>Runs</th><th data-sort="text">Last run</th><th data-sort="text">Next run</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ((array) $this->cron_jobs as $j):
            $int = (int) (CronRuns::INTERVALS[$j['name']] ?? 0);
            $next = ($int > 0 && $j['finished_at'] !== '') ? gmdate('Y-m-d H:i:s', strtotime($j['finished_at'] . ' UTC') + $int) : '';
            $status = ($j['finished_at'] !== '' && !$j['ok']) ? 'Failed' : ($j['stale'] ? 'Overdue' : 'OK'); ?>
            <tr>
                <td class="adm-t__main adm-t__nowrap"><?php echo $e(str_replace(array('Seo ', 'Db '), array('SEO ', 'DB '), ucwords(str_replace('_', ' ', $j['name'])))); ?></td>
                <td><?php echo adm_pill($status); ?></td>
                <td class="adm-t__muted adm-t__nowrap"><?php echo $int > 0 ? $e($every($int)) : 'Manual'; ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($j['finished_at']); ?>"><?php echo $j['finished_at'] !== '' ? $e($fmt($j['finished_at'], true)) : 'Never'; ?></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($next); ?>"><?php echo $next !== '' ? ($j['stale'] ? '<span class="adm-neg">' . $e($fmt($next, true)) . '</span>' : $e($fmt($next, true))) : '—'; ?></td>
                <td class="adm-t__muted adm-t__trunc" title="<?php echo $e($j['note']); ?>"><?php echo $e($j['note']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
