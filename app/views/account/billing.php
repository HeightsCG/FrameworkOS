<link rel="stylesheet" href="/css/account-billing.css?v=<?php echo @filemtime(Main::app_path().'/public/css/account-billing.css'); ?>">
<script src="https://js.stripe.com/v3/"></script>
<?php
    $e        = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $has_plan = !empty($this->has_plan);
    $status   = (string) ($this->user['subscription_status'] ?? '');
    $usage    = is_array($this->usage) ? $this->usage : null;
    $tier_key = (string) ($this->tier ?? '');
    $tier_def = $tier_key !== '' ? PlanTiers::get($tier_key) : null;
    $canceling = !empty($this->user['subscription_cancel_at_period_end']);
    $past_due  = ($status === 'past_due');
    $period_end_ts = !empty($this->user['subscription_current_period_end']) ? strtotime((string) $this->user['subscription_current_period_end']) : 0;
    $grant_n   = $tier_def ? (int) $tier_def['limits']['ai_credits'] : 0;
    $over_rows = array();
    if ($usage) { foreach ($usage['rows'] as $r) { if (!empty($r['over'])) { $over_rows[] = $r; } } }

    // Plans come from PlanTiers (price, limits, Stripe price id), low → high.
    $ordered = (array) $this->plan_rows;
    $is_free = ($tier_key === PlanTiers::FREE_KEY);
    $free_def = PlanTiers::get(PlanTiers::FREE_KEY);
    $free_fee = $free_def ? (int) $free_def['limits']['fee_percent'] : 0;
    $grants_once = $tier_def ? PlanTiers::grants_once($tier_def) : false;
    // Add-ons on the current plan (extra AI influencers on Creator): quantity held + scheduled change.
    $addons = is_array($this->addons ?? null) ? $this->addons : array();
?>
<script>
$(function () {

    var stripe    = Stripe('<?php echo $e($this->stripe_pk); ?>');
    var elements  = null;
    var price_id  = '';
    var paid      = false;
    var pay_mode  = 'payment';   // 'payment' | 'setup' ($0 first invoice, card saved for renewals)
    var credit_elements = null;

    function fmt_amount(cents, currency) {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: (currency || 'usd').toUpperCase() }).format(cents / 100);
    }
    function parse(data) { try { return JSON.parse(data); } catch (e) { return { success: false, message: 'Something went wrong' }; } }
    function reload_after(ms) { setTimeout(function () { window.location.href = '/account/billing'; }, ms || 1200); }

    /* ---- new subscription (no plan yet) ---- */
    function start_payment(promo_code) {
        $('#pay_button').prop('disabled', true);
        ApiDataSvc.apiCall('post', 'create_subscription', { price_id: price_id, promo_code: promo_code || '' }, function (data) {
            var o = parse(data);
            if (!o.success) {
                toastr.error(o.message);
                $('#pay_button').prop('disabled', elements === null);
                $('#promo_apply').prop('disabled', false);
                return;
            }
            pay_mode = o.mode || 'payment';
            if (pay_mode === 'none') {
                paid = true;
                $('#payment_form').modal('hide');
                ApiDataSvc.apiCall('post', 'sync_subscription', {}, function () { toastr.success('Your subscription is active'); reload_after(); });
                return;
            }
            $('#payment_element').html('');
            elements = stripe.elements({ clientSecret: o.client_secret });
            elements.create('payment').mount('#payment_element');
            $('#pay_button').prop('disabled', false);
            $('#promo_apply').prop('disabled', false);
            $('#pay_total').text(fmt_amount(o.amount_due, o.currency));
            $('#pay_note').toggle(pay_mode === 'setup');
            if (o.promo_label) { $('#promo_applied').text(o.promo_label + ' applied').show(); $('#promo_row').hide(); }
            else { $('#promo_applied').hide(); }
            $('#payment_form').modal('show');
        });
    }

    $('.plan-choose').on('click', function () {
        price_id = $(this).data('price-id');
        paid = false; elements = null;
        $('#promo_code').val(''); $('#promo_row').show(); $('#promo_applied').hide();
        start_payment('');
    });
    $('#promo_apply').on('click', function () {
        var code = String($('#promo_code').val() || '').trim();
        if (code === '') { return; }
        $('#promo_apply').prop('disabled', true);
        start_payment(code);
    });
    $('#promo_code').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#promo_apply').trigger('click'); } });

    $('#pay_button').on('click', function () {
        if (!elements) { return; }
        $('#pay_button').prop('disabled', true);
        var method = (pay_mode === 'setup') ? 'confirmSetup' : 'confirmPayment';
        stripe[method]({ elements: elements, redirect: 'if_required' }).then(function (result) {
            if (result.error) { $('#pay_button').prop('disabled', false); toastr.error(result.error.message); return; }
            paid = true;
            ApiDataSvc.apiCall('post', 'sync_subscription', {}, function () { toastr.success('Your subscription is active'); reload_after(); });
        });
    });
    $('#payment_form').on('hidden.bs.modal', function () {
        if (paid) { return; }
        elements = null;
        ApiDataSvc.apiCall('post', 'abandon_subscription', {}, function () {});
    });

    /* ---- change plan (upgrade / downgrade in place, prorated) ---- */
    $('.plan-change').on('click', function () {
        var $b = $(this);
        var up = $b.data('direction') === 'up';
        Swal.fire({
            title: (up ? 'Upgrade to ' : 'Downgrade to ') + $b.data('tier-name') + '?',
            text: up ? 'The difference is prorated and charged today. Your renewal date stays <?php echo $period_end_ts ? $e(date('M j', $period_end_ts)) : 'the same'; ?>.'
                     : 'The difference is credited to your next invoice. Your renewal date stays <?php echo $period_end_ts ? $e(date('M j', $period_end_ts)) : 'the same'; ?>.',
            showCancelButton: true,
            confirmButtonText: up ? 'Upgrade' : 'Downgrade',
            cancelButtonText: 'Keep current plan',
            reverseButtons: true,
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' },
            buttonsStyling: false
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            $('.plan-change').prop('disabled', true);
            ApiDataSvc.apiCall('post', 'change_subscription', { price_id: $b.data('price-id') }, function (data) {
                var o = parse(data);
                if (!o.success) { toastr.error(o.message); $('.plan-change').prop('disabled', false); return; }
                toastr.success(o.message);
                reload_after();
            });
        });
    });

    /* ---- downgrade to Free: cancels the subscription at period end, nothing is deleted ---- */
    $('.plan-downgrade-free').on('click', function () {
        Swal.fire({
            title: 'Downgrade to Free?',
            html: 'You keep everything you have made. Your plan stays active until <?php echo $period_end_ts ? $e(date('M j, Y', $period_end_ts)) : 'the end of the period'; ?>, then you move to Free: a <?php echo $free_fee; ?>% platform take rate and the Free limits. Anything over those limits is locked, not deleted, and comes back if you upgrade again.',
            showCancelButton: true,
            confirmButtonText: 'Downgrade to Free',
            cancelButtonText: 'Keep current plan',
            reverseButtons: true,
            customClass: { confirmButton: 'btn btn-danger', cancelButton: 'btn btn-secondary' },
            buttonsStyling: false
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            $('.plan-downgrade-free').prop('disabled', true);
            ApiDataSvc.apiCall('post', 'cancel_subscription', {}, function (data) {
                var o = parse(data);
                if (!o.success) { toastr.error(o.message); $('.plan-downgrade-free').prop('disabled', false); return; }
                toastr.success(o.message);
                reload_after();
            });
        });
    });

    /* ---- add-ons (extra AI influencer slots): adding is prorated now, removing applies at period end ---- */
    $('.addon-set').on('click', function () {
        var $b = $(this);
        var target = parseInt($b.data('quantity'), 10);
        var adding = $b.data('direction') === 'up';
        Swal.fire({
            title: adding ? 'Add a slot?' : 'Remove a slot?',
            text: adding ? ('$' + $b.data('price') + '/month, prorated and charged today. Renews with your plan.')
                         : 'You keep the slot until your plan renews, then it is removed. If you have more influencers than slots, the newest are locked, not deleted.',
            showCancelButton: true,
            confirmButtonText: adding ? 'Add Slot' : 'Remove Slot',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            customClass: { confirmButton: adding ? 'btn btn-primary' : 'btn btn-danger', cancelButton: 'btn btn-secondary' },
            buttonsStyling: false
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            $('.addon-set').prop('disabled', true);
            ApiDataSvc.apiCall('post', 'addon_set', { addon: $b.data('addon'), quantity: target }, function (data) {
                var o = parse(data);
                if (!o.success) { toastr.error(o.message); $('.addon-set').prop('disabled', false); return; }
                toastr.success(o.message);
                reload_after();
            });
        });
    });

    /* ---- AI credits (top up) ---- */
    $('#buy_credits').on('click', function () { $('#credits_modal').modal('show'); });
    if (/[?&]buy=credits\b/.test(window.location.search)) { $('#credits_modal').modal('show'); }   // "Buy credits" links from the generate screens
    $('#credit_packs').on('click', '.pack', function () {
        var dollars = $(this).data('dollars');
        $('#credit_packs .pack').removeClass('is-on'); $(this).addClass('is-on');
        $('#credit_pay_button').prop('disabled', true).text('Pay $' + dollars);
        $('#credit_payment_element').html('<div class="billing__loading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span></div>');
        ApiDataSvc.apiCall('post', 'buy_ai_credits', { dollars: dollars }, function (data) {
            var o = parse(data);
            if (!o.success) { toastr.error(o.message); $('#credit_payment_element').html(''); return; }
            $('#credit_payment_element').html('');
            credit_elements = stripe.elements({ clientSecret: o.client_secret });
            credit_elements.create('payment').mount('#credit_payment_element');
            $('#credit_pay_button').prop('disabled', false).text('Pay $' + (o.total_cents / 100).toFixed(0) + ' for ' + o.credits + ' credits');
        });
    });
    $('#credit_pay_button').on('click', function () {
        if (!credit_elements) { return; }
        $('#credit_pay_button').prop('disabled', true);
        stripe.confirmPayment({ elements: credit_elements, redirect: 'if_required' }).then(function (result) {
            if (result.error) { $('#credit_pay_button').prop('disabled', false); toastr.error(result.error.message); return; }
            ApiDataSvc.apiCall('post', 'confirm_ai_credit_purchase', { payment_intent_id: result.paymentIntent.id }, function (data) {
                var o = parse(data);
                if (!o.success) { toastr.error(o.message); return; }
                toastr.success(o.message);
                $('#credits_modal').modal('hide');
                reload_after(900);
            });
        });
    });
    $('#credits_modal').on('hidden.bs.modal', function () {
        credit_elements = null;
        $('#credit_packs .pack').removeClass('is-on');
        $('#credit_payment_element').html('');
        $('#credit_pay_button').prop('disabled', true).text('Pay');
    });

    /* ---- cancel / resume ---- */
    $('#cancel_subscription').on('click', function () { $('#cancel_form').modal('show'); });
    $('#confirm_cancel').on('click', function () {
        $('#cancel_form').modal('hide');
        ApiDataSvc.apiCall('post', 'cancel_subscription', {}, function (data) {
            var o = parse(data);
            if (o.success) { toastr.success(o.message); reload_after(); } else { toastr.error(o.message); }
        });
    });
    $('#resume_subscription').on('click', function () {
        ApiDataSvc.apiCall('post', 'resume_subscription', {}, function (data) {
            var o = parse(data);
            if (o.success) { toastr.success(o.message); reload_after(); } else { toastr.error(o.message); }
        });
    });
    $('#cancel_now').on('click', function () { $('#cancel_now_form').modal('show'); });
    $('#confirm_cancel_now').on('click', function () {
        $('#cancel_now_form').modal('hide');
        ApiDataSvc.apiCall('post', 'cancel_now_subscription', {}, function (data) {
            var o = parse(data);
            if (o.success) { toastr.success(o.message); reload_after(); } else { toastr.error(o.message); }
        });
    });

});
</script>

<div class="billing">

    <?php if ($has_plan): ?>
    <div class="billing__current">
        <div>
            <div class="billing__current-name"><?php echo $e($tier_def ? $tier_def['name'] : 'Subscription'); ?><?php if ($tier_def): ?> <span class="billing__current-price">$<?php echo number_format((int) $tier_def['price']); ?> / month</span><?php endif; ?><?php if ($tier_def && !empty($tier_def['retired'])): ?> <span class="billing__current-price">&middot; no longer offered to new members</span><?php endif; ?></div>
            <?php if ($period_end_ts): ?>
            <div class="billing__current-meta"><?php echo $canceling ? 'Moves to Free on ' : 'Renews on '; ?><?php echo $e(date('M j, Y', $period_end_ts)); ?></div>
            <?php endif; ?>
        </div>
        <div class="billing__current-actions">
            <span class="billing__status billing__status--<?php echo $canceling ? 'canceling' : ($past_due ? 'due' : 'active'); ?>"><?php echo $canceling ? 'Canceling' : ($past_due ? 'Payment due' : $e($status)); ?></span>
            <?php if ($canceling): ?>
            <button type="button" class="btn btn-secondary" id="resume_subscription">Resume Subscription</button>
            <button type="button" class="btn btn-danger" id="cancel_now">Cancel Immediately</button>
            <?php else: ?>
            <button type="button" class="btn btn-secondary" id="cancel_subscription">Cancel Subscription</button>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

    <?php if ($has_plan && !empty($addons)): ?>
    <?php foreach ($addons as $ad): $held = (int) $ad['quantity']; $next = $ad['next']; $billed = ($next === null) ? $held : (int) $next; ?>
    <div class="billing__current billing__addon">
        <div>
            <div class="billing__current-name"><?php echo $e($ad['def']['name']); ?> <span class="billing__current-price">$<?php echo (int) $ad['def']['price']; ?> / month each</span></div>
            <div class="billing__current-meta">
                <?php echo $held; ?> of <?php echo (int) $ad['def']['max']; ?> slots &middot; <?php echo (int) $ad['included']; ?> included with <?php echo $e($tier_def['name']); ?>, <?php echo (int) $ad['included'] + $held; ?> AI influencers in total
                <?php if ($next !== null && (int) $next < $held): ?>&middot; drops to <?php echo (int) $next; ?> on <?php echo $e(date('M j', strtotime((string) $ad['next_at'] . ' UTC'))); ?><?php endif; ?>
            </div>
        </div>
        <div class="billing__current-actions">
            <?php if ($canceling): ?>
            <span class="billing__current-meta">Resume your plan to change add-ons</span>
            <?php else: ?>
            <button type="button" class="btn btn-secondary addon-set" data-addon="<?php echo $e($ad['def']['key']); ?>" data-quantity="<?php echo $billed - 1; ?>" data-direction="down" data-price="<?php echo (int) $ad['def']['price']; ?>" <?php echo $billed <= 0 ? 'disabled' : ''; ?> aria-label="Remove a slot"><i class="fa-solid fa-minus"></i></button>
            <button type="button" class="btn btn-secondary addon-set" data-addon="<?php echo $e($ad['def']['key']); ?>" data-quantity="<?php echo $billed + 1; ?>" data-direction="up" data-price="<?php echo (int) $ad['def']['price']; ?>" <?php echo $billed >= (int) $ad['def']['max'] ? 'disabled' : ''; ?>><i class="fa-solid fa-plus"></i> Add Slot</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($tier_key === PlanTiers::FREE_KEY): ?>
    <div class="billing__current">
        <div>
            <div class="billing__current-name">Free <span class="billing__current-price">$0 / month</span></div>
            <div class="billing__current-meta">No card needed. The platform takes <?php echo (int) ($tier_def['limits']['fee_percent'] ?? 0); ?>% of what you earn.</div>
        </div>
        <div class="billing__current-actions">
            <span class="billing__status billing__status--active">Active</span>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($over_rows)): ?>
    <div class="billing__notice">
        <i class="fa-solid fa-circle-exclamation"></i>
        <div>
            <?php foreach ($over_rows as $r): ?>
            <p>You have <?php echo $e($r['kind'] === 'gb' ? number_format($r['used'], 1) . ' GB of storage' : $r['used'] . ' ' . ($r['key'] === 'seats' ? 'team seats' : $r['label'])); ?>; <?php echo $e($usage['tier_name']); ?> includes <?php echo $e($r['limit_text']); ?>. <?php echo $r['kind'] === 'gb' ? 'Your files stay, but you can\'t upload more until you\'re under the limit or on a bigger plan.' : 'Nothing is deleted. The ones over the limit are locked until you upgrade.'; ?></p>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($usage): ?>
    <div class="billing__section billing__section--first">
        <div class="billing__section-head">
            <h2 class="billing__section-title">Usage</h2>
            <button type="button" class="btn btn-secondary btn-sm" id="buy_credits"><i class="fa-solid fa-plus"></i> Buy AI Credits</button>
        </div>
        <div class="usage">
            <?php foreach ($usage['rows'] as $r): ?>
            <?php if ($r['kind'] === 'credits'): ?>
            <?php if ($grants_once): ?>
            <?php $bal = (int) $usage['ai_credit_balance']; ?>
            <div class="usage__row usage__row--text">
                <span class="usage__label"><?php echo $e($r['label']); ?></span>
                <span class="usage__text"><?php echo number_format($grant_n); ?> to start, no monthly refill</span>
                <span class="usage__val"><b><?php echo number_format($bal); ?></b> left</span>
            </div>
            <?php else: ?>
            <?php $spent = (int) $usage['ai_credit_spent']; $pct = $grant_n > 0 ? min(100, round($spent / $grant_n * 100)) : 0; ?>
            <div class="usage__row">
                <span class="usage__label"><?php echo $e($r['label']); ?> / month</span>
                <div class="usage__bar"><div class="usage__fill" style="width:<?php echo $pct; ?>%"></div></div>
                <span class="usage__val"><b><?php echo number_format($spent); ?></b> of <?php echo number_format($grant_n); ?> &middot; <?php echo number_format((int) $usage['ai_credit_balance']); ?> left</span>
            </div>
            <?php endif; ?>
            <?php elseif ($r['kind'] === 'percent'): ?>
            <div class="usage__row usage__row--text">
                <span class="usage__label"><?php echo $e($r['label']); ?></span>
                <span class="usage__text"><?php echo $e($r['limit_text']); ?> of what you earn</span>
                <span class="usage__val"></span>
            </div>
            <?php elseif ($r['limit'] !== null && (int) $r['limit'] < 0): ?>
            <?php $up_t = PlanTiers::lowest_including($r['key']); ?>
            <div class="usage__row usage__row--text<?php echo !empty($r['over']) ? ' usage__row--over' : ''; ?>">
                <span class="usage__label"><?php echo $e($r['label']); ?></span>
                <span class="usage__text">Not on <?php echo $e($usage['tier_name']); ?><?php echo $up_t ? '. Included from ' . $e($up_t['name']) : ''; ?></span>
                <span class="usage__val"><?php if (!empty($r['used'])): ?><b><?php echo (int) $r['used']; ?></b> locked<?php endif; ?></span>
            </div>
            <?php else: ?>
            <?php
                $used  = (float) $r['used'];
                $limit = $r['limit'] === null ? 0 : (float) $r['limit'];
                $pct   = $r['unlimited'] ? 6 : ($limit > 0 ? min(100, round($used / $limit * 100)) : 0);
                $used_text = ($r['kind'] === 'gb') ? number_format($used, $used >= 10 ? 0 : 1) . ' GB' : number_format($used);
            ?>
            <div class="usage__row<?php echo !empty($r['over']) ? ' usage__row--over' : ''; ?>">
                <span class="usage__label"><?php echo $e($r['label']); ?></span>
                <div class="usage__bar"><div class="usage__fill" style="width:<?php echo $pct; ?>%"></div></div>
                <span class="usage__val"><b><?php echo $e($used_text); ?></b> <?php echo $r['unlimited'] ? '&middot; unlimited' : 'of ' . $e($r['limit_text']); ?></span>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="billing__head<?php echo $has_plan ? ' billing__head--section' : ''; ?>">
        <?php if ($has_plan): ?>
        <h2 class="billing__section-title">Plans</h2>
        <?php elseif ($is_free): ?>
        <h1 class="billing__title">Upgrade your plan</h1>
        <p class="billing__sub">Monthly, cancel anytime. Paid plans lower your take rate and add AI influencers, automations and AI inbox replies.</p>
        <?php else: ?>
        <h1 class="billing__title">Choose a plan</h1>
        <p class="billing__sub">Monthly, cancel anytime. Paid plans lower your take rate and add AI influencers, automations and AI inbox replies.</p>
        <?php endif; ?>
    </div>

    <?php if (empty($ordered)): ?>
        <p class="billing__empty">No plans are available right now.</p>
    <?php else: ?>
    <div class="plans" style="--plans:<?php echo count($ordered); ?>">
        <?php foreach ($ordered as $row): $tier = $row['tier']; $price_id = (string) $row['price_id']; ?>
        <?php
            $is_current  = ($tier['key'] === $tier_key);
            $is_free_row = ($tier['key'] === PlanTiers::FREE_KEY);
            $is_featured = !$has_plan && !empty($tier['recommended']);
            $direction   = ($tier_def && $tier['rank'] > $tier_def['rank']) ? 'up' : 'down';
        ?>
        <div class="plan<?php echo ($is_current && !$is_free) ? ' plan--current' : ''; echo $is_featured ? ' plan--featured' : ''; ?>">
            <?php if ($is_featured): ?><span class="plan__badge plan__badge--pop">Most popular</span><?php endif; ?>
            <?php if ($is_current): ?><span class="plan__badge">Current</span><?php endif; ?>
            <div class="plan__name"><?php echo $e($tier['name']); ?></div>
            <p class="plan__tagline"><?php echo $e($tier['tagline']); ?></p>
            <div class="plan__price">
                <span class="plan__amount">$<?php echo number_format((int) $tier['price']); ?></span>
                <span class="plan__interval">/ month</span>
            </div>
            <dl class="plan__rows">
                <?php foreach (PlanTiers::ROWS as $r): ?>
                <div class="plan__row">
                    <dt><?php echo $e($r['label']); ?></dt>
                    <dd><?php echo $e(PlanTiers::fmt_tier_limit($tier, $r['key'])); ?></dd>
                </div>
                <?php endforeach; ?>
                <?php foreach (PlanTiers::FEATURES as $fk => $flabel): ?>
                <div class="plan__row">
                    <dt><?php echo $flabel; ?></dt>
                    <dd><?php echo PlanTiers::has_feature($tier, $fk) ? 'Included' : '&mdash;'; ?></dd>
                </div>
                <?php endforeach; ?>
            </dl>
            <?php foreach ((array) ($row['addons'] ?? array()) as $ad): ?>
            <p class="plan__addon">Add more AI influencers for $<?php echo (int) $ad['price']; ?>/month each, up to <?php echo (int) $ad['max']; ?>.</p>
            <?php endforeach; ?>
            <?php if ($is_current): ?>
            <button type="button" class="btn btn-secondary plan__btn" disabled>Current Plan</button>
            <?php elseif ($is_free_row): ?>
            <button type="button" class="btn btn-secondary plan__btn plan-downgrade-free">Downgrade to Free</button>
            <?php elseif ($price_id === ''): ?>
            <button type="button" class="btn btn-secondary plan__btn" disabled>Unavailable</button>
            <?php elseif (!$has_plan): ?>
            <button type="button" class="btn <?php echo $is_featured ? 'btn-primary' : 'btn-secondary'; ?> plan__btn plan-choose" data-price-id="<?php echo $e($price_id); ?>">Choose <?php echo $e($tier['name']); ?></button>
            <?php else: ?>
            <button type="button" class="btn btn-secondary plan__btn plan-change" data-price-id="<?php echo $e($price_id); ?>" data-tier-name="<?php echo $e($tier['name']); ?>" data-direction="<?php echo $e($direction); ?>"><?php echo $direction === 'up' ? 'Upgrade to ' : 'Switch to '; ?><?php echo $e($tier['name']); ?></button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="plans__included"><span>Included on every plan:</span> <?php echo implode(' &middot; ', PlanTiers::INCLUDED); ?></p>
    <?php endif; ?>

    <?php if (!empty($this->ai_history)): ?>
    <div class="billing__section">
        <h2 class="billing__section-title">AI credit activity</h2>
        <table class="invoices">
            <thead><tr><th>Date</th><th>Activity</th><th class="invoices__num">Credits</th><th class="invoices__num">Balance</th></tr></thead>
            <tbody>
                <?php foreach ($this->ai_history as $tx): $n = (int) $tx['credits']; ?>
                <tr>
                    <td><?php echo $e(date('M j, Y', strtotime((string) $tx['created_at']))); ?></td>
                    <td><?php echo $e($tx['description'] !== '' ? $tx['description'] : ucfirst(str_replace('_', ' ', (string) $tx['type']))); ?></td>
                    <td class="invoices__num <?php echo $n < 0 ? 'is-neg' : 'is-pos'; ?>"><?php echo $n > 0 ? '+' : ''; echo number_format($n); ?></td>
                    <td class="invoices__num"><?php echo number_format((int) $tx['balance_after']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($this->user['stripe_customer_id'])): ?>
    <div class="billing__section">
        <h2 class="billing__section-title">Payment Methods</h2>
        <?php if (empty($this->cards)): ?>
            <p class="billing__empty">No cards on file.</p>
        <?php else: ?>
        <div class="cards">
            <?php foreach ($this->cards as $card): ?>
            <div class="cardrow">
                <div class="cardrow__main">
                    <i class="fa-regular fa-credit-card cardrow__icon"></i>
                    <span class="cardrow__brand"><?php echo $e($card['brand']); ?></span>
                    <?php if ($card['type'] === 'card'): ?>
                        <span class="cardrow__num">&bull;&bull;&bull;&bull; <?php echo $e($card['last4']); ?></span>
                    <?php elseif (!empty($card['detail'])): ?>
                        <span class="cardrow__num"><?php echo $e($card['detail']); ?></span>
                    <?php endif; ?>
                    <?php if ($card['is_default']): ?><span class="cardrow__default">Default</span><?php endif; ?>
                </div>
                <?php if ($card['type'] === 'card' && $card['exp_year']): ?>
                <span class="cardrow__exp">Expires <?php echo str_pad((string) $card['exp_month'], 2, '0', STR_PAD_LEFT); ?>/<?php echo $e($card['exp_year']); ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="billing__section">
        <h2 class="billing__section-title">Billing History</h2>
        <?php if (empty($this->invoices)): ?>
            <p class="billing__empty">No invoices yet.</p>
        <?php else: ?>
        <table class="invoices">
            <thead><tr><th>Date</th><th>Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($this->invoices as $inv): ?>
                <tr>
                    <td><?php echo $e(date('M j, Y', $inv['created'])); ?></td>
                    <td><?php echo ((int) $inv['amount'] < 0 ? '&minus;$' : '$') . number_format(abs((int) $inv['amount']) / 100, 2); ?><?php echo (int) $inv['amount'] < 0 ? ' credit' : ''; ?></td>
                    <td><span class="inv-status inv-status--<?php echo $e($inv['status']); ?>"><?php echo $e(ucfirst($inv['status'])); ?></span></td>
                    <td class="invoices__action"><?php if (!empty($inv['hosted'])): ?><a href="<?php echo $e($inv['hosted']); ?>" target="_blank" rel="noopener">View</a><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="payment_form" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="payment_element"></div>
                <div class="promo" id="promo_row">
                    <input type="text" class="form-control promo__input" id="promo_code" placeholder="Promo code" autocomplete="off" autocapitalize="characters" spellcheck="false">
                    <button type="button" class="btn btn-secondary promo__btn" id="promo_apply">Apply</button>
                </div>
                <div class="promo__applied" id="promo_applied" style="display:none;"></div>
                <div class="pay-total">
                    <span class="pay-total__label">Due today</span>
                    <span class="pay-total__amount" id="pay_total"></span>
                </div>
                <p class="pay-note" id="pay_note" style="display:none;">Nothing is charged today. Your card is saved for future renewals.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="pay_button">Subscribe</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="credits_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Buy AI credits</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="packs" id="credit_packs">
                    <?php foreach (PlanTiers::AI_PACKS as $d): ?>
                    <button type="button" class="pack" data-dollars="<?php echo (int) $d; ?>"><span class="pack__n"><?php echo (int) $d; ?></span><span class="pack__l">credits</span><span class="pack__p">$<?php echo (int) $d; ?></span></button>
                    <?php endforeach; ?>
                </div>
                <p class="packs__note">$1 per credit. Credits never expire and are used after your monthly plan credits.</p>
                <div id="credit_payment_element"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="credit_pay_button" disabled>Pay</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cancel_form" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cancel Subscription</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Your subscription stays active until the end of the current billing period, then it won't renew. You can resume any time before then.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Subscription</button>
                <button type="button" class="btn btn-danger" id="confirm_cancel">Cancel Subscription</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cancel_now_form" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cancel Immediately</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">This ends your paid plan right now and moves you to Free, with no refund for the rest of the period. Anything over the Free limits is locked, not deleted.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Subscription</button>
                <button type="button" class="btn btn-danger" id="confirm_cancel_now">Cancel Immediately</button>
            </div>
        </div>
    </div>
</div>
