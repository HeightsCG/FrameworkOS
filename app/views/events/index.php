<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<?php
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$tz  = (string) ($this->timezone ?? 'UTC');
$fmt = function ($utc, $ev_tz) use ($tz) {   // each event in its own time zone
    if ((string) $utc === '' || $utc === null) { return '—'; }
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone(EventsModel::clean_timezone($ev_tz, $tz))); return $d->format('M j, Y · g:i A T'); }
    catch (\Throwable $x) { return '—'; }
};
$events = $this->events;
$tiers  = $this->tiers;
$status_label = array('published' => 'Live', 'draft' => 'Not live', 'canceled' => 'Canceled');
?>
<div class="ev">
    <header class="ev__head">
        <div>
            <h1 class="ev__title">Events</h1>
            <p class="ev__sub">Workshops, livestreams and meetups your fans register for.</p>
        </div>
        <button type="button" class="ev-btn ev-btn--primary" id="evCreate"><i class="fa-solid fa-plus"></i> Create Event</button>
    </header>

    <?php if (empty($events)): ?>
    <div class="ev-empty">
        <span class="ev-empty__ic"><i class="fa-regular fa-calendar"></i></span>
        <h2 class="ev-empty__t">No Events Yet</h2>
        <p class="ev-empty__x">Create your first event and share it on your profile.</p>
    </div>
    <?php else: ?>
    <div class="ev-table">
        <div class="ev-table__head"><span>Event</span><span>When</span><span>Price</span><span>Sold</span><span>Status</span></div>
        <div class="ev-table__body" id="evBody">
            <?php foreach ($events as $ev):
                $in_person = ($ev['format'] ?? 'virtual') === 'in_person';
                $where = $in_person ? trim(explode(',', html_entity_decode((string) $ev['location'], ENT_QUOTES, 'UTF-8'))[0]) : 'Virtual';
                $members = in_array($ev['access_type'], array('subscribers', 'tier'), true);
                $price = ($ev['access_type'] !== 'free' && (int) $ev['price_credits'] > 0) ? Price::credits((int) $ev['price_credits']) : 'Free';
                $st = in_array($ev['status'], array('published', 'draft', 'canceled'), true) ? $ev['status'] : 'draft';
            ?>
            <a class="ev-row" href="/events/manage/<?php echo (int) $ev['id']; ?>">
                <div class="ev-cell ev-cell--title"><span class="ev-cell__name"><?php echo $e($ev['title']); ?></span><span class="ev-cell__meta"><i class="fa-solid <?php echo $in_person ? 'fa-location-dot' : 'fa-video'; ?>" aria-hidden="true"></i> <?php echo $e($where !== '' ? $where : 'In person'); ?></span></div>
                <div class="ev-cell ev-cell--muted"><?php echo $e($fmt($ev['start_at'], $ev['timezone'] ?? '')); ?></div>
                <div class="ev-cell ev-cell--muted"><?php echo $members ? 'Subscribers · ' : ''; ?><?php echo $e($price); ?></div>
                <div class="ev-cell ev-cell--muted"><?php echo (int) $ev['attendees']; ?><?php echo (int) $ev['capacity'] > 0 ? ' / ' . (int) $ev['capacity'] : ''; ?></div>
                <div class="ev-cell"><span class="ev-status ev-status--<?php echo $st === 'published' ? 'on' : ($st === 'canceled' ? 'off' : 'draft'); ?>"><span class="ev-status__dot"></span><?php echo $e($status_label[$st]); ?></span></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php $editing = false; require __DIR__ . '/_modal.php'; ?>

<script src="/js/section-editor.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/section-editor.js'); ?>"></script>
<script src="/js/events.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/events.js'); ?>"></script>
