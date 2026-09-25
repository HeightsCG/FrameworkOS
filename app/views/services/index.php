<link rel="stylesheet" href="/css/services.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/services.css'); ?>">
<?php
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$method_label = array(
    'zoom' => 'Zoom', 'teams' => 'Microsoft Teams', 'meet' => 'Google Meet', 'webex' => 'Webex',
    'discord' => 'Discord', 'phone' => 'Phone', 'in_person' => 'In person', 'custom' => 'Custom / other',
);
$services = $this->services;
?>
<div class="sv">
    <header class="sv__head">
        <div>
            <h1 class="sv__title">Services</h1>
            <p class="sv__sub">Sell consulting, coaching, sessions, or custom work. The platform handles the listing and payment — you provide an external scheduling link (Calendly, Acuity…) and delivery details, revealed to buyers after purchase.</p>
        </div>
        <button type="button" class="sv-btn sv-btn--primary" id="svCreate"><i class="fa-solid fa-plus"></i> Create Service</button>
    </header>

    <?php if (empty($services)): ?>
    <div class="sv-empty">
        <span class="sv-empty__ic"><i class="fa-regular fa-handshake"></i></span>
        <h2 class="sv-empty__t">No Services Yet</h2>
        <p class="sv-empty__x">Create your first service — a consult, coaching session, design package, or review — and sell it from your profile.</p>
    </div>
    <?php else: ?>
    <div class="sv-table">
        <div class="sv-table__head"><span>Service</span><span>Price</span><span>Duration</span><span>Delivery</span><span>Sold</span><span>Status</span></div>
        <div class="sv-table__body" id="svBody">
            <?php foreach ($services as $s): ?>
            <div class="sv-row" role="button" tabindex="0" data-sv='<?php echo $e(json_encode(array(
                'id' => (int) $s['id'], 'name' => $s['name'], 'description' => $s['description'],
                'price' => number_format(((int) $s['price_credits']) / 10, 2, '.', ''),
                'duration_min' => (int) $s['duration_min'], 'delivery_method' => $s['delivery_method'],
                'scheduling_url' => $s['scheduling_url'], 'delivery_details' => $s['delivery_details'],
                'capacity' => (int) $s['capacity'], 'category' => $s['category'], 'refund_policy' => $s['refund_policy'],
                'status' => $s['status'],
            ))); ?>'>
                <div class="sv-cell sv-cell--title"><span class="sv-cell__name"><?php echo $e($s['name']); ?></span><?php if ((string) $s['category'] !== ''): ?><span class="sv-cell__meta"><?php echo $e($s['category']); ?></span><?php endif; ?></div>
                <div class="sv-cell sv-cell--muted"><?php echo (int) $s['price_credits'] > 0 ? '$' . number_format(((int) $s['price_credits']) / 10, 2) : 'Free'; ?></div>
                <div class="sv-cell sv-cell--muted"><?php echo (int) $s['duration_min'] > 0 ? (int) $s['duration_min'] . ' min' : '—'; ?></div>
                <div class="sv-cell"><span class="sv-tag"><?php echo $e($method_label[$s['delivery_method']] ?? 'Custom'); ?></span></div>
                <div class="sv-cell sv-cell--muted"><?php echo (int) $s['purchases']; ?><?php echo (int) $s['capacity'] > 0 ? ' / ' . (int) $s['capacity'] : ''; ?></div>
                <div class="sv-cell"><span class="sv-status sv-status--<?php echo $s['status'] === 'published' ? 'on' : 'draft'; ?>"><span class="sv-status__dot"></span><?php echo $e(ucfirst($s['status'])); ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade cs-ae-modal" id="serviceModal" tabindex="-1" aria-hidden="true" aria-labelledby="svModalTitle">
    <div class="modal-dialog cs-ae">
        <div class="modal-content cs-ae__surface">
            <header class="cs-ae__header">
                <div class="cs-ae__heading">
                    <span class="cs-ae__eyebrow">Service</span>
                    <div class="cs-ae__titlerow">
                        <h2 class="cs-ae__title" id="svModalTitle">New Service</h2>
                        <span class="cs-ae__status" id="svStatus" hidden><span class="cs-ae__statusdot" aria-hidden="true"></span><span id="svStatusText">Draft</span></span>
                    </div>
                </div>
                <div class="cs-ae__headtools">
                    <button type="button" class="btn-close cs-ae__close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </header>

            <div class="cs-ae__body">
                <input type="hidden" id="sv_id">
                <input type="hidden" id="sv_status" value="draft">

                <nav class="cs-ae__nav" aria-label="Service sections">
                    <label class="cs-ae__navselect-label" for="svNavSelect">Section</label>
                    <select class="form-select cs-ae__navselect" id="svNavSelect">
                        <option value="details">Details</option>
                        <option value="pricing">Pricing</option>
                        <option value="delivery">Delivery</option>
                        <option value="publishing">Publishing</option>
                    </select>
                    <div class="cs-ae__navlist" id="svNav">
                        <?php foreach (array('details' => 'Details', 'pricing' => 'Pricing', 'delivery' => 'Delivery', 'publishing' => 'Publishing') as $k => $label): ?>
                        <button type="button" class="cs-ae__navitem" data-section="<?php echo $k; ?>"<?php echo $k === 'details' ? ' aria-current="true"' : ''; ?>>
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
                        <p class="cs-ae__sub">What buyers see on your profile before they book.</p>
                        <div class="cs-ae__field">
                            <label class="cs-ae__label" for="sv_name">Name</label>
                            <input type="text" class="form-control" id="sv_name" maxlength="190" autocomplete="off" placeholder="1:1 Strategy Consult" aria-describedby="svErr_name">
                            <p class="cs-ae__error" id="svErr_name" role="alert" hidden></p>
                        </div>
                        <div class="cs-ae__field">
                            <label class="cs-ae__label" for="sv_desc">Description</label>
                            <textarea class="form-control" id="sv_desc" rows="6"></textarea>
                        </div>
                        <div class="cs-ae__field">
                            <label class="cs-ae__label" for="sv_category">Category</label>
                            <input type="text" class="form-control" id="sv_category" maxlength="64" placeholder="Consulting">
                        </div>
                    </section>

                    <section class="cs-ae__section" data-section="pricing" aria-labelledby="svH_pricing" hidden>
                        <h3 class="cs-ae__h" id="svH_pricing" tabindex="-1">Pricing</h3>
                        <p class="cs-ae__sub">What a booking costs and how long it lasts.</p>
                        <div class="cs-ae__row">
                            <div class="cs-ae__field">
                                <label class="cs-ae__label" for="sv_price">Price (USD)</label>
                                <input type="number" class="form-control" id="sv_price" min="0" step="0.01" placeholder="150.00" aria-describedby="svErr_price">
                                <p class="cs-ae__error" id="svErr_price" role="alert" hidden></p>
                            </div>
                            <div class="cs-ae__field">
                                <label class="cs-ae__label" for="sv_duration">Duration (minutes)</label>
                                <input type="number" class="form-control" id="sv_duration" min="0" step="5" placeholder="60">
                            </div>
                        </div>
                        <div class="cs-ae__field">
                            <label class="cs-ae__label" for="sv_refund">Refund policy</label>
                            <input type="text" class="form-control" id="sv_refund" maxlength="500" placeholder="Full refund up to 24 hours before">
                        </div>
                        <div class="cs-ae__field cs-ae__field--switch">
                            <label class="cs-ae__label" for="sv_group">Group session</label>
                            <div class="form-check form-switch cs-ae__switch"><input class="form-check-input" type="checkbox" role="switch" id="sv_group"></div>
                        </div>
                        <div class="cs-ae__field cs-ae__field--short cs-ae__reveal" id="sv_capacity_wrap" hidden>
                            <label class="cs-ae__label" for="sv_capacity">Seats</label>
                            <input type="number" class="form-control" id="sv_capacity" min="2" step="1" placeholder="10" aria-describedby="svErr_capacity">
                            <p class="cs-ae__error" id="svErr_capacity" role="alert" hidden></p>
                        </div>
                    </section>

                    <section class="cs-ae__section" data-section="delivery" aria-labelledby="svH_delivery" hidden>
                        <h3 class="cs-ae__h" id="svH_delivery" tabindex="-1">Delivery</h3>
                        <p class="cs-ae__sub">Buyers see these once they purchase.</p>
                        <div class="cs-ae__row">
                            <div class="cs-ae__field">
                                <label class="cs-ae__label" for="sv_method">Meets on</label>
                                <select class="form-select" id="sv_method">
                                <option value="zoom">Zoom</option>
                                <option value="teams">Microsoft Teams</option>
                                <option value="meet">Google Meet</option>
                                <option value="webex">Webex</option>
                                <option value="discord">Discord</option>
                                <option value="phone">Phone</option>
                                <option value="in_person">In person</option>
                                <option value="custom">Other</option>
                                </select>
                            </div>
                            <div class="cs-ae__field">
                                <label class="cs-ae__label" for="sv_url">Booking link</label>
                                <input type="url" class="form-control" id="sv_url" placeholder="https://calendly.com/" autocomplete="off" aria-describedby="svErr_url">
                                <p class="cs-ae__error" id="svErr_url" role="alert" hidden></p>
                            </div>
                        </div>
                        <div class="cs-ae__field">
                            <label class="cs-ae__label" for="sv_details">Instructions</label>
                            <textarea class="form-control" id="sv_details" rows="4"></textarea>
                        </div>
                    </section>

                    <section class="cs-ae__section" data-section="publishing" aria-labelledby="svH_publishing" hidden>
                        <h3 class="cs-ae__h" id="svH_publishing" tabindex="-1">Publishing</h3>
                        <p class="cs-ae__sub">Published services appear on your profile and can be bought.</p>
                        <div class="cs-ae__field">
                            <span class="cs-ae__label" id="svStatusLabel">Status</span>
                            <div class="cs-seg cs-ae__seg" id="svStatusSeg" role="group" aria-labelledby="svStatusLabel">
                                <button type="button" class="cs-seg__opt" aria-pressed="false" data-status="draft"><span>Draft</span></button>
                                <button type="button" class="cs-seg__opt" aria-pressed="false" data-status="published"><span>Published</span></button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <footer class="cs-ae__footer">
                <button type="button" class="cs-ae__delete" id="sv_delete" hidden>Delete Service</button>
                <div class="cs-ae__actions">
                    <button type="button" class="btn cs-ae__btn cs-ae__btn--ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary cs-ae__btn" id="sv_save">Create Service</button>
                </div>
            </footer>
        </div>
    </div>
</div>

<script src="/js/section-editor.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/section-editor.js'); ?>"></script>
<script src="/js/services.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/services.js'); ?>"></script>
