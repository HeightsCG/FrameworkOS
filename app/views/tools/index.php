<?php
/**
 * /tools: the free tools hub (2026-10-15). The home hero component, then one editorial row per tool: what it gives you, how it
 * works, its button, and an example of its output drawn beside it (invented, labelled "Example output"). Tools come from
 * ToolsController::TOOLS; the examples and button labels are below. Locals: $faq.
 */
$e = function ($s) { return Sections::e($s); };
$site = Main::site_name();
$trial = Sections::trial_link();
$chips = function (array $c) use ($e) { $o = ''; foreach ($c as $x) { $o .= '<span>' . $e($x) . '</span>'; } return $o; };
// Per tool: button label, how it works (one sentence), how results arrive, example output (trusted HTML built here).
$show = array(
    'fan-questions' => array('Find Questions', 'Enter your niche and your email. We read the recent questions people ask in that niche\'s biggest communities and send you 30 to 40 post ideas, grouped by theme.', 'By email',
        '<div class="sx-ui__bar"><b>Meal prep</b><span class="sx-ui__count">Theme 1 of 6</span></div><ul class="tl-ex__q"><li>How do you keep chicken from drying out by Thursday?</li><li>What is a cheap high-protein lunch that reheats well?</li><li>How long does prepped rice really last?</li></ul>'),
    'ai-influencer-persona' => array('Get Personas', 'Tell us your niche and the vibe you want. You get five original persona concepts by email, each with handles, a bio, content pillars and a starter image prompt.', 'By email',
        '<div class="sx-ui__bar"><b>Mara Vale</b><span class="sx-ui__count">@maravale.fit</span></div><p class="tl-ex__p">Early-morning trainer who keeps workouts short and recovery honest.</p><div class="sx-ui__chips">' . $chips(array('Morning routines', 'Home workouts', 'Recovery days')) . '</div>'),
    'ai-influencer-name-generator' => array('Generate Names', 'Pick a niche and a vibe. You get 20 names, each with a matching handle, and can shuffle for a new set as often as you like.', 'Instant, in your browser',
        '<div class="sx-ui__bar"><b>Fitness, warm</b><span class="sx-ui__count">20 names</span></div><div class="sx-ui__chips tl-ex__names">' . $chips(array('Juno Reyes', 'Nova Lane', 'Kai Mercer', 'Lila Moreau', 'Remy Cole', 'Ivy Calder')) . '</div>'),
    'ai-influencer-bio-generator' => array('Write Bios', 'Enter a name, a niche and a tone. You get a bio for Instagram, TikTok, X, Threads and Bluesky, each with its count against the limit.', 'Instant, in your browser',
        '<div class="sx-ui__bar"><b>Instagram</b><span class="sx-ui__count">118 / 150</span></div><p class="tl-ex__p">Morning workouts and honest recovery tips. New sets every week. AI-generated character.</p><div class="tl-ex__meter"><i style="width:79%"></i></div>'),
    'ai-disclosure-caption-checker' => array('Check a Caption', 'Paste a caption and pick the platform. The checker looks for a clear AI disclosure, checks it sits where people will see it, and suggests wording when it falls short.', 'Instant, in your browser',
        '<div class="sx-ui__bar"><b>TikTok caption</b><span class="sx-ui__on">Clear</span></div><ul class="tl-ex__checks"><li>' . Sections::icon('check', 16) . 'Says plainly that the content is AI</li><li>' . Sections::icon('check', 16) . 'Disclosure sits in the first 125 characters</li><li>' . Sections::icon('check', 16) . 'TikTok\'s own AI label noted</li></ul>'),
);

echo Sections::premium_hero(array(
    'title'     => 'Powerful Creator Tools.',
    'highlight' => 'Completely Free.',
    'lead'      => 'Explore AI-powered tools to develop your creator identity, discover content ideas, and prepare your next project.',
    'points'    => array(array('sparkles', 'Five Free Tools'), array('shield', 'No Account, No Card'), array('send', 'Results on the Page or by Email')),
    'buttons'   => array(array('Browse the Tools', '#tools', 'primary'), array($trial[0], $trial[1], 'secondary', $trial[2])),
    'photo'     => 'toolsport',
));
?>
<link rel="stylesheet" href="/css/tools.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/tools.css'); ?>">
<?php
echo Sections::open('white', 'Pick a Tool', 'Each one does one job well. Use the results anywhere.', 'tools');
echo '<div class="tl-hub">';
$i = 0;
foreach (ToolsController::TOOLS as $slug => $t) {
    if (!isset($show[$slug])) { continue; }
    $s = $show[$slug]; $rev = ($i % 2 === 1); $i++;
    $copy = '<div class="sx-split__copy"><h3 class="sx-h2 tl-hub__name">' . $e($t['nav_title']) . '</h3><p class="sx-lead">' . $e($t['description']) . '</p>'
        . '<p class="sx-p tl-hub__how">' . $e($s[1]) . '</p><p class="tl-hub__meta">' . Sections::icon(($s[2] === 'By email') ? 'send' : 'zap', 16) . $e($s[2]) . '</p>'
        . '<a class="sx-btn sx-btn--secondary sx-btn--lg" href="/tools/' . $e($slug) . '" data-track="tool_hub_click" data-track-tool="' . $e($slug) . '">' . $e($s[0]) . Sections::icon('arrow-right', 18) . '</a></div>';
    $ex = '<figure class="sx-ui tl-ex" aria-label="' . $e('Example output of the ' . $t['nav_title']) . '">' . $s[3] . '<figcaption>Example output</figcaption></figure>';
    echo '<div class="sx-split tl-hub__row' . ($rev ? ' sx-split--rev' : '') . '">' . ($rev ? $ex . $copy : $copy . $ex) . '</div>';
}
echo '</div>';
echo Sections::close();

echo Sections::faq($faq, 'alt');
echo Sections::trial_cta('Turn your ideas into a creator business.', 'Create the character, publish to ten platforms and sell from one page.', 'tools');
?>
<script>if (window.CLSTrack) { window.CLSTrack('tools_hub_view', { page: location.pathname }); }</script>
