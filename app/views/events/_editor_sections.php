<?php
/**
 * Event editor sections (nav + panels) for the shared .cs-ae-modal shell. Used by the Create modal
 * (events/index.php) and the Settings tab (events/manage.php). Expects $e (escaper), $tz, $tiers.
 * Field ids are ev_*; public/js/events.js (EventEditor) wires them.
 */
$paid_plans = array_values(array_filter((array) $tiers, function ($t) { return (int) ($t['price_cents'] ?? 0) > 0; }));
$sections = array('details' => 'Details', 'when' => 'Date &amp; time', 'location' => 'Location', 'tickets' => 'Tickets', 'publishing' => 'Publishing');
?>
<input type="hidden" id="ev_id" value="">
<input type="hidden" id="ev_access" value="free">
<input type="hidden" id="ev_format" value="virtual">
<input type="hidden" id="ev_who" value="anyone">
<input type="hidden" id="ev_pay" value="free">
<input type="hidden" id="ev_status" value="draft">

<nav class="cs-ae__nav" aria-label="Event sections">
    <label class="cs-ae__navselect-label" for="evNavSelect">Section</label>
    <select class="form-select cs-ae__navselect" id="evNavSelect">
        <?php foreach ($sections as $k => $label): ?><option value="<?php echo $k; ?>"><?php echo $label; ?></option><?php endforeach; ?>
    </select>
    <div class="cs-ae__navlist" id="evNav">
        <?php foreach ($sections as $k => $label): ?>
        <button type="button" class="cs-ae__navitem" data-section="<?php echo $k; ?>"<?php echo $k === 'details' ? ' aria-current="true"' : ''; ?>>
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
        <p class="cs-ae__sub">What fans see on your profile before they register.</p>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="ev_title">Title</label>
            <input type="text" class="form-control" id="ev_title" maxlength="190" autocomplete="off" placeholder="Orlando Creator Meetup" aria-describedby="evErr_title">
            <p class="cs-ae__error" id="evErr_title" role="alert" hidden></p>
        </div>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="ev_desc">Description</label>
            <textarea class="form-control" id="ev_desc" rows="6"></textarea>
        </div>
    </section>

    <section class="cs-ae__section" data-section="when" aria-labelledby="evH_when" hidden>
        <h3 class="cs-ae__h" id="evH_when" tabindex="-1">Date &amp; time</h3>
        <p class="cs-ae__sub">Times are in <?php echo $e(str_replace('_', ' ', $tz)); ?>.</p>
        <div class="cs-ae__row">
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="ev_start">Starts</label>
                <input type="datetime-local" class="form-control" id="ev_start" aria-describedby="evErr_start">
                <p class="cs-ae__error" id="evErr_start" role="alert" hidden></p>
            </div>
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="ev_end">Ends</label>
                <input type="datetime-local" class="form-control" id="ev_end" aria-describedby="evErr_end">
                <p class="cs-ae__error" id="evErr_end" role="alert" hidden></p>
            </div>
        </div>
    </section>

    <section class="cs-ae__section" data-section="location" aria-labelledby="evH_location" hidden>
        <h3 class="cs-ae__h" id="evH_location" tabindex="-1">Location</h3>
        <p class="cs-ae__sub">Attendees get these details once they register.</p>
        <div class="cs-ae__field">
            <span class="cs-ae__label" id="evFormatLabel">Format</span>
            <div class="cs-seg cs-ae__seg" id="evFormat" role="group" aria-labelledby="evFormatLabel">
                <button type="button" class="cs-seg__opt" aria-pressed="false" data-format="virtual"><i class="fa-solid fa-video" aria-hidden="true"></i><span>Virtual</span></button>
                <button type="button" class="cs-seg__opt" aria-pressed="false" data-format="in_person"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><span>In person</span></button>
            </div>
        </div>
        <div class="cs-ae__field cs-ae__reveal" id="ev_url_wrap">
            <label class="cs-ae__label" for="ev_url">Video link</label>
            <input type="url" class="form-control" id="ev_url" placeholder="https://zoom.us/j/" autocomplete="off" aria-describedby="evErr_url">
            <p class="cs-ae__error" id="evErr_url" role="alert" hidden></p>
        </div>
        <div class="cs-ae__field cs-ae__reveal" id="ev_location_wrap" hidden>
            <label class="cs-ae__label" for="ev_location">Address</label>
            <input type="text" class="form-control" id="ev_location" maxlength="255" placeholder="Street, city" autocomplete="off" aria-describedby="evErr_location">
            <p class="cs-ae__error" id="evErr_location" role="alert" hidden></p>
        </div>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="ev_instructions">Instructions</label>
            <textarea class="form-control" id="ev_instructions" rows="4"></textarea>
        </div>
    </section>

    <section class="cs-ae__section" data-section="tickets" aria-labelledby="evH_tickets" hidden>
        <h3 class="cs-ae__h" id="evH_tickets" tabindex="-1">Tickets</h3>
        <p class="cs-ae__sub">Who can attend, and whether they pay.</p>
        <div class="cs-ae__row">
            <div class="cs-ae__field">
                <span class="cs-ae__label" id="evWhoLabel">Who can attend</span>
                <div class="cs-seg cs-ae__seg" id="evWho" role="group" aria-labelledby="evWhoLabel">
                    <button type="button" class="cs-seg__opt" aria-pressed="false" data-who="anyone"><span>Anyone</span></button>
                    <button type="button" class="cs-seg__opt" aria-pressed="false" data-who="subscribers"<?php echo empty($paid_plans) ? ' disabled title="Create a membership plan first"' : ''; ?>><span>Subscribers</span></button>
                </div>
            </div>
            <div class="cs-ae__field">
                <span class="cs-ae__label" id="evPayLabel">Price</span>
                <div class="cs-seg cs-ae__seg" id="evPay" role="group" aria-labelledby="evPayLabel">
                    <button type="button" class="cs-seg__opt" aria-pressed="false" data-pay="free"><span>Free</span></button>
                    <button type="button" class="cs-seg__opt" aria-pressed="false" data-pay="paid"><span>Paid</span></button>
                </div>
            </div>
        </div>
        <div class="cs-ae__field cs-ae__reveal" id="ev_tier_wrap" hidden>
            <label class="cs-ae__label" for="ev_tier">Plan</label>
            <select class="form-select" id="ev_tier">
                <option value="0">Any plan</option>
                <?php foreach ($paid_plans as $t): ?><option value="<?php echo (int) $t['id']; ?>"><?php echo $e($t['name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="cs-ae__field cs-ae__field--short cs-ae__reveal" id="ev_price_wrap" hidden>
            <label class="cs-ae__label" for="ev_price">Ticket price (USD)</label>
            <input type="number" class="form-control" id="ev_price" min="1" step="0.01" placeholder="15.00" aria-describedby="evErr_price">
            <p class="cs-ae__error" id="evErr_price" role="alert" hidden></p>
        </div>
        <div class="cs-ae__field cs-ae__field--switch">
            <label class="cs-ae__label" for="ev_limit">Limit spots</label>
            <div class="form-check form-switch cs-ae__switch"><input class="form-check-input" type="checkbox" role="switch" id="ev_limit"></div>
        </div>
        <div class="cs-ae__field cs-ae__field--short cs-ae__reveal" id="ev_capacity_wrap" hidden>
            <label class="cs-ae__label" for="ev_capacity">Spots</label>
            <input type="number" class="form-control" id="ev_capacity" min="1" step="1" placeholder="25" aria-describedby="evErr_capacity">
            <p class="cs-ae__error" id="evErr_capacity" role="alert" hidden></p>
        </div>
    </section>

    <section class="cs-ae__section" data-section="publishing" aria-labelledby="evH_publishing" hidden>
        <h3 class="cs-ae__h" id="evH_publishing" tabindex="-1">Publishing</h3>
        <p class="cs-ae__sub">Published events appear on your profile and take registrations.</p>
        <div class="cs-ae__field">
            <span class="cs-ae__label" id="evStatusLabel">Status</span>
            <div class="cs-seg cs-ae__seg" id="evStatusSeg" role="group" aria-labelledby="evStatusLabel">
                <button type="button" class="cs-seg__opt" aria-pressed="false" data-status="draft"><span>Draft</span></button>
                <button type="button" class="cs-seg__opt" aria-pressed="false" data-status="published"><span>Published</span></button>
            </div>
        </div>
    </section>
</div>
