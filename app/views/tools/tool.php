<?php
/**
 * One free tool page (ToolsController::TOOLS): hero with the form, what you get, FAQ, and a Start Free band that
 * tags the signup with the tool (ref). The form posts to /api/tool_run; public/js/tools.js shows the inbox message.
 */
$e = function ($s) { return Sections::e($s); };
$site = Main::site_name();
$persona = $slug === 'ai-influencer-persona';
$start = '/?auth=register&role=creator&ref=' . rawurlencode($tool['source']);

?>
<link rel="stylesheet" href="/css/tools.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/tools.css'); ?>">
<?php
ob_start();
?>
<?php if (!empty($unavailable)): /* no question source (YouTube or Reddit key) on this server: no form, no lead taken */ ?>
<div class="tl-done" role="status">
    <span class="tl-done__ic"><?php echo Sections::icon('clock', 24); ?></span>
    <h2 class="ct-h">Briefly Unavailable</h2>
    <p class="sx-p">This tool is briefly unavailable.</p>
</div>
<?php else: ?>
<form class="tl-form" id="tl_form" data-source="<?php echo $e($tool['source']); ?>" novalidate>
    <div class="ct-field">
        <label class="ct-label" for="tl_niche">Niche</label>
        <input class="ct-input" id="tl_niche" name="niche" type="text" maxlength="190" placeholder="Be specific, e.g. vegan meal prep" required>
    </div>
<?php if ($persona): ?>
    <div class="ct-row">
        <div class="ct-field">
            <label class="ct-label" for="tl_vibe">Vibe Keywords</label>
            <input class="ct-input" id="tl_vibe" name="vibe" type="text" maxlength="190">
        </div>
        <div class="ct-field">
            <label class="ct-label" for="tl_audience">Audience</label>
            <input class="ct-input" id="tl_audience" name="audience" type="text" maxlength="190">
        </div>
    </div>
<?php endif; ?>
    <div class="ct-row">
        <div class="ct-field">
            <label class="ct-label" for="tl_name">First Name</label>
            <input class="ct-input" id="tl_name" name="first_name" type="text" maxlength="100" autocomplete="given-name" required>
        </div>
        <div class="ct-field">
            <label class="ct-label" for="tl_email">Email</label>
            <input class="ct-input" id="tl_email" name="email" type="email" maxlength="190" autocomplete="email" required>
        </div>
    </div>
    <label class="tl-consent" for="tl_consent">
        <input type="checkbox" id="tl_consent" name="consent" value="1" required>
        <span>I agree to receive emails from <?php echo $e($site); ?></span>
    </label>
    <div class="ct-hp" aria-hidden="true">
        <label for="tl_company">Company</label>
        <input id="tl_company" name="company" type="text" tabindex="-1" autocomplete="off">
    </div>
    <button class="sx-btn sx-btn--primary tl-submit" id="tl_send" type="submit" data-label="<?php echo $e($tool['button']); ?>"><?php echo $e($tool['button']); ?></button>
</form>
<div class="tl-done" id="tl_done" role="status" hidden>
    <span class="tl-done__ic"><?php echo Sections::icon('inbox', 24); ?></span>
    <h2 class="ct-h">Check Your Inbox</h2>
    <p class="sx-p" id="tl_done_text">Check your inbox in a few minutes.</p>
</div>
<?php endif; ?>
<?php
$form = ob_get_clean();

echo Sections::panel_hero(array('title' => $tool['h1'], 'lead' => $tool['lead'], 'panel' => $form));

if ($persona) {
    echo Sections::open('alt', 'What You Get', 'Five original characters you can build on, each one ready to brief an AI influencer.');
    echo Sections::cards(array(
        array('icon' => 'users', 'title' => 'Name And Handles', 'text' => 'A first name and three handle ideas to check on each platform.'),
        array('icon' => 'message', 'title' => 'Bio And Traits', 'text' => 'A short bio and four personality traits that keep captions consistent.'),
        array('icon' => 'list', 'title' => 'Content Pillars', 'text' => 'Four topics the persona posts about, so the feed has a clear focus.'),
        array('icon' => 'image', 'title' => 'Starter Image Prompt', 'text' => 'A description written for a realistic phone photo look, with no real person or celebrity likeness.'),
    ), 4);
    echo Sections::close();
} else {
    echo Sections::open('alt', 'How It Works', 'Real questions make better posts than guesses. This tool finds them for you.');
    echo Sections::cards(array(
        array('icon' => 'search', 'title' => 'Find The Communities', 'text' => 'We look up the biggest public communities and channels about your niche and leave adult ones out.'),
        array('icon' => 'message', 'title' => 'Read The Questions', 'text' => 'We read up to 150 recent posts that ask a question, from this week and the newest.'),
        array('icon' => 'sparkles', 'title' => 'Get Post Ideas', 'text' => 'You get 30 to 40 ideas grouped by theme, plus the communities worth watching, by email.'),
    ), 3);
    echo Sections::close();
}

echo Sections::faq($faq, 'white');
?>
<section class="sx sx--cta"><div class="ld-wrap sx__in sx-cta"><div><h2 class="sx-cta__title"><?php echo $e(Sections::tc($persona ? 'Bring your persona to life.' : 'Turn ideas into posts.')); ?></h2>
    <p class="sx-cta__text"><?php echo $e($persona ? 'Create an AI influencer on ' . $site . ' and generate realistic photos and videos for your posts.' : 'Run Ideas inside ' . $site . ' and turn any idea into a draft post in one click.'); ?></p></div>
    <div class="sx-acts"><a class="sx-btn sx-btn--light" href="<?php echo $e($start); ?>">Start Free</a></div></div></section>
<script defer src="/js/tools.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/tools.js'); ?>"></script>
