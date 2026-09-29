<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<?php
$e  = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$d  = function ($s) { return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'); };
$sv    = $this->service;
$stats = $this->stats;
$live  = ($sv['status'] === 'published');
$dollars = function ($credits) { return Price::credits((int) $credits); };   // everything inside is credits
$methods = ServicesModel::method_labels() + array('cls_video' => 'CLS Video');
$public_link = (string) $this->share_link;   // Copy Link: the creator's own domain when they have one
$editor_data = array(
    'id' => (int) $sv['id'], 'name' => $d($sv['name']), 'description' => $d($sv['description']), 'category' => $d($sv['category']),
    'price' => (int) $sv['price_credits'],   // credits 'duration_min' => (int) $sv['duration_min'],
    'capacity' => (int) $sv['capacity'], 'refund_policy' => $d($sv['refund_policy']), 'delivery_method' => (string) $sv['delivery_method'],
    'delivery_details' => $d($sv['delivery_details']), 'status' => $sv['status'],
);
$sold  = (int) $stats['sold'];
$cap   = (int) $sv['capacity'];
$pct   = $cap > 0 ? min(100, (int) round($sold * 100 / $cap)) : 0;
$price = (int) $sv['price_credits'] > 0 ? $dollars($sv['price_credits']) . ' per booking' : 'Free';
$session = trim(((int) $sv['duration_min'] > 0 ? (int) $sv['duration_min'] . ' minutes' : '') . ((int) $sv['duration_min'] > 0 ? ' · ' : '') . ($methods[$sv['delivery_method']] ?? 'Other'));
$refund = trim($d($sv['refund_policy']));
$empty_note = $live ? 'People who book show up here. Share the service link to get started.' : 'Turn on Live to take bookings.';
?>
<div class="evm" data-video="<?php echo (($sv['delivery_method'] ?? '') === 'cls_video' && LiveKit::enabled()) ? 1 : 0; ?>" data-service-id="<?php echo (int) $sv['id']; ?>" data-link="<?php echo $e($public_link); ?>" data-sv='<?php echo $e(json_encode($editor_data)); ?>'>
    <header class="evm-top">
        <a class="evm-top__back" href="/services"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Services</a>
        <div class="evm-top__row">
            <div class="evm-top__heading">
                <div class="evm-top__titlerow">
                    <h1 class="evm-top__title"><?php echo $e($d($sv['name'])); ?></h1>
                    <?php if (!$live): ?><span class="evm-flag">Not live</span><?php endif; ?>
                </div>
                <?php if (trim($d($sv['description'])) !== ''): ?><p class="evm-top__desc"><?php echo $e($d($sv['description'])); ?></p><?php endif; ?>
            </div>
            <div class="evm-top__actions">
                <label class="evm-live" for="svmLive" title="Live services show on your profile and take bookings">
                    <span class="form-check form-switch cs-ae__switch"><input class="form-check-input" type="checkbox" role="switch" id="svmLive"<?php echo $live ? ' checked' : ''; ?>></span>
                    <span class="evm-live__label">Live</span>
                </label>
                <?php if ($live): ?><button type="button" class="ev-btn" data-copy-link><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy Link</button><?php endif; ?>
                <button type="button" class="ev-btn ev-btn--primary" id="svmEdit"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i> Edit Service</button>
                <div class="dropdown">
                    <button type="button" class="ev-btn evm-kebab" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end evm-menu">
                        <?php if ($live): ?><li><a class="dropdown-item" href="<?php echo $e($public_link); ?>" target="_blank" rel="noopener">View public page</a></li><?php endif; ?>
                        <?php if ((int) $stats['rows'] > 0): ?><li><button type="button" class="dropdown-item" data-export>Export buyers (CSV)</button></li><?php endif; ?>
                        <?php if ((int) $stats['rows'] === 0): ?><li><button type="button" class="dropdown-item evm-menu__danger" id="svmDelete">Delete service…</button></li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <dl class="evm-band">
        <div class="evm-band__cell">
            <dt>Price</dt>
            <dd class="evm-band__main"><?php echo $e($price); ?></dd>
            <?php if ((int) $sv['price_credits'] > 0 || (int) $stats['net'] > 0): ?><dd class="evm-band__sub"><b class="evm-band__earned"><?php echo $e($dollars($stats['earned'])); ?></b> earned<?php echo (int) $stats['pending'] > 0 ? ' · ' . $e($dollars($stats['pending'])) . ' pending delivery' : ''; ?><?php echo (int) $stats['refunded_n'] > 0 ? ' · ' . (int) $stats['refunded_n'] . ' refunded' : ''; ?></dd><?php endif; ?>
        </div>
        <div class="evm-band__cell">
            <dt>Session</dt>
            <dd class="evm-band__main"><?php echo $e($session); ?></dd>
            <?php if (trim($d($sv['category'])) !== ''): ?><dd class="evm-band__sub"><?php echo $e($d($sv['category'])); ?></dd><?php endif; ?>
        </div>
        <div class="evm-band__cell">
            <dt>Refund Policy</dt>
            <dd class="evm-band__main"><?php echo $e($refund !== '' ? $refund : 'None set'); ?></dd>
        </div>
        <div class="evm-band__cell">
            <dt>Bookings</dt>
            <dd class="evm-band__main"><?php echo $sold; ?><?php echo $cap > 0 ? ' of ' . $cap . ' spots booked' : ($sold === 1 ? ' booking' : ' bookings'); ?></dd>
            <?php if ($cap > 0): ?>
            <dd class="evm-band__bar"><span class="evm-bar" role="progressbar" aria-label="Spots booked" aria-valuemin="0" aria-valuemax="<?php echo $cap; ?>" aria-valuenow="<?php echo min($sold, $cap); ?>"><span class="evm-bar__fill" style="width:<?php echo $pct; ?>%"></span></span></dd>
            <dd class="evm-band__sub"><?php echo max(0, $cap - $sold); ?> <?php echo max(0, $cap - $sold) === 1 ? 'spot' : 'spots'; ?> available</dd>
            <?php else: ?>
            <dd class="evm-band__sub">No spot limit</dd>
            <?php endif; ?>
        </div>
    </dl>

    <section class="evm-work">
        <div class="evm-work__bar">
            <div class="evm-tabs"><span class="evm-tab" aria-selected="true">Buyers</span></div>
        </div>
        <div class="evm-panel">
            <?php if ($sold === 0): ?>
            <div class="evm-empty">
                <span class="evm-empty__ic"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></span>
                <p class="evm-empty__t">No one has booked yet</p>
                <p class="evm-empty__x"><?php echo $e($empty_note); ?></p>
                <?php if ($live): ?><button type="button" class="btn btn-primary evm-cta" data-copy-link><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy Service Link</button><?php endif; ?>
            </div>
            <?php else: ?>
            <div class="evm-tools">
                <label class="evm-search">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <span class="visually-hidden">Search buyers</span>
                    <input type="search" class="form-control" id="svmSearch" placeholder="Search buyers" autocomplete="off" maxlength="100">
                </label>
            </div>
            <div class="evm-table" id="svmBuyers">
                <table class="evm-tbl">
                    <caption class="visually-hidden">People who booked this service</caption>
                    <thead><tr><th scope="col">Buyer</th><th scope="col" class="evm-col-reg">Booked</th><th scope="col" class="evm-col-paid">Paid</th><th scope="col">Status</th><th scope="col" class="evm-col-act"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody id="svmRows" aria-live="polite"><tr class="evm-tbl__msg"><td colspan="5">Loading buyers…</td></tr></tbody>
                </table>
            </div>
            <footer class="evm-pager">
                <span class="evm-pager__range" id="svmRange"></span>
                <div class="evm-pager__btns">
                    <button type="button" class="ev-btn" id="svmPrev" disabled>Previous</button>
                    <button type="button" class="ev-btn" id="svmNext" disabled>Next</button>
                </div>
            </footer>
            <?php endif; ?>
        </div>
    </section>

    <form method="post" action="/api/service_buyers_csv" id="svmCsv" hidden>
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="service_id" value="<?php echo (int) $sv['id']; ?>">
    </form>
</div>

<?php $editing = true; require __DIR__ . '/_modal.php'; ?>

<script src="/js/section-editor.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/section-editor.js'); ?>"></script>
<script src="/js/services.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/services.js'); ?>"></script>
<script src="/js/service-page.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/service-page.js'); ?>"></script>
