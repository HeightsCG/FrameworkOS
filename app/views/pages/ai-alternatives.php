<?php
/**
 * /alternatives: "AI influencer tools compared". The categories of tools, what each does and what to check, then where
 * the studio fits. Names no competitor: the per-platform pages (/compare/*, /<x>-alternatives) do that with sources.
 * Locals: $faq.
 */
$e = function ($s) { return Sections::e($s); };
$site = Main::site_name();
echo Sections::panel_hero(array(
    'title' => 'AI influencer tools compared',
    'lead'  => 'Running an AI influencer takes several kinds of tools: something to make the character, something to keep the face the same, something for video and voice, and somewhere to publish and get paid. Here is what each kind does and what to check before you pick one.',
));

$rows = array(
    array('Image generators', 'Turn a text prompt into a photo.', 'Whether the same character looks the same from one image to the next, and how realistic skin, hands and light look.'),
    array('Character training', 'Learn one face from a set of reference photos, so every new image shows the same person.', 'How many photos it needs, how long training takes, and whether the face holds in new poses and outfits.'),
    array('Video generators', 'Animate a still image or make a short clip from a prompt, including talking-head video.', 'Clip length, whether the face stays stable while moving, and whether lip sync matches the audio.'),
    array('Voice tools', 'Give the character a voice for videos and voice notes.', 'Whether the voice stays consistent and sounds natural reading your scripts.'),
    array('Schedulers and social tools', 'Publish posts to social networks on a schedule.', 'Which platforms it supports, whether it sets each platform\'s AI label, and whether it handles captions that are too long.'),
    array('Creator monetization platforms', 'Give fans a page to follow, subscribe and buy.', 'What you can sell, how fans pay, how you get paid out, and whether AI content is allowed and labelled.'),
);
$trows = array();
foreach ($rows as $r) { $trows[] = array('<b>' . $e($r[0]) . '</b>', $e($r[1]), $e($r[2])); }
echo Sections::open('white', 'The kinds of AI influencer tools', 'Most creators combine one tool from several of these rows.');
echo Sections::table(array('Kind of tool', 'What it does', 'What to check'), $trows);
echo Sections::close();

echo Sections::open('alt', 'Where ' . $site . ' fits', $site . ' puts these pieces in one account, so the character, the posts and the page fans pay on stay together.');
echo Sections::checks(array(
    'Create an AI influencer from a description, approve the face, then generate photos of them.',
    'Train the character on your reference photos so the face stays the same across images.',
    'Turn images into video, including talking video with a voice you design.',
    'Publish each post to LinkedIn, Bluesky, X, Facebook, Instagram, Threads, TikTok, YouTube, Pinterest and Fanvue.',
    'Label AI posts: TikTok and YouTube get their own AI setting, other platforms a disclosure line.',
    'Sell from one page: memberships, pay-per-view posts, bundles, services and events.',
), 2);
echo '<p class="sx-p"><a href="/features/ai-influencer">See the AI influencer tools</a></p>';
echo Sections::close();

echo Sections::open('white', 'Free tools to start with');
echo Sections::cards(array(
    array('icon' => 'users', 'title' => ToolsController::TOOLS['ai-influencer-name-generator']['nav_title'], 'text' => 'Twenty name ideas for your character, from a niche and a vibe.', 'link' => array('Find Names', '/tools/ai-influencer-name-generator')),
    array('icon' => 'sparkles', 'title' => ToolsController::TOOLS['ai-influencer-bio-generator']['nav_title'], 'text' => 'Bios sized for five platforms, with a character count for each.', 'link' => array('Write Bios', '/tools/ai-influencer-bio-generator')),
    array('icon' => 'shield', 'title' => ToolsController::TOOLS['ai-disclosure-caption-checker']['nav_title'], 'text' => 'Check that a caption says clearly when content is AI.', 'link' => array('Check a Caption', '/tools/ai-disclosure-caption-checker')),
), 3);
echo Sections::close();

echo Sections::faq($faq, 'alt');
echo Sections::trial_cta('Run your AI influencer from one place.', 'Create the character, make photos and video, publish to ten platforms and sell from your page.');
