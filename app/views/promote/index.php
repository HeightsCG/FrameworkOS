<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<link rel="stylesheet" href="/css/promote.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/promote.css'); ?>">
<?php
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$d = function ($s) { return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'); };
$tz = (string) $this->timezone;
$when = function ($utc) use ($tz) {
    if ((string) $utc === '') { return ''; }
    try { $x = new DateTime((string) $utc, new DateTimeZone('UTC')); $x->setTimezone(new DateTimeZone($tz)); return $x->format('M j, Y'); }
    catch (\Throwable $ex) { return ''; }
};
$requests = array(); $active = array(); $past = array(); $open_with = array();
foreach ($this->swaps as $s) {
    if ($s['state'] === 'pending') { $requests[] = $s; $open_with[(int) $s['other_id']] = 'pending'; }
    elseif ($s['state'] === 'active') { $active[] = $s; $open_with[(int) $s['other_id']] = 'active'; }
    elseif (count($past) < 30) { $past[] = $s; }
}
$incoming_n = count(array_filter($requests, function ($s) { return !empty($s['incoming']); }));
$person = function ($name, $handle, $avatar) use ($e, $d) {
    $name = trim($d($name)); if ($name === '') { $name = '@' . $handle; }
    $av = trim((string) $avatar) !== '' ? '<img class="evm-person__av" src="' . $e($avatar) . '" alt="">' : '<span class="evm-person__av evm-person__av--init" aria-hidden="true">' . $e(mb_strtoupper(mb_substr(ltrim($name, '@'), 0, 1))) . '</span>';
    return '<div class="evm-person">' . $av . '<span class="evm-person__text"><span class="evm-person__name">' . $e($name) . '</span><span class="evm-person__handle">@' . $e($handle) . '</span></span></div>';
};
$menu = function ($label, $items) use ($e) {
    return '<div class="dropdown"><button type="button" class="evm-more" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="Actions for ' . $e($label) . '"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>'
        . '<ul class="dropdown-menu dropdown-menu-end evm-menu">' . implode('', $items) . '</ul></div>';
};
$base = rtrim(Main::get_base_domain(), '/');
?>
<div class="ev pr">
    <header class="ev__head">
        <div>
            <h1 class="ev__title">Promote</h1>
            <p class="ev__sub">Swap features with creators in your niche. While a swap runs, each of you shows on the other&rsquo;s page under Featured Creators.</p>
        </div>
    </header>

    <?php if (!$this->eligible): ?>
    <div class="ev-empty">
        <span class="ev-empty__ic"><i class="fa-solid fa-arrows-left-right"></i></span>
        <h2 class="ev-empty__t">Cross-Promotion Is Not on Your Plan</h2>
        <p class="ev-empty__x"><?php echo $this->plan_names ? 'Swaps are included on ' . $e(implode(' and ', $this->plan_names)) . '.' : 'Swaps are not available right now.'; ?></p>
        <?php if ($this->plan_names): ?><a class="ev-btn pr-empty__cta" href="/account/billing">See Plans</a><?php endif; ?>
    </div>
    <?php if (!empty($active)): /* swaps made on an earlier plan keep running until they end */ ?><p class="pr-note"><?php echo count($active); ?> running <?php echo count($active) === 1 ? 'swap ends' : 'swaps end'; ?> on <?php echo $e(implode(', ', array_map(function ($s) use ($when) { return $when($s['ends_at']); }, $active))); ?>.</p><?php endif; ?>

    <?php elseif (!$this->opted_in): ?>
    <div class="ev-empty">
        <span class="ev-empty__ic"><i class="fa-solid fa-arrows-left-right"></i></span>
        <h2 class="ev-empty__t">Turn On Cross-Promotion</h2>
        <p class="ev-empty__x">Creators can find you and ask for a swap, and you can ask them.</p>
        <button type="button" class="ev-btn ev-btn--primary pr-empty__cta" id="prOptIn">Turn On Cross-Promotion</button>
    </div>

    <?php else: ?>
    <div class="evm-work pr-work">
        <div class="evm-work__bar">
            <div class="evm-tabs" role="tablist">
                <button type="button" class="evm-tab" role="tab" data-pr-tab="find" aria-selected="<?php echo $incoming_n > 0 ? 'false' : 'true'; ?>">Find Creators</button>
                <button type="button" class="evm-tab" role="tab" data-pr-tab="requests" aria-selected="<?php echo $incoming_n > 0 ? 'true' : 'false'; ?>">Requests <span class="evm-tab__n"><?php echo count($requests); ?></span></button>
                <button type="button" class="evm-tab" role="tab" data-pr-tab="active" aria-selected="false">Active <span class="evm-tab__n"><?php echo count($active); ?></span></button>
                <button type="button" class="evm-tab" role="tab" data-pr-tab="past" aria-selected="false">Past</button>
            </div>
        </div>

        <div class="evm-panel" data-pr-panel="find"<?php echo $incoming_n > 0 ? ' hidden' : ''; ?>>
            <div class="evm-tools">
                <label class="evm-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" class="form-control" id="prSearch" placeholder="Name or @handle" aria-label="Search creators" autocomplete="off"></label>
                <select class="form-select pr-niche" id="prNiche" aria-label="Niche">
                    <option value="">All Niches</option>
                    <?php foreach (DirectoryService::CATEGORIES as $ck => $cl): ?><option value="<?php echo $e($ck); ?>"<?php echo $this->my_niche === $ck ? ' selected' : ''; ?>><?php echo $e($cl); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="evm-table">
                <table class="evm-tbl pr-tbl">
                    <thead><tr><th>Creator</th><th class="pr-col-niche">Niche</th><th class="pr-col-num">Followers</th><th class="pr-col-state"></th><th class="evm-col-act"></th></tr></thead>
                    <tbody id="prFindRows">
                        <?php foreach ($this->creators as $c):
                            $c_name = trim($d($c['display_name'])) !== '' ? trim($d($c['display_name'])) : '@' . $c['u_name'];
                            $c_open = $open_with[(int) $c['user_id']] ?? '';
                            $c_items = array();
                            if ($c_open === '') { $c_items[] = '<li><button type="button" class="dropdown-item" data-pr-request>Request Swap&hellip;</button></li>'; }
                            $c_items[] = '<li><a class="dropdown-item" href="' . $e($base . '/@' . rawurlencode($c['u_name'])) . '" target="_blank" rel="noopener">View Profile</a></li>';
                        ?>
                        <tr class="evm-row pr-row<?php echo $c_open === '' ? ' is-open' : ''; ?>" data-id="<?php echo (int) $c['user_id']; ?>" data-name="<?php echo $e($c_name); ?>" data-handle="<?php echo $e($c['u_name']); ?>" data-niche="<?php echo $e($c['category']); ?>"<?php echo $c_open === '' ? ' tabindex="0"' : ''; ?>>
                            <td><?php echo $person($c['display_name'], $c['u_name'], $c['avatar_url']); ?></td>
                            <td class="pr-col-niche"><?php echo $e(DirectoryService::CATEGORIES[$c['category']] ?? ''); ?></td>
                            <td class="pr-col-num"><?php echo number_format((int) $c['followers']); ?></td>
                            <td class="pr-col-state"><?php if ($c_open !== ''): ?><span class="evm-pill<?php echo $c_open === 'active' ? ' evm-pill--registered' : ''; ?>"><?php echo $c_open === 'active' ? 'Swapping' : 'Requested'; ?></span><?php endif; ?></td>
                            <td class="evm-col-act"><?php echo $menu($c_name, $c_items); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="evm-tbl__msg" id="prFindNone"<?php echo empty($this->creators) ? '' : ' hidden'; ?>><td colspan="5"><?php echo empty($this->creators) ? 'No other creators have Cross-Promotion on yet.' : 'No creators match. <button type="button" class="evm-link" id="prClear">Show All Niches</button>'; ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="evm-panel" data-pr-panel="requests"<?php echo $incoming_n > 0 ? '' : ' hidden'; ?>>
            <?php if (empty($requests)): ?>
            <div class="evm-empty"><span class="evm-empty__ic"><i class="fa-regular fa-envelope"></i></span><p class="evm-empty__t">No Open Requests</p><p class="evm-empty__x">Requests you send and receive show here until they are answered.</p></div>
            <?php else: ?>
            <div class="evm-table">
                <table class="evm-tbl pr-tbl">
                    <thead><tr><th>Creator</th><th class="pr-col-dir">Request</th><th class="pr-col-len">Length</th><th class="pr-col-date">Sent</th><th class="evm-col-act"></th></tr></thead>
                    <tbody>
                        <?php foreach ($requests as $s):
                            $s_name = trim($d($s['other_name'])) !== '' ? trim($d($s['other_name'])) : '@' . $s['other_handle'];
                            $items = !empty($s['incoming'])
                                ? array('<li><button type="button" class="dropdown-item" data-pr-respond="accept">Accept Swap</button></li>', '<li><button type="button" class="dropdown-item evm-menu__danger" data-pr-respond="decline">Decline&hellip;</button></li>')
                                : array('<li><button type="button" class="dropdown-item evm-menu__danger" data-pr-end>Withdraw Request&hellip;</button></li>');
                            $items[] = '<li><a class="dropdown-item" href="' . $e($base . '/@' . rawurlencode($s['other_handle'])) . '" target="_blank" rel="noopener">View Profile</a></li>';
                        ?>
                        <tr class="evm-row" data-swap="<?php echo (int) $s['id']; ?>" data-name="<?php echo $e($s_name); ?>">
                            <td><?php echo $person($s['other_name'], $s['other_handle'], $s['other_avatar']); ?></td>
                            <td class="pr-col-dir"><span class="pr-dir"><?php echo !empty($s['incoming']) ? 'Wants to swap with you' : 'You asked'; ?></span><?php if (trim($d($s['note'])) !== ''): ?><span class="pr-notetext"><?php echo $e($d($s['note'])); ?></span><?php endif; ?></td>
                            <td class="pr-col-len"><?php echo (int) $s['days']; ?> days</td>
                            <td class="pr-col-date"><?php echo $e($when($s['created_at'])); ?></td>
                            <td class="evm-col-act"><?php echo $menu($s_name, $items); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="evm-panel" data-pr-panel="active" hidden>
            <?php if (empty($active)): ?>
            <div class="evm-empty"><span class="evm-empty__ic"><i class="fa-solid fa-arrows-left-right"></i></span><p class="evm-empty__t">No Running Swaps</p><p class="evm-empty__x">Once a request is accepted, both of you are featured until the swap ends.</p></div>
            <?php else: ?>
            <div class="evm-table">
                <table class="evm-tbl pr-tbl">
                    <thead><tr><th>Creator</th><th class="pr-col-date">Ends</th><th class="pr-col-num">Clicks to You</th><th class="pr-col-num">Clicks You Sent</th><th class="evm-col-act"></th></tr></thead>
                    <tbody>
                        <?php foreach ($active as $s):
                            $s_name = trim($d($s['other_name'])) !== '' ? trim($d($s['other_name'])) : '@' . $s['other_handle'];
                            $items = array('<li><a class="dropdown-item" href="' . $e($base . '/@' . rawurlencode($s['other_handle'])) . '" target="_blank" rel="noopener">View Profile</a></li>',
                                           '<li><button type="button" class="dropdown-item evm-menu__danger" data-pr-end>End Swap&hellip;</button></li>');
                        ?>
                        <tr class="evm-row" data-swap="<?php echo (int) $s['id']; ?>" data-name="<?php echo $e($s_name); ?>">
                            <td><?php echo $person($s['other_name'], $s['other_handle'], $s['other_avatar']); ?></td>
                            <td class="pr-col-date"><?php echo $e($when($s['ends_at'])); ?></td>
                            <td class="pr-col-num"><?php echo number_format((int) $s['clicks_in']); ?></td>
                            <td class="pr-col-num"><?php echo number_format((int) $s['clicks_out']); ?></td>
                            <td class="evm-col-act"><?php echo $menu($s_name, $items); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="evm-panel" data-pr-panel="past" hidden>
            <?php if (empty($past)): ?>
            <div class="evm-empty"><span class="evm-empty__ic"><i class="fa-regular fa-clock"></i></span><p class="evm-empty__t">No Past Swaps</p><p class="evm-empty__x">Ended and declined swaps show here with their clicks.</p></div>
            <?php else: ?>
            <div class="evm-table">
                <table class="evm-tbl pr-tbl">
                    <thead><tr><th>Creator</th><th class="pr-col-state">Status</th><th class="pr-col-len">Length</th><th class="pr-col-num">Clicks to You</th><th class="pr-col-num">Clicks You Sent</th></tr></thead>
                    <tbody>
                        <?php foreach ($past as $s): ?>
                        <tr class="evm-row">
                            <td><?php echo $person($s['other_name'], $s['other_handle'], $s['other_avatar']); ?></td>
                            <td class="pr-col-state"><span class="evm-pill"><?php echo $s['state'] === 'declined' ? 'Declined' : 'Ended'; ?></span></td>
                            <td class="pr-col-len"><?php echo (int) $s['days']; ?> days</td>
                            <td class="pr-col-num"><?php echo number_format((int) $s['clicks_in']); ?></td>
                            <td class="pr-col-num"><?php echo number_format((int) $s['clicks_out']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($this->eligible && $this->opted_in): ?>
<div class="modal fade cs-ae-modal pr-modal" id="prModal" tabindex="-1" aria-hidden="true" aria-labelledby="prModalTitle">
    <div class="modal-dialog cs-ae">
        <div class="modal-content cs-ae__surface">
            <header class="cs-ae__header">
                <div class="cs-ae__heading">
                    <span class="cs-ae__eyebrow">Cross-Promotion</span>
                    <div class="cs-ae__titlerow"><h2 class="cs-ae__title" id="prModalTitle">Request Swap</h2></div>
                    <p class="ev-editor__context" id="prModalWho"></p>
                </div>
                <div class="cs-ae__headtools"><button type="button" class="btn-close cs-ae__close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            </header>
            <div class="cs-ae__body">
                <div class="cs-ae__main">
                    <div class="cs-ae__field">
                        <span class="cs-ae__label" id="prDaysLabel">Length</span>
                        <div class="cs-seg cs-ae__seg" role="group" aria-labelledby="prDaysLabel" id="prDays">
                            <?php foreach (PromoSwapsModel::DAYS as $i => $dd): ?><button type="button" class="cs-seg__opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-days="<?php echo (int) $dd; ?>" aria-pressed="<?php echo $i === 0 ? 'true' : 'false'; ?>"><?php echo (int) $dd; ?> Days</button><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="cs-ae__field">
                        <label class="cs-ae__label" for="prNote">Note</label>
                        <textarea class="form-control" id="prNote" rows="4" maxlength="500" placeholder="Optional"></textarea>
                    </div>
                </div>
            </div>
            <footer class="cs-ae__footer">
                <div class="cs-ae__actions">
                    <button type="button" class="btn cs-ae__btn cs-ae__btn--ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary cs-ae__btn" id="prSend">Send Request</button>
                </div>
            </footer>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="/js/promote.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/promote.js'); ?>"></script>
