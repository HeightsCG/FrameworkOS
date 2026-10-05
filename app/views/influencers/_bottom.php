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
    'source'     => $this->source_asset ?? null,
    'set_id'     => (int) ($this->set_id ?? 0),
    'gender'     => (string) (($this->influencer['gender'] ?? '') ?: 'woman'),
), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="/js/ai-tools.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/ai-tools.js'); ?>"></script>
<?php if (in_array((string) ($this->page ?? ''), array('references', 'replicate', 'carousel'), true)): ?>
<?php if ((string) $this->page === 'carousel'): ?><script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script><?php endif; ?>
<script src="/js/influencers-ai.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/influencers-ai.js'); ?>"></script>
<?php endif; ?>
<?php if (in_array((string) ($this->page ?? ''), array('motion', 'replace', 'scene'), true)): ?>
<script src="/js/influencers-video.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/influencers-video.js'); ?>"></script>
<?php endif; ?>
<script src="/js/influencers.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/influencers.js'); ?>"></script>
