<?php
// Jobs box (Financials tab): last run of every scheduled cron script, from CronRuns::status(). Stale = last finish
// older than twice the job's interval. Uses $e and $fmt from index.php.
$every = function ($s) { $s = (int) $s; return $s >= 86400 ? 'Daily' : ($s >= 3600 ? 'Every ' . ($s / 3600) . ' h' : 'Every ' . ($s / 60) . ' min'); };
?>
        <div class="adm-sec__head adm-sec__head--gap"><h2 class="adm-sec__title">Jobs</h2><span class="adm-sec__meta">Last run of each scheduled job</span></div>
        <div class="adm-table" style="--adm-cols:minmax(140px,1fr) 110px 170px 100px minmax(160px,1.6fr);">
            <div class="adm-table__head"><span>Job</span><span>Runs</span><span>Last finished</span><span>Status</span><span>Note</span></div>
            <div class="adm-table__body" id="admJobs">
                <?php foreach ((array) $this->cron_jobs as $j): ?>
                <div class="adm-cbrow">
                    <div class="adm-ucell"><?php echo $e(str_replace(array('Seo ', 'Db '), array('SEO ', 'DB '), ucwords(str_replace('_', ' ', $j['name'])))); ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo isset(CronRuns::INTERVALS[$j['name']]) ? $e($every(CronRuns::INTERVALS[$j['name']])) : ''; ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $j['finished_at'] !== '' ? $e($fmt($j['finished_at'], true)) : 'Never'; ?></div>
                    <div class="adm-ucell">
                        <?php if ($j['finished_at'] !== '' && !$j['ok']): ?><span class="adm-status adm-status--off"><span class="adm-status__dot"></span>Failed</span>
                        <?php elseif ($j['stale']): ?><span class="adm-tag adm-tag--flag">Stale</span>
                        <?php else: ?><span class="adm-status adm-status--on"><span class="adm-status__dot"></span>OK</span><?php endif; ?>
                    </div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $j['note'] !== '' ? $e($j['note']) : ''; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
