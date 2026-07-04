<link rel="stylesheet" href="/css/account-settings.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script src="https://js.stripe.com/v3/"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<div class="settings">

    <div class="settings__body">
        <nav class="settings__nav" id="settings_nav">
            <button type="button" class="settings__nav-item is-active" data-section="account"><i class="fa-solid fa-user"></i><span>Account</span></button>
            <button type="button" class="settings__nav-item" data-section="security"><i class="fa-solid fa-lock"></i><span>Security</span></button>
            <button type="button" class="settings__nav-item" data-section="notifications"><i class="fa-solid fa-bell"></i><span>Notifications</span></button>
            <button type="button" class="settings__nav-item" data-section="creator"><i class="fa-solid fa-star"></i><span><?php echo $this->is_creator ? 'Creator' : 'Become a Creator'; ?></span></button>
            <?php if ($this->is_creator): ?>
            <button type="button" class="settings__nav-item" data-section="payouts"><i class="fa-solid fa-money-bill-transfer"></i><span>Payouts</span></button>
            <?php endif; ?>
            <button type="button" class="settings__nav-item" data-section="wallet"><i class="fa-solid fa-wallet"></i><span>My Wallet</span></button>
            <button type="button" class="settings__nav-item" data-section="privacy"><i class="fa-solid fa-shield-halved"></i><span>Restricted Content</span></button>
            <button type="button" class="settings__nav-item" data-section="blocked"><i class="fa-solid fa-ban"></i><span>Blocked Users</span></button>
            <button type="button" class="settings__nav-item" data-section="connected"><i class="fa-solid fa-share-nodes"></i><span>Integrations</span></button>
        </nav>

        <div class="settings__content">

            <section class="settings__section is-active" data-section="account">
                <?php $next_change = $this->username_next_change; ?>
                <div class="uname">
                    <label class="uname__label" for="account_username">Username</label>
                    <p class="uname__hint">Your public handle: <span class="uname__url"><?php echo htmlspecialchars((string) $this->public_domain, ENT_QUOTES, 'UTF-8'); ?>/@<span id="uname_preview"><?php echo htmlspecialchars((string) $this->user['u_name'], ENT_QUOTES, 'UTF-8'); ?></span></span></p>
                    <div class="uname__row">
                        <div class="uname__field">
                            <span class="uname__at">@</span>
                            <input type="text" class="form-control" id="account_username" maxlength="30" autocomplete="off" value="<?php echo htmlspecialchars((string) $this->user['u_name'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $next_change != '' ? 'disabled' : ''; ?>>
                        </div>
                        <button type="button" class="btn btn-primary" id="username_save_btn" <?php echo $next_change != '' ? 'disabled' : ''; ?>>Save</button>
                    </div>
                    <p class="uname__rules" id="uname_rules">
                        <?php if ($next_change != ''): ?>
                        You can change your username again on <strong><?php echo htmlspecialchars($next_change, ENT_QUOTES, 'UTF-8'); ?></strong>.
                        <?php else: ?>
                        3–30 characters — lowercase letters, numbers, and underscores. You can change your username once every 30 days.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="acct-card">
                    <h3 class="acct-card__title">Profile details</h3>
                    <div class="acct-grid">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="first_name" value="<?php echo htmlspecialchars((string) $this->user['first_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="first_name">First Name</label>
                        </div>
                        <div class="form-floating">
                            <input type="text" class="form-control" id="last_name" value="<?php echo htmlspecialchars((string) $this->user['last_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="last_name">Last Name</label>
                        </div>
                        <div class="form-floating">
                            <input type="email" class="form-control" id="user_email" value="<?php echo htmlspecialchars((string) $this->user['user_email'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="user_email">Email Address</label>
                        </div>
                        <div class="form-floating">
                            <input type="text" class="form-control" id="user_phone" value="<?php echo htmlspecialchars((string) $this->user['user_phone'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="user_phone">Phone Number (Optional)</label>
                        </div>
                        <div class="form-floating">
                            <input type="text" class="form-control" id="business_name" value="<?php echo htmlspecialchars((string) $this->user['business_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="business_name">Business Name (Optional)</label>
                        </div>
                        <div class="form-floating">
                            <input type="text" class="form-control" id="website_url" value="<?php echo htmlspecialchars((string) $this->user['website_url'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="website_url">Website URL (Optional)</label>
                        </div>
                    </div>
                    <div class="acct-card__actions">
                        <button type="button" class="btn btn-primary" id="save_profile">Update Profile</button>
                    </div>
                </div>

                <div class="acct-danger">
                    <div class="acct-danger__info">
                        <span class="acct-danger__title">Delete account</span>
                        <span class="acct-danger__desc">This is irreversible and permanently deletes your account and all associated data.</span>
                    </div>
                    <button type="button" class="btn btn-danger" id="delete_account">Delete Account</button>
                </div>
            </section>

            <section class="settings__section" data-section="security">
                <div class="acct-card">
                    <h3 class="acct-card__title">Password</h3>
                    <p class="acct-card__desc">Change the password you use to sign in.</p>
                    <button type="button" class="btn btn-secondary" id="change_password">Change Password</button>
                </div>

                <div class="acct-card">
                    <h3 class="acct-card__title">Two-factor authentication</h3>
                    <p class="acct-card__desc">Add a second step at sign-in to keep your account secure.</p>

                    <div class="mfa-method">
                        <div class="mfa-method__info">
                            <span class="mfa-method__name"><i class="fa-solid fa-mobile-screen-button"></i> Authenticator app</span>
                            <span class="mfa-method__desc">Use Google Authenticator, Authy, or 1Password to generate codes.</span>
                        </div>
                        <div class="mfa-method__state">
                            <span class="mfa-method__badge <?php echo $this->mfa_totp_enabled ? 'is-on' : ''; ?>" id="totp_badge"><?php echo $this->mfa_totp_enabled ? 'On' : 'Off'; ?></span>
                            <button type="button" class="btn btn-secondary" id="totp_toggle_btn"><?php echo $this->mfa_totp_enabled ? 'Disable' : 'Set up'; ?></button>
                        </div>
                    </div>

                    <div class="mfa-method">
                        <div class="mfa-method__info">
                            <span class="mfa-method__name"><i class="fa-solid fa-envelope"></i> Email verification</span>
                            <span class="mfa-method__desc">We email a one-time code to <?php echo htmlspecialchars((string) $this->user['user_email'], ENT_QUOTES, 'UTF-8'); ?> at sign-in.</span>
                        </div>
                        <div class="mfa-method__state">
                            <span class="mfa-method__badge <?php echo $this->mfa_email_enabled ? 'is-on' : ''; ?>" id="email_badge"><?php echo $this->mfa_email_enabled ? 'On' : 'Off'; ?></span>
                            <button type="button" class="btn btn-secondary" id="email_toggle_btn"><?php echo $this->mfa_email_enabled ? 'Disable' : 'Set up'; ?></button>
                        </div>
                    </div>

                    <div class="mfa-backup" id="mfa_backup_row" <?php echo ($this->mfa_totp_enabled || $this->mfa_email_enabled) ? '' : 'hidden'; ?>>
                        <div class="mfa-method__info">
                            <span class="mfa-method__name"><i class="fa-solid fa-key"></i> Backup codes</span>
                            <span class="mfa-method__desc"><span id="backup_count"><?php echo (int) $this->mfa_backup_count; ?></span> unused codes remaining. Each can be used once if you lose access to your other methods.</span>
                        </div>
                        <div class="mfa-method__state">
                            <button type="button" class="btn btn-secondary" id="regen_backup_btn">Regenerate</button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="settings__section" data-section="notifications">

                <div class="notif">
                    <div class="notif__row notif__row--head">
                        <span class="notif__label">Notify me about</span>
                        <span class="notif__toggle-label">In-app</span>
                        <span class="notif__toggle-label">Email</span>
                    </div>
                    <?php foreach ($this->notification_meta as $key => $meta): ?>
                    <?php $pref = $this->notification_prefs[$key] ?? array('in_platform' => true, 'email' => true); ?>
                    <div class="notif__row">
                        <div class="notif__label">
                            <span class="notif__name"><?php echo htmlspecialchars($meta[0], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="notif__desc"><?php echo htmlspecialchars($meta[1], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <label class="notif__switch">
                            <input type="checkbox" class="notif-pref" data-category="<?php echo $key; ?>" data-channel="in_platform" <?php echo !empty($pref['in_platform']) ? 'checked' : ''; ?>>
                            <span class="notif__slider"></span>
                        </label>
                        <label class="notif__switch">
                            <input type="checkbox" class="notif-pref" data-category="<?php echo $key; ?>" data-channel="email" <?php echo !empty($pref['email']) ? 'checked' : ''; ?>>
                            <span class="notif__slider"></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="settings__section" data-section="creator">
                <?php if (!$this->is_creator): ?>
                <div class="creator-cta">
                    <label class="creator-cta__terms-label" for="creator_terms">Creator Agreement &amp; Content Policy</label>
                    <textarea id="creator_terms" class="creator-cta__terms form-control" rows="10" readonly><?php echo htmlspecialchars($this->creator_terms, ENT_QUOTES, 'UTF-8'); ?></textarea>

                    <label class="creator-cta__agree">
                        <input type="checkbox" id="creator_agree">
                        <span>I have read and accept the Creator Agreement and Content Policy.</span>
                    </label>

                    <button type="button" class="btn btn-primary" id="become_creator_btn">Become a Creator</button>
                </div>
                <?php else: ?>
                <?php $cp = $this->creator_profile; ?>
                <div class="cprofile">
                    <div class="cprofile__field">
                        <label>Cover image</label>
                        <div class="cprofile__cover" id="cover_preview" style="<?php echo !empty($cp['cover_url']) ? 'background-image:url(\'' . htmlspecialchars($cp['cover_url'], ENT_QUOTES, 'UTF-8') . '\')' : ''; ?>">
                            <div class="cprofile__cover-actions">
                                <button type="button" class="btn btn-secondary" id="cover_upload_btn"><i class="fa-solid fa-camera"></i> Upload cover</button>
                                <button type="button" class="btn btn-secondary" id="cover_remove" <?php echo empty($cp['cover_url']) ? 'hidden' : ''; ?>>Remove</button>
                            </div>
                        </div>
                    </div>

                    <div class="cprofile__field">
                        <label>Profile photo</label>
                        <div class="cprofile__avatar-row">
                            <div class="cprofile__avatar" id="avatar_preview" style="<?php echo !empty($cp['avatar_url']) ? 'background-image:url(\'' . htmlspecialchars($cp['avatar_url'], ENT_QUOTES, 'UTF-8') . '\')' : ''; ?>">
                                <?php if (empty($cp['avatar_url'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary" id="avatar_upload_btn"><i class="fa-solid fa-camera"></i> Upload photo</button>
                            <button type="button" class="btn btn-secondary" id="avatar_remove" <?php echo empty($cp['avatar_url']) ? 'hidden' : ''; ?>>Remove</button>
                        </div>
                    </div>

                    <input type="file" id="cover_file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                    <input type="file" id="avatar_file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>

                    <div class="cprofile__grid">
                        <div class="cprofile__field">
                            <label for="cp_display_name">Display Name</label>
                            <input type="text" class="form-control" id="cp_display_name" maxlength="190" value="<?php echo htmlspecialchars((string) $cp['display_name'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="cprofile__field">
                            <label for="cp_location">Location (Optional)</label>
                            <input type="text" class="form-control" id="cp_location" maxlength="190" placeholder="City, Country" value="<?php echo htmlspecialchars((string) $cp['location'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <div class="cprofile__field">
                        <label for="cp_bio">Bio (Optional)</label>
                        <textarea class="form-control" id="cp_bio" rows="4" placeholder="Tell visitors who you are and what you offer."><?php echo htmlspecialchars((string) $cp['bio'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="cprofile__field">
                        <label for="tag_entry">Tags (Optional)</label>
                        <input type="text" class="form-control" id="tag_entry" placeholder="Type a tag and press Enter" autocomplete="off">
                        <div class="taglist" id="tag_list">
                            <?php foreach (array_filter(array_map('trim', explode(',', (string) $cp['tags']))) as $t): ?>
                            <span class="taginput__chip" data-tag="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="taginput__remove" aria-label="Remove tag">&times;</button></span>
                            <?php endforeach; ?>
                        </div>
                        <span class="cprofile__hint">Press Enter or comma to add a tag.</span>
                    </div>

                    <div class="cprofile__actions">
                        <button type="button" class="btn btn-primary" id="cp_save">Save Changes</button>
                    </div>
                </div>

                <div class="links-head">
                    <h3 class="links-head__title">Links</h3>
                    <button type="button" class="btn btn-secondary" id="link_add_btn"><i class="fa-solid fa-plus"></i> Add link</button>
                </div>
                <div class="links-card">
                    <div class="links-list" id="links_list">
                        <?php foreach ($this->creator_links as $lnk): ?>
                        <div class="link-row" data-id="<?php echo (int) $lnk['id']; ?>">
                            <span class="link-row__handle"><i class="fa-solid fa-grip-vertical"></i></span>
                            <div class="link-row__info">
                                <span class="link-row__title"><?php echo htmlspecialchars((string) $lnk['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="link-row__url"><?php echo htmlspecialchars((string) $lnk['url'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <button type="button" class="link-row__btn link-edit" aria-label="Edit link"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="link-row__btn link-delete" aria-label="Remove link"><i class="fa-solid fa-trash"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="settings__empty links-empty" id="links_empty" <?php echo empty($this->creator_links) ? '' : 'hidden'; ?>>No links yet. Add your website, socials, or anything you want to share.</p>
                </div>

                <div class="creator-danger">
                    <div class="creator-danger__info">
                        <span class="creator-danger__title">Stop Being a Creator</span>
                        <span class="creator-danger__desc">This permanently deletes all your creator content. It cannot be undone.</span>
                    </div>
                    <button type="button" class="btn btn-danger" id="leave_creator_btn">Delete Account</button>
                </div>
                <?php endif; ?>
            </section>

            <?php if ($this->is_creator): ?>
            <?php $ps = $this->payout_status; ?>
            <section class="settings__section" data-section="payouts">
                <?php if (empty($ps['payouts_enabled'])): ?>
                <div class="payout-setup">
                    <div class="payout-setup__info">
                        <span class="payout-setup__title"><?php echo !empty($ps['details_submitted']) ? 'Finish setting up payouts' : 'Set up payouts'; ?></span>
                        <span class="payout-setup__desc"><?php echo !empty($ps['details_submitted']) ? 'Stripe needs a little more information before you can receive payouts.' : 'Connect a Stripe account to receive payouts from your sales. This is required before you can be paid.'; ?></span>
                    </div>
                    <button type="button" class="btn btn-primary" id="payout_setup_btn"><?php echo !empty($ps['details_submitted']) ? 'Continue setup' : 'Set up payouts'; ?></button>
                </div>
                <?php else: ?>
                <?php $pb = $this->payout_balance; ?>
                <div class="payout__head">
                    <span class="payout-status__badge"><i class="fa-solid fa-circle-check"></i> Payouts Enabled</span>
                </div>

                <div class="payout-balance">
                    <div class="payout-balance__cell">
                        <span class="payout-balance__label">Available</span>
                        <span class="payout-balance__value">$<?php echo number_format($pb['available'] / 100, 2); ?></span>
                    </div>
                    <div class="payout-balance__cell">
                        <span class="payout-balance__label">Pending</span>
                        <span class="payout-balance__value">$<?php echo number_format($pb['pending'] / 100, 2); ?></span>
                    </div>
                </div>

                <div class="payout-actions">
                    <button type="button" class="btn btn-primary" id="payout_request_btn" <?php echo $pb['available'] <= 0 ? 'disabled' : ''; ?>>Request payout</button>
                    <?php if ($pb['available'] <= 0): ?><span class="payout-actions__note">No funds available to pay out yet.</span><?php endif; ?>
                </div>

                <h3 class="wallet__subhead">Payout history</h3>
                <div class="payout-history">
                    <?php if (empty($this->payouts)): ?>
                        <p class="settings__empty">No payouts yet.</p>
                    <?php else: ?>
                    <table class="ledger ledger--flush">
                        <thead><tr><th>Date</th><th>Status</th><th class="ledger__num">Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($this->payouts as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('M j, Y', $p['arrival'] ?: $p['created']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($p['status']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="ledger__num">$<?php echo number_format($p['amount'] / 100, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <div class="payout-disconnect">
                    <button type="button" class="payout-disconnect__link" id="payout_disconnect_btn">Disconnect Stripe account</button>
                </div>
                <?php endif; ?>

                <?php if ($this->has_connect && empty($ps['payouts_enabled'])): ?>
                <div class="payout-disconnect">
                    <button type="button" class="btn btn-secondary" id="payout_disconnect_btn">Disconnect Stripe account</button>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="settings__section" data-section="wallet">
                <?php
                    $ar     = $this->autoreplenishment;
                    $ar_on  = !empty($ar['enabled']);
                    $ar_dollars = (int) round($ar['amount_cents'] / 100);
                    $ar_credits = 0;
                    foreach ($this->credit_packages as $pkg) {
                        if ((int) $pkg['dollars'] === $ar_dollars) { $ar_credits = (int) $pkg['credits']; }
                    }
                    $ar_status = $ar_on
                        ? 'Buy ' . number_format($ar_credits) . ' credits when balance drops below ' . number_format((int) $ar['threshold'])
                        : 'Automatically top up when your balance runs low.';
                ?>
                <div class="wallet">
                    <div class="wallet__balance">
                        <span class="wallet__balance-label">Current Balance</span>
                        <span class="wallet__balance-value"><i class="fa-solid fa-coins"></i> <span id="credit_balance"><?php echo number_format((int) $this->credit_balance); ?></span> credits</span>
                    </div>

                    <h3 class="wallet__subhead">Buy Credits</h3>
                    <div class="credit-packs">
                        <?php foreach ($this->credit_packages as $pkg): ?>
                        <button type="button" class="credit-pack buy-credits" data-dollars="<?php echo (int) $pkg['dollars']; ?>">
                            <span class="credit-pack__credits"><?php echo number_format((int) $pkg['credits']); ?></span>
                            <span class="credit-pack__label">credits</span>
                            <span class="credit-pack__price">$<?php echo number_format((int) $pkg['dollars']); ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="wallet__subhead">Auto-Replenishment</h3>
                    <div class="wallet__ar-summary">
                        <div class="wallet__ar-summary-info">
                            <span class="wallet__ar-summary-badge <?php echo $ar_on ? 'is-on' : ''; ?>" id="ar_badge"><?php echo $ar_on ? 'On' : 'Off'; ?></span>
                            <span class="wallet__ar-summary-status" id="ar_status"><?php echo htmlspecialchars($ar_status, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <button type="button" class="btn btn-secondary" id="ar_manage">Manage</button>
                    </div>

                    <h3 class="wallet__subhead">Transaction History</h3>
                    <div id="credit_history">
                    <?php if (empty($this->credit_transactions)): ?>
                        <p class="settings__empty">No credit activity yet.</p>
                    <?php else: ?>
                    <?php $stripe_payment_url = (strpos((string) $this->stripe_pk, 'pk_test') === 0) ? 'https://dashboard.stripe.com/test/payments/' : 'https://dashboard.stripe.com/payments/'; ?>
                    <table class="ledger">
                        <thead><tr><th>Date</th><th>Activity</th><th class="ledger__num">Credits</th></tr></thead>
                        <tbody>
                            <?php foreach ($this->credit_transactions as $t): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('M j, Y', strtotime($t['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if (!empty($t['stripe_payment_intent_id'])): ?>
                                        <a href="<?php echo htmlspecialchars($stripe_payment_url . $t['stripe_payment_intent_id'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string) $t['description'], ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars((string) $t['description'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="ledger__num <?php echo ((int) $t['credits'] >= 0) ? 'ledger__pos' : 'ledger__neg'; ?>"><?php echo ((int) $t['credits'] >= 0 ? '+' : '') . number_format((int) $t['credits']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="settings__section" data-section="privacy">
                <div class="notif">
                    <div class="notif__row notif__row--single">
                        <div class="notif__label">
                            <span class="notif__name">Show adult content</span>
                            <span class="notif__desc">Adult content is hidden by default. You must be 18 or older and in a permitted region to enable it.</span>
                        </div>
                        <label class="notif__switch">
                            <input type="checkbox" id="adult_content_toggle" <?php echo !empty($this->user['adult_content_enabled']) ? 'checked' : ''; ?>>
                            <span class="notif__slider"></span>
                        </label>
                    </div>
                </div>
            </section>

            <section class="settings__section" data-section="blocked">
                <div class="acct-panel">
                <div class="acct-list" id="block_list">
                    <?php if (empty($this->blocked_users)): ?>
                        <p class="settings__empty" id="block_empty">You haven't blocked anyone.</p>
                    <?php else: ?>
                        <?php foreach ($this->blocked_users as $b): ?>
                        <div class="acct-row" data-user-id="<?php echo (int) $b['blocked_user_id']; ?>">
                            <div class="acct-row__who">
                                <span class="acct-row__handle">@<?php echo htmlspecialchars((string) $b['u_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php $name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')); ?>
                                <?php if ($name !== ''): ?>
                                <span class="acct-row__name"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary block-unblock" data-user-id="<?php echo (int) $b['blocked_user_id']; ?>">Unblock</button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                </div>
            </section>

            <section class="settings__section" data-section="connected">
                <?php if (!$this->can_social_post): ?>
                    <div class="settings__upgrade">
                        <i class="fa-solid fa-lock settings__upgrade-icon"></i>
                        <div>
                            <div class="settings__upgrade-title">Social posting is a premium feature</div>
                            <p class="settings__upgrade-text">Upgrade to a plan that includes social posting to connect your accounts and publish to Instagram, TikTok, LinkedIn and more.</p>
                        </div>
                        <a href="/account/billing" class="btn btn-primary">View plans</a>
                    </div>
                <?php else: ?>
                <div class="conn-grid">
                    <?php foreach ($this->platform_meta as $p => $meta): ?>
                    <?php $accts = $this->connected[$p] ?? array(); ?>
                    <div class="conn-card">
                        <div class="conn-card__head">
                            <i class="fa-brands <?php echo $meta[1]; ?> conn-card__icon"></i>
                            <span class="conn-card__name"><?php echo $meta[0]; ?></span>
                            <span class="conn-card__badge <?php echo !empty($accts) ? 'is-on' : ''; ?>"><?php echo !empty($accts) ? 'Connected' : 'Not connected'; ?></span>
                        </div>
                        <?php if (!empty($accts)): ?>
                            <?php foreach ($accts as $a): ?>
                            <div class="conn-card__acct">
                                <span class="conn-card__handle"><?php echo htmlspecialchars(($a['username'] !== '' && $a['username'] !== null) ? '@' . $a['username'] : 'Connected', ENT_QUOTES, 'UTF-8'); ?></span>
                                <button type="button" class="btn btn-secondary conn-disconnect" data-account-id="<?php echo htmlspecialchars($a['post_for_me_social_account_id'], ENT_QUOTES, 'UTF-8'); ?>">Disconnect</button>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <button type="button" class="btn btn-primary conn-connect" data-platform="<?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?>">Connect</button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

        </div>
    </div>
</div>

<div class="modal fade" id="link_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="link_modal_title">Add link</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="link_id" value="0">
                <div class="link-field">
                    <label for="link_title">Label</label>
                    <input type="text" class="form-control" id="link_title" maxlength="120" placeholder="My website">
                </div>
                <div class="link-field">
                    <label for="link_url">URL</label>
                    <input type="text" class="form-control" id="link_url" placeholder="https://...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="link_save">Save link</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="payout_disconnect_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Disconnect Stripe account?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">This unlinks your Stripe payout account. You won't be able to receive payouts until you set up payouts again.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="payout_disconnect_confirm">Disconnect</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="crop_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="crop_modal_title">Crop image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="crop-hint" id="crop_hint"></p>
                <div class="crop-stage"><img id="crop_img" alt=""></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="crop_confirm">Crop &amp; upload</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="leave_creator_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Stop being a creator?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>This will <strong>permanently delete all of your creator content</strong> — it will not be recoverable. Your account will return to a regular user account.</p>
                <p class="mb-0">Are you sure you want to continue?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="leave_creator_confirm">Delete and stop being a creator</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="ar_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Auto-Replenishment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="wallet__note">Automatically buy more credits when your balance runs low.</p>

                <div class="wallet__ar-modal-toggle">
                    <label class="notif__switch wallet__ar-switch">
                        <input type="checkbox" id="ar_enabled" <?php echo $ar_on ? 'checked' : ''; ?>>
                        <span class="notif__slider"></span>
                    </label>
                    <span>Enable Auto-Replenishment</span>
                </div>

                <div class="wallet__ar-body<?php echo $ar_on ? '' : ' is-hidden'; ?>" id="ar_fields">
                    <div class="wallet__ar-field">
                        <label for="ar_threshold">When my credits drop below</label>
                        <div class="wallet__ar-input">
                            <input type="number" min="1" step="1" class="form-control" id="ar_threshold" value="<?php echo (int) $ar['threshold'] > 0 ? (int) $ar['threshold'] : 100; ?>">
                            <span class="wallet__ar-unit">Credits</span>
                        </div>
                    </div>
                    <div class="wallet__ar-field">
                        <label for="ar_package">Automatically Buy</label>
                        <select class="form-select" id="ar_package">
                            <?php foreach ($this->credit_packages as $pkg): ?>
                            <option value="<?php echo (int) $pkg['dollars']; ?>" data-credits="<?php echo (int) $pkg['credits']; ?>" <?php echo ((int) $ar['amount_cents'] === (int) $pkg['dollars'] * 100) ? 'selected' : ''; ?>>
                                <?php echo number_format((int) $pkg['credits']); ?> credits &mdash; $<?php echo number_format((int) $pkg['dollars']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wallet__ar-field">
                        <label for="ar_pm">Charge To</label>
                        <?php if (empty($this->cards)): ?>
                            <p class="wallet__note wallet__note--tight">No payment methods on file. <a href="/account/billing">Add one in billing</a>, then buy credits once to save a card.</p>
                        <?php else: ?>
                        <select class="form-select" id="ar_pm">
                            <?php foreach ($this->cards as $card): ?>
                            <?php
                                if ($card['type'] === 'card') {
                                    $label = $card['brand'] . ' •••• ' . $card['last4'];
                                } elseif ($card['detail'] !== '') {
                                    $label = $card['brand'] . ' · ' . $card['detail'];
                                } else {
                                    $label = $card['brand'];
                                }
                            ?>
                            <option value="<?php echo htmlspecialchars($card['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($card['id'] === $ar['pm_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="ar_save">Save</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="credit_payment_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Buy Credits</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="wallet__pay-summary" id="credit_pay_summary"></p>
                <div id="credit_payment_element"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="credit_pay_button">Pay</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="adult_confirm_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Enable Adult Content</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Adult content may include mature and explicit material. By continuing you confirm that you are 18 years of age or older and that viewing this content is permitted in your region.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="adult_confirm_yes">I am 18 or older</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="change_password_form" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="form-floating mb-3">
                    <input type="password" class="form-control" id="current_password" autocomplete="current-password" placeholder="Current Password">
                    <label for="current_password">Current Password</label>
                </div>
                <div class="form-floating mb-3">
                    <input type="password" class="form-control" id="new_password" autocomplete="new-password" placeholder="New Password">
                    <label for="new_password">New Password</label>
                </div>
                <div class="password-meter mb-3" id="meter">
                    <div class="password-meter__bar mb-2"><span></span><span></span><span></span><span></span></div>
                    <p class="password-meter__label mb-2" id="meterLabel">Password Strength</p>
                    <ul class="password-reqs" id="password-reqs">
                        <li data-rule="length"><i class="password-reqs__dot"></i>At least 8 characters</li>
                        <li data-rule="upper"><i class="password-reqs__dot"></i>An uppercase letter</li>
                        <li data-rule="lower"><i class="password-reqs__dot"></i>A lowercase letter</li>
                        <li data-rule="number"><i class="password-reqs__dot"></i>A number</li>
                        <li data-rule="symbol"><i class="password-reqs__dot"></i>A symbol</li>
                        <li data-rule="match"><i class="password-reqs__dot"></i>Matches the confirm password</li>
                    </ul>
                </div>
                <div class="form-floating">
                    <input type="password" class="form-control" id="confirm_password" autocomplete="new-password" placeholder="Confirm Password">
                    <label for="confirm_password">Confirm Password</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="update_password">Update Password</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="delete_account_form" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Account</h5>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete your account? This action is irreversible and will permanently delete your account and all associated data.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirm_delete_account">Confirm</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="totp_enroll_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Set up authenticator app</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mfa-modal__step">1. Scan this QR code with your authenticator app.</p>
                <div class="mfa-qr" id="totp_qr"></div>
                <p class="mfa-modal__step">Can't scan? Enter this key manually:</p>
                <p class="mfa-secret" id="totp_secret"></p>
                <p class="mfa-modal__step">2. Enter the 6-digit code from your app.</p>
                <div class="form-floating">
                    <input type="text" class="form-control" id="totp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000">
                    <label for="totp_code">6-digit code</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="totp_confirm_btn">Verify &amp; enable</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="email_enroll_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Set up email verification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mfa-modal__step">We emailed a 6-digit code to <strong><?php echo htmlspecialchars((string) $this->user['user_email'], ENT_QUOTES, 'UTF-8'); ?></strong>. Enter it below to turn on email verification.</p>
                <div class="form-floating">
                    <input type="text" class="form-control" id="email_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000">
                    <label for="email_code">6-digit code</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" id="email_resend_btn">Resend code</button>
                <button type="button" class="btn btn-primary" id="email_confirm_btn">Verify &amp; enable</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="mfa_disable_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm your password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="mfa_disable_prompt">Enter your current password to disable this method.</p>
                <div class="form-floating">
                    <input type="password" class="form-control" id="mfa_disable_password" autocomplete="current-password" placeholder="Current Password">
                    <label for="mfa_disable_password">Current Password</label>
                </div>
                <input type="hidden" id="mfa_disable_method" value="">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="mfa_disable_confirm">Disable</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="backup_codes_modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Save your backup codes</h5>
            </div>
            <div class="modal-body">
                <p>Store these somewhere safe. Each code works once if you lose access to your other methods. They won't be shown again.</p>
                <div class="mfa-codes" id="backup_codes_list"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="backup_copy_btn">Copy codes</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I've saved them</button>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {

    var params = new URLSearchParams(window.location.search);
    if (params.get('connected') === '1') { toastr.success('Account connected'); }
    if (params.get('error') === '1') { toastr.error('Connection was not completed'); }
    if (params.get('payout_return') === '1') { toastr.success('Payout details updated'); }

    function activateSection(section) {
        $('#settings_nav .settings__nav-item').removeClass('is-active');
        $('#settings_nav .settings__nav-item[data-section="' + section + '"]').addClass('is-active');
        $('.settings__section').removeClass('is-active');
        $('.settings__section[data-section="' + section + '"]').addClass('is-active');
        $('.settings__content').scrollTop(0);
        window.scrollTo(0, 0);
    }

    var deepLink = (params.get('section') || '').replace(/[^a-z_]/gi, '');
    if (deepLink && $('#settings_nav .settings__nav-item[data-section="' + deepLink + '"]').length) {
        activateSection(deepLink);
    }

    // Consume one-time query params (deep-link section, Stripe/social returns) on
    // this load, then strip them so a refresh doesn't re-fire toasts or re-force a tab.
    if (window.location.search) {
        history.replaceState({}, '', window.location.pathname);
    }

    $('#settings_nav').on('click', '.settings__nav-item', function () {
        activateSection($(this).data('section'));
    });

    $('.notif-pref').on('change', function () {
        var prefs = {};
        $('.notif-pref:checked').each(function () {
            var cat = $(this).data('category');
            var ch  = $(this).data('channel');
            if (!prefs[cat]) { prefs[cat] = {}; }
            prefs[cat][ch] = 1;
        });
        ApiDataSvc.apiCall('post', 'save_notification_prefs', { prefs: prefs }, function (data) {
            var o = JSON.parse(data);
            if (o.success) { toastr.success(o.message); } else { toastr.error(o.message); }
        });
    });

    var adultConfirmed = false;

    function saveAdultContent(enabled, ageConfirmed) {
        ApiDataSvc.apiCall('post', 'save_adult_content_pref', {
            enabled: enabled ? 1 : 0,
            age_confirmed: ageConfirmed ? 1 : 0
        }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
            } else {
                toastr.error(o.message);
                $('#adult_content_toggle').prop('checked', false);
            }
        });
    }

    $('#adult_content_toggle').on('change', function () {
        if (this.checked) {
            adultConfirmed = false;
            $('#adult_confirm_modal').modal('show');
        } else {
            saveAdultContent(false, false);
        }
    });

    $('#adult_confirm_yes').on('click', function () {
        adultConfirmed = true;
        $('#adult_confirm_modal').modal('hide');
        saveAdultContent(true, true);
    });

    // Cancelling the confirmation reverts the toggle back to off.
    $('#adult_confirm_modal').on('hidden.bs.modal', function () {
        if (!adultConfirmed) {
            $('#adult_content_toggle').prop('checked', false);
        }
    });

    function escapeHtml(s) {
        return $('<div>').text(s == null ? '' : s).html();
    }

    $('#username_save_btn').on('click', function () {
        var u_name = ($('#account_username').val() || '').trim().toLowerCase().replace(/^@+/, '');
        if (u_name == '') { toastr.error('Enter a username'); return; }

        ApiDataSvc.apiCall('post', 'change_username', { u_name: u_name }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            $('#account_username').val(o.u_name).prop('disabled', true);
            $('#username_save_btn').prop('disabled', true);
            $('#uname_preview').text(o.u_name);
            if (o.next_change_date) {
                $('#uname_rules').html('You can change your username again on <strong>' + escapeHtml(o.next_change_date) + '</strong>.');
            }
        });
    });

    /* ---- profile details ---- */
    $('#save_profile').on('click', function () {
        if ($('#first_name').val() == '') { toastr.error('First name is required'); return; }
        if ($('#last_name').val() == '') { toastr.error('Last name is required'); return; }
        if ($('#user_email').val() == '') { toastr.error('Email is required'); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test($('#user_email').val())) { toastr.error('A valid email is required'); return; }

        ApiDataSvc.apiCall('post', 'update_profile', {
            first_name:    $('#first_name').val(),
            last_name:     $('#last_name').val(),
            user_email:    $('#user_email').val(),
            user_phone:    $('#user_phone').val(),
            business_name: $('#business_name').val(),
            website_url:   $('#website_url').val()
        }, function (data) {
            var o = JSON.parse(data);
            if (o.success) { toastr.success(o.message); } else { toastr.error(o.message); }
        });
    });

    $('#delete_account').on('click', function () {
        new bootstrap.Modal(document.getElementById('delete_account_form')).show();
    });

    $('#confirm_delete_account').on('click', function () {
        bootstrap.Modal.getInstance(document.getElementById('delete_account_form')).hide();
        ApiDataSvc.apiCall('post', 'delete_my_account', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) { setTimeout(function () { window.location.href = '/'; }, 800); }
            else { toastr.error(o.message); }
        });
    });

    /* ---- change password (Security) ---- */
    var password_rules = {
        length: function (v) { return v.length >= 8; },
        upper:  function (v) { return /[A-Z]/.test(v); },
        lower:  function (v) { return /[a-z]/.test(v); },
        number: function (v) { return /[0-9]/.test(v); },
        symbol: function (v) { return /[^A-Za-z0-9]/.test(v); },
        match:  function (v) { return v === $('#confirm_password').val(); }
    };
    var password_labels  = ['Strength', 'Weak', 'Fair', 'Good', 'Strong'];
    var password_classes = ['', 'is-weak', 'is-fair', 'is-good', 'is-strong'];
    var password_bucket  = [0, 1, 1, 2, 3, 4];

    function password_render() {
        var v = $('#new_password').val() || '';
        var score = 0;
        $.each(password_rules, function (rule, test) {
            var ok = test(v);
            if (ok) { score++; }
            $('#password-reqs li[data-rule="' + rule + '"]').toggleClass('is-met', ok);
        });
        var bucket = v.length ? password_bucket[score] : 0;
        $('#meter').removeClass('is-weak is-fair is-good is-strong').addClass(password_classes[bucket]);
        $('#meterLabel').text(password_labels[bucket]);
    }

    $('#new_password, #confirm_password').on('keyup', password_render);

    $('#change_password').on('click', function () {
        $('#current_password, #new_password, #confirm_password').val('');
        $('#password-reqs li').removeClass('is-met');
        $('#meter').removeClass('is-weak is-fair is-good is-strong');
        $('#meterLabel').text(password_labels[0]);
        new bootstrap.Modal(document.getElementById('change_password_form')).show();
    });

    $('#update_password').on('click', function () {
        if ($('#current_password').val() == '') { toastr.error('Current password is required'); return; }
        if ($('#new_password').val() == '') { toastr.error('New password is required'); return; }
        if ($('#confirm_password').val() == '') { toastr.error('Confirm password is required'); return; }
        if ($('#new_password').val() !== $('#confirm_password').val()) { toastr.error('New passwords do not match'); return; }
        if (!password_rules.length($('#new_password').val())) { toastr.error('New password must be at least 8 characters'); return; }
        if (!password_rules.upper($('#new_password').val()))  { toastr.error('New password must include an uppercase letter'); return; }
        if (!password_rules.lower($('#new_password').val()))  { toastr.error('New password must include a lowercase letter'); return; }
        if (!password_rules.number($('#new_password').val())) { toastr.error('New password must include a number'); return; }
        if (!password_rules.symbol($('#new_password').val())) { toastr.error('New password must include a symbol'); return; }

        ApiDataSvc.apiCall('post', 'change_password', {
            current_password: $('#current_password').val(),
            p_word:           $('#new_password').val(),
            confirm_password: $('#confirm_password').val()
        }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                bootstrap.Modal.getInstance(document.getElementById('change_password_form')).hide();
            } else {
                toastr.error(o.message);
            }
        });
    });

    /* ---- MFA ---- */
    function setMethodOn(method, on) {
        var badge = method === 'totp' ? $('#totp_badge') : $('#email_badge');
        var btn   = method === 'totp' ? $('#totp_toggle_btn') : $('#email_toggle_btn');
        badge.toggleClass('is-on', on).text(on ? 'On' : 'Off');
        btn.text(on ? 'Disable' : 'Set up');
        var anyOn = $('#totp_badge').hasClass('is-on') || $('#email_badge').hasClass('is-on');
        $('#mfa_backup_row').prop('hidden', !anyOn);
    }

    function showBackupCodes(codes) {
        if (!codes || !codes.length) { return; }
        $('#backup_count').text(codes.length);
        $('#backup_codes_list').html(codes.map(function (c) {
            return '<span class="mfa-codes__code">' + escapeHtml(c) + '</span>';
        }).join(''));
        setTimeout(function () {
            new bootstrap.Modal(document.getElementById('backup_codes_modal')).show();
        }, 300);
    }

    var pwConfirmCb = null;
    function openPasswordConfirm(promptText, btnLabel, cb) {
        pwConfirmCb = cb;
        $('#mfa_disable_prompt').text(promptText);
        $('#mfa_disable_confirm').text(btnLabel);
        $('#mfa_disable_password').val('');
        new bootstrap.Modal(document.getElementById('mfa_disable_modal')).show();
    }
    $('#mfa_disable_confirm').on('click', function () {
        var pw = $('#mfa_disable_password').val();
        if (pw == '') { toastr.error('Enter your current password'); return; }
        if (pwConfirmCb) { pwConfirmCb(pw); }
    });

    // Authenticator app
    $('#totp_toggle_btn').on('click', function () {
        if ($('#totp_badge').hasClass('is-on')) {
            openPasswordConfirm('Enter your current password to disable the authenticator app.', 'Disable', function (pw) {
                ApiDataSvc.apiCall('post', 'mfa_totp_disable', { current_password: pw }, function (data) {
                    var o = JSON.parse(data);
                    if (!o.success) { toastr.error(o.message); return; }
                    bootstrap.Modal.getInstance(document.getElementById('mfa_disable_modal')).hide();
                    toastr.success(o.message);
                    setMethodOn('totp', false);
                });
            });
            return;
        }
        ApiDataSvc.apiCall('post', 'mfa_totp_begin', {}, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            $('#totp_secret').text(o.secret);
            $('#totp_qr').empty();
            new QRCode(document.getElementById('totp_qr'), { text: o.otpauth_uri, width: 176, height: 176 });
            $('#totp_code').val('');
            new bootstrap.Modal(document.getElementById('totp_enroll_modal')).show();
        });
    });

    $('#totp_confirm_btn').on('click', function () {
        var code = ($('#totp_code').val() || '').trim();
        if (code == '') { toastr.error('Enter the 6-digit code'); return; }
        ApiDataSvc.apiCall('post', 'mfa_totp_confirm', { code: code }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            bootstrap.Modal.getInstance(document.getElementById('totp_enroll_modal')).hide();
            setMethodOn('totp', true);
            showBackupCodes(o.backup_codes);
        });
    });

    // Email verification
    $('#email_toggle_btn').on('click', function () {
        if ($('#email_badge').hasClass('is-on')) {
            openPasswordConfirm('Enter your current password to disable email verification.', 'Disable', function (pw) {
                ApiDataSvc.apiCall('post', 'mfa_email_disable', { current_password: pw }, function (data) {
                    var o = JSON.parse(data);
                    if (!o.success) { toastr.error(o.message); return; }
                    bootstrap.Modal.getInstance(document.getElementById('mfa_disable_modal')).hide();
                    toastr.success(o.message);
                    setMethodOn('email', false);
                });
            });
            return;
        }
        ApiDataSvc.apiCall('post', 'mfa_email_send_enroll', {}, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            $('#email_code').val('');
            new bootstrap.Modal(document.getElementById('email_enroll_modal')).show();
        });
    });

    $('#email_resend_btn').on('click', function () {
        ApiDataSvc.apiCall('post', 'mfa_email_send_enroll', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) { toastr.success(o.message); } else { toastr.error(o.message); }
        });
    });

    $('#email_confirm_btn').on('click', function () {
        var code = ($('#email_code').val() || '').trim();
        if (code == '') { toastr.error('Enter the 6-digit code'); return; }
        ApiDataSvc.apiCall('post', 'mfa_email_confirm', { code: code }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            bootstrap.Modal.getInstance(document.getElementById('email_enroll_modal')).hide();
            setMethodOn('email', true);
            showBackupCodes(o.backup_codes);
        });
    });

    // Backup codes
    $('#regen_backup_btn').on('click', function () {
        openPasswordConfirm('Enter your current password to generate new backup codes. Your old codes will stop working.', 'Regenerate', function (pw) {
            ApiDataSvc.apiCall('post', 'mfa_regenerate_backup_codes', { current_password: pw }, function (data) {
                var o = JSON.parse(data);
                if (!o.success) { toastr.error(o.message); return; }
                bootstrap.Modal.getInstance(document.getElementById('mfa_disable_modal')).hide();
                toastr.success(o.message);
                showBackupCodes(o.backup_codes);
            });
        });
    });

    $('#backup_copy_btn').on('click', function () {
        var text = $('#backup_codes_list .mfa-codes__code').map(function () { return $(this).text(); }).get().join('\n');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () { toastr.success('Copied'); }, function () { toastr.error('Copy failed'); });
        }
    });

    $(document).on('keydown', '#totp_code', function (e) { if (e.keyCode === 13) { $('#totp_confirm_btn').click(); } });
    $(document).on('keydown', '#email_code', function (e) { if (e.keyCode === 13) { $('#email_confirm_btn').click(); } });

    $('#block_list').on('click', '.block-unblock', function () {
        var $row = $(this).closest('.acct-row');
        var id   = $row.data('user-id');
        ApiDataSvc.apiCall('post', 'unblock_user', { blocked_user_id: id }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            $row.remove();
            if ($('#block_list .acct-row').length === 0) {
                $('#block_list').html('<p class="settings__empty" id="block_empty">You haven\'t blocked anyone.</p>');
            }
        });
    });

    var stripe = <?php echo !empty($this->stripe_pk) ? "Stripe('" . htmlspecialchars((string) $this->stripe_pk, ENT_QUOTES, 'UTF-8') . "')" : 'null'; ?>;
    var creditElements = null;

    $('.buy-credits').on('click', function () {
        if (!stripe) { toastr.error('Payments are not available right now'); return; }
        var dollars = $(this).data('dollars');
        ApiDataSvc.apiCall('post', 'buy_credits', { dollars: dollars }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            $('#credit_pay_summary').text('$' + dollars + ' for ' + Number(o.credits).toLocaleString() + ' credits');
            $('#credit_payment_element').html('');
            $('#credit_pay_button').prop('disabled', false);
            creditElements = stripe.elements({ clientSecret: o.client_secret });
            creditElements.create('payment').mount('#credit_payment_element');
            $('#credit_payment_modal').modal('show');
        });
    });

    $('#credit_pay_button').on('click', function () {
        if (!creditElements) { return; }
        $('#credit_pay_button').prop('disabled', true);
        stripe.confirmPayment({ elements: creditElements, redirect: 'if_required' }).then(function (result) {
            if (result.error) {
                $('#credit_pay_button').prop('disabled', false);
                toastr.error(result.error.message);
                return;
            }
            ApiDataSvc.apiCall('post', 'confirm_credit_purchase', { payment_intent_id: result.paymentIntent.id }, function (data) {
                var o = JSON.parse(data);
                if (!o.success) { toastr.error(o.message); return; }
                toastr.success(o.message);
                $('#credit_payment_modal').modal('hide');
                setTimeout(function () { window.location.href = '/account/settings?section=wallet'; }, 1000);
            });
        });
    });

    $('#ar_manage').on('click', function () {
        $('#ar_modal').modal('show');
    });

    $('#ar_enabled').on('change', function () {
        $('#ar_fields').toggleClass('is-hidden', !this.checked);
    });

    function arStatusText() {
        if (!$('#ar_enabled').is(':checked')) { return 'Automatically top up when your balance runs low.'; }
        var credits = Number($('#ar_package option:selected').data('credits')) || 0;
        var thr = parseInt($('#ar_threshold').val(), 10) || 0;
        return 'Buy ' + credits.toLocaleString() + ' credits when credits drop below ' + thr.toLocaleString();
    }

    $('#ar_save').on('click', function () {
        ApiDataSvc.apiCall('post', 'save_autoreplenishment', {
            enabled: $('#ar_enabled').is(':checked') ? 1 : 0,
            threshold: parseInt($('#ar_threshold').val(), 10) || 0,
            dollars: parseInt($('#ar_package').val(), 10) || 0,
            payment_method_id: $('#ar_pm').val() || ''
        }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            var on = $('#ar_enabled').is(':checked');
            $('#ar_badge').text(on ? 'On' : 'Off').toggleClass('is-on', on);
            $('#ar_status').text(arStatusText());
            $('#ar_modal').modal('hide');
        });
    });

    $('#become_creator_btn').on('click', function () {
        if (!$('#creator_agree').is(':checked')) {
            toastr.error('Please accept the Creator Agreement and Content Policy');
            return;
        }
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'become_creator', { accept_agreement: 1 }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = '/account/settings?section=creator'; }, 1000);
            } else {
                toastr.error(o.message);
                $btn.prop('disabled', false);
            }
        });
    });

    function uploadCreatorImage(kind, file, filename, $preview) {
        var fd = new FormData();
        fd.append('kind', kind);
        fd.append('image', file, filename);
        $.ajax({
            url: ApiDataSvc.baseUrl + 'upload_creator_image',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function (data) {
                var o = JSON.parse(data);
                if (o.success) {
                    toastr.success(o.message);
                    $preview.css('background-image', "url('" + o.url + "')").find('i').remove();
                    $('#' + kind + '_remove').removeAttr('hidden');
                } else {
                    toastr.error(o.message);
                }
            },
            error: function () { toastr.error('Upload failed'); }
        });
    }

    // Required output sizes per image kind.
    var CROP_SPEC = {
        avatar: { ratio: 1,   width: 512,  height: 512, label: 'Square, 512×512' },
        cover:  { ratio: 3,   width: 1500, height: 500, label: 'Wide banner, 1500×500' }
    };
    var cropper = null, cropKind = null, cropTarget = null;

    function openCropper(kind, file, $preview) {
        cropKind = kind;
        cropTarget = $preview;
        $('#crop_hint').text(CROP_SPEC[kind].label);
        var reader = new FileReader();
        reader.onload = function (e) { $('#crop_img').attr('src', e.target.result); $('#crop_modal').modal('show'); };
        reader.readAsDataURL(file);
    }

    $('#crop_modal').on('shown.bs.modal', function () {
        if (cropper) { cropper.destroy(); }
        cropper = new Cropper(document.getElementById('crop_img'), {
            aspectRatio: CROP_SPEC[cropKind].ratio,
            viewMode: 1, autoCropArea: 1, background: false, responsive: true
        });
    }).on('hidden.bs.modal', function () {
        if (cropper) { cropper.destroy(); cropper = null; }
    });

    $('#crop_confirm').on('click', function () {
        if (!cropper) { return; }
        var spec = CROP_SPEC[cropKind];
        cropper.getCroppedCanvas({ width: spec.width, height: spec.height, imageSmoothingQuality: 'high' })
            .toBlob(function (blob) {
                uploadCreatorImage(cropKind, blob, cropKind + '.jpg', cropTarget);
                $('#crop_modal').modal('hide');
            }, 'image/jpeg', 0.9);
    });

    $('#cover_upload_btn').on('click', function () { $('#cover_file').trigger('click'); });
    $('#avatar_upload_btn').on('click', function () { $('#avatar_file').trigger('click'); });
    $('#cover_file').on('change', function () { if (this.files[0]) { openCropper('cover', this.files[0], $('#cover_preview')); this.value = ''; } });
    $('#avatar_file').on('change', function () { if (this.files[0]) { openCropper('avatar', this.files[0], $('#avatar_preview')); this.value = ''; } });

    $('#avatar_remove, #cover_remove').on('click', function () {
        var kind = this.id === 'avatar_remove' ? 'avatar' : 'cover';
        ApiDataSvc.apiCall('post', 'remove_creator_image', { kind: kind }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            var $preview = $('#' + kind + '_preview').css('background-image', '');
            if (kind === 'avatar' && !$preview.find('i').length) {
                $preview.append('<i class="fa-solid fa-user"></i>');
            }
            $('#' + kind + '_remove').attr('hidden', 'hidden');
        });
    });

    var $taglist = $('#tag_list');

    function addTag(text) {
        text = (text || '').replace(/,/g, '').trim();
        if (text === '') { return; }
        var dup = false;
        $taglist.find('.taginput__chip').each(function () {
            if (($(this).data('tag') + '').toLowerCase() === text.toLowerCase()) { dup = true; }
        });
        if (dup) { return; }
        var $chip = $('<span class="taginput__chip"></span>').attr('data-tag', text).text(text);
        $chip.append('<button type="button" class="taginput__remove" aria-label="Remove tag">×</button>');
        $taglist.append($chip);
    }

    $('#tag_entry').on('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addTag(this.value);
            this.value = '';
        } else if (e.key === 'Backspace' && this.value === '') {
            $taglist.find('.taginput__chip').last().remove();
        }
    }).on('blur', function () {
        if (this.value.trim() !== '') { addTag(this.value); this.value = ''; }
    });

    $taglist.on('click', '.taginput__remove', function () { $(this).closest('.taginput__chip').remove(); });

    function collectTags() {
        return $taglist.find('.taginput__chip').map(function () { return $(this).data('tag'); }).get().join(',');
    }

    $('#cp_save').on('click', function () {
        if (($('#cp_display_name').val() || '').trim() === '') {
            toastr.error('Display name is required');
            $('#cp_display_name').focus();
            return;
        }
        ApiDataSvc.apiCall('post', 'save_creator_profile', {
            display_name: $('#cp_display_name').val(),
            bio:          $('#cp_bio').val(),
            location:     $('#cp_location').val(),
            tags:         collectTags()
        }, function (data) {
            var o = JSON.parse(data);
            if (o.success) { toastr.success(o.message); } else { toastr.error(o.message); }
        });
    });

    $('#payout_setup_btn').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'start_payout_onboarding', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                window.location = o.url;
            } else {
                toastr.error(o.message);
                $btn.prop('disabled', false);
            }
        });
    });

    $('#payout_request_btn').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'request_payout', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = '/account/settings?section=payouts'; }, 1000);
            } else {
                toastr.error(o.message);
                $btn.prop('disabled', false);
            }
        });
    });

    $('#payout_disconnect_btn').on('click', function () {
        $('#payout_disconnect_modal').modal('show');
    });

    $('#payout_disconnect_confirm').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'disconnect_payout_account', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = '/account/settings?section=payouts'; }, 900);
            } else {
                toastr.error(o.message);
                $btn.prop('disabled', false);
                $('#payout_disconnect_modal').modal('hide');
            }
        });
    });

    function renderLinkRow(id, title, url, enabled) {
        return '<div class="link-row" data-id="' + id + '">'
            + '<span class="link-row__handle"><i class="fa-solid fa-grip-vertical"></i></span>'
            + '<div class="link-row__info"><span class="link-row__title">' + escapeHtml(title) + '</span>'
            + '<span class="link-row__url">' + escapeHtml(url) + '</span></div>'
            + '<button type="button" class="link-row__btn link-edit" aria-label="Edit link"><i class="fa-solid fa-pen"></i></button>'
            + '<button type="button" class="link-row__btn link-delete" aria-label="Remove link"><i class="fa-solid fa-trash"></i></button>'
            + '</div>';
    }

    if (window.Sortable && document.getElementById('links_list')) {
        Sortable.create(document.getElementById('links_list'), {
            handle: '.link-row__handle', animation: 150,
            onEnd: function () {
                var ids = $('#links_list .link-row').map(function () { return $(this).data('id'); }).get();
                ApiDataSvc.apiCall('post', 'reorder_creator_links', { ids: ids }, function () {});
            }
        });
    }

    $('#link_add_btn').on('click', function () {
        $('#link_id').val('0');
        $('#link_title').val('');
        $('#link_url').val('');
        $('#link_modal_title').text('Add link');
        $('#link_modal').modal('show');
    });

    $('#links_list').on('click', '.link-edit', function () {
        var $row = $(this).closest('.link-row');
        $('#link_id').val($row.data('id'));
        $('#link_title').val($row.find('.link-row__title').text());
        $('#link_url').val($row.find('.link-row__url').text());
        $('#link_modal_title').text('Edit link');
        $('#link_modal').modal('show');
    });

    $('#link_save').on('click', function () {
        var id = $('#link_id').val();
        var title = ($('#link_title').val() || '').trim();
        var url = ($('#link_url').val() || '').trim();
        if (title === '') { toastr.error('A label is required'); return; }
        if (url === '') { toastr.error('A URL is required'); return; }
        ApiDataSvc.apiCall('post', 'save_creator_link', { id: id, title: title, url: url }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            if (parseInt(id, 10) > 0) {
                var $row = $('#links_list .link-row[data-id="' + id + '"]');
                $row.find('.link-row__title').text(title);
                $row.find('.link-row__url').text(url);
            } else {
                $('#links_empty').attr('hidden', 'hidden');
                $('#links_list').append(renderLinkRow(o.id, title, url, true));
            }
            $('#link_modal').modal('hide');
        });
    });

    $('#links_list').on('click', '.link-delete', function () {
        var $row = $(this).closest('.link-row');
        var id = $row.data('id');
        ApiDataSvc.apiCall('post', 'delete_creator_link', { id: id }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            $row.remove();
            if ($('#links_list .link-row').length === 0) { $('#links_empty').removeAttr('hidden'); }
        });
    });

    $('#leave_creator_btn').on('click', function () {
        $('#leave_creator_modal').modal('show');
    });

    $('#leave_creator_confirm').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'leave_creator', { confirm: 1 }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = '/account/settings?section=creator'; }, 1000);
            } else {
                toastr.error(o.message);
                $btn.prop('disabled', false);
                $('#leave_creator_modal').modal('hide');
            }
        });
    });

    $('.conn-connect').on('click', function () {
        var platform = $(this).data('platform');
        ApiDataSvc.apiCall('post', 'connect_account', { platform: platform }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                window.location = o.url;
            } else {
                toastr.error(o.message);
            }
        });
    });

    $('.conn-disconnect').on('click', function () {
        var account_id = $(this).data('account-id');
        ApiDataSvc.apiCall('post', 'disconnect_account', { account_id: account_id }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = '/account/settings?section=connected'; }, 800);
            } else {
                toastr.error(o.message);
            }
        });
    });

});
</script>
