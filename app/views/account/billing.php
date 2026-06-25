<link rel="stylesheet" href="/css/account-billing.css">
<script src="https://js.stripe.com/v3/"></script>
<script>
$(function () {

    var stripe   = Stripe('<?php echo htmlspecialchars((string) $this->stripe_pk, ENT_QUOTES, 'UTF-8'); ?>');
    var elements = null;

    $('.plan-choose').on('click', function () {
        var price_id = $(this).data('price-id');

        ApiDataSvc.apiCall('post', 'create_subscription', { price_id: price_id }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) {
                toastr.error(o.message);
                return;
            }
            $('#payment_element').html('');
            $('#pay_button').prop('disabled', false);
            elements = stripe.elements({ clientSecret: o.client_secret });
            elements.create('payment').mount('#payment_element');
            $('#payment_form').modal('show');
        });
    });

    $('#pay_button').on('click', function () {
        if (!elements) { return; }
        $('#pay_button').prop('disabled', true);

        stripe.confirmPayment({ elements: elements, redirect: 'if_required' }).then(function (result) {
            if (result.error) {
                $('#pay_button').prop('disabled', false);
                toastr.error(result.error.message);
                return;
            }
            ApiDataSvc.apiCall('post', 'sync_subscription', {}, function () {
                toastr.success('Your subscription is active');
                setTimeout(function () {
                    window.location.href = '/account/billing';
                }, 1200);
            });
        });
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

    <?php if (!empty($this->user['subscription_status'])): ?>
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
            <button type="button" class="btn btn-secondary" id="resume_subscription">Resume</button>
            <?php else: ?>
            <button type="button" class="btn btn-secondary" id="cancel_subscription">Cancel subscription</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="billing__head">
        <h1 class="billing__title"><?php echo !empty($this->user['subscription_status']) ? 'Change plan' : 'Choose a plan'; ?></h1>
        <p class="billing__sub">Pick the plan that fits. Upgrade or downgrade anytime.</p>
    </div>

    <?php if (empty($this->plans)): ?>
        <p class="text-muted">No plans are available right now.</p>
    <?php else: ?>
    <div class="plans">
        <?php foreach ($this->plans as $plan): ?>
        <?php $is_current = ($plan['price_id'] === ($this->user['stripe_price_id'] ?? '') && $this->user['subscription_status'] === 'active'); ?>
        <div class="plan<?php echo $is_current ? ' plan--current' : ''; ?>">
            <?php if ($is_current): ?><span class="plan__badge">Current</span><?php endif; ?>
            <div class="plan__name"><?php echo htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if (!empty($plan['description'])): ?>
            <p class="plan__desc"><?php echo htmlspecialchars($plan['description'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <div class="plan__price">
                <span class="plan__amount">$<?php echo number_format($plan['amount'] / 100, ($plan['amount'] % 100 === 0) ? 0 : 2); ?></span>
                <span class="plan__interval">/ <?php echo htmlspecialchars($plan['interval'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <button type="button" class="btn btn-primary plan__btn plan-choose" data-price-id="<?php echo htmlspecialchars($plan['price_id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $is_current ? 'disabled' : ''; ?>>
                <?php echo $is_current ? 'Current plan' : 'Choose'; ?>
            </button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($this->user['stripe_customer_id'])): ?>
    <div class="billing__section">
        <h2 class="billing__section-title">Payment methods</h2>
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
        <h2 class="billing__section-title">Billing history</h2>
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
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="payment_element"></div>
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
                <h5 class="modal-title">Cancel subscription</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Your subscription will remain active until the end of the current billing period, then it won't renew. You can resume any time before then.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep subscription</button>
                <button type="button" class="btn btn-danger" id="confirm_cancel">Cancel subscription</button>
            </div>
        </div>
    </div>
</div>
