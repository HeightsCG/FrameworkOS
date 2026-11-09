<link rel="stylesheet" href="/css/account-settings.css?v=<?php echo @filemtime(Main::app_path().'/public/css/account-settings.css'); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
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
            <?php $settings_covers = Plan::settings_covers();   /* creator sections a Free account sees as locked panels */ ?>
            <?php if ($this->can_content || isset($settings_covers['brand'])): ?>
            <button type="button" class="settings__nav-item" data-section="brand"><i class="fa-solid fa-wand-magic-sparkles"></i><span>Brand Identity</span></button>
            <?php endif; ?>
            <?php if ($this->is_owner_creator): ?>
            <button type="button" class="settings__nav-item" data-section="wallet"><i class="fa-solid fa-wand-magic-sparkles"></i><span>AI Credits</span></button>
            <?php endif; ?>
            <button type="button" class="settings__nav-item" data-section="blocked"><i class="fa-solid fa-ban"></i><span>Blocked Users</span></button>
            <?php if ($this->can_manage): ?>
            <button type="button" class="settings__nav-item" data-section="inbox"><i class="fa-solid fa-robot"></i><span>Inbox Automation</span><span class="settings__nav-badge" id="inboxNavBadge" <?php echo $this->inbox_pending > 0 ? '' : 'hidden'; ?>><?php echo (int) $this->inbox_pending; ?></span></button>
            <button type="button" class="settings__nav-item" data-section="connected"><i class="fa-solid fa-share-nodes"></i><span>Integrations</span></button>
            <button type="button" class="settings__nav-item" data-section="domain"><i class="fa-solid fa-globe"></i><span>Custom Domain</span></button>
            <?php elseif ($settings_covers): ?>
            <button type="button" class="settings__nav-item" data-section="inbox"><i class="fa-solid fa-robot"></i><span>Inbox Automation</span></button>
            <button type="button" class="settings__nav-item" data-section="connected"><i class="fa-solid fa-share-nodes"></i><span>Integrations</span></button>
            <button type="button" class="settings__nav-item" data-section="domain"><i class="fa-solid fa-globe"></i><span>Custom Domain</span></button>
            <?php endif; ?>
        </nav>

        <div class="settings__content">

            <section class="settings__section is-active" data-section="account">
                <?php echo Tutorials::bar('08'); ?>
                <?php $my_av = (string) ($this->my_avatar ?? ''); ?>
                <div class="myav">
                    <label class="uname__label">Profile Photo</label>
                    <div class="myav__row">
                        <div class="cprofile__avatar myav__img" id="my_avatar_preview" style="<?php echo $my_av !== '' ? 'background-image:url(\'' . htmlspecialchars($my_av, ENT_QUOTES, 'UTF-8') . '\')' : ''; ?>">
                            <?php if ($my_av === ''): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-secondary" id="my_avatar_upload_btn"><i class="fa-solid fa-camera"></i> Upload Photo</button>
                        <?php if (!empty($this->is_creator)): /* a creator has a library to pick from; a fan does not */ ?><button type="button" class="btn btn-secondary gal-pick" data-kind="my_avatar"><i class="fa-regular fa-images"></i> Choose From Gallery</button><?php endif; ?>
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
                    <h3 class="acct-card__title">Profile Details</h3>
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

                <?php $dx = $this->data_export; ?>
                <div class="acct-card" id="dx_card" data-export='<?php echo htmlspecialchars(json_encode($dx), ENT_QUOTES, 'UTF-8'); ?>'>
                    <h3 class="acct-card__title">Download Your Data</h3>
                    <p class="acct-card__desc">A zip of your account details, posts, messages and AI credit history, plus the original files in your Library. We&rsquo;ll notify you when it&rsquo;s ready, and it stays available for 7 days.</p>
                    <p class="dx__status" id="dx_status" hidden></p>
                    <div class="acct-card__actions">
                        <button type="button" class="btn btn-secondary" id="dx_request"><i class="fa-solid fa-file-zipper"></i> Request Export</button>
                        <button type="button" class="btn btn-secondary" id="dx_download" hidden><i class="fa-solid fa-download"></i> Download Export</button>
                    </div>
                </div>

                <div class="acct-danger">
                    <div class="acct-danger__info">
                        <span class="acct-danger__title">Delete Account</span>
                        <span class="acct-danger__desc">This is irreversible and permanently deletes your account and all associated data.</span>
                    </div>
                    <button type="button" class="btn btn-danger" id="delete_account">Delete Account</button>
                </div>
            </section>

            <section class="settings__section" data-section="security">
                <?php echo Tutorials::bar('09'); ?>
                <div class="acct-card">
                    <h3 class="acct-card__title">Password</h3>
                    <p class="acct-card__desc">Change the password you use to sign in.</p>
                    <button type="button" class="btn btn-secondary" id="change_password">Change Password</button>
                </div>

                <div class="acct-card">
                    <h3 class="acct-card__title">Google Sign-In</h3>
                    <p class="acct-card__desc"><?php echo $this->google_linked ? 'You can sign in to this account with Google.' : 'Connect your Google account to sign in with it.'; ?></p>
                    <?php if ($this->google_linked): ?>
                    <button type="button" class="btn btn-secondary" id="google_disconnect">Disconnect Google</button>
                    <?php else: ?>
                    <a class="btn btn-secondary" href="/account/google_start">Connect Google</a>
                    <?php endif; ?>
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
                <?php echo Tutorials::bar('10'); ?>

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
                        <?php if (in_array($key, NotificationPrefsModel::$email_only, true)): /* email only: no in-app notices in this category */ ?>
                        <span></span>
                        <?php else: ?>
                        <label class="notif__switch">
                            <input type="checkbox" class="notif-pref" data-category="<?php echo $key; ?>" data-channel="in_platform" <?php echo !empty($pref['in_platform']) ? 'checked' : ''; ?>>
                            <span class="notif__slider"></span>
                        </label>
                        <?php endif; ?>
                        <label class="notif__switch">
                            <input type="checkbox" class="notif-pref" data-category="<?php echo $key; ?>" data-channel="email" <?php echo !empty($pref['email']) ? 'checked' : ''; ?>>
                            <span class="notif__slider"></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="settings__section" data-section="creator">
                <?php echo Tutorials::bar($this->is_creator ? '03' : '01'); ?>
                <?php if (!$this->is_creator): ?>
                <div class="creator-cta">
                    <button type="button" class="btn btn-primary" id="become_creator_btn">Become a Creator</button>
                </div>
                <?php else: ?>
                <?php if (!empty($this->needs_agreement)): /* a selling creator who never accepted (pre-checkout accounts): selling and payouts wait on this */ ?>
                <div class="creator-cta" style="margin-bottom:1.5rem;">
                    <label class="creator-cta__terms-label" for="creator_terms">Creator Agreement &amp; Content Policy</label>
                    <textarea id="creator_terms" class="creator-cta__terms form-control" rows="10" readonly><?php echo htmlspecialchars($this->creator_terms, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <label class="creator-cta__agree">
                        <input type="checkbox" id="creator_agree">
                        <span>I have read and accept the Creator Agreement and Content Policy.</span>
                    </label>
                    <button type="button" class="btn btn-primary" id="become_creator_btn">Accept Agreement</button>
                </div>
                <?php endif; ?>
                <?php $cp = $this->creator_profile; ?>
                <div class="cprofile">
                    <?php if ($this->is_owner_creator): ?>
                    <?php $vs = (string) $this->verif_status; $vr = (string) ($this->verif_state['reason'] ?? ''); /* IdentityVerification::state: Didit's ID check sets the badge */ ?>
                    <div class="cprofile__verify cprofile__verify--<?php echo $this->verified ? 'done' : ($vs === 'pending' ? 'pending' : 'none'); ?>">
                        <?php if ($this->verified): ?>
                            <span class="cprofile__verify-ic"><i class="fa-solid fa-circle-check"></i></span>
                            <div class="cprofile__verify-txt"><strong>Verified creator</strong><span>Your ID check passed. The badge shows on your profile.</span></div>
                        <?php elseif ($vs === 'pending'): ?>
                            <span class="cprofile__verify-ic"><i class="fa-solid fa-clock"></i></span>
                            <div class="cprofile__verify-txt"><strong>ID check in progress</strong><span>Finish the ID check to get your badge. If you already did, the result can take a minute.</span></div>
                            <button type="button" class="btn btn-secondary" id="verify_request_btn">Continue ID Check</button>
                        <?php elseif ($vs === 'rejected'): ?>
                            <span class="cprofile__verify-ic"><i class="fa-solid fa-triangle-exclamation"></i></span>
                            <div class="cprofile__verify-txt"><strong>We couldn't verify your ID</strong><span><?php echo htmlspecialchars($vr !== '' ? rtrim($vr, '.') . '.' : 'The ID check did not pass.', ENT_QUOTES, 'UTF-8'); ?> Use a valid photo ID and good light, then try again.</span></div>
                            <button type="button" class="btn btn-primary" id="verify_request_btn">Try Again</button>
                        <?php else: ?>
                            <span class="cprofile__verify-ic"><i class="fa-solid fa-shield-halved"></i></span>
                            <div class="cprofile__verify-txt"><strong>Get verified</strong><span>Add a verified badge so fans know it's really you. It takes a photo of your ID and a selfie.</span></div>
                            <button type="button" class="btn btn-primary" id="verify_request_btn">Request Verification</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($cp['avatar_url']) || empty($this->creator_links)): /* a new page: offer to bring the old one over */ ?>
                    <div class="cprofile__verify cprofile__verify--none" id="import_strip">
                        <span class="cprofile__verify-ic"><i class="fa-solid fa-file-import"></i></span>
                        <div class="cprofile__verify-txt"><strong>Moving from Linktree, Beacons or Stan?</strong><span>Bring over your photo, name, bio and links in one step.</span></div>
                        <button type="button" class="btn btn-secondary imp-open">Import Your Page</button>
                    </div>
                    <?php endif; ?>

                    <div class="cprofile__field">
                        <label>Cover Image</label>
                        <div class="cprofile__cover" id="cover_preview" style="<?php echo !empty($cp['cover_url']) ? 'background-image:url(\'' . htmlspecialchars($cp['cover_url'], ENT_QUOTES, 'UTF-8') . '\')' : ''; ?>">
                            <div class="cprofile__cover-actions">
                                <button type="button" class="btn btn-secondary" id="cover_upload_btn"><i class="fa-solid fa-camera"></i> Upload Cover</button>
                                <button type="button" class="btn btn-secondary gal-pick" data-kind="cover"><i class="fa-regular fa-images"></i> Choose From Gallery</button>
                                <button type="button" class="btn btn-secondary" id="cover_remove" <?php echo empty($cp['cover_url']) ? 'hidden' : ''; ?>>Remove</button>
                            </div>
                        </div>
                    </div>

                    <div class="cprofile__field">
                        <label>Profile Photo</label>
                        <div class="cprofile__avatar-row">
                            <div class="cprofile__avatar" id="avatar_preview" style="<?php echo !empty($cp['avatar_url']) ? 'background-image:url(\'' . htmlspecialchars($cp['avatar_url'], ENT_QUOTES, 'UTF-8') . '\')' : ''; ?>">
                                <?php if (empty($cp['avatar_url'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary" id="avatar_upload_btn"><i class="fa-solid fa-camera"></i> Upload Photo</button>
                            <button type="button" class="btn btn-secondary gal-pick" data-kind="avatar"><i class="fa-regular fa-images"></i> Choose From Gallery</button>
                            <button type="button" class="btn btn-secondary" id="avatar_remove" <?php echo empty($cp['avatar_url']) ? 'hidden' : ''; ?>>Remove</button>
                        </div>
                    </div>

                    <input type="file" id="cover_file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                    <input type="file" id="avatar_file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>

                    <div class="cprofile__grid">
                        <div class="cprofile__field">
                            <label for="cp_display_name">Display Name</label>
                            <input type="text" class="form-control" id="cp_display_name" maxlength="190" value="<?php echo htmlspecialchars(html_entity_decode((string) $cp['display_name'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="cprofile__field">
                            <label for="cp_location">Location (Optional)</label>
                            <input type="text" class="form-control" id="cp_location" maxlength="190" placeholder="City, Country" value="<?php echo htmlspecialchars(html_entity_decode((string) $cp['location'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <div class="cprofile__field">
                        <label for="cp_bio">Bio (Optional)</label>
                        <textarea class="form-control" id="cp_bio" rows="4" placeholder="Tell visitors who you are and what you offer."><?php echo htmlspecialchars(html_entity_decode((string) $cp['bio'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="cprofile__divider"></div>

                    <div class="cprofile__subhead">
                        <h3 class="cprofile__subtitle">Links</h3>
                        <div class="cprofile__subhead-actions">
                            <button type="button" class="btn btn-secondary imp-open"><i class="fa-solid fa-file-import"></i> Import Page</button>
                            <button type="button" class="btn btn-secondary" id="link_add_btn"><i class="fa-solid fa-plus"></i> Add Link</button>
                        </div>
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

                    <div class="cprofile__divider"></div>
                    <?php $dir_on = !empty($cp['directory_listed']); $dir_state = (string) $this->directory_state; ?>
                    <div class="cprofile__subhead">
                        <h3 class="cprofile__subtitle">Creator Directory</h3>
                        <span class="dir-status dir-status--<?php echo $dir_state; ?>" id="dir_status"<?php echo $dir_state === 'off' ? ' hidden' : ''; ?>><?php echo $dir_state === 'listed' ? 'Listed' : ((string) ($this->directory_missing ?? '') !== '' ? 'Not showing: ' . htmlspecialchars(lcfirst($this->directory_missing), ENT_QUOTES) : 'Not showing yet'); ?></span>
                    </div>
                    <div class="dir-row">
                        <label class="notif__switch">
                            <input type="checkbox" id="dir_listed" <?php echo $dir_on ? 'checked' : ''; ?> aria-label="List me in the Creator Directory">
                            <span class="notif__slider"></span>
                        </label>
                        <label class="dir-row__label" for="dir_listed">List me in the Creator Directory</label>
                        <select class="form-select dir-row__cat" id="dir_category" aria-label="Directory category">
                            <option value="">Choose a category</option>
                            <?php foreach (DirectoryService::choices($cp['directory_category'] ?? '') as $dk => $dl): ?><option value="<?php echo $dk; ?>"<?php echo ((string) ($cp['directory_category'] ?? '')) === $dk ? ' selected' : ''; ?>><?php echo htmlspecialchars($dl, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (Permissions::team_allows('manage') && PromoSwapsModel::eligible(Permissions::creator_id())): /* cross-promotion opt-in (/promote), saved on change */ ?>
                    <div class="dir-row" style="margin-top:16px;">
                        <label class="notif__switch">
                            <input type="checkbox" id="cross_promo_in" <?php echo !empty($cp['cross_promo_in']) ? 'checked' : ''; ?> aria-label="Cross-Promotion">
                            <span class="notif__slider"></span>
                        </label>
                        <label class="dir-row__label" for="cross_promo_in">Cross-Promotion</label>
                        <a class="btn btn-secondary btn-sm" href="/promote">Find Partners</a>
                    </div>
                    <script>
                    document.getElementById('cross_promo_in').addEventListener('change', function () {
                        var box = this; box.disabled = true;
                        ApiDataSvc.apiCall('post', 'promo_opt_in', { on: box.checked ? 1 : 0 }, function (data) {
                            box.disabled = false;
                            var o = null; try { o = JSON.parse(data); } catch (e) {}
                            if (!o || !o.success) { box.checked = !box.checked; toastr.error((o && o.message) || 'Could not save Cross-Promotion'); return; }
                            toastr.success(o.message);
                        });
                    });
                    </script>
                    <?php endif; ?>

                    <div class="cprofile__divider"></div>
                    <div class="cprofile__subhead">
                        <h3 class="cprofile__subtitle">More Creators Like This</h3>
                    </div>
                    <div class="dir-row" style="flex-wrap:nowrap;">
                        <label class="notif__switch">
                            <input type="checkbox" id="similar_on" <?php echo empty($cp['similar_off']) ? 'checked' : ''; ?> aria-label="Show More Creators Like This on My Page">
                            <span class="notif__slider"></span>
                        </label>
                        <label class="dir-row__label" for="similar_on">Show More Creators Like This on My Page</label>
                    </div>

                    <div class="cprofile__actions cprofile__actions--end">
                        <button type="button" class="btn btn-primary" id="cp_save">Save Changes</button>
                    </div>
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
                    <?php echo Tutorials::button('04', 'data-float'); ?>
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
                                <label for="brand_name">Brand Name</label>
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

            <?php foreach ($settings_covers as $cover_key => $cover): ?>
            <section class="settings__section" data-section="<?php echo $cover_key; ?>">
                <div class="settings-lock">
                    <div class="settings-lock__icon"><i class="fa-solid <?php echo $cover['icon']; ?>" aria-hidden="true"></i></div>
                    <h2 class="settings-lock__title"><?php echo htmlspecialchars($cover['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="settings-lock__text"><?php echo htmlspecialchars($cover['text'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="settings-lock__actions">
                        <a href="/account/billing" class="btn btn-primary">Choose a Plan</a>
                        <?php echo $cover['video'] !== '' ? Tutorials::button($cover['video']) : ''; ?>
                    </div>
                    <p class="settings-lock__note">Included with Creator and Studio.</p>
                </div>
            </section>
            <?php endforeach; ?>

            <?php if ($this->is_owner_creator): ?>
            <section class="settings__section" data-section="wallet">
                <div class="wallet">
                    <div class="wallet__balance wallet__balance--ai">
                        <span class="wallet__balance-label">AI credits<small>For creating images and videos</small></span>
                        <span class="wallet__balance-side">
                            <span class="wallet__balance-value wallet__balance-value--ai"><i class="fa-solid fa-wand-magic-sparkles"></i> <?php echo number_format((int) ($this->user['ai_credit_balance'] ?? 0)); ?></span>
                            <?php if (Plan::has_paid_plan($this->user)): ?><a class="wallet__balance-link" href="/account/billing?tab=credits">Buy AI Credits</a><?php endif; ?>
                        </span>
                    </div>
                </div>
            </section>
            <?php endif; ?>

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
                    <?php echo Tutorials::button('07', 'data-float'); ?>
                    <h2 class="settings__section-title">Inbox Automation</h2>
                    <p class="settings__section-desc"><?php echo !empty($this->inbox_ai) ? 'Answer fan messages in your own voice, automatically. Drafts wait for your approval until you decide to let them send on their own.' : 'Welcome and trigger messages go out automatically, once per fan.'; ?></p>
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
                <?php $is = $this->inbox_settings; $inbox_ai = !empty($this->inbox_ai); ?>

                <?php if (!$inbox_ai): ?>
                    <div class="settings__upgrade inbox-upgrade">
                        <i class="fa-solid fa-lock settings__upgrade-icon"></i>
                        <div>
                            <div class="settings__upgrade-title">AI replies start on <?php echo htmlspecialchars((string) $this->inbox_ai_upgrade, ENT_QUOTES, 'UTF-8'); ?></div>
                            <p class="settings__upgrade-text">Your inbox works as usual and you answer fans yourself. Upgrade to have fan messages answered in your voice.</p>
                        </div>
                        <a href="/account/billing" class="btn btn-primary">Upgrade to <?php echo htmlspecialchars((string) $this->inbox_ai_upgrade, ENT_QUOTES, 'UTF-8'); ?></a>
                    </div>
                <?php endif; ?>

                <?php if ($inbox_ai && !$this->claude_ok): ?>
                    <p class="inbox-note inbox-note--warn"><i class="fa-solid fa-triangle-exclamation"></i> AI replies are not configured on this server yet.</p>
                <?php endif; ?>

                <div class="inbox-tabs" id="inboxTabs" role="tablist">
                    <?php if ($inbox_ai): ?>
                    <button type="button" class="inbox-tabs__tab is-active" data-tab="queue" role="tab">Queue<span class="inbox-tabs__count" id="inboxQueueCount"></span></button>
                    <button type="button" class="inbox-tabs__tab" data-tab="settings" role="tab">Settings</button>
                    <?php endif; ?>
                    <button type="button" class="inbox-tabs__tab<?php echo $inbox_ai ? '' : ' is-active'; ?>" data-tab="messages" role="tab">Welcome Messages</button>
                </div>

                <?php if ($inbox_ai): ?>
                <div class="inbox-panel" data-tab="queue">
                <div class="inbox-block">
                    <div class="inbox-block__head">
                        <h3 class="inbox-block__title">Waiting for Your Approval</h3>
                    </div>
                    <div id="inboxQueue" class="inbox-queue"><p class="settings__empty">Loading&hellip;</p></div>
                </div>
                <div class="inbox-block">
                    <div class="inbox-block__head"><h3 class="inbox-block__title">Recent Activity</h3></div>
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
                        <label>How Replies Go Out</label>
                        <div class="inbox-radios">
                            <label class="inbox-radio"><input type="radio" name="inbox_mode" value="approve" <?php echo ($is['mode'] !== 'auto') ? 'checked' : ''; ?>><span><strong>Approve first</strong><small>Each draft waits here until you send, edit, or dismiss it.</small></span></label>
                            <label class="inbox-radio"><input type="radio" name="inbox_mode" value="auto" <?php echo ($is['mode'] === 'auto') ? 'checked' : ''; ?>><span><strong>Send automatically</strong><small>Replies go straight to the fan after a short, natural pause.</small></span></label>
                        </div>
                    </div>

                    <div class="inbox-grid">
                        <div class="cprofile__field">
                            <label for="inboxQuietStart">Quiet Hours</label>
                            <div class="inbox-quiet">
                                <input type="time" class="form-control" id="inboxQuietStart" value="<?php echo htmlspecialchars((string) ($is['quiet_start'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="inbox-quiet__to">to</span>
                                <input type="time" class="form-control" id="inboxQuietEnd" value="<?php echo htmlspecialchars((string) ($is['quiet_end'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <span class="cprofile__hint">In your content timezone. Leave both empty for none.</span>
                        </div>
                        <div class="cprofile__field">
                            <label for="inboxQuietAction">During Quiet Hours</label>
                            <select class="form-select" id="inboxQuietAction">
                                <option value="hold" <?php echo ($is['quiet_action'] !== 'skip') ? 'selected' : ''; ?>>Hold drafts for approval</option>
                                <option value="skip" <?php echo ($is['quiet_action'] === 'skip') ? 'selected' : ''; ?>>Don't reply at all</option>
                            </select>
                        </div>
                        <div class="cprofile__field">
                            <label for="inboxMaxConsecutive">Max Replies in a Row</label>
                            <input type="number" class="form-control" id="inboxMaxConsecutive" min="1" max="10" value="<?php echo (int) $is['max_consecutive']; ?>">
                            <span class="cprofile__hint">After this many automated replies without you, the AI pauses for that fan.</span>
                        </div>
                    </div>

                    <div class="cprofile__field">
                        <label for="inboxPersona">How You Talk to Fans</label>
                        <textarea class="form-control" id="inboxPersona" rows="4" maxlength="2000" placeholder="Short and playful. Use their name. Never use emojis. Sign off with x."><?php echo htmlspecialchars((string) $is['persona'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <span class="cprofile__hint">Your Brand Identity voice is used automatically. Add anything specific to DMs here.</span>
                    </div>
                    <div class="cprofile__field">
                        <label for="inboxAvoid">Topics to Avoid</label>
                        <textarea class="form-control" id="inboxAvoid" rows="2" maxlength="2000" placeholder="Politics, my family, where I live"><?php echo htmlspecialchars((string) $is['avoid_topics'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <span class="cprofile__hint">Messages about these are held for you instead of answered.</span>
                    </div>

                    <div class="cprofile__field">
                        <label for="inboxAiDisclosure">First Reply Disclosure</label>
                        <input type="text" class="form-control" id="inboxAiDisclosure" maxlength="300" required placeholder="<?php echo htmlspecialchars(AiDisclosure::DEFAULT_FIRST_REPLY, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) ($is['ai_disclosure_text'] ?? AiDisclosure::DEFAULT_FIRST_REPLY), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
<?php $inbox_infl = array(); try { foreach ((new InfluencersModel())->list_ready(Permissions::creator_id()) as $ir) { $inbox_infl[] = $ir; } } catch (\Throwable $e) {} ?>
<?php if (!empty($inbox_infl)): ?>
                    <div class="cprofile__field">
                        <label for="inboxPersonaInfluencer">Inbox Persona</label>
                        <select class="form-select" id="inboxPersonaInfluencer">
                            <option value="0">Brand Voice Only</option>
                            <?php foreach ($inbox_infl as $ir): ?><option value="<?php echo (int) $ir['id']; ?>"<?php echo ((int) ($is['persona_influencer_id'] ?? 0) === (int) $ir['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $ir['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                        </select>
                    </div>
<?php endif; ?>

                    <div class="inbox-checks">
                        <label class="inbox-check"><input class="form-check-input" type="checkbox" id="inboxDisclose" <?php echo !empty($is['disclose_ai']) ? 'checked' : ''; ?>><span><strong>Admit AI When Asked</strong></span></label>
                    </div>

                    <div class="cprofile__field">
                        <label for="inboxTestText">Try It</label>
                        <div class="inbox-test">
                            <input type="text" class="form-control" id="inboxTestText" maxlength="500" placeholder="hey, loved your last post. what are you up to this weekend?">
                            <button type="button" class="btn btn-secondary" id="inboxTest" <?php echo $this->claude_ok ? '' : 'disabled'; ?>>Draft a Reply</button>
                        </div>
                        <div class="inbox-test__out" id="inboxTestOut" hidden></div>
                    </div>

                    <div class="cprofile__actions">
                        <button type="button" class="btn btn-primary" id="inboxSave">Save</button>
                    </div>
                </div>
                </div>

                <?php endif; ?>

                <div class="inbox-panel" data-tab="messages"<?php echo $inbox_ai ? ' hidden' : ''; ?>>
                <div class="inbox-block">
                    <div class="inbox-block__head"><h3 class="inbox-block__title">Welcome &amp; trigger messages</h3></div>
                    <p class="inbox-block__desc">Sent automatically as a direct message, once per fan.</p>
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
                            <textarea class="form-control inbox-trigger__input" rows="2" maxlength="2000" placeholder="<?php echo $inbox_ai ? 'Write the message, or let AI draft one' : 'Write the message'; ?>" data-role="text"></textarea>
                            <div class="inbox-trigger__media" data-role="media">
                                <div class="inbox-trigger__thumbs" data-role="thumbs"></div>
                                <button type="button" class="inbox-trigger__attach" data-role="attach"><i class="fa-solid fa-image"></i> Attach Media</button>
                            </div>
                            <div class="inbox-trigger__actions">
                                <span class="inbox-trigger__sent" data-role="sent"></span>
                                <?php if ($inbox_ai): ?><button type="button" class="btn btn-secondary" data-role="ai" <?php echo $this->claude_ok ? '' : 'disabled'; ?>><i class="fa-solid fa-wand-magic-sparkles"></i> Write with AI</button><?php endif; ?>
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

            <section class="settings__section" data-section="domain">
                <div class="settings__section-head">
                    <h2 class="settings__section-title">Custom Domain</h2>
                    <p class="settings__section-desc">Show your page at your own address, like yourname.com.</p>
                </div>
                <?php if (!$this->domain_allowed): ?>
                    <div class="settings__upgrade">
                        <i class="fa-solid fa-globe settings__upgrade-icon"></i>
                        <div>
                            <div class="settings__upgrade-title">Custom domains are on the <?php echo htmlspecialchars($this->domain_upgrade !== '' ? $this->domain_upgrade : 'Studio', ENT_QUOTES, 'UTF-8'); ?> plan</div>
                            <p class="settings__upgrade-text">Upgrade to show your page at your own domain.</p>
                        </div>
                        <a href="/account/billing" class="btn btn-primary">View Plans</a>
                    </div>
                <?php elseif (empty($this->domains)): ?>
                    <div class="integ">
                        <div class="integ__row">
                            <span class="integ__icon"><i class="fa-solid fa-globe"></i></span>
                            <div class="integ__main">
                                <div class="integ__name">Connect a domain</div>
                                <div class="integ__inline">
                                    <input type="text" class="form-control form-control-sm" id="domain_host" placeholder="yourname.com" autocomplete="off" spellcheck="false">
                                    <button type="button" class="btn btn-primary btn-sm" id="domain_add">Add Domain</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php
                        $dom_status = array('active' => 'Connected', 'verified' => 'Waiting for the address record', 'pending' => 'Waiting for DNS records', 'failed' => 'DNS records not found yet', 'disabled' => 'Off');
                        $dom_pair   = count($this->domains) > 1;
                        $dom_prev_error = null;   // a pair shares one TXT record: show its error once
                    ?>
                    <div class="integ">
                        <?php foreach ($this->domains as $d): $d_live = in_array($d['status'], array('active', 'verified'), true); ?>
                        <div class="integ__row">
                            <span class="integ__icon"><i class="fa-solid fa-globe"></i></span>
                            <div class="integ__main">
                                <div class="integ__name"><?php echo htmlspecialchars($d['hostname'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="integ__meta">
                                    <span class="integ__dot <?php echo $d['status'] === 'active' ? 'is-on' : ''; ?>"></span><?php echo $dom_status[$d['status']] ?? ''; ?>
                                    <?php if ($dom_pair): ?><span class="integ__sep">·</span><?php echo !empty($d['is_primary']) ? 'Main address' : 'Redirects to your main address'; ?><?php endif; ?>
                                    <?php if ($d_live): ?><span class="integ__sep">·</span><a class="integ__link" href="https://<?php echo htmlspecialchars($d['hostname'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Open</a><?php endif; ?>
                                </div>
                                <?php if ($d['status'] !== 'active' && (string) $d['last_error'] !== '' && $d['last_error'] !== $dom_prev_error): ?><div class="integ__error"><?php echo htmlspecialchars($d['last_error'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; $dom_prev_error = (string) $d['last_error']; ?>
                            </div>
                            <?php if ($dom_pair && empty($d['is_primary'])): ?>
                            <div class="integ__actions"><button type="button" class="btn btn-secondary btn-sm" data-domain-primary="<?php echo (int) $d['id']; ?>">Make Main Address</button></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="integ__group dns-group">DNS Records</h3>
                    <div class="integ">
                        <table class="dns-records">
                            <thead><tr><th>Type</th><th>Host</th><th>Value</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($this->domain_records as $r): ?>
                                <tr>
                                    <td class="dns-records__type"><?php echo $r['type']; ?></td>
                                    <td class="dns-records__mono"><?php echo htmlspecialchars($r['host'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if ($r['value'] === ''): ?><span class="dns-records__missing">Not available yet</span>
                                        <?php else: ?>
                                        <span class="dns-records__value">
                                            <span class="dns-records__mono"><?php echo htmlspecialchars($r['value'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <button type="button" class="dns-records__copy" data-copy="<?php echo htmlspecialchars($r['value'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Copy value"><i class="fa-regular fa-copy"></i></button>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="dns-records__state <?php echo $r['done'] ? 'is-done' : ''; ?>"><?php echo $r['done'] ? '<i class="fa-solid fa-check"></i> Found' : 'Waiting'; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="dns-actions">
                        <button type="button" class="btn btn-primary" id="domain_verify">Check DNS</button>
                        <button type="button" class="btn btn-secondary" id="domain_remove">Remove Domain</button>
                    </div>
                <?php endif; ?>
            </section>

            <section class="settings__section" data-section="connected">
                <div class="settings__section-head">
                    <?php echo Tutorials::button('06', 'data-float'); ?>
                    <h2 class="settings__section-title">Integrations</h2>
                    <p class="settings__section-desc">Connect the platforms you publish to and the tools that work on your behalf.</p>
                </div>

                <?php $er = $this->eromify; $er_on = $er && ($er['status'] ?? '') === 'connected'; ?>

                <h3 class="integ__group">Platforms &amp; tools</h3>
                <div class="integ">

                    <?php if ($this->can_manage): ?>
                    <div class="integ__row">
                        <span class="integ__icon"><i class="fa-solid fa-user-astronaut"></i></span>
                        <div class="integ__main">
                            <div class="integ__name">Eromify</div>
                            <div class="integ__meta">
                                <?php if ($er_on): ?>
                                    <span class="integ__dot is-on"></span><?php echo htmlspecialchars(ucfirst((string) ($er['plan'] ?: 'Connected')) . ' plan' . ($er['credits'] !== null ? ' · ' . number_format((int) $er['credits']) . ' credits' : ''), ENT_QUOTES, 'UTF-8'); ?>
                                <?php else: ?>
                                    <span class="integ__dot"></span>Put your AI character in automated posts
                                <?php endif; ?>
                            </div>
                            <?php if (!$er_on): ?>
                            <div class="integ__reveal" id="eromifyKeyWrap" hidden>
                                <input type="text" class="form-control form-control-sm" id="eromifyKey" placeholder="API key from eromify.com/studio/api" aria-label="Eromify API key" autocomplete="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true">
                            </div>
                            <?php endif; ?>
                            <?php if ($er_on && !empty($er['last_error'])): ?><div class="integ__error"><?php echo htmlspecialchars($er['last_error'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                        </div>
                        <div class="integ__actions">
                            <?php if ($er_on): ?><button type="button" class="btn btn-ghost btn-sm" id="eromifyDisconnect">Disconnect</button>
                            <?php else: ?><button type="button" class="btn btn-secondary btn-sm" id="eromifyConnect">Connect</button>
                            <button type="button" class="btn btn-ghost btn-sm" id="eromifyCancel" hidden>Cancel</button><?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

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

                <h3 class="integ__group">Social Accounts</h3>
                <?php if (!$this->can_social_post): ?>
                    <div class="settings__upgrade">
                        <i class="fa-solid fa-lock settings__upgrade-icon"></i>
                        <div>
                            <div class="settings__upgrade-title">Social posting is a premium feature</div>
                            <p class="settings__upgrade-text">Upgrade to a plan that includes social posting to connect your accounts and publish to Instagram, TikTok, LinkedIn and more.</p>
                        </div>
                        <a href="/account/billing" class="btn btn-primary">View Plans</a>
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
                                    <span class="integ__dot is-on"></span><?php echo count($accts) === 1 ? '1 account connected' : count($accts) . ' accounts connected'; ?>
                                <?php else: ?>
                                    <span class="integ__dot"></span>Not connected
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="integ__actions">
                            <button type="button" class="btn btn-secondary btn-sm conn-connect" data-platform="<?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?>"><?php echo !empty($accts) ? 'Add Account' : 'Connect'; ?></button>
                        </div>
                    </div>
                    <?php if (!empty($accts)): ?>
                    <ul class="integ__accts">
                        <?php foreach ($accts as $a): ?>
                        <li class="integ__acct">
                            <span class="integ__dot is-on"></span>
                            <span class="integ__acctname"><?php echo htmlspecialchars(($a['username'] !== '' && $a['username'] !== null) ? '@' . $a['username'] : 'Connected', ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if (AiDisclosure::flag_for($p) === ''): /* platforms with their own AI label need no line */ ?>
                            <label class="integ__ailine"><span class="integ__ailabel">AI Disclosure Line</span>
                                <input type="text" class="form-control form-control-sm conn-ai-line" maxlength="200" placeholder="<?php echo htmlspecialchars(AiDisclosure::DEFAULT_LINE, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) ($a['ai_disclosure_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-account-id="<?php echo htmlspecialchars($a['post_for_me_social_account_id'], ENT_QUOTES, 'UTF-8'); ?>"></label>
                            <?php endif; ?>
                            <button type="button" class="btn btn-ghost btn-sm conn-disconnect" data-account-id="<?php echo htmlspecialchars($a['post_for_me_social_account_id'], ENT_QUOTES, 'UTF-8'); ?>">Disconnect</button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
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
                <h5 class="modal-title" id="link_modal_title">Add Link</h5>
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
                <button type="button" class="btn btn-primary" id="link_save">Save Link</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="import_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Import Your Page</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="imp-pane" id="imp_pane_url">
                    <div class="link-field">
                        <label for="imp_url">Page Address</label>
                        <input type="text" class="form-control" id="imp_url" placeholder="linktr.ee/yourname" autocomplete="off">
                    </div>
                    <p class="imp-sources"><button type="button" class="imp-switch" id="imp_to_paste">Paste Links Instead</button></p>
                </div>
                <div class="imp-pane" id="imp_pane_paste" hidden>
                    <p class="imp-error" id="imp_error" hidden><i class="fa-solid fa-circle-exclamation"></i><span></span></p>
                    <div class="link-field">
                        <label for="imp_pasted">Links</label>
                        <textarea class="form-control" id="imp_pasted" rows="7" placeholder="Instagram https://instagram.com/yourname&#10;https://yourshop.com"></textarea>
                    </div>
                    <p class="imp-sources"><button type="button" class="imp-switch" id="imp_to_url">Read a Page Address Instead</button></p>
                </div>
                <div class="imp-pane" id="imp_pane_review" hidden>
                    <p class="imp-from" id="imp_from"></p>
                    <div class="imp-group" id="imp_profile_group">
                        <h6 class="imp-group__title">Profile</h6>
                        <label class="imp-row" id="imp_row_avatar"><input type="checkbox" class="form-check-input" id="imp_take_avatar" checked><span class="imp-row__thumb" id="imp_avatar"></span><span class="imp-row__body"><span class="imp-row__title">Profile Photo</span><span class="imp-row__sub">Replaces your current photo</span></span></label>
                        <label class="imp-row" id="imp_row_name"><input type="checkbox" class="form-check-input" id="imp_take_name" checked><span class="imp-row__body"><span class="imp-row__title">Display Name</span><span class="imp-row__sub" id="imp_name"></span></span></label>
                        <label class="imp-row" id="imp_row_bio"><input type="checkbox" class="form-check-input" id="imp_take_bio" checked><span class="imp-row__body"><span class="imp-row__title">Bio</span><span class="imp-row__sub imp-row__sub--wrap" id="imp_bio"></span></span></label>
                    </div>
                    <div class="imp-group" id="imp_links_group">
                        <div class="imp-group__head"><h6 class="imp-group__title">Links <span id="imp_links_count"></span></h6><button type="button" class="imp-switch" id="imp_toggle_links">Clear All</button></div>
                        <div id="imp_links"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="imp_back" hidden>Back</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="imp_cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="imp_read">Read Page</button>
                <button type="button" class="btn btn-primary" id="imp_apply" hidden>Import Selected</button>
            </div>
        </div>
    </div>
</div>

<?php /* Pick a picture from the creator's own library for a profile photo or cover; it then goes through the same crop and upload as a file. */ ?>
<div class="modal fade" id="gallery_modal" tabindex="-1" aria-hidden="true" aria-labelledby="gallery_modal_title">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="gallery_modal_title">Choose from your gallery</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="gal-state" id="gallery_state" role="status"></div>
                <div class="gal-grid" id="gallery_grid"></div>
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
                <p>Are you sure you want to continue?</p>
                <div class="leave-why">
                    <div class="form-floating mb-2">
                        <select class="form-select" id="lc_reason" required aria-describedby="lc_reason_err"><option value="">Choose one</option><?php foreach (UsersModel::LEAVE_REASONS as $lk => $ll): ?><option value="<?php echo $lk; ?>"><?php echo htmlspecialchars($ll, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                        <label for="lc_reason">Reason for Leaving</label>
                        <div class="invalid-feedback" id="lc_reason_err">Choose a reason.</div>
                    </div>
                    <div class="form-floating">
                        <textarea class="form-control" id="lc_note" style="height:88px"></textarea>
                        <label for="lc_note">Anything Else (Optional)</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="leave_creator_confirm">Delete and Stop Being a Creator</button>
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
                <div class="leave-why">
                    <div class="form-floating mb-2">
                        <select class="form-select" id="del_reason" required aria-describedby="del_reason_err"><option value="">Choose one</option><?php foreach (UsersModel::LEAVE_REASONS as $lk => $ll): ?><option value="<?php echo $lk; ?>"><?php echo htmlspecialchars($ll, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                        <label for="del_reason">Reason for Leaving</label>
                        <div class="invalid-feedback" id="del_reason_err">Choose a reason.</div>
                    </div>
                    <div class="form-floating">
                        <textarea class="form-control" id="del_note" style="height:88px"></textarea>
                        <label for="del_note">Anything Else (Optional)</label>
                    </div>
                </div>
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
                <button type="button" class="btn btn-link" id="email_resend_btn">Resend Code</button>
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
                <button type="button" class="btn btn-secondary" id="backup_copy_btn">Copy Codes</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I've saved them</button>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {

    var params = new URLSearchParams(window.location.search);
    if (params.get('connected') === '1') { toastr.success('Account connected'); }
    if (params.get('google_connected') === '1') { toastr.success('Google connected'); }
    if (params.get('google_link_error')) {
        var gerr = { taken: 'That Google account is already connected to another account.', session: 'Sign in again, then connect Google.', unavailable: 'Google sign-in isn\u2019t available right now.' };
        toastr.error(gerr[params.get('google_link_error')] || 'Google could not be connected');
    }
    $('#google_disconnect').on('click', function () {
        Swal.fire({ titleText: 'Disconnect Google?', text: 'You will sign in with your email and password instead.', input: 'password', inputPlaceholder: 'Current Password',
            inputAttributes: { autocomplete: 'current-password' }, showCancelButton: true, reverseButtons: true, confirmButtonText: 'Disconnect', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' })
        .then(function (r) {
            if (!r.isConfirmed) { return; }
            ApiDataSvc.apiCall('post', 'google_disconnect', { current_password: r.value || '' }, function (data) {
                var o = JSON.parse(data);
                if (!o.success) { toastr.error(o.message); return; }
                toastr.success(o.message);
                window.location = '/account/settings?section=security';
            });
        });
    });
    if (params.get('error') === '1') { toastr.error('Connection was not completed.' + (params.get('why') ? ' ' + params.get('why') : ''), '', { timeOut: 0, closeButton: true }); }

    function activateSection(section) {
        $('#settings_nav .settings__nav-item').removeClass('is-active');
        $('#settings_nav .settings__nav-item[data-section="' + section + '"]').addClass('is-active');
        $('.settings__section').removeClass('is-active');
        $('.settings__section[data-section="' + section + '"]').addClass('is-active');
        $('.settings__content').scrollTop(0);
        window.scrollTo(0, 0);
    }

    var deepLink = (params.get('section') || window.location.hash.slice(1) || '').replace(/[^a-z_]/gi, '');   // ?section=x, or #x from older links
    if (deepLink && $('#settings_nav .settings__nav-item[data-section="' + deepLink + '"]').length) {
        activateSection(deepLink);
    }

    // Consume one-time query params (deep-link section, Stripe/social returns) on
    // this load, then strip them so a refresh doesn't re-fire toasts or re-force a tab.
    if (window.location.search || window.location.hash) {
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

    function escapeHtml(s) {
        return $('<div>').text(s == null ? '' : s).html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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

    /* ---- verification (PRD §33): the ID check opens on Didit and comes back to /account/identity_verification ---- */
    $('#verify_request_btn').on('click', function () {
        var $b = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'verification_request', {}, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) {}
            if (o && o.success && o.url) { window.location.href = o.url; return; }
            $b.prop('disabled', false);
            toastr.error((o && o.message) || 'Could not open the ID check');
        });
    });
    (function () {   // back from the ID check
        var st = new URLSearchParams(window.location.search).get('identity_verification');
        if (st === 'approved') { toastr.success('You\'re verified'); }
        else if (st === 'rejected') { toastr.error('We couldn\'t verify your ID'); }
        else if (st === 'pending') { toastr.info('Your ID check is still being processed'); }
    })();

    /* ---- profile details ---- */
    $('#save_profile').on('click', function () {
        if ($('#first_name').val() == '') { toastr.error('First name is required'); return; }
        if ($('#last_name').val() == '') { toastr.error('Last name is required'); return; }
        if ($('#user_email').val() == '') { toastr.error('Email is required'); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test($('#user_email').val())) { toastr.error('A valid email is required'); return; }

        var payload = {
            first_name:    $('#first_name').val(),
            last_name:     $('#last_name').val(),
            user_email:    $('#user_email').val(),
            user_phone:    $('#user_phone').val(),
            business_name: $('#business_name').val(),
            website_url:   $('#website_url').val()
        };
        function send() {
            ApiDataSvc.apiCall('post', 'update_profile', payload, function (data) {
                var o = null; try { o = JSON.parse(data); } catch (e) {}
                if (o && o.success) { $('#user_email').prop('defaultValue', payload.user_email); toastr.success(o.message); } else { toastr.error((o && o.message) || 'Could not save'); }
            });
        }
        // Changing the sign-in email asks for the current password first.
        if (payload.user_email.toLowerCase() !== String($('#user_email').prop('defaultValue')).toLowerCase() && window.Swal) {
            Swal.fire({ title: 'Confirm your password', text: 'Enter your current password to change your email.', input: 'password',
                inputAttributes: { autocomplete: 'current-password' }, showCancelButton: true, reverseButtons: true,
                confirmButtonText: 'Save Email', confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779',
                inputValidator: function (v) { return v ? undefined : 'Enter your password'; } })
                .then(function (r) { if (r.isConfirmed) { payload.current_password = r.value; send(); } });
            return;
        }
        send();
    });

    /* ---- Creator Directory: saved by the profile's Save Changes; turning on checks the photos (a few seconds) ---- */
    var dir_saved = { on: $('#dir_listed').is(':checked'), cat: $('#dir_category').val() || '' };
    function dir_save(done) {
        var on = $('#dir_listed').is(':checked'), cat = $('#dir_category').val() || '';
        ApiDataSvc.apiCall('post', 'save_directory_listing', { listed: on ? 1 : 0, category: cat }, function (data) {
            var o = null; try { o = JSON.parse(data); } catch (e) {}
            if (!o || !o.success) { done(false, (o && o.message) || 'Could not save your directory listing'); return; }
            dir_saved = { on: on, cat: cat };
            var listed = on && (o.listed === true || /listed in the Creator Directory/.test(o.message));
            var why = o.missing ? 'Not showing: ' + o.missing.charAt(0).toLowerCase() + o.missing.slice(1) : 'Not showing yet';
            $('#dir_status').prop('hidden', !on).text(listed ? 'Listed' : why).attr('class', 'dir-status dir-status--' + (!on ? 'off' : (listed ? 'listed' : 'waiting')));
            done(true, o.message);
        });
    }

    /* ---- Download Your Data: request, then poll until the export is ready ---- */
    var dx_timer = null;
    function dx_paint(x) {
        var $s = $('#dx_status'), $r = $('#dx_request'), $d = $('#dx_download');
        clearTimeout(dx_timer);
        $r.prop('hidden', false).prop('disabled', false).html('<i class="fa-solid fa-file-zipper"></i> Request Export');
        $d.prop('hidden', true);
        if (!x) { $s.prop('hidden', true); return; }
        $s.prop('hidden', false).removeClass('is-failed');
        if (x.status === 'queued' || x.status === 'running') {
            $s.html('<span class="spinner-border spinner-border-sm" role="status"></span> Preparing your export, requested ' + $('<i>').text(x.requested).html() + '.');
            $r.prop('disabled', true).html('<i class="fa-solid fa-file-zipper"></i> Preparing…');
            dx_timer = setTimeout(dx_poll, 8000);
        } else if (x.status === 'ready') {
            $s.text('Ready' + (x.size ? ', ' + x.size : '') + '. Available until ' + x.expires + '.');
            $r.prop('hidden', true);
            $d.prop('hidden', false).data('id', x.id);
        } else if (x.status === 'failed') {
            $s.addClass('is-failed').text('Your last export couldn\u2019t be finished. Try again.');
            $r.html('<i class="fa-solid fa-rotate-right"></i> Try Again');
        } else {
            $s.text('Your last export has expired.');
        }
    }
    function dx_poll() {
        ApiDataSvc.apiCall('post', 'data_export_status', {}, function (data) { var o = null; try { o = JSON.parse(data); } catch (e) {} if (o && o.success) { var was = $('#dx_card').data('state'); dx_paint(o.export); if (o.export && o.export.status === 'ready' && was !== 'ready') { toastr.success('Your data export is ready'); } $('#dx_card').data('state', o.export ? o.export.status : ''); } });
    }
    (function () { var x = null; try { x = JSON.parse($('#dx_card').attr('data-export') || 'null'); } catch (e) {} $('#dx_card').data('state', x ? x.status : ''); dx_paint(x); })();
    $('#dx_request').on('click', function () {
        var $b = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'data_export_request', {}, function (data) { var o = null; try { o = JSON.parse(data); } catch (e) {}
            if (o && o.success) { toastr.success(o.message); $('#dx_card').data('state', o.export.status); dx_paint(o.export); }
            else { $b.prop('disabled', false); toastr.error((o && o.message) || 'Could not start your export'); }
        });
    });
    $('#dx_download').on('click', function () {
        var $b = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'data_export_download', { id: $(this).data('id') }, function (data) { var o = null; try { o = JSON.parse(data); } catch (e) {}
            $b.prop('disabled', false);
            if (o && o.success && o.url) { window.location = o.url; } else { toastr.error((o && o.message) || 'Could not download your export'); dx_poll(); }
        });
    });

    $('#delete_account').on('click', function () {
        new bootstrap.Modal(document.getElementById('delete_account_form')).show();
    });

    $('#del_reason, #lc_reason').on('change', function () { $(this).toggleClass('is-invalid', !this.value); });
    $('#confirm_delete_account').on('click', function () {
        if (!$('#del_reason').val()) { $('#del_reason').addClass('is-invalid').trigger('focus'); return; }   // a reason is required
        bootstrap.Modal.getInstance(document.getElementById('delete_account_form')).hide();
        ApiDataSvc.apiCall('post', 'delete_my_account', { leave_reason: $('#del_reason').val() || '', leave_note: $('#del_note').val() || '' }, function (data) {
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

    $('#become_creator_btn').on('click', function () {
        if ($('#creator_agree').length && !$('#creator_agree').is(':checked')) {   // the backstop box for a creator who never accepted; becoming a creator asks nothing
            toastr.error('Please accept the Creator Agreement and Content Policy');
            return;
        }
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'become_creator', { accept_agreement: $('#creator_agree').is(':checked') ? 1 : 0 }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = o.accepted_only ? window.location.href : '/account/billing?tab=plan&welcome=1'; }, 800);   // choose a plan next (Free needs no card); an existing creator stays here
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
    /* Choose From Gallery: list the creator's library images, then hand the chosen one to the same cropper an upload uses. */
    var gal_kind = null, gal_next = null;
    function gal_state(html) { $('#gallery_state').html(html).prop('hidden', html === ''); }
    $('.gal-pick').on('click', function () {
        gal_kind = $(this).data('kind'); gal_next = null;
        $('#gallery_grid').empty(); gal_state('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Loading your images');
        $('#gallery_modal').modal('show');
        ApiDataSvc.apiCall('post', 'media_list', { type: 'image' }, function (data) {
            var o = null; try { o = JSON.parse(data); } catch (e) {}
            if (!o || !o.success) { gal_state('Your images could not be loaded. Close this and try again.'); return; }
            var ready = (o.assets || []).filter(function (a) { return a.status === 'ready' && a.thumb_url; });
            if (!ready.length) { gal_state('No images in your gallery yet. Upload one, or make one under Influencers.'); return; }
            gal_state('');
            ready.forEach(function (a) {
                $('<button type="button" class="gal-tile"></button>').attr('aria-label', a.name || 'Image').data('id', a.id)
                    .append($('<img alt="" loading="lazy">').attr('src', a.thumb_url)).appendTo('#gallery_grid');
            });
        });
    });
    $('#gallery_grid').on('click', '.gal-tile', function () {
        var $t = $(this), kind = gal_kind;
        if ($t.hasClass('is-busy')) { return; }
        $('#gallery_grid .gal-tile').prop('disabled', true); $t.addClass('is-busy');
        ApiDataSvc.apiCall('post', 'media_crop_source', { id: $t.data('id') }, function (data) {
            var o = null; try { o = JSON.parse(data); } catch (e) {}
            $('#gallery_grid .gal-tile').prop('disabled', false); $t.removeClass('is-busy');
            if (!o || !o.success) { toastr.error((o && o.message) || 'That image could not be opened'); return; }
            fetch(o.data_url).then(function (r) { return r.blob(); }).then(function (blob) {
                gal_next = { kind: kind, blob: blob };
                $('#gallery_modal').modal('hide');   // the cropper opens once this one has closed (two dialogs never overlap)
            });
        });
    });
    $('#gallery_modal').on('hidden.bs.modal', function () {
        if (!gal_next) { return; }
        var n = gal_next; gal_next = null;
        openCropper(n.kind, n.blob, $('#' + n.kind + '_preview'));
    });

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
        if ($('#dir_listed').is(':checked') && $('#dir_category').val() == '') {
            toastr.error('Choose a category to be listed in the Creator Directory');
            $('#dir_category').focus();
            return;
        }
        var btn = $(this).prop('disabled', true);
        var dir_changed = $('#dir_listed').is(':checked') !== dir_saved.on || ($('#dir_category').val() || '') !== dir_saved.cat;
        ApiDataSvc.apiCall('post', 'save_creator_profile', {
            display_name: $('#cp_display_name').val(),
            bio:          $('#cp_bio').val(),
            location:     $('#cp_location').val(),
            similar_on:   $('#similar_on').length ? ($('#similar_on').is(':checked') ? 1 : 0) : ''
        }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { btn.prop('disabled', false); toastr.error(o.message); return; }
            if (!dir_changed) { btn.prop('disabled', false); toastr.success(o.message); return; }
            btn.html('Saving...');
            dir_save(function (ok, message) {
                btn.prop('disabled', false).html('Save Changes');
                if (ok) { toastr.success(message); } else { toastr.error(message); }
            });
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
        $('#link_modal_title').text('Add Link');
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

    /* ---- import your page (Linktree, Beacons, Stan, or pasted links) ---- */
    var imp_page = null;

    function imp_show(pane) {
        $('#imp_pane_url').prop('hidden', pane !== 'url');
        $('#imp_pane_paste').prop('hidden', pane !== 'paste');
        $('#imp_pane_review').prop('hidden', pane !== 'review');
        $('#imp_read').prop('hidden', pane === 'review').text(pane === 'paste' ? 'Read Links' : 'Read Page');
        $('#imp_apply').prop('hidden', pane !== 'review');
        $('#imp_back').prop('hidden', pane !== 'review');
        $('#imp_cancel').prop('hidden', pane === 'review');
    }

    function imp_error(message) {
        $('#imp_error').prop('hidden', message == '').find('span').text(message);
    }

    function imp_links_count() {
        var total = $('#imp_links .imp-link:not(:disabled)').length;
        var on = $('#imp_links .imp-link:checked:not(:disabled)').length;
        $('#imp_links_count').text(total > 0 ? '(' + on + ' of ' + total + ')' : '');
        $('#imp_toggle_links').prop('hidden', total === 0).text(on > 0 ? 'Clear All' : 'Select All');
    }

    function imp_review(page, existing) {
        imp_page = page;
        var names = { linktree: 'Linktree', beacons: 'Beacons', stan: 'Stan', page: '', pasted: '' };
        $('#imp_from').text(page.source == 'pasted' ? 'From the links you pasted' : 'From ' + (names[page.source] ? names[page.source] + ': ' : '') + page.url);
        $('#imp_row_avatar').prop('hidden', page.avatar == '');
        $('#imp_avatar').css('background-image', page.avatar == '' ? 'none' : 'url("' + String(page.avatar).replace(/["\\]/g, '') + '")');
        $('#imp_row_name').prop('hidden', page.name == '');
        $('#imp_name').text(page.name);
        $('#imp_row_bio').prop('hidden', page.bio == '');
        $('#imp_bio').text(page.bio);
        $('#imp_take_avatar, #imp_take_name, #imp_take_bio').prop('checked', true);
        $('#imp_profile_group').prop('hidden', page.avatar == '' && page.name == '' && page.bio == '');

        var html = '';
        $.each(page.links, function (i, l) {
            var key = String(l.url).replace(/^https?:\/\/(www\.)?/i, '').replace(/\/+$/, '').toLowerCase();
            var have = existing.indexOf(key) !== -1;
            html += '<label class="imp-row' + (have ? ' is-disabled' : '') + '">'
                + '<input type="checkbox" class="form-check-input imp-link" data-i="' + i + '"' + (have ? ' disabled' : ' checked') + '>'
                + '<span class="imp-row__body"><span class="imp-row__title">' + escapeHtml(l.title) + '</span>'
                + '<span class="imp-row__sub">' + escapeHtml(l.url) + '</span></span>'
                + (have ? '<span class="imp-row__tag">Already Added</span>' : '')
                + '</label>';
        });
        $('#imp_links').html(html);
        $('#imp_links_group').prop('hidden', page.links.length === 0);
        imp_links_count();
        imp_show('review');
    }

    function imp_open() {
        imp_page = null;
        $('#imp_url').val('');
        $('#imp_pasted').val('');
        imp_error('');
        imp_show('url');
        $('#import_modal').modal('show');
    }

    $(document).on('click', '.imp-open', imp_open);
    $('#import_modal').on('shown.bs.modal', function () { if (!$('#imp_pane_url').prop('hidden')) { $('#imp_url').trigger('focus'); } });
    $('#imp_to_paste').on('click', function () { imp_error(''); imp_show('paste'); $('#imp_pasted').trigger('focus'); });
    $('#imp_to_url').on('click', function () { imp_show('url'); $('#imp_url').trigger('focus'); });
    $('#imp_back').on('click', function () { imp_show(imp_page && imp_page.source == 'pasted' ? 'paste' : 'url'); });
    $('#imp_url').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#imp_read').trigger('click'); } });
    $('#imp_links').on('change', '.imp-link', imp_links_count);
    $('#imp_toggle_links').on('click', function () {
        var on = $('#imp_links .imp-link:checked:not(:disabled)').length > 0;
        $('#imp_links .imp-link:not(:disabled)').prop('checked', !on);
        imp_links_count();
    });

    $('#imp_read').on('click', function () {
        var pasting = !$('#imp_pane_paste').prop('hidden');
        var body = {};
        if (pasting) {
            if ($('#imp_pasted').val().trim() == '') { toastr.error('Paste at least one link'); return; }
            body.pasted = $('#imp_pasted').val();
        } else {
            if ($('#imp_url').val().trim() == '') { toastr.error('Enter your page address'); return; }
            body.url = $('#imp_url').val().trim();
        }
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'import_page_read', body, function (data) {
            $btn.prop('disabled', false);
            var o = JSON.parse(data);
            if (o.success) { imp_review(o.page, o.existing || []); return; }
            if (pasting) { toastr.error(o.message); return; }
            // the page could not be read: say why and offer the paste box
            imp_error(o.message + ' Paste your links below instead.');
            imp_show('paste');
            $('#imp_pasted').trigger('focus');
        });
    });

    $('#imp_apply').on('click', function () {
        if (!imp_page) { return; }
        var links = [];
        $('#imp_links .imp-link:checked:not(:disabled)').each(function () { links.push(imp_page.links[$(this).data('i')]); });
        var body = {
            name: !$('#imp_row_name').prop('hidden') && $('#imp_take_name').prop('checked') ? imp_page.name : '',
            bio: !$('#imp_row_bio').prop('hidden') && $('#imp_take_bio').prop('checked') ? imp_page.bio : '',
            avatar: !$('#imp_row_avatar').prop('hidden') && $('#imp_take_avatar').prop('checked') ? imp_page.avatar : '',
            links_json: JSON.stringify(links)
        };
        if (body.name == '' && body.bio == '' && body.avatar == '' && links.length === 0) { toastr.error('Select something to import'); return; }
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'import_page_apply', body, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { $btn.prop('disabled', false); toastr.error(o.message); return; }
            if (o.photo_error) { toastr.warning(o.message); } else { toastr.success(o.message); }
            setTimeout(function () { window.location.href = '/account/settings?section=creator'; }, 900);
        });
    });

    if (params.get('import') === '1' && $('.imp-open').length) { imp_open(); }

    $('#leave_creator_btn').on('click', function () {
        $('#leave_creator_modal').modal('show');
    });

    $('#leave_creator_confirm').on('click', function () {
        if (!$('#lc_reason').val()) { $('#lc_reason').addClass('is-invalid').trigger('focus'); return; }   // a reason is required
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'leave_creator', { confirm: 1, leave_reason: $('#lc_reason').val() || '', leave_note: $('#lc_note').val() || '' }, function (data) {
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
    // Post for Me holds the connections: check it once the page is up, and reload when an account came or went there
    if ($('.conn-connect').length) {
        ApiDataSvc.apiCall('post', 'social_sync', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success && o.changed > 0) { window.location = '/account/settings?section=connected'; }
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
                + '<label for="bsAppPw">App Password</label>'
                + '<input id="bsAppPw" type="password" placeholder="xxxx-xxxx-xxxx-xxxx" maxlength="64" autocomplete="off">'
                + '<p style="margin:.5rem 0 0;font-size:.78rem;color:#9a97a8;">Create one in Bluesky under Settings &rarr; Privacy and security &rarr; App passwords. It is not your account password.</p>'
                + '</div>',
            focusConfirm: false, showCancelButton: true, reverseButtons: true,
            confirmButtonText: 'Connect', confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779',
            preConfirm: function () {
                var handle = (document.getElementById('bsHandle').value || '').trim().replace(/^@/, '');
                var pw = (document.getElementById('bsAppPw').value || '').trim();
                if (handle === '') { Swal.showValidationMessage('Enter your Bluesky handle'); return false; }
                if (pw === '') { Swal.showValidationMessage('Enter an app password'); return false; }
                return { platform: 'bluesky', handle: handle, app_password: pw };
            }
        }).then(function (r) { if (r.isConfirmed && r.value) { start_social_connect(r.value); } });
    });

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
    else if ($('#inboxTabs .is-active').data('tab') === 'messages') { setTimeout(function () { load_inbox_triggers(); }, 0); }   // plans without AI replies open on Welcome Messages

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
        ApiDataSvc.apiCall('post', 'auto_message_save', { trigger: t, text: $row.find('[data-role="text"]').val(), asset_ids: ids }, function (data) {
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
            disclose_ai:     $('#inboxDisclose').is(':checked') ? 1 : 0,
            ai_disclosure_text: $('#inboxAiDisclosure').val(),
            persona_influencer_id: $('#inboxPersonaInfluencer').val() || 0
        };
    }

    $('#inboxSave').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'inbox_settings_save', inbox_form(), function (data) {
            var o = JSON.parse(data);
            $btn.prop('disabled', false);
            if (o.success) { toastr.success(o.message); return; }
            if (window.cls_need_plan(o)) { return; }   // the upgrade prompt, back here after checkout
            toastr.error(o.message);
        });
    });

    $('#inboxTest').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        var f = inbox_form();
        var $out = $('#inboxTestOut').prop('hidden', false).removeClass('is-hold').text('Thinking…');
        ApiDataSvc.apiCall('post', 'inbox_test_draft', { sample_text: $('#inboxTestText').val(), persona: f.persona, avoid_topics: f.avoid_topics, disclose_ai: f.disclose_ai }, function (data) {
            var o = JSON.parse(data);
            $btn.prop('disabled', false);
            if (!o.success) { $out.prop('hidden', true); toastr.error(o.message); return; }
            if (o.hold) { $out.addClass('is-hold').text(o.message); } else { $out.text(o.text); }
        });
    });
    $('#inboxTestText').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#inboxTest').trigger('click'); } });

    // Connect sits on the right like every other row: the first click opens the key field, the next one connects.
    $('#eromifyConnect').on('click', function () {
        var $wrap = $('#eromifyKeyWrap');
        if ($wrap.prop('hidden') || $.trim($('#eromifyKey').val()) === '') { $wrap.prop('hidden', false); $('#eromifyCancel').prop('hidden', false); $('#eromifyKey').trigger('focus'); return; }
        var $btn = $(this).prop('disabled', true);
        ApiDataSvc.apiCall('post', 'eromify_connect', { api_key: $('#eromifyKey').val() }, function (data) {
            var o = JSON.parse(data);
            if (o.success) { toastr.success(o.message); setTimeout(function () { window.location.href = '/account/settings?section=connected'; }, 800); }
            else { toastr.error(o.message); $btn.prop('disabled', false); }
        });
    });
    $('#eromifyKey').on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); $('#eromifyConnect').trigger('click'); }
        else if (e.key === 'Escape') { e.preventDefault(); $('#eromifyCancel').trigger('click'); }
    });
    $('#eromifyCancel').on('click', function () {
        $('#eromifyKey').val(''); $('#eromifyKeyWrap').prop('hidden', true); $(this).prop('hidden', true);
        $('#eromifyConnect').prop('disabled', false).trigger('focus');
    });
    $('#eromifyDisconnect').on('click', function () {
        ApiDataSvc.apiCall('post', 'eromify_disconnect', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) { toastr.success(o.message); setTimeout(function () { window.location.href = '/account/settings?section=connected'; }, 800); } else { toastr.error(o.message); }
        });
    });

    // AI disclosure line per social account: saved when the field is left.
    $(document).on('change', '.conn-ai-line', function () {
        var $f = $(this);
        ApiDataSvc.apiCall('post', 'social_disclosure_save', { account_id: $f.data('account-id'), text: $f.val() }, function (data) {
            var o = null; try { o = JSON.parse(data); } catch (e) {}
            if (o && o.success) { toastr.success('Saved'); } else { toastr.error((o && o.message) || 'Could not save.'); }
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

    // Custom domain
    function domain_reload() {
        window.location.href = '/account/settings?section=domain';
    }
    function domain_call(action, payload, btn) {
        var label = btn.html();
        btn.prop('disabled', true);
        ApiDataSvc.apiCall('post', action, payload, function (data) {
            var o = JSON.parse(data);
            btn.prop('disabled', false).html(label);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(domain_reload, 800);
            } else {
                if (o.need_plan) { window.location.href = '/account/billing'; return; }
                toastr.error(o.message);
                if (action === 'domain_verify') { setTimeout(domain_reload, 1600); }
            }
        });
    }
    $('#domain_add').on('click', function () {
        if ($('#domain_host').val() == '') {
            toastr.error('Domain is required');
            return;
        }
        domain_call('domain_add', { hostname: $('#domain_host').val() }, $(this));
    });
    $('#domain_host').keyup(function (e) {
        if (e.key === 'Enter') { $('#domain_add').trigger('click'); }
    });
    $('#domain_verify').on('click', function () {
        $(this).html('Checking...');
        domain_call('domain_verify', {}, $(this));
    });
    $('[data-domain-primary]').on('click', function () {
        domain_call('domain_set_primary', { id: $(this).attr('data-domain-primary') }, $(this));
    });
    $('#domain_remove').on('click', function () {
        var btn = $(this);
        Swal.fire({ title: 'Remove your domain?', text: 'Your page will only be at its ' + <?php echo json_encode(Main::public_domain(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?> + ' address.',
            showCancelButton: true, reverseButtons: true, confirmButtonText: 'Remove Domain', confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' })
            .then(function (r) { if (r.isConfirmed) { domain_call('domain_remove', {}, btn); } });
    });
    $('.dns-records__copy').on('click', function () {
        var value = $(this).attr('data-copy');
        if (navigator.clipboard) { navigator.clipboard.writeText(value).then(function () { toastr.success('Copied'); }); }
    });

});
</script>
