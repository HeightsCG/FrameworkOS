<link rel="stylesheet" href="/css/account-billing.css?v=<?php echo @filemtime(Main::app_path().'/public/css/account-billing.css'); ?>">
<script src="https://js.stripe.com/v3/"></script>
<?php
    $e        = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $acct     = (array) $this->acct;
    $has_plan = !empty($this->has_plan);
    $usage    = is_array($this->usage) ? $this->usage : null;
    $tier_key = (string) ($this->tier ?? '');
    $tier_def = $tier_key !== '' ? PlanTiers::get($tier_key) : null;
    $status   = (string) $acct['status'];
    $past_due = ($status === 'past_due');
    $canceling = !empty($acct['cancel_at_period_end']);
    $pending   = (string) ($acct['pending_plan_key'] ?? '') !== '' ? PlanTiers::get((string) $acct['pending_plan_key']) : null;
    $end_ts    = !empty($acct['current_period_end']) ? strtotime($acct['current_period_end'] . ' UTC') : 0;
    $next      = $this->next;
    $next_ts   = $next ? strtotime($next['at'] . ' UTC') : 0;
    $has_card  = (string) ($acct['stripe_payment_method_id'] ?? '') !== '';
    $grant_n   = $tier_def ? (int) $tier_def['limits']['ai_credits'] : 0;
    $grants_once = $tier_def ? PlanTiers::grants_once($tier_def) : false;
    $is_free   = ($tier_key === PlanTiers::FREE_KEY);
    $ordered   = (array) $this->plan_rows;
    $slot_def  = PlanTiers::addon('influencer_slot');
    $slots_on  = $has_plan && BillingService::takes_slots($tier_key) && $slot_def;
    $slots     = (int) $acct['influencer_slots'];
    $slots_next = $acct['influencer_slots_next'] !== null ? (int) $acct['influencer_slots_next'] : null;
    $pack      = (int) $acct['pack_dollars'];
    $pack_next = $acct['pack_dollars_next'] !== null ? (int) $acct['pack_dollars_next'] : null;
    $money     = function ($c) { return BillingService::money($c); };
    $day       = function ($ts) { return date('M j, Y', $ts); };
    $over_rows = array();
    if ($usage) { foreach ($usage['rows'] as $r) { if (!empty($r['over'])) { $over_rows[] = $r; } } }
    $charge_label = array('subscribe' => 'Plan started', 'renewal' => 'Renewal', 'upgrade' => 'Upgrade', 'slots' => 'Extra AI influencers', 'pack' => 'Credit pack');
?>
<script>
$(function () {

    var stripe = Stripe('<?php echo $e($this->stripe_pk); ?>');
    var card_elements = null;     // Payment Element for saving a card (SetupIntent)
    var pending = null;           // the action waiting on the disclosure modal: {endpoint, body, ok}

    function parse(data) { try { return JSON.parse(data); } catch (e) { return { success: false, message: 'Something went wrong' }; } }
    function reload_after(ms) { setTimeout(function () { window.location.href = '/account/billing'; }, ms || 1100); }
    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

    /* A charge the bank wants the cardholder to confirm (3-D Secure): confirm it here, then record it. */
    function authenticate(o) {
        stripe.confirmCardPayment(o.client_secret, o.payment_method ? { payment_method: o.payment_method } : {}).then(function (res) {
            if (res.error) { toastr.error(res.error.message); reload_after(1800); return; }
            ApiDataSvc.apiCall('post', 'billing_confirm', { charge_id: o.charge_id }, function (data) {
                var r = parse(data);
                if (r.success) { toastr.success(r.message); } else { toastr.error(r.message); }
                reload_after();
            });
        });
    }
    /* Answer of any charging endpoint. */
    function handle(o, $btn) {
        if (!o.success) { toastr.error(o.message); if ($btn) { $btn.prop('disabled', false); } return; }
        if (o.status === 'requires_action') { $('#confirm_modal').modal('hide'); authenticate(o); return; }
        $('#confirm_modal').modal('hide');
        toastr.success(o.message);
        reload_after();
    }
    function mount_card(target, cb) {
        $(target).html('<div class="billing__loading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span></div>');
        ApiDataSvc.apiCall('post', 'billing_card_setup', {}, function (data) {
            var o = parse(data);
            if (!o.success) { toastr.error(o.message); $(target).html(''); return; }
            $(target).html('');
            card_elements = stripe.elements({ clientSecret: o.client_secret });
            card_elements.create('payment').mount(target);
            if (cb) { cb(); }
        });
    }
    /* Save the card in the Payment Element; then run next(). */
    function save_card(next, $btn) {
        stripe.confirmSetup({ elements: card_elements, redirect: 'if_required' }).then(function (res) {
            if (res.error) { toastr.error(res.error.message); $btn.prop('disabled', false); return; }
            ApiDataSvc.apiCall('post', 'billing_card_save', { setup_intent_id: res.setupIntent.id }, function (data) {
                var o = parse(data);
                if (!o.success) { toastr.error(o.message); $btn.prop('disabled', false); return; }
                if (o.status === 'requires_action') { authenticate(o); return; }
                next(o);
            });
        });
    }

    /* ---- the disclosure: what is charged today, what renews, how often, next date, how to cancel ---- */
    var quote_body = null;
    function show_quote(q) {
        var rows = (q.lines || []).map(function (l) { return '<div class="disc__row' + (l.amount.charAt(0) === '-' ? ' disc__row--off' : '') + '"><span>' + esc(l.label) + '</span><span>' + esc(l.amount) + '</span></div>'; }).join('');
        $('#disc_lines').html(rows);
        $('#disc_today').text(q.mode === 'downgrade' ? '$0.00' : q.today);
        $('#confirm_go').text(q.mode === 'downgrade' ? 'Schedule Change' : 'Pay ' + q.today);
    }
    /* Promo code (plan checkouts): re-quote with it; the same code goes with the charge. */
    $('#disc_promo_apply').on('click', function () {
        var code = String($('#disc_promo_code').val() || '').trim();
        if (code === '' || !quote_body || !pending) { return; }
        var $b = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'billing_quote', $.extend({}, quote_body, { promo_code: code }), function (data) {
            var q = parse(data);
            $b.prop('disabled', false);
            if (!q.success) { toastr.error(q.message); return; }
            pending.body.promo_code = code;
            show_quote(q);
            toastr.success((q.promo_label || 'Promo') + ' applied');
        });
    });
    $('#disc_promo_code').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#disc_promo_apply').trigger('click'); } });
    function disclose(body, title, action) {
        ApiDataSvc.apiCall('post', 'billing_quote', body, function (data) {
            var q = parse(data);
            if (!q.success) { toastr.error(q.message); return; }
            pending = action; quote_body = body;
            $('#confirm_title').text(title);
            show_quote(q);
            $('#disc_promo_code').val('');
            $('#disc_promo').prop('hidden', !q.promo_ok);
            $('#disc_terms').text(q.mode === 'downgrade'
                ? 'Your plan changes on ' + q.next_at + '. Nothing is charged today. From then on: ' + q.recurring + ', billed monthly until you cancel.'
                : 'Then ' + q.recurring + ', billed monthly on the same date. Next charge on ' + q.next_at + '. Cancel anytime from Billing; you keep what you paid for until the end of the period.');
            card_elements = null;
            if (q.has_card || q.mode === 'downgrade') {
                $('#disc_card').html(q.mode === 'downgrade' ? '' : 'Charged to ' + esc(q.card) + ' <button type="button" class="disc__change" id="disc_change">Change</button>').show();
                $('#disc_card_form').hide().html('');
            } else {
                $('#disc_card').hide();
                $('#disc_card_form').show();
                mount_card('#disc_card_form');
            }
            $('#confirm_go').prop('disabled', false);
            $('#confirm_modal').modal('show');
        });
    }
    /* Pay with a different card: the card form opens right here and the new card is saved before paying. */
    $('#confirm_modal').on('click', '#disc_change', function () {
        $('#disc_card').hide();
        $('#disc_card_form').show();
        $('#confirm_go').prop('disabled', true);
        mount_card('#disc_card_form', function () { $('#confirm_go').prop('disabled', false); });
    });
    $('#confirm_go').on('click', function () {
        var $b = $(this); if (!pending) { return; }
        // A code typed but not applied: apply it and show the new total first, never charge full price by surprise.
        var typed = String($('#disc_promo_code').val() || '').trim();
        if (!$('#disc_promo').prop('hidden') && typed !== '' && typed.toUpperCase() !== String(pending.body.promo_code || '').toUpperCase()) {
            $('#disc_promo_apply').trigger('click');
            return;
        }
        $b.prop('disabled', true);
        var run = function () { ApiDataSvc.apiCall('post', pending.endpoint, pending.body, function (data) { handle(parse(data), $b); }); };
        if (card_elements) { save_card(run, $b); } else { run(); }
    });

    /* ---- plans ---- */
    $('.plan-go').on('click', function () {
        var $b = $(this);
        disclose({ plan: $b.data('plan') }, $b.data('title'), { endpoint: 'billing_change_plan', body: { plan: $b.data('plan') } });
    });
    $('.plan-cancel, #cancel_plan').on('click', function () {
        Swal.fire({
            title: 'Cancel your plan?',
            html: 'Your plan stays on until <?php echo $end_ts ? $e($day($end_ts)) : 'the end of the period'; ?>. Then you move to Free and no more plan charges are made. Everything you made is kept; anything over the Free limits is locked until you upgrade.',
            showCancelButton: true, reverseButtons: true, confirmButtonText: 'Cancel Plan', cancelButtonText: 'Keep Plan',
            customClass: { confirmButton: 'btn btn-danger', cancelButton: 'btn btn-secondary' }, buttonsStyling: false
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            ApiDataSvc.apiCall('post', 'billing_cancel', {}, function (data) { var o = parse(data); if (o.success) { toastr.success(o.message); reload_after(); } else { toastr.error(o.message); } });
        });
    });
    $('#resume_plan').on('click', function () {
        ApiDataSvc.apiCall('post', 'billing_resume', {}, function (data) { var o = parse(data); if (o.success) { toastr.success(o.message); reload_after(); } else { toastr.error(o.message); } });
    });

    /* ---- extra AI influencers: adding is charged now (prorated); removing applies on the billing date ---- */
    function add_slot() {
        var to = parseInt($('#slots_add').data('quantity'), 10);
        disclose({ slots: to }, 'Add an AI influencer slot', { endpoint: 'billing_set_slots', body: { quantity: to } });
    }
    $('#slots_add').on('click', add_slot);
    $('#slots_remove').on('click', function () {
        var to = parseInt($(this).data('quantity'), 10);
        Swal.fire({
            title: 'Remove a slot?', text: 'You keep it until your next billing date, then it is removed and no longer charged. If you have more AI influencers than slots, the newest are locked, not deleted.',
            showCancelButton: true, reverseButtons: true, confirmButtonText: 'Remove Slot', cancelButtonText: 'Keep It',
            customClass: { confirmButton: 'btn btn-danger', cancelButton: 'btn btn-secondary' }, buttonsStyling: false
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            ApiDataSvc.apiCall('post', 'billing_set_slots', { quantity: to }, function (data) { handle(parse(data)); });
        });
    });

    /* ---- AI credits: pick an amount, buy it once, or tick "Repeat every month" ---- */
    var credit_pick = parseInt($('.copt.is-on').data('dollars'), 10) || 0;   // packs are identified by price
    function credit_label() {
        var repeat = $('#credit_repeat').is(':checked');
        var cur = parseInt($('#credit_repeat').data('current'), 10) || 0;
        var n = (parseInt($('.copt.is-on').data('credits'), 10) || 0).toLocaleString('en-US');
        var txt = !repeat ? 'Buy ' + n + ' Credits for $' + credit_pick
                : (cur > 0 ? (cur === credit_pick ? 'Already Monthly' : 'Switch to ' + n + ' Credits a Month') : 'Get ' + n + ' Credits Every Month');
        $('#credit_go').text(txt).prop('disabled', repeat && cur === credit_pick);
    }
    $('.copts').on('click', '.copt', function () {
        $('.copt').removeClass('is-on').attr('aria-checked', 'false');
        $(this).addClass('is-on').attr('aria-checked', 'true');
        credit_pick = parseInt($(this).data('dollars'), 10);
        credit_label();
    });
    $('#credit_repeat').on('change', credit_label);
    $('#credit_go').on('click', function () {
        if (!$('#credit_repeat').is(':checked')) {   // one-time purchase: the existing card form, amount already chosen
            $('#credits_modal').modal('show');
            $('#credit_packs .pack[data-dollars="' + credit_pick + '"]').trigger('click');
            return;
        }
        if (parseInt($(this).data('has-pack'), 10) === 1) {   // already monthly: change applies on the next billing date
            ApiDataSvc.apiCall('post', 'billing_set_pack', { dollars: credit_pick }, function (data) { handle(parse(data)); });
            return;
        }
        disclose({ pack: credit_pick }, $('.copt.is-on .copt__n').text() + ' every month', { endpoint: 'billing_set_pack', body: { dollars: credit_pick } });
    });
    $('.pack-change').on('click', function () {
        ApiDataSvc.apiCall('post', 'billing_set_pack', { dollars: $(this).data('dollars') }, function (data) { handle(parse(data)); });
    });

    /* ---- card on file ---- */
    $('#update_card').on('click', function () {
        $('#card_save').prop('disabled', true);
        $('#card_modal').modal('show');
        mount_card('#card_element', function () { $('#card_save').prop('disabled', false); });
    });
    $('#card_save').on('click', function () {
        var $b = $(this); $b.prop('disabled', true);
        save_card(function (o) { $('#card_modal').modal('hide'); toastr.success(o.message); reload_after(); }, $b);
    });

    /* ---- a payment waiting on the bank (from the email link, or the notice) ---- */
    function pay_pending(id) {
        ApiDataSvc.apiCall('post', 'billing_pending', { charge_id: id }, function (data) {
            var o = parse(data);
            if (!o.success) { toastr.info(o.message); return; }
            authenticate(o);
        });
    }
    $('#pay_pending').on('click', function () { pay_pending($(this).data('charge')); });
    var qs = new URLSearchParams(window.location.search);

    /* ---- tabs: ?tab=, links that need a tab (?buy=credits, ?add=slot), else the last one used ---- */
    function show_tab(name) {
        if (!$('.btabs__tab[data-tab="' + name + '"]').length) { name = 'usage'; }
        $('.btabs__tab').each(function () { var on = $(this).data('tab') === name; $(this).toggleClass('is-on', on).attr('aria-selected', on ? 'true' : 'false').attr('tabindex', on ? '0' : '-1'); });
        $('.bpanel').each(function () { $(this).prop('hidden', $(this).data('tab') !== name); });
        try { localStorage.setItem('cls_billing_tab', name); } catch (e) {}
    }
    $('.btabs').on('click', '.btabs__tab', function () { show_tab($(this).data('tab')); });
    $('.btabs').on('keydown', '.btabs__tab', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
        var $t = $('.btabs__tab'), i = $t.index(this) + (e.key === 'ArrowRight' ? 1 : -1);
        var $n = $t.eq((i + $t.length) % $t.length); show_tab($n.data('tab')); $n.trigger('focus');
    });
    var first = qs.get('tab') || (qs.get('buy') ? 'credits' : (qs.get('add') ? 'plan' : ''));
    if (!first) { try { first = localStorage.getItem('cls_billing_tab') || 'usage'; } catch (e) { first = 'usage'; } }
    show_tab(first);
    if (qs.get('pay')) { pay_pending(qs.get('pay')); }
    if (qs.get('add') === 'slot' && $('#slots_add').length && !$('#slots_add').prop('disabled')) { add_slot(); }

    /* ---- one-time AI credits (existing flow) ---- */
    var credit_elements = null;
    if (qs.get('buy') === 'credits') { $('#credits_modal').modal('show'); }   // "Buy credits" links from the generate screens
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
});
</script>

<div class="billing">

    <?php if (empty($this->is_creator)): ?>
    <div class="billing__head"><h1 class="billing__title">Billing</h1></div>
    <div class="blist">
        <div class="blist__row">
            <div class="blist__body blist__body--inline">
                <div class="blist__grow">
                    <div class="blist__value">Plans are for creators</div>
                    <div class="blist__meta">Become a creator to sell on your page and choose a plan. Free needs no card.</div>
                </div>
                <a href="/account/settings?section=creator" class="btn btn-primary">Become a Creator</a>
            </div>
        </div>
    </div>
</div>
<?php return; endif; ?>

    <?php if ($this->awaiting): ?>
    <div class="billing__notice billing__notice--action">
        <i class="fa-solid fa-shield-halved"></i>
        <div><p><b>Your bank needs you to confirm a payment of <?php echo $e($money((int) $this->awaiting['amount_cents'])); ?>.</b> Your plan stays on while you do.</p></div>
        <button type="button" class="btn btn-primary" id="pay_pending" data-charge="<?php echo (int) $this->awaiting['id']; ?>">Confirm Payment</button>
    </div>
    <?php elseif ($past_due): ?>
    <div class="billing__notice billing__notice--action">
        <i class="fa-solid fa-circle-exclamation"></i>
        <div><p><b>Your last payment didn't go through.</b> <?php if (!empty($acct['next_retry_at'])): ?>We'll try again on <?php echo $e($day(strtotime($acct['next_retry_at'] . ' UTC'))); ?>. <?php endif; ?>Update your card and we'll retry right away.</p></div>
        <button type="button" class="btn btn-primary" onclick="$('.btabs__tab[data-tab=payments]').trigger('click'); $('#update_card').trigger('click')">Update Card</button>
    </div>
    <?php endif; ?>

<?php
    $cur_key = $has_plan ? $tier_key : PlanTiers::FREE_KEY;
    $cur_cents = BillingService::plan_cents($cur_key);
    $next_up = '';   // the one plan to push: the cheapest offered plan above the current one
    foreach ($ordered as $row) { if (BillingService::plan_cents($row['tier']['key']) > $cur_cents && empty($row['tier']['retired'])) { $next_up = $row['tier']['key']; break; } }
?>
    <div class="billing__head">
        <h1 class="billing__title">Billing</h1>
    </div>


    <nav class="btabs" role="tablist" aria-label="Billing">
        <button type="button" class="btabs__tab" role="tab" data-tab="usage" id="btab-usage" aria-controls="bpanel-usage">Usage</button>
        <button type="button" class="btabs__tab" role="tab" data-tab="plan" id="btab-plan" aria-controls="bpanel-plan">Plan</button>
        <button type="button" class="btabs__tab" role="tab" data-tab="credits" id="btab-credits" aria-controls="bpanel-credits">AI Credits</button>
        <button type="button" class="btabs__tab" role="tab" data-tab="payments" id="btab-payments" aria-controls="bpanel-payments">Payments<?php if ($past_due || $this->awaiting): ?> <span class="btabs__dot" aria-label="Needs attention"></span><?php endif; ?></button>
    </nav>

    <section class="bpanel" id="bpanel-usage" role="tabpanel" aria-labelledby="btab-usage" data-tab="usage" hidden>
    <?php if ($usage): ?>
    <div>
        <div class="usage">
            <?php foreach ($usage['rows'] as $r): if (in_array($r['kind'], array('credits', 'percent'), true)) { continue; } ?>
            <?php if ($r['limit'] !== null && (int) $r['limit'] < 0): ?>
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
                <span class="usage__val"><b><?php echo $e($used_text); ?></b> <?php echo $r['unlimited'] ? 'of unlimited' : 'of ' . $e($r['limit_text']); ?></span>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    </section>

    <section class="bpanel" id="bpanel-plan" role="tabpanel" aria-labelledby="btab-plan" data-tab="plan">
    <?php if (!empty($_GET['welcome']) && !$has_plan): ?>
    <div class="billing__welcome">
        <div>
            <div class="billing__welcome-title">You're a creator. Choose your plan.</div>
            <div class="billing__welcome-text">Paid plans lower your fee and add AI influencers, automations and AI replies. Or start on Free, no card needed.</div>
        </div>
        <a href="/account/settings?section=creator" class="btn btn-secondary">Continue on Free</a>
    </div>
    <?php endif; ?>
    <div class="plans" style="--plans:<?php echo count($ordered); ?>">
        <?php foreach ($ordered as $row): $tier = $row['tier']; ?>
        <?php
            $is_current  = ($tier['key'] === $cur_key);
            $is_free_row = ($tier['key'] === PlanTiers::FREE_KEY);
            $up          = BillingService::plan_cents($tier['key']) > $cur_cents;
            $is_pending  = $pending && $pending['key'] === $tier['key'];
            $locked      = $past_due;
        ?>
        <div class="plan<?php echo $is_current ? ' plan--mine' : ''; ?>">
            <?php if ($is_current): ?><span class="plan__badge plan__badge--mine">Your plan</span><?php endif; ?>
            <div class="plan__name"><?php echo $e($tier['name']); ?></div>
            <div class="plan__price">
                <span class="plan__amount">$<?php echo number_format((int) $tier['price']); ?></span>
                <span class="plan__interval">/ month</span>
            </div>
            <dl class="plan__rows">
                <?php foreach (PlanTiers::ROWS as $r): ?>
                <div class="plan__row"><dt><?php echo $e($r['label']); ?></dt><dd><?php echo $e(PlanTiers::fmt_tier_limit($tier, $r['key'])); ?></dd></div>
                <?php endforeach; ?>
                <?php foreach (PlanTiers::FEATURES as $fk => $flabel): ?>
                <div class="plan__row"><dt><?php echo $flabel; ?></dt><dd><?php echo PlanTiers::has_feature($tier, $fk) ? 'Included' : '&mdash;'; ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <?php foreach ((array) ($row['addons'] ?? array()) as $ad): ?>
            <p class="plan__addon">Add more AI influencers for $<?php echo (int) $ad['price']; ?>/month each, up to <?php echo (int) $ad['max']; ?>.</p>
            <?php endforeach; ?>

            <?php if ($is_current): ?>
            <div class="plan__mine">
                <?php if ($past_due): ?>
                    <p class="plan__mine-line plan__mine-line--bad">Payment failed. Update your card in Payments.</p>
                <?php elseif ($has_plan && $canceling): ?>
                    <p class="plan__mine-line">Ends <?php echo $e($day($end_ts)); ?>. Then you're on Free.</p>
                <?php elseif ($has_plan && $pending): ?>
                    <p class="plan__mine-line">Changes to <?php echo $e($pending['name']); ?> on <?php echo $e($day($end_ts)); ?>.</p>
                <?php elseif ($next): ?>
                    <p class="plan__mine-line">Renews <?php echo $e($day($next_ts)); ?> for <b><?php echo $e($money($next['total'])); ?></b></p>
                    <?php if (count($next['lines']) > 1): ?>
                    <ul class="plan__mine-items"><?php foreach ($next['lines'] as $l): ?><li><span><?php echo $e($l[0]); ?></span><span><?php echo $e($money($l[1])); ?></span></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="plan__mine-line">Free forever. No card needed.</p>
                <?php endif; ?>
            </div>
            <?php if ($has_plan && ($canceling || $pending)): ?>
            <button type="button" class="btn btn-secondary plan__btn" id="resume_plan">Keep <?php echo $e($tier['name']); ?></button>
            <?php elseif ($has_plan): ?>
            <button type="button" class="btn btn-secondary plan__btn plan__btn--quiet" id="cancel_plan">Cancel Plan</button>
            <?php endif; ?>
            <?php elseif ($is_pending || ($is_free_row && $canceling)): ?>
            <button type="button" class="btn btn-secondary plan__btn" disabled>Starts <?php echo $e(date('M j', $end_ts)); ?></button>
            <?php elseif ($is_free_row): ?>
            <button type="button" class="btn btn-secondary plan__btn plan-cancel" <?php echo $locked ? 'disabled' : ''; ?>>Downgrade to Free</button>
            <?php else: ?>
            <?php $label = ($up ? 'Upgrade to ' : 'Switch to ') . $tier['name']; ?>
            <button type="button" class="btn <?php echo $tier['key'] === $next_up ? 'btn-primary' : 'btn-secondary'; ?> plan__btn plan-go" data-plan="<?php echo $e($tier['key']); ?>" data-title="<?php echo $e($label); ?>" <?php echo $locked ? 'disabled' : ''; ?>><?php echo $e($label); ?></button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
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

    <?php if ($slots_on): ?>
    <div class="billing__section">
        <h2 class="billing__section-title">Extra AI influencers</h2>
        <div class="billing__row">
            <div>
                <div class="billing__row-title"><?php echo $slots; ?> extra &middot; <?php echo (int) $tier_def['limits']['influencers'] + $slots; ?> AI influencers in total</div>
                <div class="billing__row-meta"><?php echo $e($money(BillingService::slot_cents())); ?> / month each, up to <?php echo (int) $slot_def['max']; ?>. Adding one is charged now for the rest of this period.<?php if ($slots_next !== null): ?> Drops to <?php echo $slots_next; ?> on <?php echo $e($day($next_ts ?: $end_ts)); ?>.<?php endif; ?></div>
            </div>
            <div class="billing__row-actions">
                <?php $billed = $slots_next !== null ? $slots_next : $slots; ?>
                <button type="button" class="btn btn-secondary" id="slots_remove" data-quantity="<?php echo max(0, $billed - 1); ?>" <?php echo ($billed <= 0 || $canceling || $past_due) ? 'disabled' : ''; ?> aria-label="Remove a slot"><i class="fa-solid fa-minus"></i></button>
                <button type="button" class="btn btn-secondary" id="slots_add" data-quantity="<?php echo $slots + 1; ?>" <?php echo ($slots >= (int) $slot_def['max'] || $canceling || $past_due) ? 'disabled' : ''; ?>><i class="fa-solid fa-plus"></i> Add Slot</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    </section>

    <section class="bpanel" id="bpanel-credits" role="tabpanel" aria-labelledby="btab-credits" data-tab="credits" hidden>
        <?php
            $b = $this->buckets;
            $reset = $next_ts ?: $end_ts;
            $monthly = $pack > 0 ? ($pack_next !== null && $pack_next > 0 ? $pack_next : $pack) : 0;
            $packs = array_map('intval', PlanTiers::AI_PACKS);
            $pick = $monthly ?: ($packs[1] ?? $packs[0]);
        ?>
        <div class="ccard">
            <div class="ccard__big"><?php echo number_format((int) $b['total']); ?> <span>credits</span></div>
            <?php if ((int) $b['plan'] > 0): ?><p class="ccard__text"><?php echo number_format((int) $b['plan']); ?> of these come with your plan and reset on <?php echo $e(date('M j', $reset)); ?>. Credits you buy never expire.</p><?php endif; ?>

            <?php if ($pack > 0): ?>
            <div class="ccard__monthly">
                <span>You get <b><?php echo number_format(PlanTiers::pack_credits($pack)); ?> credits every month</b> for <?php echo $e($money((int) ($acct['pack_price_cents'] ?? $pack * 100))); ?><?php echo $next_ts ? ', next on ' . $e(date('M j', $next_ts)) : ''; ?>.<?php if ($pack_next === 0): ?> Stops after that.<?php elseif ($pack_next !== null): ?> Changes to <?php echo number_format(PlanTiers::pack_credits($pack_next)); ?> then.<?php endif; ?></span>
                <?php if ($pack_next !== 0): ?><button type="button" class="ccard__stop pack-change" data-dollars="0" <?php echo $past_due ? 'disabled' : ''; ?>>Stop</button><?php endif; ?>
            </div>
            <?php endif; ?>

            <h2 class="ccard__title">Buy credits</h2>
            <div class="copts" role="radiogroup" aria-label="How many credits">
                <?php foreach ($packs as $d): ?>
                <button type="button" class="copt<?php echo $d === $pick ? ' is-on' : ''; ?>" role="radio" aria-checked="<?php echo $d === $pick ? 'true' : 'false'; ?>" data-dollars="<?php echo $d; ?>" data-credits="<?php echo PlanTiers::pack_credits($d); ?>">
                    <span class="copt__n"><?php echo number_format(PlanTiers::pack_credits($d)); ?> credits</span><span class="copt__p">$<?php echo $d; ?></span>
                </button>
                <?php endforeach; ?>
            </div>
            <label class="ccard__repeat"><input type="checkbox" class="form-check-input" id="credit_repeat" data-current="<?php echo (int) $monthly; ?>" <?php echo $past_due ? 'disabled' : ''; ?>> Repeat every month</label>
            <button type="button" class="btn btn-primary ccard__btn" id="credit_go" data-has-pack="<?php echo $pack > 0 ? 1 : 0; ?>">Buy <?php echo number_format(PlanTiers::pack_credits($pick)); ?> Credits for $<?php echo $pick; ?></button>
        </div>
    </section>

    <section class="bpanel" id="bpanel-payments" role="tabpanel" aria-labelledby="btab-payments" data-tab="payments" hidden>
        <div class="blist">
            <div class="blist__row">
                <div class="blist__label">Card</div>
                <div class="blist__body blist__body--inline">
                    <div class="blist__grow">
                        <?php if ($has_card): ?>
                        <div class="blist__value"><?php echo $e($acct['card_brand']); ?><?php if ((string) $acct['card_last4'] !== ''): ?> ending in <?php echo $e($acct['card_last4']); ?><?php endif; ?></div>
                        <div class="blist__meta"><?php echo (string) $acct['card_exp'] !== '' ? 'Expires ' . $e($acct['card_exp']) : 'Saved'; ?></div>
                        <?php else: ?>
                        <div class="blist__value">No card on file</div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-secondary" id="update_card"><?php echo $has_card ? 'Update Card' : 'Add Card'; ?></button>
                </div>
            </div>
        </div>

        <div class="billing__section">
            <h2 class="billing__section-title">Charges</h2>
        <?php if (empty($this->charges) && empty($this->invoices)): ?>
            <p class="billing__empty">No charges yet.</p>
        <?php else: ?>
        <table class="invoices">
            <thead><tr><th>Date</th><th>Items</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ((array) $this->charges as $c): $items = (array) json_decode((string) $c['line_items'], true); ?>
                <tr>
                    <td><?php echo $e(date('M j, Y', strtotime($c['created_at'] . ' UTC'))); ?></td>
                    <td><?php echo $e(implode(', ', array_column($items, 'label')) ?: ($charge_label[$c['kind']] ?? $c['kind'])); ?></td>
                    <td><?php echo $e($money((int) $c['amount_cents'])); ?></td>
                    <?php $st = (string) $c['status']; ?>
                    <td><span class="inv-status inv-status--<?php echo $st === 'succeeded' ? 'paid' : ($st === 'failed' ? 'void' : 'open'); ?>" <?php echo $st === 'failed' && $c['failure_reason'] ? 'title="' . $e($c['failure_reason']) . '"' : ''; ?>><?php echo $st === 'succeeded' ? 'Paid' : ($st === 'failed' ? 'Failed' : ($st === 'requires_action' ? 'Needs confirmation' : 'Pending')); ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ((array) $this->invoices as $inv): ?>
                <tr>
                    <td><?php echo $e(date('M j, Y', $inv['created'])); ?></td>
                    <td>Earlier plan invoice<?php if (!empty($inv['hosted'])): ?> &middot; <a href="<?php echo $e($inv['hosted']); ?>" target="_blank" rel="noopener">View</a><?php endif; ?></td>
                    <td><?php echo ((int) $inv['amount'] < 0 ? '&minus;$' : '$') . number_format(abs((int) $inv['amount']) / 100, 2); ?></td>
                    <td><span class="inv-status inv-status--<?php echo $e($inv['status']); ?>"><?php echo $e(ucfirst($inv['status'])); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        </div>
    </section>
</div>

<div class="modal fade" id="confirm_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirm_title">Confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="disc" id="disc_lines"></div>
                <div class="disc__row disc__row--total"><span>Due today</span><span id="disc_today"></span></div>
                <div class="promo" id="disc_promo" hidden>
                    <input type="text" class="form-control promo__input" id="disc_promo_code" placeholder="Promo code" autocomplete="off" autocapitalize="characters" spellcheck="false">
                    <button type="button" class="btn btn-secondary promo__btn" id="disc_promo_apply">Apply</button>
                </div>
                <p class="disc__terms" id="disc_terms"></p>
                <p class="disc__card" id="disc_card"></p>
                <div id="disc_card_form"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirm_go">Confirm</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="card_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update card</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="card_element"></div>
                <p class="disc__terms">This card is charged for your plan, add-ons and monthly pack on each billing date.<?php echo $past_due ? ' Your overdue payment is retried as soon as it is saved.' : ''; ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="card_save" disabled>Save Card</button>
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
                    <button type="button" class="pack" data-dollars="<?php echo (int) $d; ?>"><span class="pack__n"><?php echo number_format(PlanTiers::pack_credits($d)); ?></span><span class="pack__l">credits</span><span class="pack__p">$<?php echo (int) $d; ?></span></button>
                    <?php endforeach; ?>
                </div>
                <p class="packs__note">One-time purchase. Bought credits never expire.</p>
                <div id="credit_payment_element"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="credit_pay_button" disabled>Pay</button>
            </div>
        </div>
    </div>
</div>
