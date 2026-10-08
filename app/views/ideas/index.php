<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<link rel="stylesheet" href="/css/ideas.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/ideas.css'); ?>">
<?php
$e  = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$tz = (string) $this->timezone;
$fmt = function ($utc) use ($tz) {
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format('M j, Y g:i A'); }
    catch (\Throwable $ex) { return ''; }
};
$status = array('done' => array('on', 'Ready'), 'failed' => array('off', 'Failed'), 'queued' => array('draft', 'Running'), 'running' => array('draft', 'Running'));
$runs = $this->runs;
?>
<div class="ev ide">
    <header class="ev__head">
        <div>
            <h1 class="ev__title">Ideas</h1>
            <p class="ev__sub">Post ideas built from the questions people ask about your niche online.</p>
        </div>
    </header>

    <form class="ide-run" id="ideRun" novalidate>
        <label class="visually-hidden" for="ideNiche">Niche</label>
        <input class="form-control ide-run__input" id="ideNiche" name="niche" type="text" maxlength="190" placeholder="Be specific, e.g. vegan meal prep" autocomplete="off">
        <button type="submit" class="ev-btn ev-btn--primary ide-run__btn" id="ideRunBtn"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Find Questions</button>
    </form>

    <section class="ide-result" id="ideResult" aria-live="polite" hidden>
        <div class="ide-result__head">
            <div>
                <h2 class="ide-result__title" id="ideResultTitle"></h2>
                <p class="ide-result__sub" id="ideResultSub"></p>
            </div>
            <button type="button" class="ev-btn" id="ideResultClose"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Close</button>
        </div>
        <div class="ide-result__body" id="ideResultBody"></div>
    </section>

    <h2 class="ide-h">Past Runs</h2>
    <div class="ev-empty" id="ideEmpty"<?php echo empty($runs) ? '' : ' hidden'; ?>>
        <span class="ev-empty__ic"><i class="fa-regular fa-lightbulb"></i></span>
        <h2 class="ev-empty__t">No Runs Yet</h2>
        <p class="ev-empty__x">Enter your niche above to find the questions people ask about it.</p>
    </div>
    <div class="ev-table ide-list" id="ideList"<?php echo empty($runs) ? ' hidden' : ''; ?>>
        <div class="ev-table__head"><span>Niche</span><span>Ideas</span><span>Run</span><span>Status</span><span></span></div>
        <div class="ev-table__body" id="ideRows">
            <?php foreach ($runs as $r): $st = $status[$r['status']] ?? array('draft', 'Running'); ?>
            <div class="ev-row ide-row" role="button" tabindex="0" data-run="<?php echo (int) $r['id']; ?>" data-status="<?php echo $e($r['status']); ?>">
                <div class="ev-cell ev-cell--title"><span class="ev-cell__name"><?php echo $e($r['niche']); ?></span></div>
                <div class="ev-cell ev-cell--muted"><?php echo $r['status'] === 'done' ? (int) $r['ideas'] : 0; ?></div>
                <div class="ev-cell ev-cell--muted"><?php echo $e($fmt($r['created_at'])); ?></div>
                <div class="ev-cell"><span class="ev-status ev-status--<?php echo $st[0]; ?>"><span class="ev-status__dot"></span><?php echo $e($st[1]); ?></span></div>
                <div class="ev-cell ev-cell--act">
                    <div class="dropdown"><button type="button" class="evm-more" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Run actions"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end evm-menu">
                            <li><button type="button" class="dropdown-item" data-ide-view>View</button></li>
                            <li><button type="button" class="dropdown-item" data-ide-draft>Draft Post</button></li>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="ideDraftModal" tabindex="-1" aria-labelledby="ideDraftTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content ide-modal">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="ideDraftTitle">Pick an idea to draft</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="ideDraftBody"></div>
        </div>
    </div>
</div>

<script src="/js/ideas.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/ideas.js'); ?>"></script>
