<?php
/**
 * Service editor sections for the approved section-editor shell (.cs-ae-modal.ev-editor, styled by events.css):
 * left nav with live summaries, one section at a time — Details · Pricing · Delivery — and a live preview.
 * public/js/services.js (ServiceEditor) wires it and turns it into the service_save payload. Expects $e.
 */
$methods  = ServicesModel::method_labels();
$sections = array('details' => 'Details', 'pricing' => 'Pricing', 'delivery' => 'Delivery');
$icons    = array('details' => 'fa-regular fa-file-lines', 'pricing' => 'fa-solid fa-tag', 'delivery' => 'fa-solid fa-video');
?>
<input type="hidden" id="sv_id" value="">

<nav class="cs-ae__nav" aria-label="Service sections">
    <label class="cs-ae__navselect-label" for="svNavSelect">Section</label>
    <div class="ev-navbar">
        <select class="form-select cs-ae__navselect" id="svNavSelect">
            <?php foreach ($sections as $k => $label): ?><option value="<?php echo $k; ?>"><?php echo $label; ?></option><?php endforeach; ?>
        </select>
        <button type="button" class="ev-pvtoggle" id="svPvToggle" aria-expanded="false" aria-controls="svPreview">Preview</button>
    </div>
    <div class="cs-ae__navlist" id="svNav">
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
    <section class="cs-ae__section" data-section="details" aria-labelledby="svH_details">
        <h3 class="cs-ae__h" id="svH_details" tabindex="-1">Details</h3>
        <p class="cs-ae__sub">What buyers see on the service page.</p>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="sv_name">Service Name</label>
            <input type="text" class="form-control" id="sv_name" maxlength="190" autocomplete="off" placeholder="Service name" aria-describedby="svErr_name">
            <p class="cs-ae__error" id="svErr_name" role="alert" hidden></p>
        </div>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="sv_desc">Description</label>
            <textarea class="form-control" id="sv_desc" rows="6" placeholder="What the buyer gets"></textarea>
        </div>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="sv_category">Category</label>
            <input type="text" class="form-control" id="sv_category" maxlength="64" placeholder="Coaching">
        </div>
    </section>

    <section class="cs-ae__section" data-section="pricing" aria-labelledby="svH_pricing" hidden>
        <h3 class="cs-ae__h" id="svH_pricing" tabindex="-1">Pricing</h3>
        <p class="cs-ae__sub">What a booking costs, how long it lasts, and how many you'll take.</p>
        <div class="cs-ae__row">
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="sv_price">Price</label>
                <div class="ev-money ev-money--credits">
                    <input type="number" class="form-control" id="sv_price" min="0" max="5000" step="1" inputmode="numeric" placeholder="Free" aria-describedby="svErr_price">
                    <span class="ev-money__sym" aria-hidden="true">credits</span>
                </div>
                <p class="cs-ae__error" id="svErr_price" role="alert" hidden></p>
            </div>
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="sv_duration">Duration (Minutes)</label>
                <input type="number" class="form-control" id="sv_duration" min="0" step="5" placeholder="60">
            </div>
        </div>
        <div class="cs-ae__row">
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="sv_capacity">Spots</label>
                <input type="number" class="form-control" id="sv_capacity" min="1" step="1" placeholder="Unlimited" aria-describedby="svErr_capacity">
                <p class="cs-ae__error" id="svErr_capacity" role="alert" hidden></p>
            </div>
            <div class="cs-ae__field">
                <label class="cs-ae__label" for="sv_refund">Refund Policy</label>
                <input type="text" class="form-control" id="sv_refund" maxlength="500" placeholder="Full refund up to 24 hours before">
            </div>
        </div>
    </section>

    <section class="cs-ae__section" data-section="delivery" aria-labelledby="svH_delivery" hidden>
        <h3 class="cs-ae__h" id="svH_delivery" tabindex="-1">Delivery</h3>
        <p class="cs-ae__sub">How the session happens. Instructions show to buyers once they book.</p>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="sv_method">Meets On</label>
            <select class="form-select" id="sv_method">
                <?php foreach ($methods as $k => $label): ?><option value="<?php echo $k; ?>"><?php echo $e($label); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="cs-ae__field">
            <label class="cs-ae__label" for="sv_details">Instructions for Buyers</label>
            <textarea class="form-control" id="sv_details" rows="6" placeholder="How to reach you, what to prepare, how you'll schedule"></textarea>
        </div>
    </section>
</div>

<aside class="ev-pv" id="svPreview" aria-label="Service preview">
    <p class="ev-pv__eyebrow">Preview</p>
    <h3 class="ev-pv__title" id="svPv_title">Untitled service</h3>
    <p class="ev-pv__desc" id="svPv_desc"></p>
    <dl class="ev-pv__facts">
        <div><dt>Session</dt><dd id="svPv_session"></dd></div>
        <div id="svPv_refund_row"><dt>Refund Policy</dt><dd id="svPv_refund"></dd></div>
    </dl>
    <p class="ev-pv__price"><span id="svPv_price">Free</span> <span class="ev-pv__per" id="svPv_per"></span></p>
    <p class="ev-pv__spots" id="svPv_spots"></p>
</aside>
