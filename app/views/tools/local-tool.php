<?php
/**
 * A browser-only free tool (ToolsController::TOOLS, 'kind' => 'local'): the tool in the hero panel (public/js/free-tools.js
 * builds it; nothing is posted anywhere), how to use it, FAQ, related integrations, the other free tools, the embed
 * snippet and the trial band. Locals: $tool, $slug, $faq.
 */
$e = function ($s) { return Sections::e($s); };
$site = Main::site_name();
$base = SeoMeta::base();
$steps = array(
    'ai-influencer-name-generator'  => array(array('Pick a niche', 'Choose the topic your character posts about.'), array('Pick a vibe', 'Choose how the name should feel, from luxe to futuristic.'), array('Copy a favourite', 'Generate 20 names, shuffle for more, and copy the ones you like.')),
    'ai-influencer-bio-generator'   => array(array('Enter the basics', 'Type the character\'s name and niche, and pick a tone.'), array('Write the bios', 'Get one bio per platform, sized to its limit, with the count shown.'), array('Copy and paste', 'Copy each bio into the matching profile and adjust it in your own words.')),
    'ai-disclosure-caption-checker' => array(array('Paste a caption', 'Paste the caption you are about to post.'), array('Pick the platform', 'Choose where the post is going.'), array('Fix what it flags', 'Read what passed and what needs work, then copy the suggested wording if you want it.')),
);
$embed = '<div data-cls-tool="' . $slug . '"><a href="' . $base . '/tools/' . $slug . '">Free ' . $tool['nav_title'] . ' by ' . $site . '</a></div>' . "\n"
       . '<script async src="' . $base . '/js/embed.js"></script>';
?>
<link rel="stylesheet" href="/css/tools.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/tools.css'); ?>">
<?php
$panel = '<div class="ft-host" data-ft="' . $e($slug) . '"><noscript><p class="sx-p">This tool runs in your browser and needs JavaScript turned on.</p></noscript></div>';
// The next step, shown under the result once the tool has run (2026-10-15): what to do with it, tied to a real workflow.
$trial = Sections::trial_link();
$next_html = '';
$next = array(
    'ai-influencer-name-generator'  => array('Your name is ready. Bring your character to life.', 'Create the character on ' . $site . ', generate photos and video of the same person, and publish them to ten platforms.', array('Write the Bio Next', '/tools/ai-influencer-bio-generator')),
    'ai-influencer-bio-generator'   => array('Your bio is ready. Build the page it links to.', 'Your ' . $site . ' page is where the link in your bio goes: fans follow, subscribe and buy there.', array('See Link in Bio', '/features/link-in-bio')),
    'ai-disclosure-caption-checker' => array('Your caption is ready. Publish it everywhere.', $site . ' posts it to ten platforms at once and sets TikTok\'s and YouTube\'s own AI label for you.', array('See Publishing', '/features/publishing')),
);
if (isset($next[$slug])) {
    $nx = $next[$slug];
    $next_html = '<div class="tl-next" id="tl_next" hidden><p class="tl-next__h">' . $e($nx[0]) . '</p><p class="tl-next__p">' . $e($nx[1]) . '</p><div class="tl-next__acts">'
        . '<a class="sx-btn sx-btn--primary" href="' . $e($trial[1]) . '" rel="nofollow"' . ($trial[2] !== '' ? ' data-auth="' . $e($trial[2]) . '"' : '') . ' data-track="tool_cta_click" data-track-tool="' . $e($slug) . '">' . $e($trial[0]) . Sections::icon('arrow-right', 18) . '</a>'
        . '<a class="sx-btn sx-btn--secondary" href="' . $e($nx[2][1]) . '" data-track="tool_cta_click" data-track-tool="' . $e($slug) . '">' . $e($nx[2][0]) . '</a></div></div>';
}
echo Sections::panel_hero(array('photo' => 'tools', 'variant' => 'panel', 'title' => $tool['h1'], 'lead' => $tool['lead'], 'panel' => $panel));
// Results get their own full-width section under the hero (2026-10-15): a long result list inside the hero panel stretched
// the hero and left a tall empty column beside it. The script below moves the tool's .ft-out here once the tool is built.
echo '<section class="sx tl-results" id="tl_results" aria-label="Results" hidden><div class="ld-wrap sx__in"><h2 class="sx-h2 tl-results__h">Your Results</h2><div class="tl-results__out"></div>' . $next_html . '</div></section>';

echo Sections::open('alt', 'How to use it');
$st = $steps[$slug] ?? array(); $last = count($st) - 1;
echo '<ol class="fx-steps fx-steps--3">';
foreach ($st as $i => $s) { echo '<li' . ($i === $last ? ' class="is-last"' : '') . '><h3>' . $e(($i + 1) . '. ' . $s[0]) . '</h3><p>' . $e($s[1]) . '</p></li>'; }
echo '</ol>';
echo '<p class="sx-note">Everything runs in your browser. Nothing you type is sent or stored.</p>';
echo Sections::close();

echo Sections::faq($faq, 'white');

// related: the integrations this tool goes with, then the other free tools
$links = array();
foreach ((array) ($tool['related'] ?? array()) as $i_slug) { if (isset(IntegrationPages::PAGES[$i_slug])) { $links[] = '<a class="hx__chip" href="/integrations/' . $e($i_slug) . '">' . $e(IntegrationPages::PAGES[$i_slug]['name']) . ' integration</a>'; } }
$cards = array();
foreach (ToolsController::TOOLS as $o_slug => $o) {
    if ($o_slug === $slug) { continue; }
    $cards[] = array('icon' => ($o['kind'] ?? '') === 'local' ? 'sparkles' : 'send', 'title' => $o['nav_title'], 'text' => $o['description'], 'link' => array('Open ' . $o['nav_title'], '/tools/' . $o_slug));
}
echo Sections::open('alt', 'More free tools');
if (!empty($links)) { echo '<p class="tl-related">' . implode('', $links) . '</p>'; }
echo Sections::cards($cards, 4);
echo Sections::close();

echo Sections::open('white', 'Embed this tool', 'Add the ' . $tool['nav_title'] . ' to your own site. Paste this where you want it to appear.', 'embed');
?>
<div class="tl-embed">
    <label class="ct-label" for="tl_embed_code">Embed Code</label>
    <textarea class="ct-input ct-input--area tl-embed__code" id="tl_embed_code" rows="3" readonly><?php echo $e($embed); ?></textarea>
    <button class="sx-btn sx-btn--secondary" type="button" data-copy-from="tl_embed_code">Copy Code</button>
</div>
<?php
echo Sections::close();

echo Sections::trial_cta('Build the character, then publish it.', 'Create an AI influencer on ' . $site . ', make photos and video, and publish to ten platforms from one page.');
?>
<script defer src="/js/free-tools.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/free-tools.js'); ?>"></script>
<script>
/* Tool events and the next step (2026-10-15): the tool's own form submit is the start; a result in .ft-out is the completion. Nothing the visitor typed is sent. */
(function () {
    var host = document.querySelector('.ft-host'), box = document.getElementById('tl_results'), slot = box ? box.querySelector('.tl-results__out') : null;
    var next = document.getElementById('tl_next'), slug = <?php echo json_encode($slug); ?>, started = false, done = false;
    if (!host) { return; }
    var track = function (n) { if (window.CLSTrack) { window.CLSTrack(n, { tool: slug }); } };
    var out_of = function () { return (slot && slot.querySelector('.ft-out')) || host.querySelector('.ft-out'); };
    // free-tools.js keeps its own reference to .ft-out, so moving the node keeps every later render landing here
    var relocate = function () { var o = host.querySelector('.ft-out'); if (o && slot) { slot.appendChild(o); } };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', relocate); } else { relocate(); }
    var show = function () {
        var out = out_of();
        if (!out || out.querySelector('.ft-empty') || out.textContent.trim() === '') { return; }
        if (!done) { done = true; track('tool_complete'); }
        if (next) { next.hidden = false; }
        if (box && box.hidden) {
            box.hidden = false;
            var top = box.getBoundingClientRect().top;
            if (top > window.innerHeight * 0.6) { box.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); }
        }
    };
    host.addEventListener('submit', function () { if (!started) { started = true; track('tool_start'); } setTimeout(show, 0); });
    host.addEventListener('click', function () { setTimeout(show, 0); });   // Shuffle re-renders without a submit
    if (box) {
        box.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.ft-copy') : null;
            if (b && window.CLSFreeTools) { window.CLSFreeTools.copy(b.getAttribute('data-copy') || '', b); track('tool_copy'); }
        });
    }
})();
</script>
