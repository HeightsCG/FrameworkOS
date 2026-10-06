<?php require __DIR__ . '/_top.php'; ?>

    <header class="inf-head">
        <div></div>
        <div class="inf-head__actions">
            <a href="/influencers/create" class="btn btn-primary" id="inf_new_btn" hidden><i class="fa-solid fa-plus"></i> New Influencer</a>
        </div>
    </header>

    <?php if (empty($this->config['enabled'])): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation"></i>
        Rendering isn't configured yet (no provider key), so training and generation are turned off.
    </div>
    <?php endif; ?>

    <div class="inf-state-loading" id="inf_loading">
        <span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading your influencers…
    </div>
    <div class="inf-state-error" id="inf_error" hidden>
        <i class="fa-solid fa-circle-exclamation"></i>
        <p>We couldn't load your influencers.</p>
        <button type="button" class="btn btn-outline-secondary" id="inf_retry">Try Again</button>
    </div>
    <div class="inf-empty" id="inf_empty" hidden>
        <span class="inf-empty__ic"><i class="fa-solid fa-user-astronaut"></i></span>
        <h2 class="inf-empty__title">Create Your First Influencer</h2>
        <p class="inf-empty__text">Train from your photos or start from a description. Once trained, it generates on demand, no retraining needed.</p>
        <a href="/influencers/create" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New Influencer</a>
    </div>
    <?php $lim = (array) ($this->limit ?? array()); if (empty($lim['included'])): ?>
    <div class="inf-empty inf-upgrade" id="inf_upgrade" hidden>
        <span class="inf-empty__ic"><i class="fa-solid fa-lock"></i></span>
        <h2 class="inf-empty__title">AI Influencers Start on <?php echo htmlspecialchars((string) $lim['upgrade_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="inf-empty__text">Train an influencer once, then generate images and video of them whenever you post. Your plan doesn't include AI influencers.</p>
        <a href="/account/billing" class="btn btn-primary">Upgrade to <?php echo htmlspecialchars((string) $lim['upgrade_name'], ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
    <?php endif; ?>
    <div class="inf-cards" id="inf_cards" hidden></div>

<?php require __DIR__ . '/_bottom.php'; ?>
