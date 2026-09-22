<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; ?>

    <header class="inf-head">
        <div>
            <a href="/influencers" class="inf-back"><i class="fa-solid fa-arrow-left"></i> Your Influencers</a>
            <h1 class="inf-head__title" id="inf_wiz_title"><?php echo $infl ? $e($infl['name']) : 'New Influencer'; ?></h1>
        </div>
        <div class="inf-head__actions" id="inf_wiz_actions"></div>
    </header>

    <?php if (!$infl): ?>
    <section class="inf-chooser" id="inf_chooser">
        <p class="inf-chooser__lead">How should she be created? You can switch later, before training.</p>
        <div class="inf-chooser__grid">
            <button type="button" class="inf-path" data-path="photos">
                <span class="inf-path__ic"><i class="fa-solid fa-images"></i></span>
                <span class="inf-path__title">Train from your photos</span>
                <span class="inf-path__for">Best fidelity, highest likeness, full customization.</span>
                <span class="inf-path__needs">Needs <?php echo (int) $this->config['limits']['min_photos']; ?> to <?php echo (int) $this->config['limits']['max_photos']; ?> photos</span>
            </button>
            <button type="button" class="inf-path" data-path="reference">
                <span class="inf-path__ic"><i class="fa-solid fa-pen-nib"></i></span>
                <span class="inf-path__title">Text or single image</span>
                <span class="inf-path__for">Fast start, budget friendly, good for testing.</span>
                <span class="inf-path__needs">Needs a description or one face photo</span>
            </button>
        </div>
    </section>
    <?php endif; ?>

    <section class="inf-wiz" id="inf_wizard" <?php echo $infl ? '' : 'hidden'; ?>>
        <ol class="inf-steps" id="inf_steps"></ol>
        <div class="inf-wiz__panel" id="inf_panel"></div>
    </section>

<?php require __DIR__ . '/_bottom.php'; ?>
