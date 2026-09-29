<?php
/**
 * Event editor sections for the approved section-editor shell (.cs-ae-modal): left nav with live
 * summaries, one section at a time — Details · When · Where · Tickets. Expects $e, $tz, $tiers.
 * public/js/events.js (EventEditor) wires it and turns it into the event_save payload.
 */
$paid_plans = array_values(array_filter((array) $tiers, function ($t) { return (int) ($t['price_cents'] ?? 0) > 0; }));
$tz_label = str_replace('_', ' ', $tz);
if (class_exists('IntlTimeZone')) {
    $itz = IntlTimeZone::createTimeZone($tz);
    if ($itz && $itz->getID() !== 'Etc/Unknown') { $tz_label = $itz->getDisplayName(false, IntlTimeZone::DISPLAY_LONG_GENERIC, 'en_US'); }
}
$sections = array('details' => 'Details', 'when' => 'When', 'where' => 'Where', 'tickets' => 'Tickets');
$icons    = array('details' => 'fa-regular fa-file-lines', 'when' => 'fa-regular fa-calendar', 'where' => 'fa-solid fa-location-dot', 'tickets' => 'fa-solid fa-ticket');
?>
<input type="hidden" id="ev_id" value="">
<input type="hidden" id="ev_format" value="virtual">

<nav class="cs-ae__nav" aria-label="Event sections">
    <label class="cs-ae__navselect-label" for="evNavSelect">Section</label>
    <div class="ev-navbar">
        <select class="form-select cs-ae__navselect" id="evNavSelect">
            <?php foreach ($sections as $k => $label): ?><option value="<?php echo $k; ?>"><?php echo $label; ?></option><?php endforeach; ?>
        </select>
        <button type="button" class="ev-pvtoggle" id="evPvToggle" aria-expanded="false" aria-controls="evPreview">Preview</button>
    </div>
    <div class="cs-ae__navlist" id="evNav">
        <?php foreach ($sections as $k => $label): ?>
        <button type="button" class="cs-ae__navitem" data-section="<?php echo $k; ?>"<?php echo $k === 'details' ? ' aria-current="true"' : ''; ?>>
            <i class="<?php echo $icons[$k]; ?> ev-navic" aria-hidden="true"></i>
            <span class="cs-ae__navlabel"><?php echo $label; ?></span>
            <span class="cs-ae__navsum" data-sum="<?php echo $k; ?>"></span>
            <span class="cs-ae__navflag" hidden>Needs attention</span>
        </button>
        <?php endforeach; ?>
    </div>
</nav>

<div class="cs-ae__main">
    <section class="cs-ae__section" data-section="details" aria-labelledby="evH_details">
        <h3 class="cs-ae__h" id="evH_details" tabindex="-1">Details</h3>
        <p class="cs-ae__sub">What fans see on the event page.</p>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="ev_title">Event Name</label>
            <input type="text" class="form-control" id="ev_title" maxlength="190" autocomplete="off" placeholder="Event name" aria-describedby="evErr_title">
            <p class="cs-ae__error" id="evErr_title" role="alert" hidden></p>
        </div>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="ev_desc">Description</label>
            <textarea class="form-control" id="ev_desc" rows="7" placeholder="What happens at the event"></textarea>
        </div>
    </section>

    <section class="cs-ae__section" data-section="when" aria-labelledby="evH_when" hidden>
        <h3 class="cs-ae__h" id="evH_when" tabindex="-1">When</h3>
        <p class="cs-ae__sub">Set when the event starts and ends.</p>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="ev_date">Date</label>
            <input type="date" class="form-control" id="ev_date" aria-describedby="evErr_date">
        </div>
        <div class="cs-ae__row">
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="ev_time">Start Time</label>
                <input type="time" class="form-control" id="ev_time" step="300" aria-describedby="evErr_date">
            </div>
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="ev_time_end">End Time</label>
                <input type="time" class="form-control" id="ev_time_end" step="300" aria-describedby="evErr_date">
            </div>
        </div>
        <p class="cs-ae__error" id="evErr_date" role="alert" hidden></p>
        <div class="cs-ae__field ev-tzfield">
            <label class="cs-ae__label" for="ev_tz">Time Zone</label>
            <div class="ev-tzselect">
                <i class="fa-solid fa-globe" aria-hidden="true"></i>
                <select class="form-select" id="ev_tz" data-default="<?php echo $e($tz); ?>">
                    <?php foreach (EventsModel::timezone_options($tz) as $id => $label): ?><option value="<?php echo $e($id); ?>"><?php echo $e($label); ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="cs-ae__field">
            <span class="cs-ae__label" id="evRemLabel">Reminder Emails Before the Start</span>
            <div class="ev-chips" id="evReminders" role="group" aria-labelledby="evRemLabel">
                <?php foreach (EventsModel::REMINDERS as $mins => $label): ?><button type="button" class="ev-chip" data-rem="<?php echo (int) $mins; ?>" aria-pressed="false"><i class="fa-solid fa-check" aria-hidden="true"></i><span><?php echo $e($label); ?></span></button><?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="cs-ae__section" data-section="where" aria-labelledby="evH_where" hidden>
        <h3 class="cs-ae__h" id="evH_where" tabindex="-1">Where</h3>
        <p class="cs-ae__sub">The address shows on the event page. Meeting links are emailed to attendees when they register and in a reminder the day before.<?php if (LiveKit::enabled()): ?> CLS Video calls are joined from the event page.<?php endif; ?></p>
        <div class="cs-ae__field ev-formatrow">
            <span class="cs-ae__label" id="evFormatLabel">Format</span>
            <div class="cs-seg cs-ae__seg" id="evFormat" role="group" aria-labelledby="evFormatLabel">
                <button type="button" class="cs-seg__opt" aria-pressed="false" data-format="virtual"><i class="fa-solid fa-link" aria-hidden="true"></i><span>Online Link</span></button>
                <?php if (LiveKit::enabled()): ?><button type="button" class="cs-seg__opt" aria-pressed="false" data-format="cls_video"><i class="fa-solid fa-video" aria-hidden="true"></i><span>CLS Video</span></button><?php endif; ?>
                <button type="button" class="cs-seg__opt" aria-pressed="false" data-format="in_person"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><span>In person</span></button>
            </div>
        </div>
        <div class="cs-ae__field cs-ae__reveal" id="ev_pw_wrap" hidden>
            <label class="cs-ae__label" for="ev_pw">Call Password</label>
            <input type="text" class="form-control" id="ev_pw" maxlength="64" autocomplete="off" placeholder="No password">
        </div>
        <div class="cs-ae__field cs-ae__reveal" id="ev_url_wrap">
            <label class="cs-ae__label" for="ev_url">Meeting Link</label>
            <input type="url" class="form-control" id="ev_url" placeholder="https://zoom.us/j/123456789" autocomplete="off" aria-describedby="evErr_url">
            <p class="cs-ae__error" id="evErr_url" role="alert" hidden></p>
        </div>
        <div class="cs-ae__reveal" id="ev_location_wrap" hidden>
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="ev_venue">Venue Name</label>
                <input type="text" class="form-control" id="ev_venue" maxlength="160" autocomplete="organization" placeholder="Venue or place name">
            </div>
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="ev_street">Street Address</label>
                <input type="text" class="form-control" id="ev_street" maxlength="190" autocomplete="address-line1" placeholder="Street and number" aria-describedby="evErr_location">
            </div>
            <div class="ev-cityrow">
                <div class="cs-ae__field">
                    <label class="cs-ae__label" for="ev_city">City</label>
                    <input type="text" class="form-control" id="ev_city" maxlength="100" autocomplete="address-level2" placeholder="City" aria-describedby="evErr_location">
                </div>
                <div class="cs-ae__field">
                    <label class="cs-ae__label" for="ev_region">State</label>
                    <input type="text" class="form-control" id="ev_region" maxlength="60" autocomplete="address-level1" placeholder="State">
                </div>
                <div class="cs-ae__field">
                    <label class="cs-ae__label" for="ev_postal">ZIP Code</label>
                    <input type="text" class="form-control" id="ev_postal" maxlength="20" autocomplete="postal-code" inputmode="numeric" placeholder="ZIP">
                </div>
            </div>
            <p class="cs-ae__error" id="evErr_location" role="alert" hidden></p>
        </div>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="ev_instructions">Instructions for Attendees</label>
            <textarea class="form-control ev-short-ta" id="ev_instructions" rows="3" placeholder="Parking, entry code, what to bring"></textarea>
        </div>
    </section>

    <section class="cs-ae__section" data-section="tickets" aria-labelledby="evH_tickets" hidden>
        <h3 class="cs-ae__h" id="evH_tickets" tabindex="-1">Tickets</h3>
        <p class="cs-ae__sub">Who can come, what it costs, and how many spots there are.</p>
        <div class="cs-ae__row">
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="ev_price">Price</label>
                <div class="ev-money ev-money--credits">
                    <input type="number" class="form-control" id="ev_price" min="0" max="5000" step="1" inputmode="numeric" placeholder="Free" aria-describedby="evErr_price">
                    <span class="ev-money__sym" aria-hidden="true">credits</span>
                </div>
                <p class="cs-ae__error" id="evErr_price" role="alert" hidden></p>
            </div>
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="ev_capacity">Spots</label>
                <input type="number" class="form-control" id="ev_capacity" min="1" step="1" placeholder="Unlimited" aria-describedby="evErr_capacity">
                <p class="cs-ae__error" id="evErr_capacity" role="alert" hidden></p>
            </div>
        </div>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="ev_who">Who Can Come</label>
            <select class="form-select" id="ev_who">
                <option value="anyone">Anyone</option>
                <?php if (!empty($paid_plans)): ?>
                <option value="subscribers">Any subscriber</option>
                <optgroup label="Only subscribers on">
                    <?php foreach ($paid_plans as $t): ?><option value="<?php echo (int) $t['id']; ?>"><?php echo $e($t['name']); ?></option><?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
            </select>
        </div>
    </section>
</div>

<aside class="ev-pv" id="evPreview" aria-label="Event preview">
    <p class="ev-pv__eyebrow">Preview</p>
    <h3 class="ev-pv__title" id="evPv_title">Untitled event</h3>
    <p class="ev-pv__desc" id="evPv_desc"></p>
    <dl class="ev-pv__facts">
        <div><dt>Date and Time</dt><dd id="evPv_when"></dd></div>
        <div><dt>Location</dt><dd id="evPv_where"></dd></div>
        <div><dt>Who Can Come</dt><dd id="evPv_who"></dd></div>
    </dl>
    <p class="ev-pv__price"><span id="evPv_price">Free</span> <span class="ev-pv__per" id="evPv_per"></span></p>
    <p class="ev-pv__spots" id="evPv_spots"></p>
</aside>
