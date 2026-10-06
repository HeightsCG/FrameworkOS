<link rel="stylesheet" href="/css/influencers.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/influencers.css'); ?>">
<?php
/* Shared shell for every /influencers page: header, destination nav, plan/feature gates.
   Expects $this->page, $this->ready (ready influencers), $this->can_ai, $this->needs_plan.
   Brand mode ($this->brand_mode, /influencers/images/0 and /videos/0) is the Generate pages with no influencer. */
$e      = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$page   = (string) ($this->page ?? 'index');
$ready  = (array) ($this->ready ?? array());
$brand_mode = !empty($this->brand_mode);
$cur    = isset($this->influencer['id']) ? (int) $this->influencer['id'] : 0;
$target = $cur > 0 && in_array($cur, array_map(function ($r) { return (int) $r['id']; }, $ready), true) ? $cur : (int) ($ready[0]['id'] ?? 0);
$gen_target = $brand_mode ? 0 : $target;   /* Generate Images / Videos stay in brand mode while it is chosen, and work with nothing trained */
$nav = array(
    array('key' => 'index',   'href' => '/influencers',                         'icon' => 'fa-user-group',           'label' => 'Your Influencers', 'needs' => false),
    array('key' => 'images',  'href' => '/influencers/images/' . $gen_target,   'icon' => 'fa-wand-magic-sparkles',  'label' => 'Generate Images',  'needs' => false),
    array('key' => 'videos',  'href' => '/influencers/videos/' . $gen_target,   'icon' => 'fa-clapperboard',         'label' => 'Generate Videos',  'needs' => false),
    array('key' => 'references', 'href' => '/influencers/references/' . $target, 'icon' => 'fa-id-badge',            'label' => 'References',       'needs' => true),
    array('key' => 'voice',   'href' => '/influencers/voice/' . $target,        'icon' => 'fa-microphone-lines',     'label' => 'Voice',            'needs' => true),
    array('key' => 'gallery', 'href' => '/influencers/gallery/' . $target,      'icon' => 'fa-images',               'label' => 'Gallery',          'needs' => true),
);
/* Replicate Photo, Carousel and Scenes are ways of generating images: they sit under Generate Images. */
$image_modes = array('images' => 'From Prompt', 'replicate' => 'Replicate Photo', 'carousel' => 'Carousel', 'scenes' => 'Scenes');
/* Likewise the ways of generating video sit under Generate Videos. */
$video_modes = array('videos' => 'Image To Video', 'motion' => 'Motion Control', 'talking' => 'Talking', 'replace' => 'Replace Character', 'scene' => 'Scene');
?>
<div class="inf" id="inf" data-page="<?php echo $e($page); ?>"<?php echo $brand_mode ? ' data-brand="1"' : ''; ?>>
<?php if (!empty($this->needs_plan)): ?>
    <div class="plan-bar" role="status">
        <p class="plan-bar__text"><strong>You're on Free.</strong> Your influencers are still here to view or delete. Choose a plan to create, publish and sell.</p>
        <a href="/account/billing" class="btn btn-primary plan-bar__btn">Choose a Plan</a>
    </div>
<?php endif; ?>
<?php $sub_modes = isset($image_modes[$page]) ? $image_modes : (isset($video_modes[$page]) ? $video_modes : array());
      if ($brand_mode) { $sub_modes = array($page => $sub_modes[$page] ?? ''); }   /* the other modes need an influencer in the picture */
      if (!empty($this->needs_plan) || (!$brand_mode && $target <= 0)) { $sub_modes = array(); } ?>
    <nav class="inf-nav<?php echo $sub_modes ? ' inf-nav--sub' : ''; ?>" aria-label="Influencer sections">
        <?php foreach ($nav as $n):
            $off = !empty($this->needs_plan) || ($n['needs'] && $target <= 0);
            $on  = ($page === $n['key']) || ($page === 'create' && $n['key'] === 'index') || ($n['key'] === 'images' && isset($image_modes[$page])) || ($n['key'] === 'videos' && isset($video_modes[$page])); ?>
            <?php if ($off): ?>
            <span class="inf-nav__item is-off" aria-disabled="true" title="<?php echo !empty($this->needs_plan) ? 'Choose a plan first' : 'Train an influencer first'; ?>"><i class="fa-solid <?php echo $e($n['icon']); ?>"></i> <?php echo $e($n['label']); ?></span>
            <?php else: ?>
            <a href="<?php echo $e($n['href']); ?>" class="inf-nav__item<?php echo $on ? ' is-on' : ''; ?>"><i class="fa-solid <?php echo $e($n['icon']); ?>"></i> <?php echo $e($n['label']); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php /* Which influencer the page is working with: a small switcher at the end of the tab row. Generate Images and
                 Generate Videos also offer No Influencer (brand mode). */
        $gen_page = in_array($page, array('images', 'videos'), true);
        $who = (is_array($this->influencer ?? null) && !in_array($page, array('index', 'create'), true) && empty($this->needs_plan)) ? $this->influencer : null;
        if (!$who && $brand_mode && $gen_page) { $who = array('id' => 0, 'name' => 'No Influencer', 'cover_url' => ''); }
        if ($who): ?>
        <span class="inf-nav__who">
            <span class="inf-nav__face"><?php if (!empty($who['cover_url'])): ?><img src="<?php echo $e($who['cover_url']); ?>" alt=""><?php elseif ($brand_mode): ?><i class="fa-regular fa-image" aria-hidden="true"></i><?php else: ?><i class="fa-regular fa-user" aria-hidden="true"></i><?php endif; ?></span>
            <label class="visually-hidden" for="inf_who">Influencer</label>
            <select class="inf-nav__name" id="inf_who">
                <?php foreach ($ready as $r): ?>
                <option value="<?php echo (int) $r['id']; ?>"<?php echo ((int) $r['id'] === (int) $who['id']) ? ' selected' : ''; ?>><?php echo $e($r['name']); ?></option>
                <?php endforeach; ?>
                <?php if ($gen_page): ?>
                <option value="0"<?php echo $brand_mode ? ' selected' : ''; ?>>No Influencer</option>
                <?php endif; ?>
            </select>
        </span>
        <?php endif; ?>
    </nav>
<?php if ($sub_modes): /* The ways to generate are pages of their own: one row under the section tabs. */ ?>
    <nav class="inf-sub" aria-label="How to generate">
        <?php foreach ($sub_modes as $mk => $ml): ?>
        <a class="inf-sub__item<?php echo $page === $mk ? ' is-on' : ''; ?>" href="/influencers/<?php echo $mk; ?>/<?php echo (int) $gen_target; ?>"<?php echo $page === $mk ? ' aria-current="page"' : ''; ?>><?php echo $e($ml); ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
