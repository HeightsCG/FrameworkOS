<?php include __DIR__ . '/_shell.php'; ?>
<header class="adm-head">
    <h1 class="adm-head__title">Testimonials</h1>
    <span class="adm-head__sub">The home page row shows once <?php echo (int) Proof::TESTIMONIALS_MIN; ?> are approved</span>
    <div class="adm-head__acts"><button type="button" class="adm-btn adm-btn--primary" id="admTestimonialNew">+ New Testimonial</button></div>
</header>
<?php include __DIR__ . '/_testimonials.php'; ?>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script src="/js/admin-testimonials.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-testimonials.js'); ?>"></script>
