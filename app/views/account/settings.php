<link rel="stylesheet" href="/css/account-settings.css?v=<?php echo @filemtime(Main::app_path().'/public/css/account-settings.css'); ?>">
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
            <?php if ($this->can_content): ?>
            <button type="button" class="settings__nav-item" data-section="creator"><i class="fa-solid fa-star"></i><span>Creator Profile</span></button>
            <?php elseif (!$this->is_creator): ?>
            <button type="button" class="settings__nav-item" data-section="creator"><i class="fa-solid fa-star"></i><span>Become a Creator</span></button>
            <?php endif; ?>
            <?php if ($this->can_content): ?>
            <button type="button" class="settings__nav-item" data-section="brand"><i class="fa-solid fa-wand-magic-sparkles"></i><span>Brand Identity</span></button>
            <?php endif; ?>
            <?php if ($this->can_manage): ?>
            <button type="button" class="settings__nav-item" data-section="plans"><i class="fa-solid fa-gem"></i><span>Membership Plans</span></button>
            <?php endif; ?>
            <button type="button" class="settings__nav-item" data-section="subscriptions"><i class="fa-solid fa-heart"></i><span>My Subscriptions</span></button>
            <button type="button" class="settings__nav-item" data-section="wallet"><i class="fa-solid fa-wallet"></i><span>Wallet</span></button>
            <button type="button" class="settings__nav-item" data-section="privacy"><i class="fa-solid fa-shield-halved"></i><span>Restricted Content</span></button>
            <button type="button" class="settings__nav-item" data-section="blocked"><i class="fa-solid fa-ban"></i><span>Blocked Users</span></button>
            <?php if ($this->can_manage): ?>
            <button type="button" class="settings__nav-item" data-section="inbox"><i class="fa-solid fa-robot"></i><span>Inbox Automation</span><span class="settings__nav-badge" id="inboxNavBadge" <?php echo $this->inbox_pending > 0 ? '' : 'hidden'; ?>><?php echo (int) $this->inbox_pending; ?></span></button>
            <button type="button" class="settings__nav-item" data-section="connected"><i class="fa-solid fa-share-nodes"></i><span>Integrations</span></button>
            <?php endif; ?>
        </nav>

        <div class="settings__content">

            <section class="settings__section is-active" data-section="account">
                <?php $my_av = (string) ($this->my_avatar ?? ''); ?>
                <div class="myav">
                    <label class="uname__label">Profile Photo</label>
                    <div class="myav__row">
                        <div class="cprofile__avatar myav__img" id="my_avatar_preview" style="<?php echo $my_av !== '' ? 'background-image:url(\'' . htmlspecialchars($my_av, ENT_QUOTES, 'UTF-8') . '\')' : ''; ?>">
                            <?php if ($my_av === ''): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-secondary" id="my_avatar_upload_btn"><i class="fa-solid fa-camera"></i> Upload Photo</button>
                        <button type="button" class="btn btn-secondary" id="my_avatar_remove" <?php echo $my_av === '' ? 'hidden' : ''; ?>>Remove</button>
                    </div>
                    <input type="file" id="my_avatar_file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                </div>
                <?php $next_change = $this->username_next_change; ?>
                <div class="uname">
                    <label class="uname__label" for="account_username">Username</label>
                    <?php if ($this->is_owner_creator): ?>
                    <p class="uname__hint">Your public handle:
                        <a class="uname__url uname__url--link" id="uname_link" href="/@<?php echo htmlspecialchars(rawurlencode((string) $this->user['u_name']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string) $this->public_domain, ENT_QUOTES, 'UTF-8'); ?>/@<span id="uname_preview"><?php echo htmlspecialchars((string) $this->user['u_name'], ENT_QUOTES, 'UTF-8'); ?></span> <i class="fa-solid fa-arrow-up-right-from-square uname__url-icon"></i></a>
                    </p>
                    <?php else: ?>
                    <p class="uname__hint">Used to sign in to your account.</p>
                    <?php endif; ?>
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
                    <?php if ($this->is_owner_creator): ?>
                    <div class="cprofile__verify cprofile__verify--<?php echo $this->verified ? 'done' : ($this->verif_status === 'pending' ? 'pending' : 'none'); ?>">
                        <?php if ($this->verified): ?>
                            <span class="cprofile__verify-ic"><i class="fa-solid fa-circle-check"></i></span>
                            <div class="cprofile__verify-txt"><strong>Verified creator</strong><span>Your identity is verified — the badge shows on your profile.</span></div>
                        <?php elseif ($this->verif_status === 'pending'): ?>
                            <span class="cprofile__verify-ic"><i class="fa-solid fa-clock"></i></span>
                            <div class="cprofile__verify-txt"><strong>Verification pending</strong><span>We're reviewing your request — you'll get the badge once approved.</span></div>
                        <?php else: ?>
                            <span class="cprofile__verify-ic"><i class="fa-solid fa-shield-halved"></i></span>
                            <div class="cprofile__verify-txt"><strong>Get verified</strong><span>Add a verified badge so fans know it's really you.</span></div>
                            <button type="button" class="btn btn-primary" id="verify_request_btn">Request verification</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

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

                    <div class="cprofile__actions">
                        <button type="button" class="btn btn-primary" id="cp_save">Save Changes</button>
                    </div>

                    <div class="cprofile__divider"></div>

                    <div class="cprofile__subhead">
                        <h3 class="cprofile__subtitle">Links</h3>
                        <button type="button" class="btn btn-secondary" id="link_add_btn"><i class="fa-solid fa-plus"></i> Add link</button>
                    </div>
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

            <?php if ($this->can_content): $cb = $this->creator_brand; ?>
            <section class="settings__section" data-section="brand">
                <div class="settings__section-head">
                    <h2 class="settings__section-title">Brand Identity</h2>
                    <p class="settings__section-desc">Paste your website and we'll draft a brand kit — name, tagline, voice, colours, and keywords — from your own content. Review, tweak, then save.</p>
                </div>
                <div class="cprofile">
                    <div class="cprofile__field">
                        <label for="brand_url">Website URL</label>
                        <div class="brand-gen__row">
                            <input type="url" class="form-control" id="brand_url" placeholder="https://yoursite.com" value="<?php echo htmlspecialchars((string) ($cb['source_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="button" class="btn btn-primary" id="brand_generate"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
                        </div>
                        <span class="cprofile__hint">We read the page and draft an identity. Nothing is saved until you hit Save.</span>
                    </div>

                    <div class="brand-result" id="brand_result" <?php echo (empty($cb['brand_name']) && empty($cb['tagline'])) ? 'hidden' : ''; ?>>
                        <div class="cprofile__grid">
                            <div class="cprofile__field">
                                <label for="brand_name">Brand name</label>
                                <input type="text" class="form-control" id="brand_name" maxlength="190" value="<?php echo htmlspecialchars((string) ($cb['brand_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="cprofile__field">
                                <label for="brand_tagline">Tagline</label>
                                <input type="text" class="form-control" id="brand_tagline" maxlength="255" value="<?php echo htmlspecialchars((string) ($cb['tagline'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="cprofile__field">
                            <label for="brand_description">Description</label>
                            <textarea class="form-control" id="brand_description" rows="2"><?php echo htmlspecialchars((string) ($cb['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="cprofile__field">
                            <label for="brand_voice">Voice &amp; tone</label>
                            <textarea class="form-control" id="brand_voice" rows="2"><?php echo htmlspecialchars((string) ($cb['voice'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="cprofile__field">
                            <label>Colours</label>
                            <div class="brand-colors" id="brand_colors">
                                <?php foreach (($cb['colors'] ?? array()) as $hex): $h = htmlspecialchars($hex, ENT_QUOTES, 'UTF-8'); ?>
                                <span class="brand-swatch" data-hex="<?php echo $h; ?>"><span class="brand-swatch__dot" style="background:<?php echo $h; ?>"></span><?php echo $h; ?><button type="button" class="brand-swatch__x" aria-label="Remove colour">&times;</button></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="cprofile__field">
                            <label>Keywords</label>
                            <div class="brand-keywords" id="brand_keywords">
                                <?php foreach (($cb['keywords'] ?? array()) as $kw): $k = htmlspecialchars($kw, ENT_QUOTES, 'UTF-8'); ?>
                                <span class="brand-chip" data-kw="<?php echo $k; ?>"><?php echo $k; ?><button type="button" class="brand-chip__x" aria-label="Remove keyword">&times;</button></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="cprofile__actions">
                            <button type="button" class="btn btn-primary" id="brand_save">Save Brand Identity</button>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($this->can_manage): ?>
            <section class="settings__section" data-section="plans">
                <div class="links-head">
                    <h3 class="links-head__title">Membership Plans</h3>
                    <button type="button" class="btn btn-secondary" id="plan_add_btn"><i class="fa-solid fa-plus"></i> Add Plan</button>
                </div>
                <p class="acct-card__desc" style="margin:-.4rem 0 1.1rem;">Create tiers your fans can subscribe to. Drag to reorder; toggle to show or hide a tier on your profile.</p>
                <div class="links-card">
                    <div class="plans-list" id="plans_list">
                        <?php foreach ($this->creator_plans as $plan): ?>
                        <?php
                            $unit = $plan['billing_interval'] === 'year' ? 'yr' : ($plan['billing_interval'] === 'week' ? 'wk' : 'mo');
                            $tunit = in_array(($plan['trial_unit'] ?? 'day'), array('day','week','month'), true) ? $plan['trial_unit'] : 'day';
                            $tenabled = !empty($plan['trial_enabled']) && (int) ($plan['trial_value'] ?? 0) > 0;
                            $tval = $tenabled ? (int) $plan['trial_value'] : 7;
                        ?>
                        <div class="plan-row<?php echo empty($plan['is_active']) ? ' is-inactive' : ''; ?>" data-id="<?php echo (int) $plan['id']; ?>"
                             data-name="<?php echo htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-price="<?php echo htmlspecialchars(number_format($plan['price_cents'] / 100, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                             data-interval="<?php echo htmlspecialchars((string) $plan['billing_interval'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-trial-enabled="<?php echo $tenabled ? 1 : 0; ?>" data-trial-value="<?php echo $tval; ?>" data-trial-unit="<?php echo htmlspecialchars($tunit, ENT_QUOTES, 'UTF-8'); ?>"
                             data-description="<?php echo htmlspecialchars((string) $plan['description'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-perks="<?php echo htmlspecialchars((string) $plan['perks'], ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="link-row__handle"><i class="fa-solid fa-grip-vertical"></i></span>
                            <div class="plan-row__info">
                                <span class="plan-row__name"><?php echo htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="plan-row__price"><?php if ((int) $plan['price_cents'] === 0): ?>Free<?php else: ?>$<?php echo number_format($plan['price_cents'] / 100, 2); ?><span class="plan-row__unit">/<?php echo $unit; ?></span><?php endif; ?><?php if ($tenabled): ?> <span class="plan-row__trial"><?php echo $tval . '-' . $tunit; ?> trial</span><?php endif; ?></span>
                            </div>
                            <label class="plan-row__switch" title="Active">
                                <input type="checkbox" class="plan-toggle" <?php echo !empty($plan['is_active']) ? 'checked' : ''; ?>>
                                <span class="plan-row__slider"></span>
                            </label>
                            <button type="button" class="link-row__btn plan-edit" aria-label="Edit plan"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="link-row__btn plan-delete" aria-label="Remove plan"><i class="fa-solid fa-trash"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="settings__empty links-empty" id="plans_empty" <?php echo empty($this->creator_plans) ? '' : 'hidden'; ?>>No membership plans yet. Add a tier your fans can subscribe to.</p>
                </div>

                <?php if (!empty($this->can_promo)): ?>
                <div class="links-head" style="margin-top:2rem;">
                    <h3 class="links-head__title">Discount codes</h3>
                    <button type="button" class="btn btn-secondary" id="promo_add_btn"><i class="fa-solid fa-plus"></i> Add code</button>
                </div>
                <p class="acct-card__desc" style="margin:-.4rem 0 1.1rem;">Percent-off codes fans apply at checkout. Toggle to enable; set a redemption cap or expiry.</p>
                <div class="links-card">
                    <div class="promos-list" id="promos_list">
                        <?php foreach ($this->promo_codes as $pc): $applies = $pc['applies_to'] === 'ppv' ? 'Pay-per-view' : ($pc['applies_to'] === 'subscription' ? 'Subscriptions' : 'Subs &amp; PPV'); ?>
                        <div class="plan-row<?php echo empty($pc['is_active']) ? ' is-inactive' : ''; ?>" data-id="<?php echo (int) $pc['id']; ?>"
                             data-code="<?php echo htmlspecialchars((string) $pc['code'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-percent="<?php echo (int) $pc['percent_off']; ?>"
                             data-applies="<?php echo htmlspecialchars((string) $pc['applies_to'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-max="<?php echo $pc['max_redemptions'] === null ? '' : (int) $pc['max_redemptions']; ?>"
                             data-expires="<?php echo $pc['expires_at'] ? htmlspecialchars(date('Y-m-d', strtotime((string) $pc['expires_at'])), ENT_QUOTES, 'UTF-8') : ''; ?>">
                            <div class="plan-row__info">
                                <span class="plan-row__name"><?php echo htmlspecialchars((string) $pc['code'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="plan-row__price"><?php echo (int) $pc['percent_off']; ?>% off<span class="plan-row__unit"> &middot; <?php echo $applies; ?> &middot; <?php echo (int) $pc['redemptions']; ?> used</span></span>
                            </div>
                            <label class="plan-row__switch" title="Active"><input type="checkbox" class="promo-toggle" <?php echo !empty($pc['is_active']) ? 'checked' : ''; ?>><span class="plan-row__slider"></span></label>
                            <button type="button" class="link-row__btn promo-edit" aria-label="Edit code"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="link-row__btn promo-delete" aria-label="Remove code"><i class="fa-solid fa-trash"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="settings__empty links-empty" id="promos_empty" <?php echo empty($this->promo_codes) ? '' : 'hidden'; ?>>No discount codes yet. Create one fans can use at checkout.</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($this->can_bundles)): ?>
                <div class="links-head" style="margin-top:2rem;">
                    <h3 class="links-head__title">Content bundles</h3>
                    <button type="button" class="btn btn-secondary" id="bundle_add_btn"><i class="fa-solid fa-plus"></i> Add bundle</button>
                </div>
                <p class="acct-card__desc" style="margin:-.4rem 0 1.1rem;">Sell a group of your Library content (photos &amp; videos) together at one price. Buyers get every item in their Purchases.</p>
                <div class="links-card">
                    <div class="bundles-list" id="bundles_list">
                        <?php foreach ($this->content_bundles as $bd): ?>
                        <div class="plan-row<?php echo empty($bd['is_active']) ? ' is-inactive' : ''; ?>" data-id="<?php echo (int) $bd['id']; ?>"
                             data-name="<?php echo htmlspecialchars((string) $bd['name'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-description="<?php echo htmlspecialchars((string) $bd['description'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-price="<?php echo (int) $bd['price_credits']; ?>"
                             data-assets="<?php echo htmlspecialchars(implode(',', array_map('intval', (array) $bd['asset_ids'])), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="plan-row__info">
                                <span class="plan-row__name"><?php echo htmlspecialchars((string) $bd['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="plan-row__price">$<?php echo (int) round($bd['price_credits'] / 10); ?><span class="plan-row__unit"> &middot; <?php echo (int) $bd['price_credits']; ?> cr &middot; <?php echo (int) $bd['item_count']; ?> item<?php echo (int) $bd['item_count'] === 1 ? '' : 's'; ?></span></span>
                            </div>
                            <label class="plan-row__switch" title="Active"><input type="checkbox" class="bundle-toggle" <?php echo !empty($bd['is_active']) ? 'checked' : ''; ?>><span class="plan-row__slider"></span></label>
                            <button type="button" class="link-row__btn bundle-edit" aria-label="Edit bundle"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="link-row__btn bundle-delete" aria-label="Remove bundle"><i class="fa-solid fa-trash"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="settings__empty links-empty" id="bundles_empty" <?php echo empty($this->content_bundles) ? '' : 'hidden'; ?>>No bundles yet. Group content from your Library into a bundle fans can buy.</p>
                </div>
                <?php endif; ?>
            </section>

            <?php endif; ?>

            <section class="settings__section" data-section="subscriptions">
                <p class="acct-card__desc" style="margin:0 0 1.1rem;">Creators you support. Cancel or resume anytime.</p>
                <div class="subs-list" id="subs_list">
                    <?php foreach ($this->my_subscriptions as $sub): ?>
                    <?php
                        $s_free   = !empty($sub['is_free']);
                        $s_unit   = $sub['billing_interval'] === 'year' ? 'year' : ($sub['billing_interval'] === 'week' ? 'week' : 'month');
                        $s_period = !empty($sub['current_period_end']) ? date('M j, Y', strtotime((string) $sub['current_period_end'])) : '';
                        $s_active = ($sub['status'] === 'active');
                        $s_cancel = $s_active && !empty($sub['cancel_at_period_end']);
                        $s_handle = (string) $sub['creator_handle'];
                    ?>
                    <div class="sub-row" data-id="<?php echo (int) $sub['id']; ?>" data-free="<?php echo $s_free ? 1 : 0; ?>" data-period="<?php echo htmlspecialchars($s_period, ENT_QUOTES, 'UTF-8'); ?>" data-handle="<?php echo htmlspecialchars(rawurlencode($s_handle), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="sub-row__info">
                            <a class="sub-row__creator" href="/@<?php echo htmlspecialchars(rawurlencode($s_handle), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string) $sub['creator_name'], ENT_QUOTES, 'UTF-8'); ?></a>
                            <span class="sub-row__plan"><?php echo htmlspecialchars((string) $sub['plan_name'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo $s_free ? 'Free' : '$' . number_format($sub['price_cents'] / 100, 2) . '/' . $s_unit; ?></span>
                            <span class="sub-row__status" data-status><?php
                                if (!$s_active) { echo 'Canceled'; }
                                elseif ($s_cancel) { echo 'Ends ' . htmlspecialchars($s_period, ENT_QUOTES, 'UTF-8'); }
                                elseif ($s_free) { echo 'Active'; }
                                elseif ($s_period !== '') { echo 'Renews ' . htmlspecialchars($s_period, ENT_QUOTES, 'UTF-8'); }
                                else { echo 'Active'; }
                            ?></span>
                        </div>
                        <div class="sub-row__actions" data-actions>
                            <?php if (!$s_active): ?>
                                <?php if ($s_free): ?><button type="button" class="btn btn-secondary sub-reactivate">Rejoin</button>
                                <?php else: ?><a class="btn btn-secondary" href="/@<?php echo htmlspecialchars(rawurlencode($s_handle), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Subscribe again</a><?php endif; ?>
                            <?php elseif ($s_cancel): ?>
                                <button type="button" class="btn btn-secondary sub-reactivate">Resume</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary sub-cancel"><?php echo $s_free ? 'Leave' : 'Cancel'; ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="links-card" id="subs_empty"<?php echo empty($this->my_subscriptions) ? '' : ' hidden'; ?>>
                    <p class="settings__empty links-empty">You're not subscribed to any creators yet.</p>
                </div>
            </section>

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
                    <!-- Balance is always pinned at the top of the Wallet. -->
                    <div class="wallet__balance">
                        <span class="wallet__balance-label">Current Balance</span>
                        <span class="wallet__balance-value"><i class="fa-solid fa-coins"></i> <span id="credit_balance"><?php echo number_format((int) $this->credit_balance); ?></span> credits</span>
                    </div>

                    <div class="wallet-tabs" role="tablist">
                        <button type="button" class="wallet-tab is-active" data-wtab="buy">Buy Credits</button>
                        <?php if ($this->is_owner_creator): ?><button type="button" class="wallet-tab" data-wtab="cashout">Cash Out</button><?php endif; ?>
                        <button type="button" class="wallet-tab" data-wtab="history">History</button>
                        <button type="button" class="wallet-tab" data-wtab="auto">Auto-Replenishment</button>
                    </div>

                    <div class="wallet-panel is-active" data-wpanel="buy">
                        <div class="credit-packs">
                            <?php foreach ($this->credit_packages as $pkg): $pkg_fee = (int) $pkg['dollars'] * Main::credit_fee_percent() / 100; ?>
                            <button type="button" class="credit-pack buy-credits" data-dollars="<?php echo (int) $pkg['dollars']; ?>">
                                <span class="credit-pack__credits"><?php echo number_format((int) $pkg['credits']); ?></span>
                                <span class="credit-pack__label">credits</span>
                                <span class="credit-pack__price">$<?php echo number_format((int) $pkg['dollars'], 2); ?></span>
                                <span class="credit-pack__fee">+ $<?php echo number_format($pkg_fee, 2); ?> fee · $<?php echo number_format((int) $pkg['dollars'] + $pkg_fee, 2); ?> total</span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="wallet__fee-note">A <?php echo (int) Main::credit_fee_percent(); ?>% processing fee is added at checkout.</p>
                    </div>

                    <?php if ($this->is_owner_creator): $ps = $this->payout_status; $pb = $this->payout_balance; ?>
                    <div class="wallet-panel" data-wpanel="cashout">
                        <?php if (empty($ps['payouts_enabled'])): ?>
                        <div class="payout-setup">
                            <div class="payout-setup__info">
                                <span class="payout-setup__title"><?php echo !empty($ps['details_submitted']) ? 'Finish setting up payouts' : 'Set up payouts'; ?></span>
                                <span class="payout-setup__desc"><?php echo !empty($ps['details_submitted']) ? 'Stripe needs a little more information before you can cash out.' : 'Connect a Stripe account to cash your credits out to your bank.'; ?></span>
                            </div>
                            <button type="button" class="btn btn-primary" id="payout_setup_btn"><?php echo !empty($ps['details_submitted']) ? 'Continue setup' : 'Set up payouts'; ?></button>
                        </div>
                        <?php if ($this->has_connect): ?>
                        <div class="payout-disconnect"><button type="button" class="payout-disconnect__link" id="payout_disconnect_btn">Disconnect Stripe account</button></div>
                        <?php endif; ?>
                        <?php else: $avail_credits = (int) ($pb['available_credits'] ?? 0); $min_credits = 100; ?>
                        <div class="payout-balance">
                            <div class="payout-balance__cell">
                                <span class="payout-balance__label">Available to cash out</span>
                                <span class="payout-balance__value">$<?php echo number_format($pb['available'] / 100, 2); ?></span>
                                <span class="payout-balance__sub"><?php echo number_format($avail_credits); ?> credits</span>
                            </div>
                            <div class="payout-balance__cell">
                                <span class="payout-balance__label">In transit</span>
                                <span class="payout-balance__value">$<?php echo number_format($pb['pending'] / 100, 2); ?></span>
                                <span class="payout-balance__sub">on the way to your bank</span>
                            </div>
                        </div>
                        <div class="payout-actions">
                            <button type="button" class="btn btn-primary" id="payout_request_btn" <?php echo $avail_credits < $min_credits ? 'disabled' : ''; ?>>Cash out</button>
                            <?php if ($avail_credits < $min_credits): ?><span class="payout-actions__note">You need at least <?php echo $min_credits; ?> credits ($<?php echo number_format($min_credits / 10, 2); ?>) to cash out.</span><?php endif; ?>
                        </div>
                        <div class="payout-disconnect"><button type="button" class="payout-disconnect__link" id="payout_disconnect_btn">Disconnect Stripe account</button></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="wallet-panel" data-wpanel="history">
                        <div id="credit_history">
                        <?php if (empty($this->credit_transactions)): ?>
                            <p class="settings__empty">No credit activity yet.</p>
                        <?php else: ?>
                        <?php $stripe_payment_url = (strpos((string) $this->stripe_pk, 'pk_test') === 0) ? 'https://dashboard.stripe.com/test/payments/' : 'https://dashboard.stripe.com/payments/'; ?>
                        <table class="ledger">
                            <thead><tr><th>Date</th><th>Activity</th><th class="ledger__num">Amount</th></tr></thead>
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
                                    <td class="ledger__num <?php echo ((int) $t['credits'] >= 0) ? 'ledger__pos' : 'ledger__neg'; ?>"><?php
                                        $c = (int) $t['credits'];
                                        // A cash-out leaves the credit economy as real money — show it in dollars.
                                        if (($t['type'] ?? '') === 'payout') {
                                            echo '-$' . number_format(abs($c) / 10, 2);
                                        } else {
                                            echo ($c >= 0 ? '+' : '') . number_format($c) . ' cr';
                                        }
                                    ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                        </div>
                    </div>

                    <div class="wallet-panel" data-wpanel="auto">
                        <div class="wallet__ar-summary">
                            <div class="wallet__ar-summary-info">
                                <span class="wallet__ar-summary-badge <?php echo $ar_on ? 'is-on' : ''; ?>" id="ar_badge"><?php echo $ar_on ? 'On' : 'Off'; ?></span>
                                <span class="wallet__ar-summary-status" id="ar_status"><?php echo htmlspecialchars($ar_status, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <button type="button" class="btn btn-secondary" id="ar_manage">Manage</button>
                        </div>
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

            <?php if ($this->can_manage): ?>
            <section class="settings__section" data-section="inbox">
                <div class="settings__section-head">
                    <h2 class="settings__section-title">Inbox Automation</h2>
                    <p class="settings__section-desc">Answer fan messages in your own voice, automatically. Drafts wait for your approval until you decide to let them send on their own.</p>
                </div>

                <?php if (!$this->can_inbox): ?>
                    <div class="settings__upgrade">
                        <i class="fa-solid fa-lock settings__upgrade-icon"></i>
                        <div>
                            <div class="settings__upgrade-title">Managed by the account owner</div>
                            <p class="settings__upgrade-text">Inbox automation is set up by the owner or a manager on this account.</p>
                        </div>
                    </div>
                <?php else: ?>
                <?php $is = $this->inbox_settings; ?>

                <?php if (!$this->claude_ok): ?>
                    <p class="inbox-note inbox-note--warn"><i class="fa-solid fa-triangle-exclamation"></i> AI replies are not configured on this server yet.</p>
                <?php endif; ?>

                <div class="inbox-tabs" id="inboxTabs" role="tablist">
                    <button type="button" class="inbox-tabs__tab is-active" data-tab="queue" role="tab">Queue<span class="inbox-tabs__count" id="inboxQueueCount"></span></button>
                    <button type="button" class="inbox-tabs__tab" data-tab="settings" role="tab">Settings</button>
                    <button type="button" class="inbox-tabs__tab" data-tab="messages" role="tab">Welcome messages</button>
                </div>

                <div class="inbox-panel" data-tab="queue">
                <div class="inbox-block">
                    <div class="inbox-block__head">
                        <h3 class="inbox-block__title">Waiting for your approval</h3>
                    </div>
                    <div id="inboxQueue" class="inbox-queue"><p class="settings__empty">Loading&hellip;</p></div>
                </div>
                <div class="inbox-block">
                    <div class="inbox-block__head"><h3 class="inbox-block__title">Recent activity</h3></div>
                    <div id="inboxLog" class="inbox-log"></div>
                </div>
                </div>

                <div class="inbox-panel" data-tab="settings" hidden>
                <div class="cprofile">
                    <div class="inbox-toggles">
                        <label class="inbox-toggle">
                            <span class="notif__switch"><input type="checkbox" id="inboxCls" <?php echo !empty($is['cls_enabled']) ? 'checked' : ''; ?>><span class="notif__slider"></span></span>
                            <span class="inbox-toggle__text"><strong>Reply to fan messages</strong><small>Drafts an answer in your voice whenever a fan DMs you.</small></span>
                        </label>
                    </div>

                    <div class="cprofile__field">
                        <label>How replies go out</label>
                        <div class="inbox-radios">
                            <label class="inbox-radio"><input type="radio" name="inbox_mode" value="approve" <?php echo ($is['mode'] !== 'auto') ? 'checked' : ''; ?>><span><strong>Approve first</strong><small>Each draft waits here until you send, edit, or dismiss it.</small></span></label>
                            <label class="inbox-radio"><input type="radio" name="inbox_mode" value="auto" <?php echo ($is['mode'] === 'auto') ? 'checked' : ''; ?>><span><strong>Send automatically</strong><small>Replies go straight to the fan after a short, natural pause.</small></span></label>
                        </div>
                    </div>

                    <div class="inbox-grid">
                        <div class="cprofile__field">
                            <label for="inboxQuietStart">Quiet hours</label>
                            <div class="inbox-quiet">
                                <input type="time" class="form-control" id="inboxQuietStart" value="<?php echo htmlspecialchars((string) ($is['quiet_start'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="inbox-quiet__to">to</span>
                                <input type="time" class="form-control" id="inboxQuietEnd" value="<?php echo htmlspecialchars((string) ($is['quiet_end'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <span class="cprofile__hint">In your content timezone. Leave both empty for none.</span>
                        </div>
                        <div class="cprofile__field">
                            <label for="inboxQuietAction">During quiet hours</label>
                            <select class="form-select" id="inboxQuietAction">
                                <option value="hold" <?php echo ($is['quiet_action'] !== 'skip') ? 'selected' : ''; ?>>Hold drafts for approval</option>
                                <option value="skip" <?php echo ($is['quiet_action'] === 'skip') ? 'selected' : ''; ?>>Don't reply at all</option>
                            </select>
                        </div>
                        <div class="cprofile__field">
                            <label for="inboxMaxConsecutive">Max replies in a row</label>
                            <input type="number" class="form-control" id="inboxMaxConsecutive" min="1" max="10" value="<?php echo (int) $is['max_consecutive']; ?>">
                            <span class="cprofile__hint">After this many automated replies without you, the AI pauses for that fan.</span>
                        </div>
                    </div>

                    <div class="cprofile__field">
                        <label for="inboxPersona">How you talk to fans</label>
                        <textarea class="form-control" id="inboxPersona" rows="4" maxlength="2000" placeholder="Short and playful. Use their name. Never use emojis. Sign off with x."><?php echo htmlspecialchars((string) $is['persona'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <span class="cprofile__hint">Your Brand Identity voice is used automatically. Add anything specific to DMs here.</span>
                    </div>
                    <div class="cprofile__field">
                        <label for="inboxAvoid">Topics to avoid</label>
                        <textarea class="form-control" id="inboxAvoid" rows="2" maxlength="2000" placeholder="Politics, my family, where I live"><?php echo htmlspecialchars((string) $is['avoid_topics'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <span class="cprofile__hint">Messages about these are held for you instead of answered.</span>
                    </div>

                    <div class="inbox-checks">
                        <label class="inbox-check"><input class="form-check-input" type="checkbox" id="inboxUpsell" <?php echo !empty($is['upsell_enabled']) ? 'checked' : ''; ?>><span><strong>Allow gentle upsells</strong><small>May mention your paid content or tips when it fits naturally. Never on a first message.</small></span></label>
                        <label class="inbox-check"><input class="form-check-input" type="checkbox" id="inboxDisclose" <?php echo !empty($is['disclose_ai']) ? 'checked' : ''; ?>><span><strong>Be honest if asked</strong><small>If a fan asks whether they're talking to a bot, say a helper answers some messages.</small></span></label>
                    </div>

                    <div class="cprofile__field">
                        <label for="inboxTestText">Try it</label>
                        <div class="inbox-test">
                            <input type="text" class="form-control" id="inboxTestText" maxlength="500" placeholder="hey, loved your last post. what are you up to this weekend?">
                            <button type="button" class="btn btn-secondary" id="inboxTest" <?php echo $this->claude_ok ? '' : 'disabled'; ?>>Draft a reply</button>
                        </div>
                        <div class="inbox-test__out" id="inboxTestOut" hidden></div>
                    </div>

                    <div class="cprofile__actions">
                        <button type="button" class="btn btn-primary" id="inboxSave">Save</button>
                    </div>
                </div>
                </div>

                <div class="inbox-panel" data-tab="messages" hidden>
                <div class="inbox-block">
                    <div class="inbox-block__head"><h3 class="inbox-block__title">Welcome &amp; trigger messages</h3></div>
                    <p class="inbox-block__desc">Sent automatically as a direct message, once per fan. Attach media and set a price to sell from the first hello.</p>
                    <div id="inboxTriggers" class="inbox-triggers">
                        <?php foreach (InboxAutomationService::trigger_meta() as $trig => $tm): ?>
                        <div class="inbox-trigger" data-trigger="<?php echo $trig; ?>">
                            <div class="inbox-trigger__head">
                                <div class="inbox-trigger__text">
                                    <div class="inbox-trigger__name"><?php echo htmlspecialchars($tm[0], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="inbox-trigger__hint"><?php echo htmlspecialchars($tm[1], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <span class="inbox-trigger__state" data-role="state">Off</span>
                            </div>
                            <textarea class="form-control inbox-trigger__input" rows="2" maxlength="2000" placeholder="Write the message, or let AI draft one" data-role="text"></textarea>
                            <div class="inbox-trigger__media" data-role="media">
                                <div class="inbox-trigger__thumbs" data-role="thumbs"></div>
                                <button type="button" class="inbox-trigger__attach" data-role="attach"><i class="fa-solid fa-image"></i> Attach Media</button>
                                <label class="inbox-trigger__price" data-role="pricewrap" hidden><span>Price</span><input type="number" class="form-control" min="0" max="500" step="1" placeholder="Free" data-role="price" aria-label="Price in dollars, leave empty for free"></label>
                            </div>
                            <div class="inbox-trigger__actions">
                                <span class="inbox-trigger__sent" data-role="sent"></span>
                                <button type="button" class="btn btn-secondary" data-role="ai" <?php echo $this->claude_ok ? '' : 'disabled'; ?>><i class="fa-solid fa-wand-magic-sparkles"></i> Write with AI</button>
                                <button type="button" class="btn btn-secondary" data-role="off" hidden>Turn Off</button>
                                <button type="button" class="btn btn-primary" data-role="save">Save</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                </div>
                <?php endif; ?>
            </section>

            <section class="settings__section" data-section="connected">
                <div class="settings__section-head">
                    <h2 class="settings__section-title">Integrations</h2>
                    <p class="settings__section-desc">Connect the platforms you publish to and the tools that work on your behalf.</p>
                </div>

                <?php $fv = $this->fanvue; $fv_on = $fv && ($fv['status'] ?? '') === 'connected'; ?>

                <h3 class="integ__group">Platforms &amp; tools</h3>
                <div class="integ">

                    <div class="integ__row">
                        <span class="integ__icon"><i class="fa-solid fa-bolt"></i></span>
                        <div class="integ__main">
                            <div class="integ__name">Fanvue</div>
                            <div class="integ__meta">
                                <?php if ($fv_on): ?>
                                    <span class="integ__dot is-on"></span><?php echo htmlspecialchars(!empty($fv['handle']) ? '@' . $fv['handle'] : (!empty($fv['display_name']) ? $fv['display_name'] : 'Connected'), ENT_QUOTES, 'UTF-8'); ?>
                                    <span class="integ__sep">·</span>Cross-posting
                                <?php elseif (!$this->fanvue_configured): ?>
                                    <span class="integ__dot"></span>Not available yet
                                <?php else: ?>
                                    <span class="integ__dot"></span>Mirror posts and automate your Fanvue inbox
                                <?php endif; ?>
                            </div>
                            <?php if ($fv_on && !empty($fv['last_error'])): ?><div class="integ__error"><?php echo htmlspecialchars($fv['last_error'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                        </div>
                        <div class="integ__actions">
                            <?php if ($fv_on): ?><button type="button" class="btn btn-ghost btn-sm" id="fanvueDisconnect">Disconnect</button>
                            <?php elseif ($this->fanvue_configured): ?><button type="button" class="btn btn-secondary btn-sm" id="fanvueConnect">Connect</button><?php endif; ?>
                        </div>
                    </div>

                    <div class="integ__row">
                        <span class="integ__icon"><i class="fa-solid fa-plug"></i></span>
                        <div class="integ__main">
                            <div class="integ__name">Claude</div>
                            <div class="integ__meta"><span class="integ__dot <?php echo $this->mcp_connected ? 'is-on' : ''; ?>" id="mcpBadge"></span><span id="mcpStatusText"><?php echo $this->mcp_connected ? 'Connector token active' : 'Let Claude act on your account'; ?></span><span class="integ__sep">·</span><a href="#" class="integ__link" id="mcpHelpToggle">Setup guide</a></div>
                            <input type="hidden" id="mcpBaseUrl" value="<?php echo htmlspecialchars($this->mcp_url, ENT_QUOTES, 'UTF-8'); ?>">
                            <div id="mcpTokenReveal" class="integ__reveal" hidden>
                                <div class="integ__label">Connector URL <span class="integ__label-hint">shown once, contains your private token</span></div>
                                <div class="integ__inline">
                                    <input type="text" class="form-control form-control-sm" id="mcpConnectorUrl" readonly onclick="this.select()">
                                    <button type="button" class="btn btn-secondary btn-sm" id="mcpCopy">Copy</button>
                                </div>
                            </div>
                            <ol class="integ__steps" id="mcpHelp" hidden>
                                <li>In Claude, open <strong>Settings &rarr; Connectors</strong>.</li>
                                <li>Choose <strong>Add custom connector</strong> and name it Creator Link Studio.</li>
                                <li>Paste the connector URL into <strong>Remote MCP server URL</strong>. Nothing else is needed.</li>
                            </ol>
                        </div>
                        <div class="integ__actions">
                            <button type="button" class="btn btn-secondary btn-sm" id="mcpGenerate"><?php echo $this->mcp_connected ? 'Regenerate' : 'Generate token'; ?></button>
                            <button type="button" class="btn btn-ghost btn-sm" id="mcpRevoke" <?php echo $this->mcp_connected ? '' : 'hidden'; ?>>Revoke</button>
                        </div>
                    </div>
                </div>

                <h3 class="integ__group">Social accounts</h3>
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
                <div class="integ">
                    <?php foreach ($this->platform_meta as $p => $meta): ?>
                    <?php $accts = $this->connected[$p] ?? array(); ?>
                    <div class="integ__row">
                        <span class="integ__icon"><i class="fa-brands <?php echo $meta[1]; ?>"></i></span>
                        <div class="integ__main">
                            <div class="integ__name"><?php echo $meta[0]; ?></div>
                            <div class="integ__meta">
                                <?php if (!empty($accts)): ?>
                                    <?php foreach ($accts as $i => $a): ?><?php if ($i > 0): ?><span class="integ__sep">·</span><?php endif; ?><span class="integ__dot is-on"></span><?php echo htmlspecialchars(($a['username'] !== '' && $a['username'] !== null) ? '@' . $a['username'] : 'Connected', ENT_QUOTES, 'UTF-8'); ?><?php endforeach; ?>
                                <?php else: ?>
                                    <span class="integ__dot"></span>Not connected
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="integ__actions">
                            <?php if (!empty($accts)): ?>
                                <?php foreach ($accts as $a): ?><button type="button" class="btn btn-ghost btn-sm conn-disconnect" data-account-id="<?php echo htmlspecialchars($a['post_for_me_social_account_id'], ENT_QUOTES, 'UTF-8'); ?>">Disconnect</button><?php endforeach; ?>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-sm conn-connect" data-platform="<?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?>">Connect</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

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

<div class="modal fade" id="plan_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="plan_modal_title">Add plan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="plan_id" value="0">
                <div class="link-field">
                    <label for="plan_name">Plan name</label>
                    <input type="text" class="form-control" id="plan_name" maxlength="120" placeholder="Supporter">
                </div>
                <label class="plan-free-toggle">
                    <input type="checkbox" id="plan_free"> This is a free tier
                </label>
                <div class="plan-field-row">
                    <div class="link-field">
                        <label for="plan_price">Price (USD)</label>
                        <div class="plan-price-input">
                            <span class="plan-price-input__prefix">$</span>
                            <input type="number" class="form-control" id="plan_price" min="0" step="0.01" placeholder="9.99">
                        </div>
                    </div>
                    <div class="link-field">
                        <label for="plan_interval">Billed</label>
                        <select class="form-control" id="plan_interval">
                            <option value="week">Weekly</option>
                            <option value="month">Monthly</option>
                            <option value="year">Yearly</option>
                        </select>
                    </div>
                </div>
                <?php if (!empty($this->can_trials)): ?>
                <label class="plan-free-toggle" style="margin-top:.1rem;">
                    <input type="checkbox" id="plan_trial_enabled"> Offer a free trial
                </label>
                <div class="plan-field-row" id="plan_trial_row" hidden>
                    <div class="link-field">
                        <label for="plan_trial_value">Trial length</label>
                        <input type="number" class="form-control" id="plan_trial_value" min="1" max="365" step="1" value="7">
                    </div>
                    <div class="link-field">
                        <label for="plan_trial_unit">Unit</label>
                        <select class="form-control" id="plan_trial_unit">
                            <option value="day">Days</option>
                            <option value="week">Weeks</option>
                            <option value="month">Months</option>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
                <div class="link-field">
                    <label for="plan_description">Short description <span class="plan-optional">(optional)</span></label>
                    <input type="text" class="form-control" id="plan_description" maxlength="255" placeholder="Behind-the-scenes access and more">
                </div>
                <div class="link-field">
                    <label for="plan_perks">Perks <span class="plan-optional">(one per line)</span></label>
                    <textarea class="form-control" id="plan_perks" rows="4" placeholder="Exclusive posts&#10;Members-only chat&#10;Early access"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="plan_save">Save plan</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="promo_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="promo_modal_title">Add code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="promo_id" value="0">
                <div class="link-field">
                    <label for="promo_code">Code</label>
                    <input type="text" class="form-control" id="promo_code" maxlength="40" placeholder="SUMMER20" style="text-transform:uppercase;">
                </div>
                <div class="plan-field-row">
                    <div class="link-field">
                        <label for="promo_percent">Discount (%)</label>
                        <input type="number" class="form-control" id="promo_percent" min="1" max="100" placeholder="20">
                    </div>
                    <div class="link-field">
                        <label for="promo_applies">Applies to</label>
                        <select class="form-control" id="promo_applies">
                            <option value="all">Subscriptions &amp; PPV</option>
                            <option value="subscription">Subscriptions</option>
                            <option value="ppv">Pay-per-view</option>
                        </select>
                    </div>
                </div>
                <div class="plan-field-row">
                    <div class="link-field">
                        <label for="promo_max">Max redemptions </label>
                        <input type="number" class="form-control" id="promo_max" min="1" placeholder="Unlimited">
                        <span class="plan-optional">(blank = unlimited)</span>
                    </div>
                    <div class="link-field">
                        <label for="promo_expires">Expires <span class="plan-optional">(optional)</span></label>
                        <input type="date" class="form-control" id="promo_expires">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="promo_save">Save code</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="bundle_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bundle_modal_title">Add bundle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bundle_id" value="0">
                <div class="link-field">
                    <label for="bundle_name">Name</label>
                    <input type="text" class="form-control" id="bundle_name" maxlength="120" placeholder="Summer collection">
                </div>
                <div class="link-field">
                    <label for="bundle_price">Price</label>
                    <div class="bundle-price-row">
                        <span class="bundle-price-cur">$</span>
                        <input type="number" class="form-control" id="bundle_price" min="1" placeholder="15">
                        <span class="bundle-price-hint" id="bundle_price_credits">= 150 credits</span>
                    </div>
                </div>
                <div class="link-field">
                    <label for="bundle_description">Description <span class="plan-optional">(optional)</span></label>
                    <textarea class="form-control" id="bundle_description" maxlength="500" rows="2" placeholder="What's inside this bundle"></textarea>
                </div>
                <div class="link-field">
                    <label>Content in this bundle</label>
                    <?php if (empty($this->bundle_media)): ?>
                    <p class="settings__empty" style="margin:0;">Your Library is empty. Add photos or videos in the Content Studio first, then group them here.</p>
                    <?php else: ?>
                    <div class="bundle-grid" id="bundle_picker">
                        <?php foreach ($this->bundle_media as $bm): ?>
                        <label class="bundle-tile" title="<?php echo htmlspecialchars((string) $bm['title'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="checkbox" class="bundle-pick__cb" data-asset="<?php echo (int) $bm['id']; ?>">
                            <span class="bundle-tile__thumb"<?php echo $bm['thumb'] !== '' ? ' style="background-image:url(\'' . htmlspecialchars($bm['thumb'], ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>>
                                <?php if ($bm['type'] === 'video'): ?><i class="fa-solid fa-play bundle-tile__vid"></i><?php endif; ?>
                                <i class="fa-solid fa-circle-check bundle-tile__check"></i>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="bundle-picker__sum" id="bundle_sum">0 items selected</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="bundle_save">Save bundle</button>
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
    if (params.get('fanvue_connected') === '1') { toastr.success('Fanvue connected'); }
    if (params.get('fanvue_error')) {
        var fverr = { denied: 'Fanvue access was not granted.', state: 'The Fanvue sign-in expired. Try again.',
                      token: 'Fanvue did not accept the sign-in. Try again.', profile: 'Could not read your Fanvue profile.' };
        toastr.error(fverr[params.get('fanvue_error')] || 'Fanvue connection was not completed');
    }
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
            $('#uname_link').attr('href', '/@' + encodeURIComponent(o.u_name));
            if (o.next_change_date) {
                $('#uname_rules').html('You can change your username again on <strong>' + escapeHtml(o.next_change_date) + '</strong>.');
            }
        });
    });

    /* ---- verification (PRD §33) ---- */
    $('#verify_request_btn').on('click', function () {
        if (!window.Swal) { return; }
        Swal.fire({
            title: 'Request verification',
            width: 460,
            html: '<div class="rpt-swal">'
                + '<label for="vfName">Legal name</label>'
                + '<input id="vfName" type="text" placeholder="Your full legal name" maxlength="190">'
                + '<label for="vfNote">Anything that helps us verify you <span style="font-weight:400;text-transform:none;color:#9a97a8;">(optional)</span></label>'
                + '<textarea id="vfNote" rows="3" maxlength="2000" placeholder="Links, socials, or context."></textarea>'
                + '</div>',
            focusConfirm: false, showCancelButton: true, reverseButtons: true,
            confirmButtonText: 'Submit request', confirmButtonColor: '#5b4be0', cancelButtonColor: '#6b6779',
            preConfirm: function () {
                var name = (document.getElementById('vfName').value || '').trim();
                if (name === '') { Swal.showValidationMessage('Enter your legal name'); return false; }
                return { full_name: name, note: document.getElementById('vfNote').value };
            }
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) { return; }
            ApiDataSvc.apiCall('post', 'verification_request', r.value, function (resp) {
                var o = null; try { o = JSON.parse(resp); } catch (e) {}
                if (o && o.success) { toastr.success(o.message || 'Verification requested'); setTimeout(function () { location.reload(); }, 900); }
                else { toastr.error((o && o.message) || 'Could not submit'); }
            });
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

    // Wallet in-page tabs (balance stays pinned above them).
    $('.wallet-tab').on('click', function () {
        var t = $(this).data('wtab');
        $('.wallet-tab').removeClass('is-active');
        $(this).addClass('is-active');
        $('.wallet-panel').removeClass('is-active').filter('[data-wpanel="' + t + '"]').addClass('is-active');
    });
    (function () {
        // `params` was captured before the one-time query strip above; location.search is already empty here.
        var m = (params.get('tab') || '').replace(/[^a-z]/g, '');
        if (m && $('.wallet-tab[data-wtab="' + m + '"]').length) { $('.wallet-tab[data-wtab="' + m + '"]').trigger('click'); }
    })();

    $('.buy-credits').on('click', function () {
        if (!stripe) { toastr.error('Payments are not available right now'); return; }
        var dollars = $(this).data('dollars');
        ApiDataSvc.apiCall('post', 'buy_credits', { dollars: dollars }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            $('#credit_pay_summary').html(
                '<span class="pay-line pay-line--head">' + Number(o.credits).toLocaleString() + ' credits</span>' +
                '<span class="pay-line"><span>Subtotal</span><span>$' + (o.base_cents / 100).toFixed(2) + '</span></span>' +
                '<span class="pay-line"><span>Processing fee (' + o.fee_percent + '%)</span><span>$' + (o.fee_cents / 100).toFixed(2) + '</span></span>' +
                '<span class="pay-line pay-line--total"><span>Total</span><span>$' + (o.total_cents / 100).toFixed(2) + '</span></span>'
            );
            $('#credit_payment_element').html('');
            $('#credit_pay_button').prop('disabled', false).text('Pay $' + (o.total_cents / 100).toFixed(2));
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
        my_avatar: { ratio: 1, width: 512, height: 512, label: 'Square, 512×512' },
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
                if (cropKind === 'my_avatar') { uploadMyAvatar(blob); } else { uploadCreatorImage(cropKind, blob, cropKind + '.jpg', cropTarget); }
                $('#crop_modal').modal('hide');
            }, 'image/jpeg', 0.9);
    });

    /* Account > Profile Photo: every user's own photo (uses the same cropper) */
    function setMyAvatar(url) {
        $('#my_avatar_preview, #avatar_preview').each(function () {
            var $p = $(this);
            if (url) { $p.css('background-image', "url('" + url + "')").find('i, svg').remove(); }   // the icon kit swaps <i> for <svg>
            else { $p.css('background-image', ''); if (!$p.find('i, svg').length) { $p.append('<i class="fa-solid fa-user"></i>'); } }
        });
        $('#my_avatar_remove, #avatar_remove').prop('hidden', !url);
    }
    function uploadMyAvatar(blob) {
        var fd = new FormData();
        fd.append('image', blob, 'avatar.jpg');
        $.ajax({
            url: ApiDataSvc.baseUrl + 'upload_my_avatar', type: 'POST', data: fd, processData: false, contentType: false,
            success: function (data) { var o = JSON.parse(data); if (o.success) { toastr.success(o.message); setMyAvatar(o.url); } else { toastr.error(o.message); } },
            error: function () { toastr.error('Upload failed'); }
        });
    }
    $('#my_avatar_upload_btn').on('click', function () { $('#my_avatar_file').trigger('click'); });
    $('#my_avatar_file').on('change', function () { if (this.files[0]) { openCropper('my_avatar', this.files[0], $('#my_avatar_preview')); this.value = ''; } });
    $('#my_avatar_remove').on('click', function () {
        ApiDataSvc.apiCall('post', 'remove_my_avatar', {}, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message); setMyAvatar('');
        });
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

    $('#cp_save').on('click', function () {
        if (($('#cp_display_name').val() || '').trim() === '') {
            toastr.error('Display name is required');
            $('#cp_display_name').focus();
            return;
        }
        ApiDataSvc.apiCall('post', 'save_creator_profile', {
            display_name: $('#cp_display_name').val(),
            bio:          $('#cp_bio').val(),
            location:     $('#cp_location').val()
        }, function (data) {
            var o = JSON.parse(data);
            if (o.success) { toastr.success(o.message); } else { toastr.error(o.message); }
        });
    });

    // ----- Brand Identity -----
    function brandEsc(s) { return $('<div>').text(s == null ? '' : s).html(); }
    function brandSwatch(h) { h = String(h); return '<span class="brand-swatch" data-hex="' + brandEsc(h) + '"><span class="brand-swatch__dot" style="background:' + brandEsc(h) + '"></span>' + brandEsc(h) + '<button type="button" class="brand-swatch__x" aria-label="Remove colour">&times;</button></span>'; }
    function brandChip(w) { w = String(w); return '<span class="brand-chip" data-kw="' + brandEsc(w) + '">' + brandEsc(w) + '<button type="button" class="brand-chip__x" aria-label="Remove keyword">&times;</button></span>'; }
    function fillBrand(b) {
        $('#brand_name').val(b.brand_name || '');
        $('#brand_tagline').val(b.tagline || '');
        $('#brand_description').val(b.description || '');
        $('#brand_voice').val(b.voice || '');
        var $c = $('#brand_colors').empty();   (b.colors   || []).forEach(function (h) { $c.append(brandSwatch(h)); });
        var $k = $('#brand_keywords').empty(); (b.keywords || []).forEach(function (w) { $k.append(brandChip(w)); });
    }

    $('#brand_generate').on('click', function () {
        var url = ($('#brand_url').val() || '').trim();
        if (url === '') { toastr.error('Enter your website URL first.'); $('#brand_url').focus(); return; }
        var $b = $(this).prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Generating…');
        ApiDataSvc.apiCall('post', 'generate_brand_identity', { url: url }, function (data) {
            $b.prop('disabled', false).html('<i class="fa-solid fa-wand-magic-sparkles"></i> Generate');
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            fillBrand(o.brand);
            $('#brand_result').prop('hidden', false);
            toastr.success('Brand identity generated — review and save.');
        });
    });

    $('#brand_colors').on('click', '.brand-swatch__x', function () { $(this).closest('.brand-swatch').remove(); });
    $('#brand_keywords').on('click', '.brand-chip__x', function () { $(this).closest('.brand-chip').remove(); });

    $('#brand_save').on('click', function () {
        var colors   = $('#brand_colors .brand-swatch').map(function () { return $(this).attr('data-hex'); }).get().join(',');
        var keywords = $('#brand_keywords .brand-chip').map(function () { return $(this).attr('data-kw'); }).get().join(',');
        ApiDataSvc.apiCall('post', 'save_brand_identity', {
            source_url:  $('#brand_url').val(),
            brand_name:  $('#brand_name').val(),
            tagline:     $('#brand_tagline').val(),
            description: $('#brand_description').val(),
            voice:       $('#brand_voice').val(),
            colors:      colors,
            keywords:    keywords
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
                setTimeout(function () { window.location.href = '/account/settings?section=wallet&tab=cashout'; }, 1000);
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
                setTimeout(function () { window.location.href = '/account/settings?section=wallet&tab=cashout'; }, 900);
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

    /* ---- membership plans ---- */
    function intervalAbbr(interval) { return interval === 'year' ? 'yr' : (interval === 'week' ? 'wk' : 'mo'); }
    function planPriceLabel(price, interval) {
        if (parseFloat(price) === 0) { return 'Free'; }
        return '$' + parseFloat(price).toFixed(2) + '<span class="plan-row__unit">/' + intervalAbbr(interval) + '</span>';
    }
    function setPlanFreeState(free) {
        $('#plan_price').prop('disabled', free);
        $('#plan_interval').prop('disabled', free);
        $('#plan_trial_enabled').prop('disabled', free);
        if (free) { $('#plan_price').val('0'); $('#plan_trial_enabled').prop('checked', false); setPlanTrialState(false); }
    }
    $('#plan_free').on('change', function () { setPlanFreeState($(this).is(':checked')); });
    function setPlanTrialState(on) { $('#plan_trial_row').prop('hidden', !on); }
    $('#plan_trial_enabled').on('change', function () { setPlanTrialState($(this).is(':checked')); });
    function renderPlanRow(p) {
        return '<div class="plan-row' + (p.active ? '' : ' is-inactive') + '" data-id="' + p.id + '"'
            + ' data-name="' + escapeHtml(p.name) + '" data-price="' + escapeHtml(p.price) + '"'
            + ' data-interval="' + escapeHtml(p.interval) + '" data-trial-enabled="' + (p.trial_enabled ? 1 : 0) + '" data-trial-value="' + (parseInt(p.trial_value, 10) || 7) + '" data-trial-unit="' + escapeHtml(p.trial_unit || 'day') + '" data-description="' + escapeHtml(p.description) + '"'
            + ' data-perks="' + escapeHtml(p.perks) + '">'
            + '<span class="link-row__handle"><i class="fa-solid fa-grip-vertical"></i></span>'
            + '<div class="plan-row__info"><span class="plan-row__name">' + escapeHtml(p.name) + '</span>'
            + '<span class="plan-row__price">' + planPriceLabel(p.price, p.interval) + (p.trial_enabled ? ' <span class="plan-row__trial">' + (parseInt(p.trial_value, 10) || 1) + '-' + (p.trial_unit || 'day') + ' trial</span>' : '') + '</span></div>'
            + '<label class="plan-row__switch" title="Active"><input type="checkbox" class="plan-toggle"' + (p.active ? ' checked' : '') + '><span class="plan-row__slider"></span></label>'
            + '<button type="button" class="link-row__btn plan-edit" aria-label="Edit plan"><i class="fa-solid fa-pen"></i></button>'
            + '<button type="button" class="link-row__btn plan-delete" aria-label="Remove plan"><i class="fa-solid fa-trash"></i></button>'
            + '</div>';
    }

    if (window.Sortable && document.getElementById('plans_list')) {
        Sortable.create(document.getElementById('plans_list'), {
            handle: '.link-row__handle', animation: 150,
            onEnd: function () {
                var ids = $('#plans_list .plan-row').map(function () { return $(this).data('id'); }).get();
                ApiDataSvc.apiCall('post', 'reorder_creator_plans', { ids: ids }, function () {});
            }
        });
    }

    // ---- Discount codes ----
    function renderPromoRow(p) {
        var applies = p.applies === 'ppv' ? 'Pay-per-view' : (p.applies === 'subscription' ? 'Subscriptions' : 'Subs & PPV');
        return '<div class="plan-row' + (p.active ? '' : ' is-inactive') + '" data-id="' + p.id + '"'
            + ' data-code="' + escapeHtml(p.code) + '" data-percent="' + (parseInt(p.percent, 10) || 0) + '"'
            + ' data-applies="' + escapeHtml(p.applies) + '" data-max="' + escapeHtml(p.max || '') + '" data-expires="' + escapeHtml(p.expires || '') + '">'
            + '<div class="plan-row__info"><span class="plan-row__name">' + escapeHtml(p.code) + '</span>'
            + '<span class="plan-row__price">' + (parseInt(p.percent, 10) || 0) + '% off<span class="plan-row__unit"> · ' + applies + '</span></span></div>'
            + '<label class="plan-row__switch" title="Active"><input type="checkbox" class="promo-toggle"' + (p.active ? ' checked' : '') + '><span class="plan-row__slider"></span></label>'
            + '<button type="button" class="link-row__btn promo-edit" aria-label="Edit code"><i class="fa-solid fa-pen"></i></button>'
            + '<button type="button" class="link-row__btn promo-delete" aria-label="Remove code"><i class="fa-solid fa-trash"></i></button>'
            + '</div>';
    }
    $('#promo_add_btn').on('click', function () {
        $('#promo_id').val(0);
        $('#promo_code').val(''); $('#promo_percent').val(''); $('#promo_applies').val('all');
        $('#promo_max').val(''); $('#promo_expires').val('');
        $('#promo_modal_title').text('Add code');
        $('#promo_modal').modal('show');
    });
    $('#promos_list').on('click', '.promo-edit', function () {
        var $row = $(this).closest('.plan-row');
        $('#promo_id').val($row.data('id'));
        $('#promo_code').val($row.data('code'));
        $('#promo_percent').val($row.data('percent'));
        $('#promo_applies').val($row.data('applies'));
        $('#promo_max').val($row.data('max') || '');
        $('#promo_expires').val($row.data('expires') || '');
        $('#promo_modal_title').text('Edit code');
        $('#promo_modal').modal('show');
    });
    $('#promo_save').on('click', function () {
        var id = $('#promo_id').val();
        var code = ($('#promo_code').val() || '').trim().toUpperCase();
        var percent = parseInt($('#promo_percent').val(), 10) || 0;
        if (code.length < 3) { toastr.error('Enter a code of at least 3 characters'); return; }
        if (!(percent >= 1 && percent <= 100)) { toastr.error('Discount must be 1–100%'); return; }
        var applies = $('#promo_applies').val(), max = ($('#promo_max').val() || ''), expires = ($('#promo_expires').val() || '');
        ApiDataSvc.apiCall('post', 'save_promo_code', { id: id, code: code, percent_off: percent, applies_to: applies, max_redemptions: max, expires_at: expires }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            var $existing = $('#promos_list .plan-row[data-id="' + o.id + '"]');
            var p = { id: o.id, code: o.code || code, percent: percent, applies: applies, max: max, expires: expires, active: true };
            if ($existing.length) { p.active = !$existing.hasClass('is-inactive'); $existing.replaceWith(renderPromoRow(p)); }
            else { $('#promos_list').append(renderPromoRow(p)); $('#promos_empty').attr('hidden', true); }
            $('#promo_modal').modal('hide');
        });
    });
    $('#promos_list').on('change', '.promo-toggle', function () {
        var $row = $(this).closest('.plan-row');
        var active = $(this).is(':checked');
        $row.toggleClass('is-inactive', !active);
        ApiDataSvc.apiCall('post', 'toggle_promo_code', { id: $row.data('id'), active: active ? 1 : 0 }, function () {});
    });
    $('#promos_list').on('click', '.promo-delete', function () {
        var $row = $(this).closest('.plan-row');
        if (!confirm('Remove this discount code?')) { return; }
        ApiDataSvc.apiCall('post', 'delete_promo_code', { id: $row.data('id') }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            $row.remove();
            if (!$('#promos_list .plan-row').length) { $('#promos_empty').attr('hidden', false); }
            toastr.success('Code removed');
        });
    });

    // ---- Content bundles ----
    function renderBundleRow(b) {
        var dollars = Math.round((parseInt(b.price, 10) || 0) / 10);
        var n = String(b.asset_ids || '').split(',').filter(function (x) { return x !== ''; }).length;
        return '<div class="plan-row' + (b.active ? '' : ' is-inactive') + '" data-id="' + b.id + '"'
            + ' data-name="' + escapeHtml(b.name) + '" data-description="' + escapeHtml(b.description || '') + '"'
            + ' data-price="' + (parseInt(b.price, 10) || 0) + '" data-assets="' + escapeHtml(String(b.asset_ids || '')) + '">'
            + '<div class="plan-row__info"><span class="plan-row__name">' + escapeHtml(b.name) + '</span>'
            + '<span class="plan-row__price">$' + dollars + '<span class="plan-row__unit"> · ' + (parseInt(b.price, 10) || 0) + ' cr · ' + n + ' item' + (n === 1 ? '' : 's') + '</span></span></div>'
            + '<label class="plan-row__switch" title="Active"><input type="checkbox" class="bundle-toggle"' + (b.active ? ' checked' : '') + '><span class="plan-row__slider"></span></label>'
            + '<button type="button" class="link-row__btn bundle-edit" aria-label="Edit bundle"><i class="fa-solid fa-pen"></i></button>'
            + '<button type="button" class="link-row__btn bundle-delete" aria-label="Remove bundle"><i class="fa-solid fa-trash"></i></button>'
            + '</div>';
    }
    function bundleUpdateHints() {
        var dollars = parseInt($('#bundle_price').val(), 10) || 0;
        $('#bundle_price_credits').text('= ' + (dollars * 10) + ' credits');
        var n = $('#bundle_picker .bundle-pick__cb:checked').length;
        $('#bundle_sum').text(n + ' item' + (n === 1 ? '' : 's') + ' selected');
    }
    function bundleSetAssets(ids) {
        var set = {}; (ids || []).forEach(function (x) { set[String(x)] = 1; });
        $('#bundle_picker .bundle-pick__cb').each(function () {
            this.checked = !!set[String($(this).data('asset'))];
            $(this).closest('.bundle-tile').toggleClass('is-on', this.checked);
        });
    }
    $('#bundle_price').on('input', bundleUpdateHints);
    $('#bundle_picker').on('change', '.bundle-pick__cb', function () {
        $(this).closest('.bundle-tile').toggleClass('is-on', this.checked);
        bundleUpdateHints();
    });
    $('#bundle_add_btn').on('click', function () {
        $('#bundle_id').val(0);
        $('#bundle_name').val(''); $('#bundle_price').val(''); $('#bundle_description').val('');
        bundleSetAssets([]); bundleUpdateHints();
        $('#bundle_modal_title').text('Add bundle');
        $('#bundle_modal').modal('show');
    });
    $('#bundles_list').on('click', '.bundle-edit', function () {
        var $row = $(this).closest('.plan-row');
        $('#bundle_id').val($row.data('id'));
        $('#bundle_name').val($row.data('name'));
        $('#bundle_description').val($row.data('description') || '');
        $('#bundle_price').val(parseInt($row.data('price'), 10) || '');
        var assets = String($row.data('assets') || '').split(',').filter(function (x) { return x !== ''; });
        bundleSetAssets(assets); bundleUpdateHints();
        $('#bundle_modal_title').text('Edit bundle');
        $('#bundle_modal').modal('show');
    });
    $('#bundle_save').on('click', function () {
        var id = $('#bundle_id').val();
        var name = ($('#bundle_name').val() || '').trim();
        var dollars = parseInt($('#bundle_price').val(), 10) || 0;
        if (name === '') { toastr.error('Give the bundle a name'); return; }
        if (dollars < 1) { toastr.error('Set a price of at least $1'); return; }
        var assets = $('#bundle_picker .bundle-pick__cb:checked').map(function () { return String($(this).data('asset')); }).get();
        if (!assets.length) { toastr.error('Add at least one piece of content to the bundle'); return; }
        var desc = ($('#bundle_description').val() || '').trim();
        ApiDataSvc.apiCall('post', 'save_bundle', { id: id, name: name, price_credits: dollars * 10, description: desc, asset_ids: assets }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            var b = { id: o.id, name: name, description: desc, price: dollars * 10, asset_ids: assets.join(','), active: true };
            var $existing = $('#bundles_list .plan-row[data-id="' + o.id + '"]');
            if ($existing.length) { b.active = !$existing.hasClass('is-inactive'); $existing.replaceWith(renderBundleRow(b)); }
            else { $('#bundles_list').append(renderBundleRow(b)); $('#bundles_empty').attr('hidden', true); }
            $('#bundle_modal').modal('hide');
        });
    });
    $('#bundles_list').on('change', '.bundle-toggle', function () {
        var $row = $(this).closest('.plan-row');
        var active = $(this).is(':checked');
        $row.toggleClass('is-inactive', !active);
        ApiDataSvc.apiCall('post', 'toggle_bundle', { id: $row.data('id'), active: active ? 1 : 0 }, function () {});
    });
    $('#bundles_list').on('click', '.bundle-delete', function () {
        var $row = $(this).closest('.plan-row');
        if (!confirm('Remove this bundle? Posts in it stay; only the bundle is deleted.')) { return; }
        ApiDataSvc.apiCall('post', 'delete_bundle', { id: $row.data('id') }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            $row.remove();
            if (!$('#bundles_list .plan-row').length) { $('#bundles_empty').attr('hidden', false); }
            toastr.success('Bundle removed');
        });
    });

    $('#plan_add_btn').on('click', function () {
        $('#plan_id').val(0);
        $('#plan_name').val(''); $('#plan_price').val(''); $('#plan_interval').val('month');
        $('#plan_trial_enabled').prop('checked', false); $('#plan_trial_value').val('7'); $('#plan_trial_unit').val('day'); setPlanTrialState(false);
        $('#plan_description').val(''); $('#plan_perks').val('');
        $('#plan_free').prop('checked', false); setPlanFreeState(false);
        $('#plan_modal_title').text('Add plan');
        $('#plan_modal').modal('show');
    });

    $('#plans_list').on('click', '.plan-edit', function () {
        var $row = $(this).closest('.plan-row');
        var isFree = parseFloat($row.data('price')) === 0;
        $('#plan_id').val($row.data('id'));
        $('#plan_name').val($row.data('name'));
        $('#plan_price').val($row.data('price'));
        $('#plan_interval').val($row.data('interval'));
        var tEnabled = String($row.data('trial-enabled')) === '1';
        $('#plan_trial_enabled').prop('checked', tEnabled);
        $('#plan_trial_value').val($row.data('trial-value') || 7);
        $('#plan_trial_unit').val($row.data('trial-unit') || 'day');
        setPlanTrialState(tEnabled);
        $('#plan_description').val($row.data('description'));
        $('#plan_perks').val($row.data('perks'));
        $('#plan_free').prop('checked', isFree); setPlanFreeState(isFree);
        $('#plan_modal_title').text('Edit plan');
        $('#plan_modal').modal('show');
    });

    $('#plan_save').on('click', function () {
        var id = $('#plan_id').val();
        var name = ($('#plan_name').val() || '').trim();
        var free = $('#plan_free').is(':checked');
        var price = free ? '0' : $('#plan_price').val();
        var interval = $('#plan_interval').val();
        var description = ($('#plan_description').val() || '').trim();
        var perks = $('#plan_perks').val() || '';
        var trialEnabled = !free && $('#plan_trial_enabled').is(':checked');
        var trialValue = parseInt($('#plan_trial_value').val(), 10) || 1;
        var trialUnit = $('#plan_trial_unit').val();
        if (name == '') { toastr.error('Enter a plan name'); return; }
        if (!free && !(parseFloat(price) >= 1)) { toastr.error('Enter a price of at least $1.00, or make it a free tier'); return; }

        ApiDataSvc.apiCall('post', 'save_creator_plan', { id: id, name: name, price: price, is_free: free ? 1 : 0, billing_interval: interval, trial_enabled: trialEnabled ? 1 : 0, trial_value: trialValue, trial_unit: trialUnit, description: description, perks: perks }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            var $existing = $('#plans_list .plan-row[data-id="' + o.id + '"]');
            var p = { id: o.id, name: name, price: (free ? '0.00' : parseFloat(price).toFixed(2)), interval: interval, trial_enabled: trialEnabled, trial_value: trialValue, trial_unit: trialUnit, description: description, perks: perks, active: true };
            if ($existing.length) {
                p.active = !$existing.hasClass('is-inactive');
                $existing.replaceWith(renderPlanRow(p));
            } else {
                $('#plans_list').append(renderPlanRow(p));
                $('#plans_empty').attr('hidden', true);
            }
            $('#plan_modal').modal('hide');
        });
    });

    $('#plans_list').on('change', '.plan-toggle', function () {
        var $row = $(this).closest('.plan-row');
        var active = $(this).is(':checked');
        $row.toggleClass('is-inactive', !active);
        ApiDataSvc.apiCall('post', 'toggle_creator_plan', { id: $row.data('id'), active: active ? 1 : 0 }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); }
        });
    });

    $('#plans_list').on('click', '.plan-delete', function () {
        var $row = $(this).closest('.plan-row');
        ApiDataSvc.apiCall('post', 'delete_creator_plan', { id: $row.data('id') }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            $row.remove();
            if ($('#plans_list .plan-row').length === 0) { $('#plans_empty').removeAttr('hidden'); }
        });
    });

    /* ---- my subscriptions ---- */
    function subApply($row, state) {
        var isFree = $row.data('free') == 1;
        var period = $row.data('period') || '';
        var handle = $row.data('handle') || '';
        var status, action;
        if (state === 'canceled') {
            status = 'Canceled';
            action = isFree
                ? '<button type="button" class="btn btn-secondary sub-reactivate">Rejoin</button>'
                : '<a class="btn btn-secondary" href="/@' + handle + '" target="_blank" rel="noopener">Subscribe again</a>';
        } else if (state === 'canceling') {
            status = period ? 'Ends ' + period : 'Ending';
            action = '<button type="button" class="btn btn-secondary sub-reactivate">Resume</button>';
        } else {
            status = isFree ? 'Active' : (period ? 'Renews ' + period : 'Active');
            action = '<button type="button" class="btn btn-secondary sub-cancel">' + (isFree ? 'Leave' : 'Cancel') + '</button>';
        }
        $row.find('[data-status]').text(status);
        $row.find('[data-actions]').html(action);
    }

    $('#subs_list').on('click', '.sub-cancel', function () {
        var $row = $(this).closest('.sub-row');
        ApiDataSvc.apiCall('post', 'cancel_creator_subscription', { id: $row.data('id') }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            subApply($row, o.state);
        });
    });

    $('#subs_list').on('click', '.sub-reactivate', function () {
        var $row = $(this).closest('.sub-row');
        ApiDataSvc.apiCall('post', 'reactivate_creator_subscription', { id: $row.data('id') }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            subApply($row, o.state);
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

    function start_social_connect(payload) {
        ApiDataSvc.apiCall('post', 'connect_account', payload, function (data) {
            var o = JSON.parse(data);
            if (o.success) { window.location = o.url; } else { toastr.error(o.message); }
        });
    }
    $('.conn-connect').on('click', function () {
        var platform = $(this).data('platform');
        if (platform !== 'bluesky') { start_social_connect({ platform: platform }); return; }
        // Bluesky has no OAuth screen: it connects with the account handle + an app password.
        Swal.fire({
            title: 'Connect Bluesky',
            width: 460,
            html: '<div class="rpt-swal">'
                + '<label for="bsHandle">Handle</label>'
                + '<input id="bsHandle" type="text" placeholder="yourname.bsky.social" maxlength="253" autocapitalize="none" autocorrect="off" spellcheck="false">'
                + '<label for="bsAppPw">App password</label>'
                + '<input id="bsAppPw" type="password" placeholder="xxxx-xxxx-xxxx-xxxx" maxlength="64" autocomplete="off">'
                + '<p style="margin:.5rem 0 0;font-size:.78rem;color:#9a97a8;">Create one in Bluesky under Settings &rarr; Privacy and security &rarr; App passwords. It is not your account password.</p>'
                + '</div>',
            focusConfirm: false, showCancelButton: true, reverseButtons: true,
            confirmButtonText: 'Connect', confirmButtonColor: '#5b4be0', cancelButtonColor: '#6b6779',
            preConfirm: function () {
                var handle = (document.getElementById('bsHandle').value || '').trim().replace(/^@/, '');
                var pw = (document.getElementById('bsAppPw').value || '').trim();
                if (handle === '') { Swal.showValidationMessage('Enter your Bluesky handle'); return false; }
                if (pw === '') { Swal.showValidationMessage('Enter an app password'); return false; }
                return { platform: 'bluesky', handle: handle, app_password: pw };
            }
        }).then(function (r) { if (r.isConfirmed && r.value) { start_social_connect(r.value); } });
    });

    function fanvue_connect(return_section, $btn) {
        if ($btn) { $btn.prop('disabled', true); }
        ApiDataSvc.apiCall('post', 'fanvue_connect', { return_section: return_section || 'connected' }, function (data) {
            var o = JSON.parse(data);
            if (o.success) { window.location = o.url; } else { toastr.error(o.message); if ($btn) { $btn.prop('disabled', false); } }
        });
    }
    $('#fanvueConnect').on('click', function () { fanvue_connect('connected', $(this)); });

    // ---- Inbox automation -------------------------------------------------------
    var inbox_timer = null;

    function inbox_esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    function inbox_status_label(r) {
        var map = { sent: 'Sent', dismissed: 'Dismissed', failed: 'Failed', skipped: 'Skipped', sending: 'Sending' };
        var reasons = { rate_cap: 'too soon after last reply', max_consecutive: 'paused, needs you', quiet_hours: 'quiet hours',
                        needs_human: 'held for you', creator_replied: 'you replied', superseded: 'newer message', claude: 'AI error' };
        var t = map[r.status] || r.status;
        if (r.reason && reasons[r.reason]) { t += ' · ' + reasons[r.reason]; }
        return t;
    }

    function render_inbox_queue(o) {
        var $q = $('#inboxQueue').empty();
        $('#inboxQueueCount').text(o.pending_count ? o.pending_count : '');
        $('#inboxNavBadge').text(o.pending_count).prop('hidden', !o.pending_count);
        if (!o.items.length) {
            $q.html('<p class="settings__empty">Nothing waiting. New drafts show up here as fans message you.</p>');
        }
        o.items.forEach(function (r) {
            $q.append(
                '<div class="inbox-queue__item" data-id="' + r.id + '">' +
                    '<div class="inbox-queue__meta"><span class="inbox-queue__fan">' + inbox_esc(r.peer_name || 'Fan') + '</span><span class="inbox-queue__time">' + inbox_esc(r.created_human) + '</span></div>' +
                    '<div class="inbox-queue__msg">' + inbox_esc(r.inbound_text) + '</div>' +
                    '<textarea class="form-control inbox-queue__draft" rows="3" maxlength="5000">' + inbox_esc(r.draft_text) + '</textarea>' +
                    '<div class="inbox-queue__actions">' +
                        '<button type="button" class="btn btn-secondary" data-inbox-dismiss="' + r.id + '">Dismiss</button>' +
                        '<button type="button" class="btn btn-primary" data-inbox-send="' + r.id + '">Send</button>' +
                    '</div>' +
                '</div>');
        });
        var $log = $('#inboxLog').empty();
        if (!o.history.length) { $log.html('<p class="settings__empty">No activity yet.</p>'); }
        o.history.forEach(function (r) {
            var text = r.final_text || r.draft_text || r.inbound_text || '';
            $log.append(
                '<div class="inbox-log__row">' +
                    '<span class="inbox-log__pill inbox-log__pill--' + inbox_esc(r.status) + '">' + inbox_esc(inbox_status_label(r)) + '</span>' +
                    '<span class="inbox-log__fan">' + inbox_esc(r.peer_name || 'Fan') + '</span>' +
                    '<span class="inbox-log__text">' + inbox_esc(text.length > 120 ? text.slice(0, 120) + '…' : text) + '</span>' +
                    '<span class="inbox-log__time">' + inbox_esc(r.sent_human || r.created_human) + '</span>' +
                    (r.error ? '<span class="inbox-log__error">' + inbox_esc(r.error) + '</span>' : '') +
                '</div>');
        });
    }

    function load_inbox_queue() {
        if (!$('#inboxQueue').length) { return; }
        ApiDataSvc.apiCall('post', 'inbox_queue_list', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) { render_inbox_queue(o); }
            else if (o.need_plan) { $('#inboxQueue').html('<p class="settings__empty">' + inbox_esc(o.message) + '</p>'); }
        });
    }

    function inbox_tab(name) {
        $('#inboxTabs .inbox-tabs__tab').removeClass('is-active').filter('[data-tab="' + name + '"]').addClass('is-active');
        $('.inbox-panel').prop('hidden', true).filter('[data-tab="' + name + '"]').prop('hidden', false);
        if (name === 'messages') { load_inbox_triggers(); }
    }
    $('#inboxTabs').on('click', '.inbox-tabs__tab', function () { inbox_tab($(this).data('tab')); });
    var inbox_deep_tab = (params.get('tab') || '').replace(/[^a-z]/g, '');
    if (inbox_deep_tab && $('#inboxTabs [data-tab="' + inbox_deep_tab + '"]').length) { inbox_tab(inbox_deep_tab); }

    var inbox_triggers_loaded = false, inbox_trigger_media = {};
    function load_inbox_triggers() {
        if (!$('#inboxTriggers').length || inbox_triggers_loaded) { return; }
        ApiDataSvc.apiCall('post', 'auto_messages_list', {}, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            inbox_triggers_loaded = true;
            Object.keys(o.items).forEach(function (t) {
                var $row = $('#inboxTriggers .inbox-trigger[data-trigger="' + t + '"]');
                var it = o.items[t];
                $row.find('[data-role="text"]').val(it.text || '');
                inbox_trigger_media[t] = it.assets || [];
                $row.find('[data-role="price"]').val(it.price > 0 ? it.price : '');
                $row.find('[data-role="sent"]').text(it.sent > 0 ? ('Sent ' + it.sent + ' time' + (it.sent === 1 ? '' : 's')) : (it.is_default ? 'Default message' : ''));
                inbox_trigger_state($row, !!it.enabled);
                inbox_trigger_thumbs($row);
            });
        });
    }
    function inbox_trigger_state($row, on) {
        $row.toggleClass('is-on', on);
        $row.find('[data-role="state"]').text(on ? 'On' : 'Off');
        $row.find('[data-role="off"]').prop('hidden', !on);
    }
    function inbox_trigger_thumbs($row) {
        var t = $row.data('trigger'), list = inbox_trigger_media[t] || [], $th = $row.find('[data-role="thumbs"]').empty();
        list.forEach(function (a) {
            $th.append('<span class="inbox-trigger__thumb" style="background-image:url(\'' + inbox_esc(a.thumb) + '\')">' + (a.type === 'video' ? '<i class="fa-solid fa-play"></i>' : '') + '<button type="button" class="inbox-trigger__rm" data-rm="' + a.id + '" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button></span>');
        });
        $row.find('[data-role="pricewrap"]').prop('hidden', !list.length);
        $row.find('[data-role="attach"]').html('<i class="fa-solid fa-image"></i> ' + (list.length ? 'Change Media' : 'Attach Media'));
    }
    $('#inboxTriggers').on('click', '[data-rm]', function () {
        var $row = $(this).closest('.inbox-trigger'), t = $row.data('trigger'), id = parseInt($(this).data('rm'), 10);
        inbox_trigger_media[t] = (inbox_trigger_media[t] || []).filter(function (a) { return a.id !== id; });
        inbox_trigger_thumbs($row);
    });
    /* Library picker for a trigger: a grid in a dialog, multi-select, up to 10. */
    $('#inboxTriggers').on('click', '[data-role="attach"]', function () {
        var $row = $(this).closest('.inbox-trigger'), t = $row.data('trigger');
        var sel = {}; (inbox_trigger_media[t] || []).forEach(function (a) { sel[a.id] = a; });
        var items = [];
        Swal.fire({
            title: 'Attach Media',
            html: '<input type="text" class="form-control inbox-pick__search" id="inboxPickSearch" placeholder="Search your library" maxlength="60"><div class="inbox-pick" id="inboxPickGrid"><p class="settings__empty">Loading…</p></div>',
            showCancelButton: true, confirmButtonText: 'Done', cancelButtonText: 'Cancel',
            customClass: { popup: 'inbox-pick__popup' },
            didOpen: function () {
                function render() {
                    var $g = $('#inboxPickGrid').empty();
                    if (!items.length) { $g.html('<p class="settings__empty">Nothing in your library matches.</p>'); }
                    items.forEach(function (a) {
                        $g.append('<button type="button" class="inbox-pick__item' + (sel[a.id] ? ' is-on' : '') + '" data-id="' + a.id + '" style="background-image:url(\'' + inbox_esc(a.thumb) + '\')" aria-pressed="' + (sel[a.id] ? 'true' : 'false') + '">' + (a.type === 'video' ? '<i class="fa-solid fa-play"></i>' : '') + '<span class="inbox-pick__chk"><i class="fa-solid fa-check"></i></span></button>');
                    });
                }
                function load(q) {
                    ApiDataSvc.apiCall('post', 'message_media_list', { q: q || '' }, function (data) {
                        var o = JSON.parse(data); items = o.success ? (o.assets || []) : []; render();
                    });
                }
                load('');
                var tmr = null;
                $('#inboxPickSearch').on('input', function () { var v = this.value.trim(); clearTimeout(tmr); tmr = setTimeout(function () { load(v); }, 250); });
                $('#inboxPickGrid').on('click', '.inbox-pick__item', function () {
                    var id = parseInt($(this).data('id'), 10), a = null;
                    items.forEach(function (x) { if (x.id === id) { a = x; } });
                    if (sel[id]) { delete sel[id]; } else { if (Object.keys(sel).length >= 10) { toastr.error('Up to 10 files per message'); return; } sel[id] = a; }
                    $(this).toggleClass('is-on', !!sel[id]).attr('aria-pressed', sel[id] ? 'true' : 'false');
                });
            }
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            inbox_trigger_media[t] = Object.keys(sel).map(function (k) { return sel[k]; });
            inbox_trigger_thumbs($row);
        });
    });
    $('#inboxTriggers').on('click', '[data-role="save"]', function () {
        var $row = $(this).closest('.inbox-trigger'), t = $row.data('trigger'); var $btns = $row.find('button').prop('disabled', true);
        var ids = (inbox_trigger_media[t] || []).map(function (a) { return a.id; });
        var price = parseInt($row.find('[data-role="price"]').val(), 10); if (isNaN(price) || price < 0) { price = 0; }
        ApiDataSvc.apiCall('post', 'auto_message_save', { trigger: t, text: $row.find('[data-role="text"]').val(), asset_ids: ids, price: price }, function (data) {
            var o = JSON.parse(data); $btns.prop('disabled', false);
            if (o.success) { toastr.success(o.message); inbox_trigger_state($row, true); } else { toastr.error(o.message); }
        });
    });
    $('#inboxTriggers').on('click', '[data-role="off"]', function () {
        var $row = $(this).closest('.inbox-trigger'); var $btns = $row.find('button').prop('disabled', true);
        ApiDataSvc.apiCall('post', 'auto_message_delete', { trigger: $row.data('trigger') }, function (data) {
            var o = JSON.parse(data); $btns.prop('disabled', false);
            if (o.success) { toastr.success(o.message); inbox_trigger_state($row, false); } else { toastr.error(o.message); }
        });
    });
    $('#inboxTriggers').on('click', '[data-role="ai"]', function () {
        var $row = $(this).closest('.inbox-trigger'); var $btn = $(this).prop('disabled', true);
        var $ta = $row.find('[data-role="text"]').attr('placeholder', 'Writing…');
        ApiDataSvc.apiCall('post', 'auto_message_save', { trigger: $row.data('trigger'), ai_generate: 1 }, function (data) {
            var o = JSON.parse(data); $btn.prop('disabled', false); $ta.attr('placeholder', 'Write the message, or let AI draft one');
            if (o.success) { $ta.val(o.text).trigger('focus'); } else { toastr.error(o.message); }
        });
    });

    $('#settings_nav').on('click', '.settings__nav-item[data-section="inbox"]', function () {
        load_inbox_queue();
        if ($('#inboxTabs .is-active').data('tab') === 'messages') { load_inbox_triggers(); }
        clearInterval(inbox_timer);
        inbox_timer = setInterval(function () {
            if ($('.settings__section[data-section="inbox"]').hasClass('is-active')) { load_inbox_queue(); } else { clearInterval(inbox_timer); }
        }, 60000);
    });
    if ($('.settings__section[data-section="inbox"]').hasClass('is-active')) { $('#settings_nav .settings__nav-item[data-section="inbox"]').trigger('click'); }

    $('#inboxQueue').on('click', '[data-inbox-send]', function () {
        var $item = $(this).closest('.inbox-queue__item');
        var $btns = $item.find('button').prop('disabled', true);
        ApiDataSvc.apiCall('post', 'inbox_reply_send', { id: $(this).data('inbox-send'), text: $item.find('.inbox-queue__draft').val() }, function (data) {
            var o = JSON.parse(data);
            if (o.success) { toastr.success(o.message); } else { toastr.error(o.message); }
            $btns.prop('disabled', false);
            load_inbox_queue();
        });
    });
    $('#inboxQueue').on('click', '[data-inbox-dismiss]', function () {
        var $btns = $(this).closest('.inbox-queue__item').find('button').prop('disabled', true);
        ApiDataSvc.apiCall('post', 'inbox_reply_dismiss', { id: $(this).data('inbox-dismiss') }, function (data) {
            var o = JSON.parse(data);
            if (o.success) { toastr.success(o.message); } else { toastr.error(o.message); }
            $btns.prop('disabled', false);
            load_inbox_queue();
        });
    });

    function inbox_form() {
        return {
            cls_enabled:     $('#inboxCls').is(':checked') ? 1 : 0,
            mode:            $('input[name="inbox_mode"]:checked').val() || 'approve',
            quiet_start:     $('#inboxQuietStart').val(),
            quiet_end:       $('#inboxQuietEnd').val(),
            quiet_action:    $('#inboxQuietAction').val(),
            max_consecutive: $('#inboxMaxConsecutive').val(),
            persona:         $('#inboxPersona').val(),
            avoid_topics:    $('#inboxAvoid').val(),
            upsell_enabled:  $('#inboxUpsell').is(':checked') ? 1 : 0,
            disclose_ai:     $('#inboxDisclose').is(':checked') ? 1 : 0
        };
    }

    $('#inboxSave').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'inbox_settings_save', inbox_form(), function (data) {
            var o = JSON.parse(data);
            $btn.prop('disabled', false);
            if (o.success) { toastr.success(o.message); return; }
            toastr.error(o.message);
            if (o.need_plan) { setTimeout(function () { window.location.href = '/account/billing'; }, 1200); }
        });
    });

    $('#inboxTest').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        var f = inbox_form();
        var $out = $('#inboxTestOut').prop('hidden', false).removeClass('is-hold').text('Thinking…');
        ApiDataSvc.apiCall('post', 'inbox_test_draft', { sample_text: $('#inboxTestText').val(), persona: f.persona, avoid_topics: f.avoid_topics, upsell_enabled: f.upsell_enabled, disclose_ai: f.disclose_ai }, function (data) {
            var o = JSON.parse(data);
            $btn.prop('disabled', false);
            if (!o.success) { $out.prop('hidden', true); toastr.error(o.message); return; }
            if (o.hold) { $out.addClass('is-hold').text(o.message); } else { $out.text(o.text); }
        });
    });
    $('#inboxTestText').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#inboxTest').trigger('click'); } });

    $('#fanvueDisconnect').on('click', function () {
        ApiDataSvc.apiCall('post', 'fanvue_disconnect', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = '/account/settings?section=connected'; }, 800);
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

    // Claude MCP connector token.
    $('#mcpGenerate').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'mcp_token_generate', {}, function (data) {
            $btn.prop('disabled', false);
            var o = JSON.parse(data);
            if (o.success) {
                var base = ($('#mcpBaseUrl').val() || '').replace(/\/+$/, '');
                $('#mcpConnectorUrl').val(base + '/' + o.token);
                $('#mcpTokenReveal').prop('hidden', false); $('#mcpHelp').prop('hidden', false);
                $('#mcpBadge').addClass('is-on'); $('#mcpStatusText').text('Connector token active');
                $('#mcpRevoke').prop('hidden', false);
                $btn.text('Regenerate');
                toastr.success(o.message);
            } else {
                toastr.error(o.message);
            }
        });
    });

    $('#mcpHelpToggle').on('click', function (e) { e.preventDefault(); var $h = $('#mcpHelp'); $h.prop('hidden', !$h.prop('hidden')); });
    $('#mcpCopy').on('click', function () {
        var url = $('#mcpConnectorUrl').val(); var $b = $(this);
        function done() { $b.text('Copied'); setTimeout(function () { $b.text('Copy'); }, 1500); }
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, function () { $('#mcpConnectorUrl').trigger('focus').select(); }); }
        else { $('#mcpConnectorUrl').trigger('focus').select(); try { document.execCommand('copy'); done(); } catch (err) {} }
    });
    $('#mcpRevoke').on('click', function () {
        ApiDataSvc.apiCall('post', 'mcp_token_revoke', {}, function (data) {
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
