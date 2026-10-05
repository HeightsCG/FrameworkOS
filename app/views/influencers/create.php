<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; ?>

    <header class="inf-head">
        <div>
            <a href="/influencers" class="inf-back"><i class="fa-solid fa-arrow-left"></i> Your Influencers</a>
            <h1 class="inf-head__title" id="inf_wiz_title"><?php echo $infl ? $e($infl['name']) : 'New Influencer'; ?></h1>
        </div>
    </header>

    <section class="inf-wiz" id="inf_wizard">
        <ol class="inf-steps" id="inf_steps"></ol>
        <div class="inf-wiz__panel" id="inf_panel"></div>
    </section>

<?php require __DIR__ . '/_bottom.php'; ?>
