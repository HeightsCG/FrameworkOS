<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin/generations — AI generation jobs of every creator, newest first. A row opens /admin/generation/<id>. */
$rows = (array) $this->gen_rows; $type = (string) $this->gen_type;
$types = array('image' => 'Images', 'video' => 'Videos', 'training' => 'Training', 'all' => 'All');
$status_tone = array('done' => 'ok', 'failed' => 'bad', 'cancelled' => 'gray');
?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Generations</h1><p class="adm-head__sub">The newest <?php echo count($rows); ?> jobs, with what was typed, what was sent and how it came out</p></div>
    <div class="adm-head__acts">
        <div class="adm-seg" role="tablist" aria-label="Job type">
            <?php foreach ($types as $k => $label): ?><a class="adm-seg__b<?php echo $type === $k ? ' is-on' : ''; ?>" role="tab" aria-selected="<?php echo $type === $k ? 'true' : 'false'; ?>" href="/admin/generations?type=<?php echo $k; ?>"><?php echo $e($label); ?></a><?php endforeach; ?>
        </div>
    </div>
</header>
<section class="adm-box">
    <header class="adm-box__h"><h2 class="adm-box__t">Jobs <b class="adm-count"><?php echo count($rows); ?></b></h2>
        <div class="adm-box__tools">
            <label class="adm-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" placeholder="Search creator, influencer or prompt" autocomplete="off" maxlength="80" aria-label="Search jobs" data-search-for="admGens"></label>
        </div>
    </header>
    <?php if (!$rows): ?>
        <?php echo adm_empty('No jobs of this type yet', 'fa-wand-magic-sparkles'); ?>
    <?php else: ?>
    <table class="adm-t" id="admGens" data-sortable data-pager>
        <thead><tr><th data-sort="num">Job</th><th data-sort="text">When</th><th data-sort="text">Creator</th><th data-sort="text">Influencer</th><th>Prompt</th><th data-sort="text">Model</th><th data-sort="text">Status</th><th data-sort="text">Check</th><th class="adm-r" data-sort="num">Credits</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $j):
            $p = InfluencerJobsModel::params($j); $res = InfluencerJobsModel::result($j);
            $typed = trim((string) ($p['user_prompt'] ?? ''));
            $nm = trim((string) $j['first_name'] . ' ' . (string) $j['last_name']); $nm = $nm !== '' ? $nm : '@' . $j['u_name'];
            $verdict = ImageQualityCheck::verdict($res['quality'] ?? null); ?>
            <tr class="adm-t__link" data-href="/admin/generation/<?php echo (int) $j['id']; ?>">
                <td class="adm-t__num adm-t__muted" data-value="<?php echo (int) $j['id']; ?>"><a href="/admin/generation/<?php echo (int) $j['id']; ?>">#<?php echo (int) $j['id']; ?></a></td>
                <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($j['created_at']); ?>"><?php echo $e($fmt($j['created_at'], true)); ?></td>
                <td class="adm-t__who"><?php echo adm_who($nm, (string) $j['u_name'], '/admin/user/' . (int) $j['creator_id']); ?></td>
                <td><?php echo $e((string) ($j['influencer_name'] ?? '') !== '' ? $j['influencer_name'] : '—'); ?></td>
                <td class="adm-t__muted adm-t__trunc" title="<?php echo $e(mb_substr($typed, 0, 400)); ?>"><?php echo $e($typed !== '' ? mb_substr($typed, 0, 90) : '—'); ?><?php if (!empty($p['scene_fitted'])): ?> <?php echo adm_pill('Fitted', 'gray'); ?><?php endif; ?></td>
                <td class="adm-t__muted adm-t__nowrap"><?php echo $e((string) $j['model_key']); ?></td>
                <td><?php echo adm_pill(ucfirst((string) $j['status']), $status_tone[(string) $j['status']] ?? 'warn'); ?></td>
                <td><?php echo $verdict !== '' ? adm_pill($verdict, $verdict === 'Passed' ? 'ok' : 'bad') : '<span class="adm-t__muted">—</span>'; ?></td>
                <td class="adm-r adm-t__num" data-value="<?php echo (int) $j['credits_charged']; ?>"><?php echo number_format((int) $j['credits_charged']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
