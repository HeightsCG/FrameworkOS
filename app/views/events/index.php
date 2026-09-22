<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<?php
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$tz  = (string) ($this->timezone ?? 'UTC');
$fmt = function ($utc) use ($tz) {
    if ((string) $utc === '' || $utc === null) { return '—'; }
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format('M j, Y · g:i A'); }
    catch (\Throwable $x) { return '—'; }
};
$dtlocal = function ($utc) use ($tz) {   // UTC → creator tz, formatted for a datetime-local input
    if ((string) $utc === '' || $utc === null) { return ''; }
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format('Y-m-d\TH:i'); }
    catch (\Throwable $x) { return ''; }
};
$access_label = array('free' => 'Free', 'paid' => 'Paid', 'subscribers' => 'Subscribers', 'tier' => 'Tier');
$events = $this->events;
$tiers  = $this->tiers;
?>
<div class="ev">
    <header class="ev__head">
        <div>
            <h1 class="ev__title">Events</h1>
            <p class="ev__sub">Host events off-platform (link or venue) — the platform handles listing, registration, and paid tickets. Times are in <?php echo $e($tz); ?>.</p>
        </div>
        <button type="button" class="ev-btn ev-btn--primary" id="evCreate"><i class="fa-solid fa-plus"></i> Create Event</button>
    </header>

    <?php if (empty($events)): ?>
    <div class="ev-empty">
        <span class="ev-empty__ic"><i class="fa-regular fa-calendar"></i></span>
        <h2 class="ev-empty__t">No Events Yet</h2>
        <p class="ev-empty__x">Create your first event — a workshop, livestream, meetup, or webinar — and share it on your profile.</p>
    </div>
    <?php else: ?>
    <div class="ev-table">
        <div class="ev-table__head"><span>Event</span><span>When</span><span>Access</span><span>Registered</span><span>Status</span><span></span></div>
        <div class="ev-table__body" id="evBody">
            <?php foreach ($events as $ev): ?>
            <div class="ev-row" data-ev='<?php echo $e(json_encode(array(
                'id' => (int) $ev['id'], 'title' => $ev['title'], 'description' => $ev['description'],
                'start_at' => $dtlocal($ev['start_at']), 'end_at' => $dtlocal($ev['end_at']), 'access_type' => $ev['access_type'],
                'price' => number_format(((int) $ev['price_credits']) / 10, 2, '.', ''), 'tier_id' => (int) ($ev['tier_id'] ?? 0),
                'capacity' => (int) $ev['capacity'], 'location' => $ev['location'], 'external_url' => $ev['external_url'],
                'access_instructions' => $ev['access_instructions'], 'status' => $ev['status'], 'timezone' => $ev['timezone'],
            ))); ?>'>
                <div class="ev-cell ev-cell--title"><span class="ev-cell__name"><?php echo $e($ev['title']); ?></span><?php if ($ev['location'] !== ''): ?><span class="ev-cell__meta"><i class="fa-solid fa-location-dot"></i> <?php echo $e($ev['location']); ?></span><?php elseif ($ev['external_url'] !== ''): ?><span class="ev-cell__meta"><i class="fa-solid fa-video"></i> Online</span><?php endif; ?></div>
                <div class="ev-cell ev-cell--muted"><?php echo $e($fmt($ev['start_at'])); ?></div>
                <div class="ev-cell"><span class="ev-tag ev-tag--<?php echo $e($ev['access_type']); ?>"><?php echo $e($access_label[$ev['access_type']] ?? 'Free'); ?><?php echo ($ev['access_type'] === 'paid' && (int) $ev['price_credits'] > 0) ? ' · $' . number_format(((int) $ev['price_credits']) / 10, 2) : ''; ?></span></div>
                <div class="ev-cell ev-cell--muted"><?php echo (int) $ev['attendees']; ?><?php echo (int) $ev['capacity'] > 0 ? ' / ' . (int) $ev['capacity'] : ''; ?></div>
                <div class="ev-cell"><span class="ev-status ev-status--<?php echo $ev['status'] === 'published' ? 'on' : ($ev['status'] === 'canceled' ? 'off' : 'draft'); ?>"><span class="ev-status__dot"></span><?php echo $e(ucfirst($ev['status'])); ?></span></div>
                <div class="ev-cell ev-cell--act">
                    <button type="button" class="ev-btn ev-btn--sm" data-edit>Edit</button>
                    <button type="button" class="ev-btn ev-btn--sm ev-btn--danger" data-delete>Delete</button>
                </div>
            </div>
            <?php endforeach; ?>
            <p class="ev__none" id="evNone" hidden>No events.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="evModalTitle">New event</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ev_id">
                <div class="ev-field"><label for="ev_title">Title</label><input type="text" class="form-control" id="ev_title" maxlength="190" placeholder="Orlando meetup"></div>
                <div class="ev-field"><label for="ev_desc">Description</label><textarea class="form-control" id="ev_desc" rows="3" placeholder="What's the event about?"></textarea></div>
                <div class="ev-grid">
                    <div class="ev-field"><label for="ev_start">Starts (<?php echo $e($tz); ?>)</label><input type="datetime-local" class="form-control" id="ev_start"></div>
                    <div class="ev-field"><label for="ev_end">Ends <span class="ev-opt">(optional)</span></label><input type="datetime-local" class="form-control" id="ev_end"></div>
                </div>
                <div class="ev-grid">
                    <div class="ev-field"><label for="ev_access">Access</label>
                        <select class="form-control" id="ev_access">
                            <option value="free">Free registration</option>
                            <option value="paid">Paid ticket</option>
                            <option value="subscribers">Subscribers only</option>
                            <option value="tier">Specific tier</option>
                        </select>
                    </div>
                    <div class="ev-field" id="ev_price_wrap" hidden><label for="ev_price">Ticket price (USD)</label><input type="number" class="form-control" id="ev_price" min="1" step="0.01" placeholder="10.00"></div>
                    <div class="ev-field" id="ev_tier_wrap" hidden><label for="ev_tier">Required tier</label>
                        <select class="form-control" id="ev_tier">
                            <?php foreach ($tiers as $t): if ((int) ($t['price_cents'] ?? 0) <= 0) { continue; } ?>
                            <option value="<?php echo (int) $t['id']; ?>"><?php echo $e($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ev-field"><label for="ev_capacity">Capacity <span class="ev-opt">(0 = unlimited)</span></label><input type="number" class="form-control" id="ev_capacity" min="0" step="1" value="0"></div>
                </div>
                <div class="ev-section-label">Delivery — shown to registered attendees</div>
                <div class="ev-grid">
                    <div class="ev-field"><label for="ev_url">Meeting / livestream URL</label><input type="url" class="form-control" id="ev_url" placeholder="https://…"></div>
                    <div class="ev-field"><label for="ev_location">In-person location <span class="ev-opt">(optional)</span></label><input type="text" class="form-control" id="ev_location" maxlength="255" placeholder="Venue &amp; address"></div>
                </div>
                <div class="ev-field"><label for="ev_instructions">Access instructions <span class="ev-opt">(optional)</span></label><textarea class="form-control" id="ev_instructions" rows="2" placeholder="Directions, passcode, or what to bring."></textarea></div>
                <div class="ev-field"><label for="ev_status">Status</label>
                    <select class="form-control" id="ev_status">
                        <option value="draft">Draft — not visible yet</option>
                        <option value="published">Published — live on your profile</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ev-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="ev-btn ev-btn--primary" id="ev_save">Save Event</button>
            </div>
        </div>
    </div>
</div>

<script src="/js/events.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/events.js'); ?>"></script>
