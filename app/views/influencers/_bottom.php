</div>
<script>
window.INF_CONFIG = <?php echo json_encode(array(
    'page'       => (string) ($this->page ?? 'index'),
    'creator_id' => (int) ($this->creator_id ?? 0),
    'influencer' => $this->influencer ?? null,
    'ready'      => (array) ($this->ready ?? array()),
    'names'      => (array) ($this->name_suggestions ?? array()),
    'retrain'    => !empty($this->retrain),
    'social'     => $this->social ?? array('accounts' => array(), 'can_post' => false),
    'config'     => (array) ($this->config ?? array()),
    'can_ai'     => !empty($this->can_ai),
    'limit'      => (array) ($this->limit ?? array()),
), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php if (!empty($this->needs_plan)): ?>
<div class="plan-lock">
    <div class="plan-lock__card">
        <div class="plan-lock__icon"><i class="fa-solid fa-lock"></i></div>
        <h2 class="plan-lock__title">Subscribe to a Plan</h2>
        <p class="plan-lock__text">Unlock AI influencers, the Content Studio, publishing, scheduling, and analytics.</p>
        <a href="/account/billing" class="plan-lock__btn">Choose a Plan</a>
    </div>
</div>
<?php else: ?>
<script src="/js/influencers.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/influencers.js'); ?>"></script>
<?php endif; ?>
