<link rel="stylesheet" href="/css/account-billing.css?v=<?php echo @filemtime(Main::app_path().'/public/css/account-billing.css'); ?>">
<script src="https://js.stripe.com/v3/"></script>
<script>
$(function () {

    var stripe    = Stripe('<?php echo htmlspecialchars((string) $this->stripe_pk, ENT_QUOTES, 'UTF-8'); ?>');
    var elements  = null;
    var price_id  = '';
    var paid      = false;
    var pay_mode  = 'payment';   // 'payment' | 'setup' ($0 first invoice, card saved for renewals)

    function fmt_amount(cents, currency) {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: (currency || 'usd').toUpperCase() }).format(cents / 100);
    }

    // Create (or re-create with a promo code) the pending subscription and mount the payment form.
    function start_payment(promo_code) {
        $('#pay_button').prop('disabled', true);
        ApiDataSvc.apiCall('post', 'create_subscription', { price_id: price_id, promo_code: promo_code || '' }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) {
                toastr.error(o.message);
                $('#pay_button').prop('disabled', elements === null);
                $('#promo_apply').prop('disabled', false);
                return;
            }
            pay_mode = o.mode || 'payment';
            if (pay_mode === 'none') {
                // Promo covered the whole first invoice and no card is needed — it's live.
                paid = true;
                $('#payment_form').modal('hide');
                ApiDataSvc.apiCall('post', 'sync_subscription', {}, function () {
                    toastr.success('Your subscription is active');
                    setTimeout(function () { window.location.href = '/account/billing'; }, 1200);
                });
                return;
            }
            $('#payment_element').html('');
            elements = stripe.elements({ clientSecret: o.client_secret });
            elements.create('payment').mount('#payment_element');
            $('#pay_button').prop('disabled', false);
            $('#promo_apply').prop('disabled', false);
            $('#pay_total').text(fmt_amount(o.amount_due, o.currency));
            $('#pay_note').toggle(pay_mode === 'setup');
            if (o.promo_label) {
                $('#promo_applied').text(o.promo_label + ' applied').show();
                $('#promo_row').hide();
            } else {
                $('#promo_applied').hide();
            }
            $('#payment_form').modal('show');
        });
    }

    $('.plan-choose').on('click', function () {
        price_id = $(this).data('price-id');
        paid     = false;
        elements = null;
        $('#promo_code').val('');
        $('#promo_row').show();
        $('#promo_applied').hide();
        start_payment('');
    });

    $('#promo_apply').on('click', function () {
        var code = String($('#promo_code').val() || '').trim();
        if (code === '') { return; }
        $('#promo_apply').prop('disabled', true);
        start_payment(code);
    });
    $('#promo_code').on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); $('#promo_apply').trigger('click'); }
    });

    $('#pay_button').on('click', function () {
        if (!elements) { return; }
        $('#pay_button').prop('disabled', true);

        var method = (pay_mode === 'setup') ? 'confirmSetup' : 'confirmPayment';
        stripe[method]({ elements: elements, redirect: 'if_required' }).then(function (result) {
            if (result.error) {
                $('#pay_button').prop('disabled', false);
                toastr.error(result.error.message);
                return;
            }
            paid = true;
            ApiDataSvc.apiCall('post', 'sync_subscription', {}, function () {
                toastr.success('Your subscription is active');
                setTimeout(function () {
                    window.location.href = '/account/billing';
                }, 1200);
            });
        });
    });

    // Closing the form without paying must not leave a half-created subscription behind.
    $('#payment_form').on('hidden.bs.modal', function () {
        if (paid) { return; }
        elements = null;
        ApiDataSvc.apiCall('post', 'abandon_subscription', {}, function () {});
    });

    $('#cancel_subscription').on('click', function () {
        $('#cancel_form').modal('show');
    });

    $('#confirm_cancel').on('click', function () {
        $('#cancel_form').modal('hide');
        ApiDataSvc.apiCall('post', 'cancel_subscription', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = '/account/billing'; }, 1200);
            } else {
                toastr.error(o.message);
            }
        });
    });

    $('#resume_subscription').on('click', function () {
        ApiDataSvc.apiCall('post', 'resume_subscription', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = '/account/billing'; }, 1200);
            } else {
                toastr.error(o.message);
            }
        });
    });

    $('#cancel_now').on('click', function () {
        $('#cancel_now_form').modal('show');
    });

    $('#confirm_cancel_now').on('click', function () {
        $('#cancel_now_form').modal('hide');
        ApiDataSvc.apiCall('post', 'cancel_now_subscription', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = '/account/billing'; }, 1200);
            } else {
                toastr.error(o.message);
            }
        });
    });

});
</script>

<div class="billing">

    <?php
        $current_plan_name = '';
        if (!empty($this->user['stripe_price_id'])) {
            foreach ($this->plans as $p) {
                if ($p['price_id'] === $this->user['stripe_price_id']) {
                    $current_plan_name = $p['name'];
                }
            }
        }
    ?>

    <?php
        // Only a subscription Stripe is actually billing counts as the current plan. An
        // "incomplete" one (payment form opened, never paid) is not a plan at all.
        $has_plan = in_array((string) ($this->user['subscription_status'] ?? ''), array('active', 'trialing', 'past_due'), true);
    ?>
    <?php if ($has_plan): ?>
    <?php $canceling = !empty($this->user['subscription_cancel_at_period_end']); ?>
    <div class="billing__current">
        <div>
            <div class="billing__current-name"><?php echo htmlspecialchars($current_plan_name !== '' ? $current_plan_name : 'Subscription', ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if (!empty($this->user['subscription_current_period_end'])): ?>
            <div class="billing__current-meta"><?php echo $canceling ? 'Cancels on ' : 'Renews on '; ?><?php echo htmlspecialchars(date('M j, Y', strtotime($this->user['subscription_current_period_end'])), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>
        <div class="billing__current-actions">
            <span class="billing__status billing__status--<?php echo $canceling ? 'canceling' : 'active'; ?>"><?php echo $canceling ? 'Canceling' : htmlspecialchars((string) $this->user['subscription_status'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($canceling): ?>
            <button type="button" class="btn btn-secondary" id="resume_subscription">Resume Subscription</button>
            <button type="button" class="btn btn-danger" id="cancel_now">Cancel Immediately</button>
            <?php else: ?>
            <button type="button" class="btn btn-secondary" id="cancel_subscription">Cancel Subscription</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="billing__head">
        <h1 class="billing__title"><?php echo $has_plan ? 'Change Plan' : 'Choose a Plan'; ?></h1>
        <p class="billing__sub">Pick the plan that fits. Upgrade or downgrade anytime.</p>
    </div>

    <?php if (empty($this->plans)): ?>
        <p class="text-muted">No plans are available right now.</p>
    <?php else: ?>
    <?php
        // Attach each Stripe plan to its code-defined tier (matched by product name)
        // and order the ladder low → high. All tier copy/features come from PlanTiers.
        $ordered = array();
        foreach ($this->plans as $plan) {
            $tier = PlanTiers::get(PlanTiers::match($plan['name']));
            $ordered[] = array('plan' => $plan, 'tier' => $tier, 'rank' => $tier ? $tier['rank'] : 99);
        }
        usort($ordered, function ($a, $b) { return $a['rank'] - $b['rank']; });
    ?>
    <div class="plans">
        <?php foreach ($ordered as $row): $plan = $row['plan']; $tier = $row['tier']; ?>
        <?php
            $is_current  = ($plan['price_id'] === ($this->user['stripe_price_id'] ?? '') && $has_plan);
            $is_featured = $tier && !empty($tier['recommended']) && !$is_current;
        ?>
        <div class="plan<?php echo $is_current ? ' plan--current' : ''; echo $is_featured ? ' plan--featured' : ''; ?>">
            <?php if ($is_featured): ?><span class="plan__badge plan__badge--pop">Most popular</span><?php endif; ?>
            <?php if ($is_current): ?><span class="plan__badge">Current</span><?php endif; ?>
            <div class="plan__name"><?php echo htmlspecialchars($tier ? $tier['name'] : $plan['name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($tier): ?>
            <p class="plan__tagline"><?php echo htmlspecialchars($tier['tagline'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php elseif (!empty($plan['description'])): ?>
            <p class="plan__desc"><?php echo htmlspecialchars($plan['description'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <div class="plan__price">
                <span class="plan__amount">$<?php echo number_format($plan['amount'] / 100, ($plan['amount'] % 100 === 0) ? 0 : 2); ?></span>
                <span class="plan__interval">/ <?php echo htmlspecialchars($plan['interval'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php if ($tier): ?>
            <ul class="plan__features">
                <?php foreach ($tier['features'] as $f): $is_head = (strpos($f, 'Everything in') === 0); ?>
                <li class="plan__feat<?php echo $is_head ? ' plan__feat--head' : ''; ?>"><?php if (!$is_head): ?><i class="fa-solid fa-check plan__feat-ic"></i> <?php endif; ?><?php echo $f; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <button type="button" class="btn <?php echo $is_featured ? 'btn-primary' : 'btn-secondary'; ?> plan__btn plan-choose" data-price-id="<?php echo htmlspecialchars($plan['price_id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $is_current ? 'disabled' : ''; ?>>
                <?php echo $is_current ? 'Current plan' : ('Choose ' . htmlspecialchars($tier ? $tier['name'] : $plan['name'], ENT_QUOTES, 'UTF-8')); ?>
            </button>
        </div>
        <?php endforeach; ?>
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
                    <span class="cardrow__brand"><?php echo htmlspecialchars($card['brand'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($card['type'] === 'card'): ?>
                        <span class="cardrow__num">&bull;&bull;&bull;&bull; <?php echo htmlspecialchars($card['last4'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php elseif (!empty($card['detail'])): ?>
                        <span class="cardrow__num"><?php echo htmlspecialchars($card['detail'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <?php if ($card['is_default']): ?><span class="cardrow__default">Default</span><?php endif; ?>
                </div>
                <?php if ($card['type'] === 'card' && $card['exp_year']): ?>
                <span class="cardrow__exp">Expires <?php echo str_pad((string) $card['exp_month'], 2, '0', STR_PAD_LEFT); ?>/<?php echo htmlspecialchars((string) $card['exp_year'], ENT_QUOTES, 'UTF-8'); ?></span>
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
            <thead>
                <tr><th>Date</th><th>Amount</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($this->invoices as $inv): ?>
                <tr>
                    <td><?php echo htmlspecialchars(date('M j, Y', $inv['created']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>$<?php echo number_format($inv['amount'] / 100, 2); ?></td>
                    <td><span class="inv-status inv-status--<?php echo htmlspecialchars($inv['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($inv['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="invoices__action"><?php if (!empty($inv['hosted'])): ?><a href="<?php echo htmlspecialchars($inv['hosted'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">View</a><?php endif; ?></td>
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

<div class="modal fade" id="cancel_form" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cancel Subscription</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Your subscription will remain active until the end of the current billing period, then it won't renew. You can resume any time before then.</p>
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
                <p class="mb-0">This ends your subscription right now and you'll lose access immediately. This can't be undone &mdash; you'd need to subscribe again.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Subscription</button>
                <button type="button" class="btn btn-danger" id="confirm_cancel_now">Cancel Immediately</button>
            </div>
        </div>
    </div>
</div>
