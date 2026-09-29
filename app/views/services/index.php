<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<?php
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$d = function ($s) { return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'); };
$method_label = ServicesModel::method_labels() + array('cls_video' => 'CLS Video');
$services = $this->services;
?>
<div class="ev">
    <header class="ev__head">
        <div>
            <h1 class="ev__title">Services</h1>
            <p class="ev__sub">Coaching, consults, reviews and custom work your fans book and pay for.</p>
        </div>
        <button type="button" class="ev-btn ev-btn--primary" id="svCreate"><i class="fa-solid fa-plus"></i> Create Service</button>
    </header>

    <?php if (empty($services)): ?>
    <div class="ev-empty">
        <span class="ev-empty__ic"><i class="fa-regular fa-handshake"></i></span>
        <h2 class="ev-empty__t">No Services Yet</h2>
        <p class="ev-empty__x">Create your first service and sell it from your profile.</p>
    </div>
    <?php else: ?>
    <div class="ev-table sv-list">
        <div class="ev-table__head"><span>Service</span><span>Price</span><span>Duration</span><span>Sold</span><span>Status</span></div>
        <div class="ev-table__body">
            <?php foreach ($services as $s):
                $live = ($s['status'] === 'published');
                $meta = trim($d($s['category'])) !== '' ? $d($s['category']) . ' · ' : '';
            ?>
            <a class="ev-row" href="/services/manage/<?php echo (int) $s['id']; ?>">
                <div class="ev-cell ev-cell--title"><span class="ev-cell__name"><?php echo $e($d($s['name'])); ?></span><span class="ev-cell__meta"><i class="fa-solid <?php echo $s['delivery_method'] === 'in_person' ? 'fa-location-dot' : ($s['delivery_method'] === 'phone' ? 'fa-phone' : 'fa-video'); ?>" aria-hidden="true"></i> <?php echo $e($meta . ($method_label[$s['delivery_method']] ?? 'Other')); ?></span></div>
                <div class="ev-cell ev-cell--muted"><?php echo (int) $s['price_credits'] > 0 ? Price::credits((int) $s['price_credits']) : 'Free'; ?></div>
                <div class="ev-cell ev-cell--muted"><?php echo (int) $s['duration_min'] > 0 ? (int) $s['duration_min'] . ' min' : '—'; ?></div>
                <div class="ev-cell ev-cell--muted"><?php echo (int) $s['purchases']; ?><?php echo (int) $s['capacity'] > 0 ? ' / ' . (int) $s['capacity'] : ''; ?></div>
                <div class="ev-cell"><span class="ev-status ev-status--<?php echo $live ? 'on' : 'draft'; ?>"><span class="ev-status__dot"></span><?php echo $live ? 'Live' : 'Not live'; ?></span></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php $editing = false; require __DIR__ . '/_modal.php'; ?>

<script src="/js/section-editor.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/section-editor.js'); ?>"></script>
<script src="/js/services.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/services.js'); ?>"></script>
