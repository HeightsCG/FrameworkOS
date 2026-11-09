<?php
/**
 * Feature pages under /features/<slug>, rendered by PagesController::featuresAction() through
 * app/views/pages/feature.php. Adding a page here puts it on the site, in the sitemap, in
 * llms.txt and in the article linker.
 */
class FeaturePages {

    /** Footer grouping, in display order: PAGES slugs under "Product"; PAGES slugs then every ROOT page under "AI". */
    const FOOTER_PRODUCT = array('link-in-bio', 'publishing', 'automations', 'analytics', 'custom-domains');
    const FOOTER_AI      = array('ai-influencer', 'dm-agent');


    /**
     * How each feature page looks (site-wide redesign 2026-10-15): its hero photo (SitePhotos key), the hero layout, and the visual
     * placed in one of its rows: 'ui:<kind>' (Sections::ui schematic), 'trio:<a>,<b>,<c>' (one character in three scenes) or
     * 'gallery:<keys>' (a training set). Every page has its own photo; the AI pages share their one AI-generated character on purpose.
     */
    const LOOK = array(
        'ai-influencer'            => array('photo' => 'aichar', 'variant' => 'split', 'visual' => 'trio:aichar-a,aichar-b,aichar-c', 'row' => 1, 'caption' => 'One AI character, three scenes. Illustrative images made with AI.'),
        'dm-agent'                 => array('photo' => 'dmagent', 'variant' => 'split', 'visual' => 'ui:inbox', 'row' => 0),
        'link-in-bio'              => array('photo' => 'linkinbio', 'variant' => 'split', 'visual' => 'ui:linkpage', 'row' => 0),
        'publishing'               => array('photo' => 'publishing', 'variant' => 'full', 'visual' => 'ui:targets', 'row' => 0),
        'automations'              => array('photo' => 'automations', 'variant' => 'split', 'visual' => 'ui:automation', 'row' => 0),
        'analytics'                => array('photo' => 'analytics', 'variant' => 'split', 'visual' => 'ui:analytics', 'row' => 0),
        'custom-domains'           => array('photo' => 'domains', 'variant' => 'split', 'visual' => 'ui:domain', 'row' => 0),
        'lora-character-training'  => array('photo' => 'train-1', 'variant' => 'split', 'visual' => 'gallery:train-1,train-2,train-3,train-4,train-5,aichar', 'row' => 0, 'caption' => 'A training set: one AI character from six angles. Illustrative images made with AI.'),
        'consistent-ai-model-face' => array('photo' => 'aichar-c', 'variant' => 'split', 'visual' => 'trio:aichar,aichar-a,aichar-b', 'row' => 0, 'caption' => 'The same face in three scenes. Illustrative images made with AI.'),
    );

    /** The visual for one page as trusted HTML, or ''. */
    public static function visual(string $slug): string {
        return self::visual_from(self::LOOK[$slug] ?? array());
    }

    /** The visual for one LOOK entry (also NichePages::LOOK). */
    public static function visual_from(array $l): string {
        $v = (string) ($l['visual'] ?? '');
        if ($v === '') { return ''; }
        list($kind, $arg) = array_pad(explode(':', $v, 2), 2, '');
        if ($kind === 'ui') { return Sections::ui($arg); }
        $imgs = ''; foreach (explode(',', $arg) as $k) { $imgs .= Sections::photo(trim($k), $kind === 'gallery' ? '(max-width: 560px) 50vw, (max-width: 1100px) 33vw, 200px' : '(max-width: 760px) 33vw, 240px'); }
        if ($imgs === '') { return ''; }
        return '<figure class="sx-' . ($kind === 'gallery' ? 'gallery' : 'trio') . '">' . $imgs . (!empty($l['caption']) ? '<figcaption>' . Sections::e($l['caption']) . '</figcaption>' : '') . '</figure>';
    }

    const PAGES = array(

        'ai-influencer' => array(   // was /features/character-generation (301 in PagesController::featuresAction)
            'nav_title'   => 'AI influencer',
            'title'       => 'AI Influencer Generator: Train, Create and Post',
            'description' => 'Create an AI influencer: train one character from photos or a description, generate photos and video of the same person, and post them anywhere from one studio.',
            'hero' => array(
                'title' => 'An AI Influencer Generator That Also Posts',
                'lead'  => 'An AI influencer is a trained character you can generate photos and video of, and post from one studio. On {site} you create one once, from your photos or a description. After that, every image and clip is the same person, ready for your page, your social accounts and your automations. AI influencers included: {influencer_plans}.',
            ),
            'video'   => '',
            'related' => array('lora-character-training', 'consistent-ai-model-face', 'publishing'),
            'rows' => array(
                array(
                    'title'  => 'Train once, from photos or a description',
                    'text'   => 'Upload 10 to 50 photos of the same person, or describe a face and let the studio build the reference set for you. Training runs once and takes a few minutes.',
                    'points' => array(
                        'Photos path: your own images, different angles and lighting',
                        'Reference path: describe the face, approve the reference, the studio builds the training set',
                        'Choose whether the character is a woman or a man, so every prompt describes the right person',
                    ),
                ),
                array(
                    'title'  => 'Generate photos that stay on model',
                    'text'   => 'Describe a scene and get images of your character in it. Pin a seed to repeat a look exactly, or change one word to get a new take.',
                    'points' => array(
                        'Prebuilt scene prompts, or let the studio write one for you',
                        'Square, portrait and landscape, up to four images a run',
                        'Upscale any image, and keep everything in your media library',
                    ),
                ),
                array(
                    'title'  => 'Turn any still into video',
                    'text'   => 'Pick a generated image, describe the movement, and get a short clip built from that exact frame.',
                    'points' => array(
                        'Motion prompts written for you when you want them',
                        'Clips land in your library beside your photos',
                    ),
                ),
                array(
                    'title'  => 'Post it, schedule it, or automate it',
                    'text'   => 'Every generation lands in your library, ready for a post on your page, a cross-post to your social accounts, or an automation that runs on a schedule.',
                    'points' => array(
                        'Publish to your page and to nine platforms at once',
                        'Schedule each post for the time your audience is active',
                        'Automations can generate and publish on a schedule',
                    ),
                    'link' => array('See publishing', '/features/publishing'),
                ),
            ),
            'fan_side' => 'Your audience never sees the training or the prompts. They see photos and short videos of one consistent person on your page and your social accounts. A page that has published AI media carries a line saying some content is AI-generated.',   // what the fan sees
            'limits'   => array('influencers', 'ai_credits', 'storage_gb'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Do I need my own photos?', 'a' => 'No. You can describe a face instead and the studio generates a reference image, then builds the training set from it. Using your own photos gives you a character that looks like a specific person.'),
                array('q' => 'How long does training take?', 'a' => 'A few minutes, once per character. After that, every image is the same person and no retraining is needed.'),
                array('q' => 'Can I make video as well?', 'a' => 'Yes. Any generated still can be turned into a short clip with a motion prompt.'),
                array('q' => 'What does it cost?', 'a' => 'Generation uses the AI credits included with your plan, and you can top up at any time. Training is included with your plan.'),
            ),
            'cta' => array('title' => 'Build your AI influencer today', 'text' => 'Train once, then generate and post from one studio.'),
        ),

        'dm-agent' => array(
            'nav_title'   => 'DM agent',
            'title'       => 'AI DM Agent for Your Inbox',
            'description' => 'Answer messages in your voice, around the clock. Drafts wait for approval until you let the agent send on its own.',
            'hero' => array(
                'title' => 'Your inbox, answered in your voice',
                'lead'  => 'The DM agent is an inbox assistant that drafts replies in your voice; you approve each one, or switch it to send on its own. It also sends welcome messages with media from your library.',
            ),
            'related' => array('automations', 'publishing', 'ai-influencer'),
            'rows' => array(
                array(
                    'title'  => 'It writes the way you do',
                    'text'   => 'Set the voice, the topics to avoid and how much detail a reply should carry. The agent uses your brand voice and what it knows about the fan.',
                    'points' => array(
                        'Your voice, your rules on what never to discuss',
                        'Quiet hours, so nothing goes out in the middle of the night',
                        'A cap on replies in a row before it waits for you',
                    ),
                ),
                array(
                    'title'  => 'Approve, or let it send',
                    'text'   => 'Start with every draft waiting in a queue for one click. When you trust it, switch it to sending on its own.',
                    'points' => array(
                        'Queue with the fan message and the draft side by side',
                        'Edit before sending, or dismiss the draft',
                        'Switch to automatic per creator, not all or nothing',
                    ),
                ),
                array(
                    'title'  => 'Welcome and trigger messages',
                    'text'   => 'New follower or first message: each can get its own message automatically, with media from your library attached.',
                    'points' => array(
                        'Attach photos and video from your media library',
                        'Scheduled broadcasts to your followers or a tagged segment',
                    ),
                    'link' => array('See automations', '/features/automations'),
                ),
            ),
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Set the Voice', 'Write how you talk, the topics the agent must never touch, your quiet hours and how many replies it may send in a row.'),
                array('Work the Queue', 'Each draft waits beside the fan\'s message. Approve it, edit it or dismiss it, and switch to automatic sending only when the drafts stop needing edits.'),
                array('Add Welcome Messages', 'Give new followers and first messages their own message, with library media when you want it.'),
            ),
            'fan_side' => 'A follower writes to you from the inbox on your page. The first automated reply to that person carries a line saying replies may be automated.',   // what the fan sees
            'limits'   => array('inbox_ai', 'ai_credits'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Does it send without me?', 'a' => 'Only if you turn that on. By default every reply waits in a queue for your approval.'),
                array('q' => 'Will fans know it is AI?', 'a' => 'Yes. The first automated reply to each fan carries a line saying replies may be automated, and the agent never denies being AI. If a fan asks whether a real person is replying, a draft that does not confirm it is AI is held for you. You decide what the agent can talk about, and you can take over any conversation at any time.'),
                array('q' => 'Can it send media in a message?', 'a' => 'Yes. Welcome and trigger messages can carry photos and video from your media library.'),
                array('q' => 'What stops it going off the rails?', 'a' => 'Topics to avoid, quiet hours, a maximum number of replies in a row, and the approval queue until you switch it off.'),
            ),
            'cta' => array('title' => 'Let your inbox answer itself', 'text' => 'Drafts in your voice, approval until you trust it, welcome messages on autopilot.'),
        ),

        'link-in-bio' => array(
            'nav_title'   => 'Link in bio',
            'title'       => 'Link in Bio with Click Tracking',
            'description' => 'One link-in-bio page at your handle with tracked links, your posts and your brand, on your own domain if you want it.',
            'hero' => array(
                'title' => 'A Link in Bio That Shows What Works',
                'lead'  => 'A link-in-bio page is one public page at your handle that holds every link, with each click tracked. Your page at {host}/@handle holds your links and your posts, in your colors, and every click shows up in your dashboard.',
            ),
            'video'   => '',
            'related' => array('publishing', 'analytics', 'custom-domains'),
            'rows' => array(
                array(
                    'title'  => 'Links with click counts',
                    'text'   => 'Add the links you would put in any bio tool. Every click is counted, so you can see which ones work.',
                    'points' => array(
                        'Turn a link off without deleting it',
                        'Clicks show up in your dashboard next to views',
                    ),
                ),
                array(
                    'title'  => 'Your posts, on the same page',
                    'text'   => 'Your latest posts sit under your links, so a visitor sees what you make without leaving.',
                    'points' => array(
                        'Posts you publish from the studio appear on the page straight away',
                        'Visitors can follow you for free and message you',
                    ),
                    'link' => array('See publishing', '/features/publishing'),
                ),
                array(
                    'title'  => 'Your brand, your address',
                    'text'   => 'Your colors, bio and links carry across the page. On the {domain_plan} plan your page can run on your own domain.',
                    'link'   => array('See custom domains', '/features/custom-domains'),
                ),
                array(
                    'title'  => 'Found beyond your bio',
                    'text'   => 'People also discover you in the home feed and in search across creators and content, and follow you for free.',
                ),
            ),
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Set Up the Page', 'Pick your handle, then add your bio and your colors.'),
                array('Add Your Links', 'Add each link you would put in a bio tool. Turn one off at any time without deleting it.'),
                array('Put It in Every Bio', 'Use the one address on every social profile, so every visitor arrives at the same page and every click is counted.'),
            ),
            'fan_side' => 'A visitor lands on your page at your handle and sees your links first, with your posts under them. Following you is free.',   // what the fan sees
            'limits'   => array('custom_domain'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Is it a replacement for my current link-in-bio tool?', 'a' => 'Yes. It holds your links like any bio page, counts every click, and shows your posts under them.'),
                array('q' => 'Can I see which links get clicked?', 'a' => 'Yes. Every link counts its clicks, and the totals show in your dashboard.'),
                array('q' => 'Can I use my own domain?', 'a' => 'Yes, on the {domain_plan} plan. Point your domain at us and your page answers on it.'),
            ),
            'cta' => array('title' => 'Put one link in every bio', 'text' => 'Links, posts and click counts on one page.'),
        ),

        'publishing' => array(
            'nav_title'   => 'Publishing',
            'title'       => 'Post to Nine Platforms at Once',
            'description' => 'Publish to your page and nine platforms from one studio, with AI captions in your voice, drafts and scheduling.',
            'hero' => array(
                'title' => 'Publish Everywhere from One Studio',
                'lead'  => 'Publishing on {site} is one upload that goes to your page and your social accounts at the same time. The networks: {networks}. Captions are written in your voice and trimmed for each network.',
            ),
            'video'   => '',
            'related' => array('link-in-bio', 'ai-influencer', 'dm-agent'),
            'rows' => array(
                array(
                    'title'  => 'Nine platforms, one upload',
                    'text'   => 'Connect your accounts once. Each post goes to your page and to the networks you pick.',
                    'points' => array(
                        '{networks}',
                        'Choose per post whether it also appears on your page',
                    ),
                    'link'   => array('See All Nine Integrations', '/integrations'),
                ),
                array(
                    'title'  => 'AI captions in your voice',
                    'text'   => 'Set your brand voice once. Captions are drafted in it and trimmed to fit each network, and you can edit every one.',
                ),
                array(
                    'title'  => 'Drafts and scheduling',
                    'text'   => 'Keep a draft, schedule it for later, or publish now. Automations can write and publish posts on a schedule you set.',
                    'points' => array(
                        'Drafts save as you type',
                        'A schedule per post, shown in your own time zone',
                    ),
                ),
                array(
                    'title'  => 'See what each post did',
                    'text'   => 'Engagement from your social posts comes back into your dashboard, next to views on your page.',
                ),
            ),
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Connect Your Accounts', 'Connect each social account once. There is no limit on how many you connect.'),
                array('Write the Post Once', 'Add the media, draft the caption in your voice, and pick the networks and the audience.'),
                array('Publish, Schedule or Save', 'Send it now, set a date and time in your own time zone, or keep it as a draft.'),
            ),
            'fan_side' => 'On your page the post appears in your feed. On each social network it appears as a normal post from your own account, with no outside link added to it.',   // what the fan sees
            'limits'   => array('automations', 'ai_credits'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Which networks can I post to?', 'a' => '{networks}.'),
                array('q' => 'Do you add a link to my social posts?', 'a' => 'No. Posts go out as you wrote them, so the networks do not hold back reach for an outside link.'),
                array('q' => 'Can I schedule posts?', 'a' => 'Yes. Schedule any post for a date and time, keep it as a draft, or publish it now.'),
                array('q' => 'Who writes the captions?', 'a' => 'You do, or the studio drafts one in your brand voice and trims it for each network. You can edit every caption before it goes out.'),
            ),
            'cta' => array('title' => 'Post once, everywhere', 'text' => 'Your page and nine platforms from one studio.'),
        ),

        'custom-domains' => array(
            'nav_title'   => 'Custom domains',
            'title'       => 'Custom Domain for Your Creator Page',
            'description' => 'Run your creator page on your own domain. Add a couple of DNS records, get a certificate automatically, and keep visitors signed in.',
            'hero' => array(
                'title' => 'Your Page on Your Own Domain',
                'lead'  => 'A custom domain is your own web address pointing at your creator page. On the {domain_plan} plan your creator page can answer on a domain you own. Visitors see your address, follow and message you as usual, and stay signed in when they arrive from our site.',
            ),
            'video'   => '',
            'related' => array('link-in-bio', 'analytics', 'publishing'),
            'rows' => array(
                array(
                    'title'  => 'Connect in a few steps',
                    'text'   => 'Add your domain in settings and copy the DNS records it shows you into your domain provider. We check them and switch the domain on.',
                    'points' => array(
                        'A bare domain and its www version are added together',
                        'A secure certificate is issued automatically',
                        'Records are checked again every day',
                    ),
                ),
                array(
                    'title'  => 'Instant handoff for signed-in visitors',
                    'text'   => 'A visitor who is signed in on our site is handed straight to your domain, so they follow and message you without signing in again.',
                ),
                array(
                    'title'  => 'Your profile and your posts',
                    'text'   => 'Your page, your links and your posts all answer on your domain.',
                    'link'   => array('See link in bio', '/features/link-in-bio'),
                ),
            ),
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Add the Domain', 'Enter the domain in your settings. The bare domain and its www version are added together.'),
                array('Copy the Records', 'Copy the DNS records shown there into your domain provider, exactly as written.'),
                array('Wait for the Check', 'We check the records and switch the domain on. The secure certificate is issued automatically.'),
            ),
            'fan_side' => 'A visitor who types your domain sees your page, your links and your posts on your own address. A visitor already signed in on our site arrives signed in.',   // what the fan sees
            'limits'   => array('custom_domain'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Which plan includes custom domains?', 'a' => 'The {domain_plan} plan.'),
                array('q' => 'What do I need to change at my domain provider?', 'a' => 'A verification record plus the routing records we show you (two for a bare domain and its www version), all shown in settings with the exact values to copy.'),
                array('q' => 'Do I need my own certificate?', 'a' => 'No. A certificate is issued automatically once your records point at us.'),
                array('q' => 'Will visitors have to sign in again?', 'a' => 'No. A signed-in visitor is handed to your domain with their session, so they can follow and message you straight away.'),
            ),
            'cta' => array('title' => 'Put your page on your domain', 'text' => 'Your address, your page, your brand.'),
        ),

        'automations' => array(   // 2026-10-15. Every line traces to cron/scheduler.php, SchedulerRulesModel, AutoPostService, MessageBlastService and InboxAutomationService.
            'nav_title'   => 'Automations',
            'title'       => 'Content Automations for Creators: Post and Message on Schedule',
            'description' => 'Set an automation once and it creates, captions and publishes a post every day or on the days you pick, or sends scheduled messages to the followers you choose.',
            'hero' => array(
                'title' => 'Your Content on Autopilot',
                'lead'  => 'An automation is a rule that runs on a schedule. A post automation makes an image or a short video, writes the caption and publishes it to your page and your social accounts. A message automation sends a message to the followers you pick. You set it once and it runs at your local time.',
            ),
            'video'   => '',
            'related' => array('publishing', 'ai-influencer', 'analytics'),
            'rows' => array(
                array(
                    'title'  => 'Posts that make themselves',
                    'text'   => 'Each run takes one idea from your list, creates the image from your brand or your AI influencer, writes a caption and publishes it.',
                    'points' => array(
                        'Photo, or a short video animated from the photo',
                        'Captions written by AI in your brand voice, or your own text',
                        'Published to your page and your social accounts in one run',
                    ),
                ),
                array(
                    'title'  => 'On your schedule',
                    'text'   => 'Run every day, or only on the weekdays you choose, at the time you set in your own timezone.',
                    'points' => array(
                        'Pause and restart any automation',
                        'Each post also goes to the social accounts you tick',
                    ),
                ),
                array(
                    'title'  => 'Scheduled messages',
                    'text'   => 'A message automation sends to your followers or a tagged segment of your audience, with your text or a message written by AI in your voice.',
                    'link'   => array('See the AI inbox', '/features/dm-agent'),
                ),
            ),
            'steps' => array(
                array('Create the Automation', 'Open the Studio, add an automation and choose a post or a message.'),
                array('Pick What It Makes', 'For a post: the image source, photo or video, the caption and who can see it. For a message: who receives it and the text.'),
                array('Set the Schedule', 'Choose every day or the weekdays you want and the time. It runs on its own from then on.'),
            ),
            'fan_side' => 'Your audience sees ordinary posts on your page and on your social accounts, published at a steady rhythm, and messages in their inbox. AI-made media carries the AI labels each platform asks for.',
            'limits'   => array('automations', 'ai_credits'),
            'faq' => array(
                array('q' => 'How often can an automation run?', 'a' => 'Once a day, either every day or on the weekdays you choose, at the time you set in your timezone.'),
                array('q' => 'Can an automation post video?', 'a' => 'Yes. Choose video and each run animates its image into a short clip before publishing.'),
                array('q' => 'Does it post to my social accounts too?', 'a' => 'Yes. Tick the accounts when you set it up and each run cross-posts there as well as to your page.'),
                array('q' => 'Which plans include automations?', 'a' => 'Automations are part of the paid plans; the number you can run depends on the plan. Free accounts can build their page and drafts.'),
            ),
            'cta' => array('title' => 'Set it once, keep posting', 'text' => 'Automations create, caption and publish on your schedule.'),
        ),

        'analytics' => array(   // 2026-10-15. Every line traces to DashboardController, app/views/dashboard/index.php, cron/social_metrics.php and GoController.
            'nav_title'   => 'Analytics',
            'title'       => 'Creator Analytics: Content and Audience in One Dashboard',
            'description' => 'See followers, views and engagement over 7, 30 or 90 days, with engagement from your social accounts, link clicks and tracking links in one dashboard.',
            'hero' => array(
                'title' => 'Know What Works',
                'lead'  => 'The dashboard shows how your page and your posts are doing over the last 7, 30 or 90 days, each compared with the period before. Followers, views, posts and engagement sit at the top, with a tab each for content and audience.',
            ),
            'video'   => '',
            'related' => array('publishing', 'automations', 'link-in-bio'),
            'rows' => array(
                array(
                    'title'  => 'Every post and where it went',
                    'text'   => 'One table lists every post with its views and engagement, and where it was shared. Engagement on the copies you cross-posted comes back from your social accounts.',
                    'points' => array(
                        'Delivery status for each platform',
                        'Social engagement refreshed every few hours',
                    ),
                ),
                array(
                    'title'  => 'Where your audience comes from',
                    'text'   => 'Follower growth, the sources and pages that send visitors, link clicks and tracking links that show which links bring signups.',
                ),
                array(
                    'title'  => 'When your audience is active',
                    'text'   => 'A heatmap shows the hours your audience is active, next to the content that gets the most engagement.',
                ),
            ),
            'steps' => array(
                array('Publish as Usual', 'Post to your page and your social accounts. Every view and like is counted.'),
                array('Open the Dashboard', 'Pick 7, 30 or 90 days and see each number against the period before.'),
                array('Follow the Tabs', 'Content and audience each have their own tab.'),
            ),
            'fan_side' => 'Your audience sees nothing of it. The dashboard is private to your account.',
            'faq' => array(
                array('q' => 'Which numbers are at the top of the dashboard?', 'a' => 'Followers, views, posts and engagement, for the period you pick, each compared with the period before.'),
                array('q' => 'Does it include my social accounts?', 'a' => 'Yes. Engagement on the posts you cross-posted through the studio comes back from the connected accounts and refreshes every few hours.'),
                array('q' => 'Can I track where signups come from?', 'a' => 'Yes. Create tracking links and the dashboard shows the clicks and signups each one brings.'),
            ),
            'cta' => array('title' => 'See what is working', 'text' => 'Content and audience in one dashboard.'),
        ),
    );

    /**
     * Keyword pages at the site root (/lora-character-training ...). Same shape as PAGES and the same template;
     * routed by PagesController::ROUTES to rootFeatureAction().
     */
    const ROOT = array(

        'lora-character-training' => array(
            'nav_title'   => 'LoRA character training',
            'title'       => 'LoRA Character Training for AI Influencers',
            'description' => 'Train a LoRA character model from 10 to 50 photos or a described face, then generate on-model photos and video and post them from one studio.',
            'hero' => array(
                'title' => 'LoRA Character Training without the Setup',
                'lead'  => 'LoRA character training teaches a model one face from {min_photos} to {max_photos} photos, so every later image is the same person. {site} trains it for you from those photos, or from a face you describe. You never touch a training script or a GPU, and every image after that is the same person.',
            ),
            'video'   => '',
            'related' => array('ai-influencer', 'consistent-ai-model-face', 'publishing'),
            'rows' => array(
                array(
                    'title'  => 'Two ways to build the training set',
                    'text'   => 'Upload 10 to 50 photos of the same person, or describe a face, approve a reference image, and the studio generates the training set from it.',
                    'points' => array(
                        'Replace any image in the set before you train',
                        'Set whether the character is a woman or a man, so prompts describe the right person',
                    ),
                ),
                array(
                    'title'  => 'One run, a few minutes',
                    'text'   => 'Training runs once per character and takes a few minutes. Training is included with your plan.',
                    'points' => array(
                        'Retrain any time; the current model keeps working until the new one is ready',
                        'AI influencers included: {influencer_plans}',
                    ),
                ),
                array(
                    'title'  => 'Then generate and post',
                    'text'   => 'Generate photos and short videos of the character, and publish them to your page and your social accounts, now or on a schedule.',
                    'link'   => array('See the AI influencer generator', '/features/ai-influencer'),
                ),
            ),
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Build the Set', 'Upload {min_photos} to {max_photos} photos of one person, or describe a face and approve the reference image.'),
                array('Train Once', 'Start the run. It takes a few minutes and is included with your plan.'),
                array('Generate', 'Describe a scene and get images of the character in it. Pin a seed to repeat a look exactly.'),
            ),
            'fan_side' => 'Your audience never sees the training. They see photos and short videos of one consistent character on your page. A page that has published AI media carries a line saying some content is AI-generated, and AI media is labeled when it is cross-posted.',   // what the fan sees
            'limits'   => array('influencers', 'ai_credits'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'What is a LoRA?', 'a' => 'A small add-on to an image model, trained on one person, so the model can draw that person in any scene. {site} trains and stores it for you.'),
                array('q' => 'How many photos do I need?', 'a' => 'Between 10 and 50 photos of the same person, with different angles and lighting. If you have none, describe a face and the studio builds the set.'),
                array('q' => 'How long does training take?', 'a' => 'A few minutes, once per character. You do not retrain to make new images.'),
                array('q' => 'What does it cost?', 'a' => 'Training is included with your plan. Generating images and video uses the AI credits included with your plan, and you can top up at any time.'),
            ),
            'cta' => array('title' => 'Train your first character', 'text' => 'Photos or a description in, a consistent character out.'),
        ),

        'consistent-ai-model-face' => array(
            'nav_title'   => 'Consistent AI face',
            'title'       => 'Consistent AI Model Face in Every Image',
            'description' => 'Keep the same AI model face across every photo and video: a trained character, pinned seeds and video made from the exact frame you approved.',
            'hero' => array(
                'title' => 'The Same Face in Every Image',
                'lead'  => 'A consistent AI model face is one character that stays recognisable across every generated photo and video. An AI model only works if your audience sees the same person every time. {site} trains a character on one face, so photos and videos stay on model across scenes, outfits and lighting.',
            ),
            'video'   => '',
            'related' => array('lora-character-training', 'ai-influencer', 'publishing'),
            'rows' => array(
                array(
                    'title'  => 'A trained character, not a prompt',
                    'text'   => 'Each character has its own model trained on one face. Every generation uses it, so the face does not drift between images.',
                    'link'   => array('See LoRA character training', '/lora-character-training'),
                ),
                array(
                    'title'  => 'Repeat a look exactly',
                    'text'   => 'Pin a seed to get the same shot again, or change one word in the prompt for a new take on it.',
                    'points' => array(
                        'Square, portrait and landscape, up to four images a run',
                        'Upscale any image you keep',
                    ),
                ),
                array(
                    'title'  => 'Photos that look like photos',
                    'text'   => 'Prompts are tuned for natural, phone-camera images rather than a glossy render, so the character reads as a real person.',
                ),
                array(
                    'title'  => 'Video from the frame you approved',
                    'text'   => 'Pick a generated still and describe the movement. The clip starts from that exact frame, so the face matches your photos.',
                ),
            ),
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Train One Face', 'Train a character on one person, from photos or from a face you describe.'),
                array('Keep the Matching Takes', 'Generate a scene, keep the images that are right, and pin the seed of a look you want again.'),
                array('Make the Video', 'Pick an approved still and describe the movement. The clip starts from that exact frame.'),
            ),
            'fan_side' => 'Someone scrolling your page sees the same person in every post, across scenes, outfits and lighting. A page that has published AI media carries a line saying some content is AI-generated.',   // what the fan sees
            'limits'   => array('influencers', 'ai_credits'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Why do AI faces change between images?', 'a' => 'A prompt alone describes a type of person, not one person. Training a character on one face is what keeps it the same.'),
                array('q' => 'Does the face stay the same in video?', 'a' => 'Yes. Video is made from a still you generated and approved, so it starts from the same face.'),
                array('q' => 'Can I change outfits and scenes?', 'a' => 'Yes. Scenes, outfits and lighting change with the prompt, and the face stays the same.'),
                array('q' => 'What if a character is not right?', 'a' => 'Retrain it with a better set. The current model keeps working until the new one is ready.'),
            ),
            'cta' => array('title' => 'Keep your AI model on model', 'text' => 'Train one face, generate it anywhere.'),
        ),

    );
}
