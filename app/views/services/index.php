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
        <button type="button" class="sv-btn sv-btn--primary" id="svCreate"><i class="fa-solid fa-plus"></i> Create service</button>
    </header>

    <?php if (empty($services)): ?>
    <div class="sv-empty">
        <span class="sv-empty__ic"><i class="fa-regular fa-handshake"></i></span>
        <h2 class="sv-empty__t">No services yet</h2>
        <p class="sv-empty__x">Create your first service — a consult, coaching session, design package, or review — and sell it from your profile.</p>
    </div>
    <?php else: ?>
    <div class="sv-table">
        <div class="sv-table__head"><span>Service</span><span>Price</span><span>Duration</span><span>Delivery</span><span>Sold</span><span>Status</span><span></span></div>
        <div class="sv-table__body" id="svBody">
            <?php foreach ($services as $s): ?>
            <div class="sv-row" data-sv='<?php echo $e(json_encode(array(
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
                <div class="sv-cell sv-cell--act">
                    <button type="button" class="sv-btn sv-btn--sm" data-edit>Edit</button>
                    <button type="button" class="sv-btn sv-btn--sm sv-btn--danger" data-delete>Delete</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="serviceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="svModalTitle">New service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="sv_id">
                <div class="sv-field"><label for="sv_name">Name</label><input type="text" class="form-control" id="sv_name" maxlength="190" placeholder="1:1 Strategy consult"></div>
                <div class="sv-field"><label for="sv_desc">Description</label><textarea class="form-control" id="sv_desc" rows="3" placeholder="What the buyer gets, who it's for, what to expect."></textarea></div>
                <div class="sv-grid">
                    <div class="sv-field"><label for="sv_price">Price (USD)</label><input type="number" class="form-control" id="sv_price" min="0" step="0.01" placeholder="150.00"></div>
                    <div class="sv-field"><label for="sv_duration">Duration <span class="sv-opt">(minutes)</span></label><input type="number" class="form-control" id="sv_duration" min="0" step="5" placeholder="60"></div>
                    <div class="sv-field"><label for="sv_capacity">Group capacity <span class="sv-opt">(0 = one-on-one)</span></label><input type="number" class="form-control" id="sv_capacity" min="0" step="1" value="0"></div>
                </div>
                <div class="sv-section-label">Delivery &amp; scheduling — shown to buyers after purchase</div>
                <div class="sv-grid">
                    <div class="sv-field"><label for="sv_method">Delivery method</label>
                        <select class="form-control" id="sv_method">
                            <option value="zoom">Zoom</option>
                            <option value="teams">Microsoft Teams</option>
                            <option value="meet">Google Meet</option>
                            <option value="webex">Webex</option>
                            <option value="discord">Discord</option>
                            <option value="phone">Phone</option>
                            <option value="in_person">In person</option>
                            <option value="custom" selected>Custom / other</option>
                        </select>
                    </div>
                    <div class="sv-field"><label for="sv_url">Scheduling URL <span class="sv-opt">(Calendly, Acuity…)</span></label><input type="url" class="form-control" id="sv_url" placeholder="https://calendly.com/…"></div>
                </div>
                <div class="sv-field"><label for="sv_details">Delivery details / meeting instructions <span class="sv-opt">(optional)</span></label><textarea class="form-control" id="sv_details" rows="2" placeholder="Meeting link, phone number, address, or how to prepare."></textarea></div>
                <div class="sv-grid">
                    <div class="sv-field"><label for="sv_category">Category <span class="sv-opt">(optional)</span></label><input type="text" class="form-control" id="sv_category" maxlength="64" placeholder="Consulting"></div>
                    <div class="sv-field"><label for="sv_status">Status</label>
                        <select class="form-control" id="sv_status">
                            <option value="draft">Draft — not visible yet</option>
                            <option value="published">Published — live on your profile</option>
                        </select>
                    </div>
                </div>
                <div class="sv-field"><label for="sv_refund">Refund policy <span class="sv-opt">(optional)</span></label><input type="text" class="form-control" id="sv_refund" maxlength="500" placeholder="e.g. Full refund if canceled 24h in advance."></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="sv-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="sv-btn sv-btn--primary" id="sv_save">Save service</button>
            </div>
        </div>
    </div>
</div>

<script src="/js/services.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/services.js'); ?>"></script>
