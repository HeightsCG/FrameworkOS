<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin/generation/<id> — one generation job for staff: what the creator typed, what was sent, to which model, with
   which settings, how long it took, and the quality check when one was run. Read-only apart from Run Quality Check. */
$j = (array) $this->job; $mdl = (array) $this->job_model;
$p = InfluencerJobsModel::params($j); $res = InfluencerJobsModel::result($j); $att = InfluencerJobsModel::attempts($j);
$q = is_array($res['quality'] ?? null) ? $res['quality'] : null;
$typed = trim((string) ($p['user_prompt'] ?? ''));
$nm = trim((string) $j['first_name'] . ' ' . (string) $j['last_name']); $nm = $nm !== '' ? $nm : '@' . $j['u_name'];
$fixed = (array) ($mdl['params'] ?? array()); $over = (array) ($p['overrides'] ?? array());
$set = function ($k) use ($fixed, $over) { return array_key_exists($k, $over) ? $over[$k] : ($fixed[$k] ?? null); };
$dash = function ($v) use ($e) { return ($v === null || $v === '') ? '—' : $e((string) $v); };
$secs = ((string) $j['finished_at'] !== '' && (string) $j['created_at'] !== '') ? max(0, strtotime((string) $j['finished_at']) - strtotime((string) $j['created_at'])) : null;
$neg = trim((string) $j['negative_prompt']);
$neg_sent = in_array((string) ($mdl['family'] ?? ''), array('clarity', 'kling_i2v'), true);
$out = (array) (((array) ($res['outputs'] ?? array()))[0] ?? array());
$verdict = ImageQualityCheck::verdict($q);
$yn = function ($m) { return $m === null ? '<span class="adm-t__muted">Not asked</span>' : ($m ? adm_pill('Match', 'ok') : adm_pill('No match', 'bad')); };
$pct = function ($c) { return number_format(100 * (float) $c) . '%'; };
$can_check = (string) $j['status'] === 'done' && !empty($j['result_asset_id']) && in_array((string) $j['type'], array('image', 'replicate', 'edit', 'angle', 'reference', 'carousel'), true);
?>
<a class="adm-back" href="/admin/generations"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Generations</a>
<header class="adm-head">
    <div><h1 class="adm-head__title">Generation #<?php echo (int) $j['id']; ?> <?php echo adm_pill(ucfirst((string) $j['status']), (string) $j['status'] === 'done' ? 'ok' : ((string) $j['status'] === 'failed' ? 'bad' : 'warn')); ?></h1>
        <p class="adm-head__sub"><?php echo $e(ucfirst((string) $j['type'])); ?> for <?php echo $e((string) ($j['influencer_name'] ?? '') !== '' ? $j['influencer_name'] : 'no influencer'); ?>, by <a href="/admin/user/<?php echo (int) $j['creator_id']; ?>"><?php echo $e($nm); ?></a>, <?php echo $e($fmt($j['created_at'], true)); ?></p></div>
    <?php if ($can_check): ?><div class="adm-head__acts"><button type="button" class="adm-btn adm-btn--primary" id="admGenCheck" data-job="<?php echo (int) $j['id']; ?>"><?php echo $q ? 'Run Quality Check Again' : 'Run Quality Check'; ?></button></div><?php endif; ?>
</header>

<div class="adm-cols adm-cols--7-5">
    <section class="adm-box">
        <header class="adm-box__h"><h2 class="adm-box__t">Prompts</h2><?php if (!empty($p['scene_fitted'])): ?><?php echo adm_pill('Fitted Before Sending', 'gray'); ?><?php endif; ?></header>
        <h3 class="adm-h3">Original prompt <span>what the creator typed</span></h3>
        <pre class="adm-pre"><?php echo $typed !== '' ? $e($typed) : 'Not recorded for this job.'; ?></pre>
        <h3 class="adm-h3 adm-h3--gap">Final prompt <span>what the model received</span></h3>
        <pre class="adm-pre"><?php echo (string) $j['prompt'] !== '' ? $e((string) $j['prompt']) : '—'; ?></pre>
        <h3 class="adm-h3 adm-h3--gap">Negative prompt <span><?php echo $neg === '' ? 'none saved' : ($neg_sent ? 'sent' : 'saved, not sent: this model has no input for it'); ?></span></h3>
        <?php if ($neg !== ''): ?><pre class="adm-pre"><?php echo $e($neg); ?></pre><?php endif; ?>
    </section>
    <section class="adm-box">
        <header class="adm-box__h"><h2 class="adm-box__t">Result</h2><?php if (!empty($out['width'])): ?><span class="adm-head__note"><?php echo (int) $out['width']; ?> × <?php echo (int) $out['height']; ?></span><?php endif; ?></header>
        <?php if ((string) $this->job_image !== ''): ?>
            <a class="adm-genimg" href="<?php echo $e($this->job_image); ?>" target="_blank" rel="noopener"><img src="<?php echo $e($this->job_image); ?>" alt="Generated picture for job <?php echo (int) $j['id']; ?>"></a>
        <?php elseif ((string) $j['error'] !== ''): ?>
            <p class="adm-quiet"><?php echo $e((string) $j['error']); ?></p>
        <?php else: ?>
            <?php echo adm_empty('No picture on this job', 'fa-image'); ?>
        <?php endif; ?>
    </section>
</div>

<section class="adm-box">
    <header class="adm-box__h"><h2 class="adm-box__t">Quality Check</h2><?php if ($verdict !== ''): ?><?php echo adm_pill($verdict, $verdict === 'Passed' ? 'ok' : 'bad'); ?><span class="adm-head__note">Checked <?php echo $e($fmt($q['checked_at'] ?? '', true)); ?> in <?php echo $e((string) ($q['seconds'] ?? '')); ?> s. Recorded only: nothing is rejected or made again.</span><?php endif; ?></header>
    <div id="admGenQuality">
    <?php if (!$q): ?>
        <?php echo adm_empty($can_check ? 'Not checked yet' : 'A check needs a finished picture', 'fa-magnifying-glass'); ?>
    <?php else: ?>
        <table class="adm-t">
            <thead><tr><th>Item</th><th>Asked</th><th>Seen</th><th>Result</th><th class="adm-r">Confidence</th><th>Notes</th></tr></thead>
            <tbody>
                <tr><td class="adm-t__main">Anatomy</td><td class="adm-t__muted">—</td><td class="adm-t__muted"><?php echo (int) ($q['people'] ?? 1); ?> <?php echo (int) ($q['people'] ?? 1) === 1 ? 'person' : 'people'; ?></td><td><?php echo !empty($q['anatomy']['ok']) ? adm_pill('OK', 'ok') : adm_pill('Problem', 'bad'); ?></td><td class="adm-r adm-t__num"><?php echo $pct($q['anatomy']['confidence']); ?></td><td class="adm-t__muted adm-t__wrap"><?php echo $e(implode('; ', (array) $q['anatomy']['issues'])); ?></td></tr>
                <tr><td class="adm-t__main">Camera angle</td><td><?php echo $dash($q['pose']['asked']); ?></td><td><?php echo $dash($q['pose']['seen']); ?></td><td><?php echo $yn($q['pose']['match']); ?></td><td class="adm-r adm-t__num"><?php echo $pct($q['pose']['confidence']); ?></td><td></td></tr>
                <tr><td class="adm-t__main">Gaze</td><td><?php echo $dash($q['gaze']['asked']); ?></td><td><?php echo $dash($q['gaze']['seen']); ?></td><td><?php echo $yn($q['gaze']['match']); ?></td><td class="adm-r adm-t__num"><?php echo $pct($q['gaze']['confidence']); ?></td><td></td></tr>
                <tr><td class="adm-t__main">Composition</td><td class="adm-t__muted">—</td><td class="adm-t__muted">—</td><td><?php echo !empty($q['composition']['ok']) ? adm_pill('OK', 'ok') : adm_pill('Problem', 'bad'); ?></td><td class="adm-r adm-t__num"><?php echo $pct($q['composition']['confidence']); ?></td><td class="adm-t__muted adm-t__wrap"><?php echo $e(implode('; ', (array) $q['composition']['issues'])); ?></td></tr>
            </tbody>
        </table>
        <?php if ((string) ($q['summary'] ?? '') !== ''): ?><p class="adm-quiet"><?php echo $e($q['summary']); ?></p><?php endif; ?>
    <?php endif; ?>
    </div>
</section>

<div class="adm-cols adm-cols--2">
    <section class="adm-box">
        <header class="adm-box__h"><h2 class="adm-box__t">Provider And Model</h2></header>
        <dl class="adm-facts adm-facts--acts">
            <div><dt>Provider</dt><dd><?php echo $dash($j['provider']); ?></dd></div>
            <div><dt>Endpoint</dt><dd><?php echo $dash($j['endpoint']); ?></dd></div>
            <div><dt>Model</dt><dd><?php echo $dash($j['model_key']); ?><?php if (!empty($mdl['label'])): ?> <span class="adm-t__muted">(<?php echo $e($mdl['label']); ?>)</span><?php endif; ?></dd></div>
            <div><dt>Trained Model</dt><dd><?php echo !empty($j['model_id']) ? '#' . (int) $j['model_id'] . ' <span class="adm-t__muted">trained ' . $e($fmt($j['trained_at'] ?? '')) . '</span>' : '—'; ?></dd></div>
            <div><dt>Trigger Word</dt><dd><?php echo $dash($j['trigger_word'] ?? ''); ?></dd></div>
            <div><dt>Identity Strength</dt><dd><?php echo isset($p['lora_scale']) ? $e((string) $p['lora_scale']) : '—'; ?></dd></div>
            <div><dt>Source Image</dt><dd><?php echo !empty($j['input_asset_id']) ? 'Asset #' . (int) $j['input_asset_id'] : 'None'; ?></dd></div>
            <div><dt>Provider Request</dt><dd><?php echo $dash($j['provider_job_id']); ?></dd></div>
        </dl>
    </section>
    <section class="adm-box">
        <header class="adm-box__h"><h2 class="adm-box__t">Settings</h2></header>
        <dl class="adm-facts adm-facts--acts">
            <div><dt>Steps</dt><dd><?php echo $dash($set('num_inference_steps')); ?></dd></div>
            <div><dt>Guidance</dt><dd><?php echo $dash($set('guidance_scale')); ?></dd></div>
            <div><dt>Seed</dt><dd><?php echo $dash($j['seed']); ?></dd></div>
            <div><dt>Shape</dt><dd><?php echo $dash($p['image_size'] ?? ($p['aspect'] ?? '')); ?></dd></div>
            <div><dt>Pictures</dt><dd><?php echo $dash($p['num_images'] ?? 1); ?></dd></div>
            <div><dt>Style</dt><dd><?php echo $dash(InfluencerService::IMAGE_STYLES[(string) ($p['style'] ?? '')]['label'] ?? ($p['style'] ?? '')); ?></dd></div>
            <div><dt>Started From</dt><dd><?php echo $dash(ucfirst((string) $j['origin'])); ?><?php if (!empty($p['template_id'])): ?> <span class="adm-t__muted">scene #<?php echo (int) $p['template_id']; ?></span><?php endif; ?></dd></div>
            <div><dt>Other Fixed Settings</dt><dd><?php $rest = array_diff_key($fixed, array('num_inference_steps' => 1, 'guidance_scale' => 1)); echo $rest ? $e(json_encode($rest)) : '—'; ?></dd></div>
        </dl>
    </section>
</div>

<section class="adm-box">
    <header class="adm-box__h"><h2 class="adm-box__t">Timing And Cost</h2></header>
    <dl class="adm-facts">
        <div><dt>Created</dt><dd><?php echo $e($fmt($j['created_at'], true)); ?></dd></div>
        <div><dt>Sent To Provider</dt><dd><?php echo $e($fmt($j['submitted_at'], true)); ?></dd></div>
        <div><dt>Finished</dt><dd><?php echo $e($fmt($j['finished_at'], true)); ?></dd></div>
        <div><dt>Duration</dt><dd><?php echo $secs !== null ? $secs . ' s' : '—'; ?></dd></div>
        <div><dt>Credits Charged</dt><dd><?php echo number_format((int) $j['credits_charged']); ?></dd></div>
        <div><dt>Provider Cost</dt><dd><?php echo (string) $j['cost_usd'] !== '' ? '$' . $e(number_format((float) $j['cost_usd'], 4)) : '—'; ?></dd></div>
    </dl>
    <?php if ($att): ?>
    <h3 class="adm-h3 adm-h3--gap">Attempts <span><?php echo count($att); ?></span></h3>
    <table class="adm-t">
        <thead><tr><th>Started</th><th>Provider</th><th>Endpoint</th><th>Outcome</th><th class="adm-r">HTTP</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($att as $a): $a = (array) $a; ?>
            <tr><td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt(str_replace(array('T', '+00:00'), array(' ', ''), (string) ($a['started_at'] ?? '')), true)); ?></td><td><?php echo $dash($a['provider'] ?? ''); ?></td><td class="adm-t__muted"><?php echo $dash($a['endpoint'] ?? ''); ?></td><td><?php echo adm_pill(ucfirst((string) ($a['outcome'] ?? '')), (string) ($a['outcome'] ?? '') === 'accepted' ? 'ok' : 'bad'); ?></td><td class="adm-r adm-t__num"><?php echo $dash($a['http_code'] ?? ''); ?></td><td class="adm-t__muted adm-t__wrap"><?php echo $e((string) ($a['error'] ?? ($a['message'] ?? ''))); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php if ((string) $j['error'] !== ''): ?><p class="adm-quiet"><?php echo adm_pill('Error', 'bad'); ?> <?php echo $e((string) $j['error']); ?><?php echo (string) $j['error_code'] !== '' ? ' (' . $e((string) $j['error_code']) . ')' : ''; ?></p><?php endif; ?>
</section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
