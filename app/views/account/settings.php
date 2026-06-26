<link rel="stylesheet" href="/css/account-settings.css">

<?php
    $platform_meta = array(
        'linkedin'  => array('LinkedIn',    'fa-linkedin'),
        'bluesky'   => array('Bluesky',     'fa-bluesky'),
        'x'         => array('X (Twitter)', 'fa-x-twitter'),
        'facebook'  => array('Facebook',    'fa-facebook'),
        'instagram' => array('Instagram',   'fa-instagram'),
        'threads'   => array('Threads',     'fa-threads'),
        'tiktok'    => array('TikTok',      'fa-tiktok'),
        'youtube'   => array('YouTube',     'fa-youtube'),
        'pinterest' => array('Pinterest',   'fa-pinterest'),
    );
    $connected = array();
    $connected_accounts = array();
    foreach ($this->accounts as $a) {
        if (($a['status'] ?? '') === 'connected') {
            $connected[$a['platform']][] = $a;
            $connected_accounts[] = $a;
        }
    }
?>

<div class="settings">
    <header class="settings__head">
        <h1 class="settings__title">Settings</h1>
        <p class="settings__sub">Connect your social accounts and publish from one place.</p>
    </header>

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
    <div class="settings__body">
        <nav class="settings__nav" id="settings_nav">
            <button type="button" class="settings__nav-item is-active" data-section="connected"><i class="fa-solid fa-share-nodes"></i><span>Connected Accounts</span></button>
            <button type="button" class="settings__nav-item" data-section="compose"><i class="fa-solid fa-pen-to-square"></i><span>Create Post</span></button>
        </nav>

        <div class="settings__content">

            <section class="settings__section is-active" data-section="connected">
                <div class="settings__section-head">
                    <h2 class="settings__section-title">Connected Accounts</h2>
                    <p class="settings__section-desc">Connect the platforms you want to publish to.</p>
                </div>
                <div class="conn-grid">
                    <?php foreach ($this->platforms as $p): ?>
                    <?php $meta = $platform_meta[$p]; $accts = $connected[$p] ?? array(); ?>
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
            </section>

            <section class="settings__section" data-section="compose">
                <div class="settings__section-head">
                    <h2 class="settings__section-title">Create Post</h2>
                    <p class="settings__section-desc">Write once, publish to your connected accounts.</p>
                </div>

                <?php if (empty($connected_accounts)): ?>
                    <p class="settings__empty">Connect an account first to start posting.</p>
                <?php else: ?>
                <div class="composer">
                    <label class="compose-label" for="post_caption">Caption</label>
                    <textarea id="post_caption" class="form-control" rows="4" placeholder="What's new?"></textarea>

                    <label class="compose-label" for="post_media">Media <span class="compose-optional">(optional)</span></label>
                    <input type="file" id="post_media" class="form-control" accept="image/*,video/*">
                    <input type="hidden" id="post_media_url" value="">
                    <div id="media_status" class="compose-note"></div>

                    <label class="compose-label">Publish to</label>
                    <div class="compose-accounts">
                        <?php foreach ($connected_accounts as $a): ?>
                        <?php $meta = $platform_meta[$a['platform']]; ?>
                        <label class="compose-acct">
                            <input type="checkbox" class="post-account" value="<?php echo htmlspecialchars($a['post_for_me_social_account_id'], ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fa-brands <?php echo $meta[1]; ?>"></i>
                            <span><?php echo $meta[0]; ?><?php echo ($a['username'] !== '' && $a['username'] !== null) ? ' &middot; @' . htmlspecialchars($a['username'], ENT_QUOTES, 'UTF-8') : ''; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <label class="compose-label">Schedule</label>
                    <div class="compose-schedule">
                        <label class="compose-radio"><input type="radio" name="schedule" value="now" checked> Now</label>
                        <label class="compose-radio"><input type="radio" name="schedule" value="later"> Later</label>
                        <input type="datetime-local" id="post_when" class="form-control" style="display:none;">
                    </div>

                    <div class="compose-actions">
                        <button type="button" class="btn btn-primary" id="publish_post">Publish</button>
                    </div>
                </div>
                <?php endif; ?>

                <div class="settings__section-head settings__section-head--spaced">
                    <h2 class="settings__section-title">Recent posts</h2>
                </div>
                <?php if (empty($this->recent_posts)): ?>
                    <p class="settings__empty">No posts yet.</p>
                <?php else: ?>
                <table class="posts">
                    <thead><tr><th>Caption</th><th>Platforms</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                        <?php foreach ($this->recent_posts as $post): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(mb_strimwidth((string) $post['caption'], 0, 48, '…'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int) $post['platform_count']; ?></td>
                            <td><span class="post-status post-status--<?php echo htmlspecialchars($post['status'], ENT_QUOTES, 'UTF-8'); ?>" data-post-id="<?php echo htmlspecialchars((string) $post['post_for_me_post_id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($post['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?php echo htmlspecialchars(date('M j, g:ia', strtotime($post['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </section>

        </div>
    </div>
    <?php endif; ?>
</div>

<script>
$(function () {

    var params = new URLSearchParams(window.location.search);
    if (params.get('connected') === '1') { toastr.success('Account connected'); }
    if (params.get('error') === '1') { toastr.error('Connection was not completed'); }
    if (params.get('section') === 'compose') {
        $('#settings_nav .settings__nav-item').removeClass('is-active');
        $('#settings_nav .settings__nav-item[data-section="compose"]').addClass('is-active');
        $('.settings__section').removeClass('is-active');
        $('.settings__section[data-section="compose"]').addClass('is-active');
    }

    $('#settings_nav').on('click', '.settings__nav-item', function () {
        var section = $(this).data('section');
        $('#settings_nav .settings__nav-item').removeClass('is-active');
        $(this).addClass('is-active');
        $('.settings__section').removeClass('is-active');
        $('.settings__section[data-section="' + section + '"]').addClass('is-active');
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

    $('input[name="schedule"]').on('change', function () {
        $('#post_when').toggle($(this).val() === 'later');
    });

    $('#post_media').on('change', function () {
        var file = this.files[0];
        if (!file) { return; }
        $('#media_status').text('Uploading...');
        ApiDataSvc.apiCall('post', 'upload_media_url', {}, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { $('#media_status').text(''); toastr.error(o.message); return; }
            fetch(o.upload_url, { method: 'PUT', headers: { 'Content-Type': file.type }, body: file })
                .then(function (r) {
                    if (r.ok) {
                        $('#post_media_url').val(o.media_url);
                        $('#media_status').text(file.name + ' ready');
                    } else {
                        $('#media_status').text('');
                        toastr.error('Upload failed');
                    }
                })
                .catch(function () { $('#media_status').text(''); toastr.error('Upload failed'); });
        });
    });

    $('#publish_post').on('click', function () {
        var ids = $('.post-account:checked').map(function () { return $(this).val(); }).get();
        if (ids.length === 0) { toastr.error('Select at least one account'); return; }
        var caption = $('#post_caption').val();
        var media_url = $('#post_media_url').val();
        if (caption.trim() === '' && media_url === '') { toastr.error('Add a caption or media'); return; }
        var schedule = $('input[name="schedule"]:checked').val();
        var when = $('#post_when').val();
        if (schedule === 'later' && when === '') { toastr.error('Choose a date and time'); return; }

        ApiDataSvc.apiCall('post', 'create_post', {
            caption: caption,
            social_account_ids: ids,
            media_url: media_url,
            schedule: schedule,
            scheduled_at: when
        }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                setTimeout(function () { window.location.href = '/account/settings?section=compose'; }, 1000);
            } else {
                toastr.error(o.message);
            }
        });
    });

    $('.post-status').each(function () {
        var $el = $(this);
        var st = ($el.text() || '').toLowerCase();
        if (st === 'processing' || st === 'scheduled') {
            var pid = $el.data('post-id');
            setTimeout(function () {
                ApiDataSvc.apiCall('post', 'post_status', { post_id: pid }, function (data) {
                    var o = JSON.parse(data);
                    if (o.success && o.status) {
                        $el.text(o.status.charAt(0).toUpperCase() + o.status.slice(1));
                    }
                });
            }, 4000);
        }
    });

});
</script>
