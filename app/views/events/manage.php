<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<?php
$e  = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$d  = function ($s) { return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'); };
$tz = (string) ($this->timezone ?? 'UTC');   // the account's zone: the editor's default for new events
$ev        = $this->event;
$ev_tz = EventsModel::clean_timezone($ev['timezone'] ?? '', $tz);   // this event's own zone: every time on the page is shown in it
$local = function ($utc, $format) use ($ev_tz) {
    if ((string) $utc === '' || $utc === null) { return ''; }
    try { $x = new DateTime((string) $utc, new DateTimeZone('UTC')); $x->setTimezone(new DateTimeZone($ev_tz)); return $x->format($format); }
    catch (\Throwable $t) { return ''; }
};
$stats     = $this->stats;
$tiers     = $this->tiers;
$canceled  = ($ev['status'] === 'canceled');
$live      = ($ev['status'] === 'published');
$in_person = (($ev['format'] ?? 'virtual') === 'in_person');
$dollars   = function ($credits) { return '$' . number_format(((int) $credits) / 10, 2); };
$public_link = (string) $this->share_link;   // Copy Link / View public page: the creator's own domain when they have one
$editor_data = array(
    'id' => (int) $ev['id'], 'title' => $d($ev['title']), 'description' => $d($ev['description']),
    'start_at' => $local($ev['start_at'], 'Y-m-d\TH:i'), 'end_at' => $local($ev['end_at'], 'Y-m-d\TH:i'), 'timezone' => $ev_tz, 'reminders' => (string) ($ev['reminders'] ?? '1440'),
    'format' => $ev['format'] ?? 'virtual', 'external_url' => (string) $ev['external_url'], 'location' => $d($ev['location']),
    'venue_name' => $d($ev['venue_name'] ?? ''), 'street' => $d($ev['street'] ?? ''), 'city' => $d($ev['city'] ?? ''),
    'region' => $d($ev['region'] ?? ''), 'postal_code' => $d($ev['postal_code'] ?? ''),
    'access_instructions' => $d($ev['access_instructions']), 'call_password' => (string) ($ev['call_password'] ?? ''),
    'access_type' => $ev['access_type'], 'tier_id' => (int) ($ev['tier_id'] ?? 0),
    'price' => number_format(((int) $ev['price_credits']) / 10, 2, '.', ''), 'capacity' => (int) $ev['capacity'], 'status' => $ev['status'],
);
$going = (int) $stats['going'];
$cap   = (int) $ev['capacity'];
$price = ($ev['access_type'] !== 'free' && (int) $ev['price_credits'] > 0) ? $dollars($ev['price_credits']) . ' per person' : 'Free';
$who   = in_array($ev['access_type'], array('subscribers', 'tier'), true) ? 'Subscribers only' : 'Open to anyone';
if ($ev['access_type'] === 'tier') {
    foreach ((array) $tiers as $t) { if ((int) $t['id'] === (int) $ev['tier_id']) { $who = $d($t['name']) . ' subscribers only'; break; } }
}
$pct   = $cap > 0 ? min(100, (int) round($going * 100 / $cap)) : 0;
$ended = strtotime((string) (!empty($ev['end_at']) ? $ev['end_at'] : $ev['start_at']) . ' UTC') < time();
$recipients  = (int) $this->recipients;   // who a send actually reaches (people going, minus anyone blocked either way)
$can_message = !$canceled && !$ended && $recipients > 0;
$tab   = (string) ($this->tab ?? 'attendees');
// Where: venue on one line, street on the next, then city/state/ZIP. Older events only have the one-line location.
$venue = trim($d($ev['venue_name'] ?? ''));
$street = trim($d($ev['street'] ?? ''));
$city_line = trim(implode(', ', array_filter(array(trim($d($ev['city'] ?? '')), trim(trim($d($ev['region'] ?? '')) . ' ' . trim($d($ev['postal_code'] ?? '')))), 'strlen')));
if ($in_person && $venue === '' && $street === '' && $city_line === '') { $street = $d($ev['location']); }
$empty_note = $canceled ? 'This event was canceled.' : ($ended ? 'This event has ended.' : ($live ? 'People who register show up here. Share the event link to get started.' : 'Turn on Live to open registration.'));
$msg_empty  = $canceled ? 'This event was canceled.' : ($ended ? 'This event has ended.' : ($recipients === 0 ? 'Once people register you can message all of them here.' : 'Messages you send to attendees show up here.'));
?>
<div class="evm" data-event-id="<?php echo (int) $ev['id']; ?>" data-link="<?php echo $e($public_link); ?>" data-ev='<?php echo $e(json_encode($editor_data)); ?>'
     data-canceled="<?php echo $canceled ? 1 : 0; ?>" data-msg-empty="<?php echo $e($msg_empty); ?>">
    <header class="evm-top">
        <a class="evm-top__back" href="/events"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Events</a>
        <div class="evm-top__row">
            <div class="evm-top__heading">
                <div class="evm-top__titlerow">
                    <h1 class="evm-top__title"><?php echo $e($d($ev['title'])); ?></h1>
                    <?php if ($canceled): ?><span class="evm-flag evm-flag--off">Canceled</span>
                    <?php elseif ($ended): ?><span class="evm-flag">Ended</span>
                    <?php elseif (!$live): ?><span class="evm-flag">Not live</span><?php endif; ?>
                    <?php if ($live && !$canceled && ($ev['format'] ?? '') === 'cls_video' && LiveKit::enabled() && LiveAccess::event_phase($ev) !== 'closed'): /* who is in the CLS Video call (event-page.js) */ ?>
                    <span class="evm-flag evm-callstat" id="evmCallStat" data-call="<?php echo (int) $ev['id']; ?>" role="status" hidden></span>
                    <?php endif; ?>
                </div>
                <?php if (trim($d($ev['description'])) !== ''): ?><p class="evm-top__desc"><?php echo $e($d($ev['description'])); ?></p><?php endif; ?>
            </div>
            <div class="evm-top__actions">
                <?php if (!$canceled): ?>
                <?php if (!$ended): ?>
                <label class="evm-live" for="evmLive" title="Live events show on your profile and take registrations">
                    <span class="form-check form-switch cs-ae__switch"><input class="form-check-input" type="checkbox" role="switch" id="evmLive"<?php echo $live ? ' checked' : ''; ?>></span>
                    <span class="evm-live__label">Live</span>
                </label>
                <?php endif; ?>
                <?php if ($live && !$ended): ?><button type="button" class="ev-btn" data-copy-link><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy Link</button><?php endif; ?>
                <?php if ($live && ($ev['format'] ?? '') === 'cls_video' && LiveKit::enabled() && LiveAccess::event_phase($ev) !== 'closed'): ?><a class="ev-btn" href="/live/event/<?php echo (int) $ev['id']; ?>"><i class="fa-solid fa-video" aria-hidden="true"></i> Start Video</a><?php endif; ?>
                <button type="button" class="ev-btn ev-btn--primary" id="evmEdit"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i> Edit Event</button>
                <?php endif; ?>
                <div class="dropdown">
                    <button type="button" class="ev-btn evm-kebab" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end evm-menu">
                        <?php if ($live || $ended): ?><li><a class="dropdown-item" href="<?php echo $e($public_link); ?>" target="_blank" rel="noopener">View public page</a></li><?php endif; ?>
                        <?php if ((int) $this->registrations > 0): ?><li><button type="button" class="dropdown-item" data-export>Export attendees (CSV)</button></li><?php endif; ?>
                        <?php if (!$canceled): ?><li><button type="button" class="dropdown-item" id="evmCancelEvent" data-going="<?php echo $going; ?>" data-paid="<?php echo $this->has_paid ? 1 : 0; ?>">Cancel event…</button></li><?php endif; ?>
                        <?php if ($canceled): ?><li><button type="button" class="dropdown-item evm-menu__danger" id="evmDeleteEvent">Delete event…</button></li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <dl class="evm-band">
        <div class="evm-band__cell">
            <dt>Date and Time</dt>
            <dd class="evm-band__main"><?php echo $e($local($ev['start_at'], 'D, M j, Y')); ?></dd>
            <dd class="evm-band__sub"><?php echo $e($local($ev['start_at'], 'g:i A')); ?><?php echo !empty($ev['end_at']) ? ' – ' . $e($local($ev['end_at'], 'g:i A')) : ''; ?> <?php echo $e($local($ev['start_at'], 'T')); ?></dd>
            <?php $rem = EventsModel::reminders_label($ev['reminders'] ?? ''); ?>
            <dd class="evm-band__sub evm-band__rem"><i class="fa-regular fa-bell" aria-hidden="true"></i> <?php echo $rem !== '' ? 'Reminders ' . $e($rem) : 'No reminder emails'; ?></dd>
        </div>
        <div class="evm-band__cell">
            <dt>Location</dt>
            <?php if ($in_person): ?>
            <dd class="evm-band__main"><?php echo $e($venue !== '' ? $venue : 'In person'); ?></dd>
            <?php if ($street !== ''): ?><dd class="evm-band__sub"><?php echo $e($street); ?></dd><?php endif; ?>
            <?php if ($city_line !== ''): ?><dd class="evm-band__sub"><?php echo $e($city_line); ?></dd><?php endif; ?>
            <?php else: ?>
            <dd class="evm-band__main">Online</dd>
            <dd class="evm-band__sub">Link emailed to attendees</dd>
            <?php endif; ?>
        </div>
        <div class="evm-band__cell">
            <dt>Tickets</dt>
            <dd class="evm-band__main"><?php echo $e($price); ?></dd>
            <dd class="evm-band__sub"><?php echo $e($who); ?></dd>
            <?php if ($price !== 'Free' || (int) $stats['net'] > 0): ?><dd class="evm-band__sub"><b class="evm-band__earned"><?php echo $e($dollars($stats['net'])); ?></b> earned<?php echo (int) $stats['refunded_n'] > 0 ? ' · ' . (int) $stats['refunded_n'] . ' refunded' : ''; ?></dd><?php endif; ?>
        </div>
        <div class="evm-band__cell">
            <dt>Registration</dt>
            <dd class="evm-band__main"><?php echo $going; ?><?php echo $cap > 0 ? ' of ' . $cap . ' spots filled' : ($going === 1 ? ' person going' : ' people going'); ?></dd>
            <?php if ($cap > 0): ?>
            <dd class="evm-band__bar"><span class="evm-bar" role="progressbar" aria-label="Spots filled" aria-valuemin="0" aria-valuemax="<?php echo $cap; ?>" aria-valuenow="<?php echo min($going, $cap); ?>"><span class="evm-bar__fill" style="width:<?php echo $pct; ?>%"></span></span></dd>
            <dd class="evm-band__sub"><?php echo max(0, $cap - $going); ?> <?php echo max(0, $cap - $going) === 1 ? 'spot' : 'spots'; ?> available</dd>
            <?php else: ?>
            <dd class="evm-band__sub">No spot limit</dd>
            <?php endif; ?>
        </div>
    </dl>

    <section class="evm-work">
        <div class="evm-work__bar">
            <div class="evm-tabs" role="tablist" aria-label="Event workspace">
                <button type="button" class="evm-tab" role="tab" id="evmTabAtt" data-tab="attendees" aria-controls="evmPanelAtt" aria-selected="<?php echo $tab === 'attendees' ? 'true' : 'false'; ?>"<?php echo $tab === 'attendees' ? '' : ' tabindex="-1"'; ?>>Attendees</button>
                <button type="button" class="evm-tab" role="tab" id="evmTabMsg" data-tab="messages" aria-controls="evmPanelMsg" aria-selected="<?php echo $tab === 'messages' ? 'true' : 'false'; ?>"<?php echo $tab === 'messages' ? '' : ' tabindex="-1"'; ?>>Messages</button>
            </div>
            <?php if ($can_message): ?><button type="button" class="ev-btn" id="evmNewMsg" hidden><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i> New Message</button><?php endif; ?>
        </div>

        <div class="evm-panel" role="tabpanel" id="evmPanelAtt" aria-labelledby="evmTabAtt"<?php echo $tab === 'attendees' ? '' : ' hidden'; ?>>
            <?php if ($going === 0): ?>
            <div class="evm-empty">
                <span class="evm-empty__ic"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></span>
                <p class="evm-empty__t">No one has registered yet</p>
                <p class="evm-empty__x"><?php echo $e($empty_note); ?></p>
                <?php if ($live && !$ended): ?><button type="button" class="btn btn-primary evm-cta" data-copy-link><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy Event Link</button><?php endif; ?>
            </div>
            <?php else: ?>
            <div class="evm-tools">
                <label class="evm-search">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <span class="visually-hidden">Search attendees</span>
                    <input type="search" class="form-control" id="evmSearch" placeholder="Search attendees" autocomplete="off" maxlength="100">
                </label>
            </div>
            <div class="evm-table" id="evmAttendees">
                <table class="evm-tbl">
                    <caption class="visually-hidden">People going to this event</caption>
                    <thead><tr><th scope="col">Attendee</th><th scope="col" class="evm-col-reg">Registered</th><th scope="col" class="evm-col-paid">Paid</th><th scope="col">Status</th><th scope="col" class="evm-col-act"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody id="evmRows" aria-live="polite"><tr class="evm-tbl__msg"><td colspan="5">Loading attendees…</td></tr></tbody>
                </table>
            </div>
            <footer class="evm-pager">
                <span class="evm-pager__range" id="evmRange"></span>
                <div class="evm-pager__btns">
                    <button type="button" class="ev-btn" id="evmPrev" disabled>Previous</button>
                    <button type="button" class="ev-btn" id="evmNext" disabled>Next</button>
                </div>
            </footer>
            <?php endif; ?>
        </div>

        <div class="evm-panel" role="tabpanel" id="evmPanelMsg" aria-labelledby="evmTabMsg"<?php echo $tab === 'messages' ? '' : ' hidden'; ?>>
            <div class="evm-history" id="evmHistory">
                <div class="evm-history__list" id="evmHistoryList" aria-live="polite"><p class="evm-note">Loading messages…</p></div>
                <footer class="evm-pager" id="evmMsgPager" hidden>
                    <span class="evm-pager__range" id="evmMsgRange"></span>
                    <div class="evm-pager__btns">
                        <button type="button" class="ev-btn" id="evmMsgPrev" disabled>Newer</button>
                        <button type="button" class="ev-btn" id="evmMsgNext" disabled>Older</button>
                    </div>
                </footer>
            </div>
            <?php if ($can_message): ?>
            <div class="evm-compose" id="evmCompose" hidden>
                <div class="evm-compose__head">
                    <h2 class="evm-compose__title">New Message</h2>
                    <button type="button" class="evm-link" id="evmBack"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Sent Messages</button>
                </div>
                <p class="evm-compose__to"><span class="evm-compose__tolabel">To</span> All registered attendees <span class="evm-tab__n"><?php echo $recipients; ?></span></p>
                <label class="evm-compose__label" for="evmMsgBody">Message</label>
                <textarea class="form-control evm-msg__text" id="evmMsgBody" maxlength="2000" rows="5" placeholder="Doors open at 6:30. Parking is on the north side."></textarea>
                <div class="evm-compose__foot">
                    <button type="button" class="ev-btn" id="evmMsgCancel">Cancel</button>
                    <button type="button" class="ev-btn ev-btn--primary" id="evmMsgSend" data-count="<?php echo $recipients; ?>" disabled>Send to <?php echo $recipients; ?> <?php echo $recipients === 1 ? 'Attendee' : 'Attendees'; ?></button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <form method="post" action="/api/event_attendees_csv" id="evmCsv" hidden>
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="event_id" value="<?php echo (int) $ev['id']; ?>">
    </form>
</div>

<?php if (!$canceled) { $editing = true; require __DIR__ . '/_modal.php'; } ?>

<script src="/js/section-editor.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/section-editor.js'); ?>"></script>
<script src="/js/events.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/events.js'); ?>"></script>
<script src="/js/event-page.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/event-page.js'); ?>"></script>
