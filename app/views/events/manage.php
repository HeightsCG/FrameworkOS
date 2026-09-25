<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<?php
$e  = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$tz = (string) ($this->timezone ?? 'UTC');
$local = function ($utc, $format) use ($tz) {
    if ((string) $utc === '' || $utc === null) { return ''; }
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format($format); }
    catch (\Throwable $x) { return ''; }
};
$ev        = $this->event;
$stats     = $this->stats;
$attendees = $this->attendees;
$messages  = $this->messages;
$tiers     = $this->tiers;
$tab       = $this->tab;
$canceled  = ($ev['status'] === 'canceled');
$in_person = (($ev['format'] ?? 'virtual') === 'in_person');
$where     = $in_person ? html_entity_decode((string) $ev['location'], ENT_QUOTES, 'UTF-8') : 'Virtual';
$dollars   = function ($credits) { return '$' . number_format(((int) $credits) / 10, 2); };
$status_label = array('published' => 'Published', 'draft' => 'Draft', 'canceled' => 'Canceled');
$reg_label    = array('registered' => 'Going', 'canceled' => 'Canceled', 'refunded' => 'Refunded', 'removed' => 'Removed');
$public_link  = Main::get_base_domain() . '/@' . rawurlencode((string) $this->handle) . '#events';
$editor_data  = array(
    'id' => (int) $ev['id'], 'title' => html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8'),
    'description' => html_entity_decode((string) $ev['description'], ENT_QUOTES, 'UTF-8'),
    'start_at' => $local($ev['start_at'], 'Y-m-d\TH:i'), 'end_at' => $local($ev['end_at'], 'Y-m-d\TH:i'),
    'format' => $ev['format'] ?? 'virtual', 'external_url' => (string) $ev['external_url'],
    'location' => html_entity_decode((string) $ev['location'], ENT_QUOTES, 'UTF-8'),
    'access_instructions' => html_entity_decode((string) $ev['access_instructions'], ENT_QUOTES, 'UTF-8'),
    'access_type' => $ev['access_type'], 'tier_id' => (int) ($ev['tier_id'] ?? 0),
    'price' => number_format(((int) $ev['price_credits']) / 10, 2, '.', ''), 'capacity' => (int) $ev['capacity'], 'status' => $ev['status'],
);
$going = (int) $stats['going'];
?>
<div class="evm" data-event-id="<?php echo (int) $ev['id']; ?>">
    <a class="evm__back" href="/events"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Events</a>

    <header class="evm__head">
        <div class="evm__heading">
            <div class="evm__titlerow">
                <h1 class="evm__title"><?php echo $e($ev['title']); ?></h1>
                <span class="ev-status ev-status--<?php echo $ev['status'] === 'published' ? 'on' : ($canceled ? 'off' : 'draft'); ?>"><span class="ev-status__dot"></span><?php echo $e($status_label[$ev['status']] ?? 'Draft'); ?></span>
            </div>
            <p class="evm__meta">
                <span><i class="fa-regular fa-calendar" aria-hidden="true"></i> <?php echo $e($local($ev['start_at'], 'D, M j, Y · g:i A T')); ?></span>
                <span><i class="fa-solid <?php echo $in_person ? 'fa-location-dot' : 'fa-video'; ?>" aria-hidden="true"></i> <?php echo $e($where); ?></span>
            </p>
        </div>
        <?php if ($ev['status'] === 'published'): ?>
        <button type="button" class="ev-btn" id="evmCopyLink" data-link="<?php echo $e($public_link); ?>"><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy Event Link</button>
        <?php endif; ?>
    </header>

    <dl class="evm__stats">
        <div class="evm__stat"><dt>Sold</dt><dd><?php echo $going; ?><?php echo (int) $ev['capacity'] > 0 ? '<span class="evm__of"> / ' . (int) $ev['capacity'] . '</span>' : ''; ?></dd></div>
        <div class="evm__stat"><dt>Ticket sales</dt><dd><?php echo $e($dollars($stats['gross'])); ?></dd></div>
        <div class="evm__stat"><dt>Your earnings</dt><dd><?php echo $e($dollars($stats['net'])); ?></dd></div>
        <div class="evm__stat"><dt>Refunded</dt><dd><?php echo (int) $stats['refunded_n']; ?><?php echo (int) $stats['refunded_credits'] > 0 ? '<span class="evm__of"> · ' . $e($dollars($stats['refunded_credits'])) . '</span>' : ''; ?></dd></div>
    </dl>

    <nav class="evm__tabs" aria-label="Event">
        <?php foreach (array('attendees' => 'Attendees', 'messages' => 'Messages', 'settings' => 'Settings') as $k => $label): ?>
        <a class="evm__tab<?php echo $tab === $k ? ' is-on' : ''; ?>" href="?tab=<?php echo $k; ?>"<?php echo $tab === $k ? ' aria-current="page"' : ''; ?>><?php echo $label; ?><?php echo $k === 'attendees' && $going > 0 ? ' <span class="evm__count">' . $going . '</span>' : ''; ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'attendees'): ?>
    <section class="evm__panel">
        <?php if (empty($attendees)): ?>
        <div class="ev-empty">
            <span class="ev-empty__ic"><i class="fa-regular fa-user"></i></span>
            <h2 class="ev-empty__t">No one has registered yet</h2>
            <p class="ev-empty__x"><?php echo $ev['status'] === 'published' ? 'Share the event link so fans can register.' : 'Publish the event in Settings so fans can register.'; ?></p>
        </div>
        <?php else: ?>
        <div class="evm__bar">
            <span class="evm__barlabel"><?php echo count($attendees); ?> <?php echo count($attendees) === 1 ? 'person' : 'people'; ?></span>
            <form method="post" action="/api/event_attendees_csv" class="evm__csv">
                <?php echo CSRF::field(); ?>
                <input type="hidden" name="event_id" value="<?php echo (int) $ev['id']; ?>">
                <button type="submit" class="ev-btn ev-btn--sm"><i class="fa-solid fa-download" aria-hidden="true"></i> Export CSV</button>
            </form>
        </div>
        <div class="evm-table" id="evmAttendees">
            <div class="evm-table__head"><span>Name</span><span>Registered</span><span>Paid</span><span>Status</span><span></span></div>
            <?php foreach ($attendees as $a):
                $name = html_entity_decode((string) $a['name'], ENT_QUOTES, 'UTF-8');
                $paid = (int) $a['price_credits'];
                $st   = (string) $a['status'];
            ?>
            <div class="evm-row" data-reg="<?php echo (int) $a['id']; ?>" data-name="<?php echo $e($name); ?>" data-paid="<?php echo $paid; ?>">
                <div class="evm-person">
                    <?php if (!empty($a['avatar_url'])): ?><img class="evm-person__av" src="<?php echo $e($a['avatar_url']); ?>" alt=""><?php else: ?><span class="evm-person__av evm-person__av--init" aria-hidden="true"><?php echo $e(mb_strtoupper(mb_substr($name, 0, 1))); ?></span><?php endif; ?>
                    <span class="evm-person__text"><span class="evm-person__name"><?php echo $e($name); ?></span><?php if ((string) $a['handle'] !== ''): ?><span class="evm-person__handle">@<?php echo $e($a['handle']); ?></span><?php endif; ?></span>
                </div>
                <div class="evm-cell"><?php echo $e($local($a['created_at'], 'M j, Y')); ?></div>
                <div class="evm-cell"><?php echo $paid > 0 ? $e($dollars($paid)) : 'Free'; ?></div>
                <div class="evm-cell"><span class="evm-pill evm-pill--<?php echo $e($st); ?>"><?php echo $e($reg_label[$st] ?? ucfirst($st)); ?></span></div>
                <div class="evm-cell evm-cell--act">
                    <?php if ($st === 'registered' && !$canceled): ?>
                    <div class="dropdown">
                        <button type="button" class="evm-more" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for <?php echo $e($name); ?>"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($paid > 0): ?><li><button type="button" class="dropdown-item" data-refund>Refund <?php echo $e($dollars($paid)); ?></button></li><?php endif; ?>
                            <li><button type="button" class="dropdown-item" data-remove>Remove</button></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php elseif ($tab === 'messages'): ?>
    <section class="evm__panel evm-msg">
        <?php if (!$canceled): ?>
        <div class="evm-msg__compose">
            <label class="evm-msg__label" for="evmMsgBody">Message everyone who is going</label>
            <textarea class="form-control" id="evmMsgBody" rows="5" maxlength="2000" placeholder="Doors open at 6:30. Parking is on the north side."></textarea>
            <div class="evm-msg__foot">
                <span class="evm-msg__hint"><?php echo $going === 0 ? 'No one is going yet.' : ''; ?></span>
                <button type="button" class="btn btn-primary cs-ae__btn" id="evmMsgSend" data-count="<?php echo $going; ?>" disabled>Send to <?php echo $going; ?> <?php echo $going === 1 ? 'Attendee' : 'Attendees'; ?></button>
            </div>
        </div>
        <?php endif; ?>
        <h2 class="evm-msg__h">Sent</h2>
        <?php if (empty($messages)): ?>
        <p class="evm-msg__none">Nothing sent yet.</p>
        <?php else: ?>
        <ul class="evm-msg__list">
            <?php foreach ($messages as $m): ?>
            <li class="evm-msg__item">
                <div class="evm-msg__meta"><?php echo $e($local($m['created_at'], 'M j, Y · g:i A')); ?> · <?php echo (int) $m['recipients']; ?> <?php echo (int) $m['recipients'] === 1 ? 'person' : 'people'; ?></div>
                <p class="evm-msg__body"><?php echo nl2br($e($m['body'])); ?></p>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <?php else: ?>
    <section class="evm__panel">
        <div class="cs-ae-modal cs-ae-inline<?php echo $canceled ? ' is-readonly' : ''; ?>" id="evmSettings" data-ev='<?php echo $e(json_encode($editor_data)); ?>'>
            <div class="cs-ae__surface">
                <div class="cs-ae__body">
                    <?php require __DIR__ . '/_editor_sections.php'; ?>
                </div>
                <?php if (!$canceled): ?>
                <footer class="cs-ae__footer">
                    <div class="cs-ae__actions"><button type="button" class="btn btn-primary cs-ae__btn" id="ev_save">Save Changes</button></div>
                </footer>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canceled): ?><p class="evm-canceled">This event was canceled. Its details can no longer be edited.</p><?php endif; ?>
        <div class="evm-danger">
            <?php if (!$canceled): ?>
            <div class="evm-danger__row">
                <div>
                    <h3 class="evm-danger__t">Cancel event</h3>
                    <p class="evm-danger__x"><?php echo $going > 0 ? 'Registration closes, ' . ($this->has_paid ? 'every paid ticket is refunded, ' : '') . ($going === 1 ? 'and the attendee is told.' : 'and all ' . $going . ' attendees are told.') : 'Registration closes and the event shows as canceled.'; ?></p>
                </div>
                <button type="button" class="ev-btn ev-btn--danger" id="evmCancelEvent" data-going="<?php echo $going; ?>">Cancel Event</button>
            </div>
            <?php endif; ?>
            <div class="evm-danger__row">
                <div>
                    <h3 class="evm-danger__t">Delete event</h3>
                    <p class="evm-danger__x"><?php echo $this->has_paid ? 'Cancel the event to refund attendees first.' : 'Removes the event and its registrations for good.'; ?></p>
                </div>
                <?php if (!$this->has_paid): ?><button type="button" class="ev-btn ev-btn--danger" id="evmDeleteEvent">Delete Event</button><?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
</div>

<script src="/js/section-editor.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/section-editor.js'); ?>"></script>
<script src="/js/events.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/events.js'); ?>"></script>
<script src="/js/event-page.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/event-page.js'); ?>"></script>
