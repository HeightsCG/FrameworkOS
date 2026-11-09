<?php
/**
 * Niche landing pages under /for/<slug> (2026-10-24): one page per kind of creator, on the feature template
 * (app/views/pages/feature.php via PagesController::forNicheAction). Same shape as FeaturePages::PAGES plus
 * 'rows_title'; placeholders like {networks} are filled by PagesController::fill_feature_text. Every claim
 * traces to a shipped feature page (link-in-bio, publishing, automations, dm-agent, analytics, ai-influencer). Media is images and video only. Each page also works as an ad landing page.
 * Adding a page here puts it on the site, in the sitemap, in llms.txt, in the footer and in the article linker.
 */
class NichePages {

    /** Hero photo (SitePhotos key), hero layout and the row visual, as FeaturePages::LOOK. The photos are shared with other public pages. */
    const LOOK = array(
        'fitness-coaches'  => array('photo' => 'publishing', 'variant' => 'full', 'visual' => 'ui:targets', 'row' => 0),
        'musicians'        => array('photo' => 'alternatives', 'variant' => 'full', 'visual' => 'ui:linkpage', 'row' => 3),
        'coaches'          => array('photo' => 'dmagent', 'variant' => 'split', 'visual' => 'ui:inbox', 'row' => 2),
        'photographers'    => array('photo' => 'featport', 'variant' => 'split', 'visual' => 'ui:targets', 'row' => 2),
        'travel-creators'  => array('photo' => 'linkinbio', 'variant' => 'split', 'visual' => 'ui:linkpage', 'row' => 0),
        'brands'           => array('photo' => 'automations', 'variant' => 'split', 'visual' => 'ui:targets', 'row' => 3),
    );

    const PAGES = array(

        'fitness-coaches' => array(
            'nav_title'   => 'Fitness coaches',
            'title'       => 'Platform for Fitness Coaches: Clips, Scheduling, Inbox',
            'description' => 'Post your training clips to every network at once, schedule a week of workouts in one sitting, answer client DMs with AI drafts and keep every link on one page.',
            'hero' => array(
                'title' => 'Post Your Workouts Everywhere from One Studio',
                'lead'  => 'Fitness coaches on {site} post training clips to {networks} from one editor, schedule a week of content in one sitting, let AI draft replies to client questions and keep their booking and program links on one page.',
            ),
            'rows_title' => 'What can a fitness coach do here?',
            'steps_title' => 'How do you start as a fitness coach?',
            'related' => array('publishing', 'automations', 'dm-agent'),
            'rows' => array(
                array(
                    'title'  => 'Training clips on every network',
                    'text'   => 'Post a clip once and send it to {networks}. Schedule a week of posts in one sitting.',
                    'points' => array(
                        'Captions written in your voice and trimmed for each network',
                        'Instagram and Facebook Stories as well as feed posts',
                    ),
                    'link' => array('See publishing', '/features/publishing'),
                ),
                array(
                    'title'  => 'A daily tip on autopilot',
                    'text'   => 'Set an automation to post a new image or short clip with a caption on the days and at the time you pick.',
                    'points' => array(
                        'Runs in your own time zone',
                        'Pause and restart it whenever your schedule changes',
                    ),
                    'link' => array('See automations', '/features/automations'),
                ),
                array(
                    'title'  => 'Client questions answered in your voice',
                    'text'   => 'Turn on AI replies and the inbox drafts answers to client questions. Approve each one, or let it send once you trust it.',
                    'points' => array(
                        'A welcome message for every new follower',
                        'Followers in one audience list with tags and notes',
                    ),
                    'link' => array('See the DM agent', '/features/dm-agent'),
                ),
                array(
                    'title'  => 'Every link on one page',
                    'text'   => 'Your program, booking and gear links sit at the top of your page, and every click is counted.',
                    'link'   => array('See the link page', '/features/link-in-bio'),
                ),
            ),
            'steps' => array(
                array('Connect Your Accounts', 'Connect the networks you post to. There is no limit on connected accounts.'),
                array('Plan a Week of Workouts', 'Upload your clips, let AI draft the captions and schedule each post.'),
                array('Turn On AI Replies', 'Set your voice and let the inbox draft answers to client questions.'),
            ),
            'fan_side_title' => 'What do your clients see?',
            'fan_side' => 'A client sees your workouts as normal posts on each network, at a steady rhythm. Your bio link opens your page with your program and booking links at the top, and a message to you gets a quick reply.',
            'limits'   => array('automations', 'ai_credits', 'inbox_ai'),
            'faq' => array(
                array('q' => 'Can I post the same clip to several networks?', 'a' => 'Yes. Write the post once, tick the networks and it goes out to each of them, now or at the time you schedule.'),
                array('q' => 'Does the inbox send replies without me?', 'a' => 'Only if you turn that on. By default every reply waits for your approval.'),
                array('q' => 'Can I keep my booking app?', 'a' => 'Yes. Put its link on your page and every click is counted in your dashboard.'),
            ),
            'cta' => array('title' => 'Put your coaching content on autopilot', 'text' => 'Clips on every network, replies in your voice, links on one page.'),
        ),

        'musicians' => array(
            'nav_title'   => 'Musicians',
            'title'       => 'Platform for Musicians: Clips, Links, Scheduling',
            'description' => 'Post performance clips to every network at once, keep every streaming and merch link on one page with click counts, and see which posts bring new listeners.',
            'hero' => array(
                'title' => 'Your Clips Everywhere, Your Links in One Place',
                'lead'  => 'Musicians on {site} post performance clips and studio diaries to {networks} from one editor, keep their streaming, merch and tour links on one page, and see which posts and links bring new listeners.',
            ),
            'rows_title' => 'What can a musician do here?',
            'steps_title' => 'How do you start as a musician?',
            'related' => array('link-in-bio', 'publishing', 'analytics'),
            'rows' => array(
                array(
                    'title'  => 'Clips on every network',
                    'text'   => 'Post an acoustic take, a live cut or a rehearsal reel once and send it to {networks}.',
                    'points' => array(
                        'Schedule a release week of posts in one sitting',
                        'Captions written in your voice and trimmed for each network',
                    ),
                    'link' => array('See publishing', '/features/publishing'),
                ),
                array(
                    'title'  => 'Video from a single photo',
                    'text'   => 'Turn a cover shot or a press photo into a short clip, and join clips and images into one video in the clip editor.',
                ),
                array(
                    'title'  => 'Release-day posts on autopilot',
                    'text'   => 'Set an automation to post on the days and at the time you pick, so your feeds keep moving while you are on the road.',
                    'link'   => array('See automations', '/features/automations'),
                ),
                array(
                    'title'  => 'Every streaming link on one page',
                    'text'   => 'Your page holds the links you used to keep on a link-in-bio page: streaming, merch, tour dates. Bring them over from your current link page in one step.',
                    'points' => array(
                        'Tracking links show which post brings new followers',
                    ),
                    'link' => array('See the link page', '/features/link-in-bio'),
                ),
            ),
            'steps' => array(
                array('Bring Your Links', 'Import your current link page, or add your streaming and merch links by hand.'),
                array('Connect Your Accounts', 'Connect the networks you post to, once.'),
                array('Schedule a Release Week', 'Upload your clips, draft the captions and schedule each post.'),
            ),
            'fan_side_title' => 'What do your listeners see?',
            'fan_side' => 'A listener sees your clips as normal posts on each network. Your bio link opens your page with your streaming links at the top, and following you there is free.',
            'limits'   => array('automations', 'ai_credits', 'storage_gb'),
            'faq' => array(
                array('q' => 'Can I post audio files?', 'a' => 'Not as files. Posts carry images and video, so share a performance video or a lyric video, and keep streaming links for the audio.'),
                array('q' => 'Can I move my link page over?', 'a' => 'Yes. Paste the address of your current link page in Settings and pick the links, photo and bio to bring over.'),
                array('q' => 'Can I see which links get clicked?', 'a' => 'Yes. Every link counts its clicks, and tracking links show the followers and signups each one brings.'),
            ),
            'cta' => array('title' => 'Put your music in every feed', 'text' => 'Clips on every network, every link on one page.'),
        ),

        'coaches' => array(
            'nav_title'   => 'Coaches and teachers',
            'title'       => 'Platform for Coaches and Teachers: Content, Inbox, Links',
            'description' => 'Post lessons and tips to every network on a schedule, let AI draft replies to student questions in your voice, and keep your booking links on one page.',
            'hero' => array(
                'title' => 'Teach Everywhere, Answer Faster',
                'lead'  => 'Coaches and teachers on {site} post lessons and tips to {networks} on a schedule, let AI draft replies to student questions in their voice, and keep booking and course links on one page.',
            ),
            'rows_title' => 'What can a coach or teacher do here?',
            'steps_title' => 'How do you start as a coach or teacher?',
            'related' => array('dm-agent', 'publishing', 'automations'),
            'rows' => array(
                array(
                    'title'  => 'Lessons on every network',
                    'text'   => 'Post a lesson clip or a worksheet image once and send it to {networks}. Schedule a week of posts in one sitting.',
                    'link'   => array('See publishing', '/features/publishing'),
                ),
                array(
                    'title'  => 'Post ideas from real questions',
                    'text'   => 'Ideas gathers the questions people ask about your topic online and turns each one into a post idea, ready to open in the editor.',
                ),
                array(
                    'title'  => 'Replies drafted for you',
                    'text'   => 'Turn on AI replies and the inbox drafts answers to student questions in your voice. Set a welcome message for every new follower.',
                    'points' => array(
                        'Followers in one audience list with tags and notes',
                        'Scheduled broadcasts to a tagged group',
                    ),
                    'link' => array('See the DM agent', '/features/dm-agent'),
                ),
                array(
                    'title'  => 'Booking and course links on one page',
                    'text'   => 'Keep the links to your booking tool and your courses at the top of your page, and see which ones get clicked.',
                    'link'   => array('See the link page', '/features/link-in-bio'),
                ),
            ),
            'steps' => array(
                array('Connect Your Accounts', 'Connect the networks you post to, once.'),
                array('Plan Your Lessons', 'Turn questions into post ideas, draft the captions and schedule each post.'),
                array('Turn On AI Replies', 'Let the inbox draft answers to questions and greet new followers.'),
            ),
            'fan_side_title' => 'What do your students see?',
            'fan_side' => 'A student sees your lessons as normal posts on each network and gets a quick answer when they message you. Your bio link opens your page with your booking and course links at the top.',
            'limits'   => array('inbox_ai', 'ai_credits', 'automations'),
            'faq' => array(
                array('q' => 'Does the inbox send replies without me?', 'a' => 'Only if you turn that on. By default every reply waits for your approval.'),
                array('q' => 'Can I message a group of students at once?', 'a' => 'Yes. Tag followers in your audience list and send a broadcast to the tag, now or on a schedule.'),
                array('q' => 'Can I share files with students?', 'a' => 'Images and video, as posts or as messages with media from your library.'),
            ),
            'cta' => array('title' => 'Teach on every network', 'text' => 'Lessons on schedule, replies in your voice, links on one page.'),
        ),

        'photographers' => array(
            'nav_title'   => 'Photographers',
            'title'       => 'Platform for Photographers: Post, Schedule, Watermark',
            'description' => 'Post your shoots to every network at once with watermarks on your images, schedule a week of posts, and keep your portfolio and booking links on one page.',
            'hero' => array(
                'title' => 'Show Your Work on Every Network',
                'lead'  => 'Photographers on {site} post their shoots to {networks} from one editor, put a watermark on any image, schedule a week of posts in one sitting and keep portfolio and booking links on one page.',
            ),
            'plan_note'  => true,   // the Free-plan limit under the hero buttons (Sections::plan_note)
            'rows_title' => 'What can a photographer do here?',
            'steps_title' => 'How do you start as a photographer?',
            'related' => array('publishing', 'link-in-bio', 'analytics'),
            'rows' => array(
                array(
                    'title'  => 'A watermark on any image',
                    'text'   => 'Switch the watermark on per image in your library, so every copy that leaves your page carries your mark.',
                ),
                array(
                    'title'  => 'One shoot, a week of posts',
                    'text'   => 'Upload a shoot to your library and schedule the best frames across the week, each with a caption written in your voice.',
                    'points' => array(
                        'Square, portrait and landscape crops per network',
                        'Instagram and Facebook Stories as well as feed posts',
                    ),
                ),
                array(
                    'title'  => 'Previews on every network',
                    'text'   => 'Post once and send it to {networks}. Captions are trimmed to each network\'s limit.',
                    'link'   => array('See publishing', '/features/publishing'),
                ),
                array(
                    'title'  => 'Portfolio and booking links on one page',
                    'text'   => 'Your portfolio, booking and print-shop links sit at the top of your page, and every click is counted.',
                    'link'   => array('See the link page', '/features/link-in-bio'),
                ),
            ),
            'steps' => array(
                array('Upload a Shoot', 'Add the images to your library and switch on the watermark where you want it.'),
                array('Schedule the Week', 'Pick the frames, draft the captions and schedule each post.'),
                array('Add Your Links', 'Put your portfolio and booking links on your page.'),
            ),
            'fan_side_title' => 'What do your clients see?',
            'fan_side' => 'A client sees your work as normal posts on each network, with your watermark where you switched it on. Your bio link opens your page with your portfolio and booking links at the top.',
            'limits'   => array('storage_gb', 'automations', 'ai_credits'),
            'faq' => array(
                array('q' => 'Can I watermark my images?', 'a' => 'Yes. Switch the watermark on per image in your library. Videos carry a watermark overlay on the player.'),
                array('q' => 'Can I schedule posts ahead?', 'a' => 'Yes. Schedule any post for a date and time in your own time zone, keep it as a draft, or publish it now.'),
                array('q' => 'Can I see which posts do best?', 'a' => 'Yes. Views, likes, comments and shares come back from each network into your dashboard.'),
            ),
            'cta' => array('title' => 'Post your next shoot everywhere', 'text' => 'Watermarks, scheduling and links from one studio.'),
        ),

        'travel-creators' => array(
            'nav_title'   => 'Travel creators',
            'title'       => 'Platform for Travel Creators: Links, Scheduling, Analytics',
            'description' => 'One page for your booking and affiliate links with click counts, posts scheduled ahead to every network, and analytics that show which posts work.',
            'hero' => array(
                'title' => 'Your Links and Posts, Even on Travel Days',
                'lead'  => 'Travel creators on {site} keep their booking and affiliate links on one page with click counts, schedule posts ahead to {networks}, and see which posts and links bring new followers.',
            ),
            'rows_title' => 'What can a travel creator do here?',
            'steps_title' => 'How do you start as a travel creator?',
            'related' => array('link-in-bio', 'analytics', 'publishing'),
            'rows' => array(
                array(
                    'title'  => 'A link page that counts clicks',
                    'text'   => 'Your booking, gear and affiliate links sit at the top of your page. Bring them over from your current link page in one step.',
                    'points' => array(
                        'Link clicks in your dashboard',
                        'Tracking links show which post brings new followers',
                    ),
                    'link' => array('See analytics', '/features/analytics'),
                ),
                array(
                    'title'  => 'Post from the road',
                    'text'   => 'Schedule posts ahead and send them to {networks}, so your feeds keep moving on travel days.',
                    'link'   => array('See publishing', '/features/publishing'),
                ),
                array(
                    'title'  => 'Video from a single photo',
                    'text'   => 'Turn a still from the trip into a short clip, and join clips and images into one video in the clip editor.',
                ),
                array(
                    'title'  => 'Questions answered while you travel',
                    'text'   => 'Turn on AI replies and the inbox drafts answers to follower questions in your voice, waiting for your approval.',
                    'link'   => array('See the DM agent', '/features/dm-agent'),
                ),
            ),
            'steps' => array(
                array('Bring Your Links', 'Import your current link page, or add your booking and affiliate links by hand.'),
                array('Schedule Ahead', 'Upload the trip, draft the captions and schedule a week of posts.'),
                array('Check What Works', 'Open the dashboard and see which posts and links bring new followers.'),
            ),
            'fan_side_title' => 'What do your followers see?',
            'fan_side' => 'A follower taps your bio link and finds your booking and affiliate links at the top of your page. Your posts keep arriving on each network at a steady rhythm, even on travel days.',
            'limits'   => array('automations', 'ai_credits'),
            'faq' => array(
                array('q' => 'Can I keep my affiliate links?', 'a' => 'Yes. Any link goes on your page, and link clicks show in your dashboard.'),
                array('q' => 'Can I move my link page over?', 'a' => 'Yes. Paste the address of your current link page in Settings and pick the links, photo and bio to bring over.'),
                array('q' => 'Can I schedule posts in another time zone?', 'a' => 'Posts are scheduled in the time zone set on your account, so change it when you travel or plan in your home time.'),
            ),
            'cta' => array('title' => 'Put your travel links to work', 'text' => 'Links, scheduling and analytics on one page.'),
        ),

        // 2026-10-26: brands using the tools for content and social. Claims trace to ImageGenService (No Influencer
        // mode), BrandService (brand kit from a website), PostForMeService/SocialShareService, cron/scheduler.php, IdeasController,
        // cron/social_metrics.php, TrackingLinks, Permissions::team_allows and McpController. Left out on purpose: the inbox AI
        // (its prompt is written for creators' followers).
        'brands' => array(
            'nav_title'   => 'Brands',
            'title'       => 'Platform for Brands: AI Content, Scheduling, Analytics',
            'description' => 'Make on-brand product images and video with AI, write captions in your brand voice, schedule posts to every network and see what works.',
            'hero' => array(
                'title' => 'On-Brand Content and Posting from One Place',
                'lead'  => '{site} is a content and social publishing platform. Brands use it to make product images and video with AI, write captions in their own voice, post to {networks} on a schedule and see which posts work.',
            ),
            'rows_title'  => 'What can a brand do with {site}?',
            'steps_title' => 'How does a brand get started?',
            'related' => array('publishing', 'automations', 'analytics'),
            'rows' => array(
                array(
                    'title'  => 'Product and lifestyle images without a shoot',
                    'text'   => 'Describe the shot and get an image back: a product on a stone slab, a flat lay, a street scene. Pick the No Influencer option in Generate Images and choose a size for the feed, a Story or a banner.',
                    'points' => array(
                        'Seven sizes, from square to 16:9 and 3:1',
                        'Edit any image in your library by instruction, and the original is kept',
                        'Images follow your brand colours and keywords',
                    ),
                ),
                array(
                    'title'  => 'Video from your product photos',
                    'text'   => 'Upload a product photo, or use one you made here, and turn it into a short video clip. Join clips and images into one video in the clip editor.',
                    'points' => array(
                        'Several video models, from quick drafts to longer cinematic takes',
                        'The clip editor exports a 1080p MP4',
                        'Enhance a finished video before you post it',
                    ),
                ),
                array(
                    'title'  => 'A brand kit that every caption follows',
                    'text'   => 'Paste your website and the brand kit is drafted for you: name, tagline, description, voice and tone, colours and keywords. Review it once, and AI captions, AI images and automations use it from then on.',
                    'points' => array(
                        'Up to six brand colours and ten keywords',
                    ),
                ),
                array(
                    'title'  => 'Post once to every network',
                    'text'   => 'Write a post once and send it to {networks}. Publish now or schedule a week of posts in one sitting.',
                    'points' => array(
                        'Captions are trimmed to each network\'s limit',
                        'Instagram and Facebook Stories as well as feed posts',
                        'AI-made media gets the AI label on TikTok and YouTube automatically',
                    ),
                    'link' => array('See publishing', '/features/publishing'),
                ),
                array(
                    'title'  => 'A daily post on autopilot',
                    'text'   => 'Set an automation to run on the days and at the time you pick. Each run makes a new on-brand image or short video, writes the caption and posts it to the accounts you choose.',
                    'points' => array(
                        'A text hook can be set on the image or video',
                        'Runs in your own time zone',
                    ),
                    'link' => array('See automations', '/features/automations'),
                ),
                array(
                    'title'  => 'An AI brand ambassador, if you want one',
                    'text'   => 'Create an AI person for your brand and keep the same face across photos, carousels and talking videos. It is optional: everything above works without one.',
                    'points' => array(
                        'AI influencers per plan: {influencer_plans}',
                    ),
                    'link' => array('See AI influencers', '/features/ai-influencer'),
                ),
                array(
                    'title'  => 'See which posts work',
                    'text'   => 'Views, likes, comments, shares, saves and reach come back from each network for every post. Tracking links show the clicks and signups each campaign link brought in.',
                    'points' => array(
                        'Compare the last 7, 30 or 90 days with the period before',
                        'Export to CSV',
                    ),
                    'link' => array('See analytics', '/features/analytics'),
                ),
            ),
            'steps' => array(
                array('Draft Your Brand Kit', 'Paste your website, check the name, voice, colours and keywords it drafts, and save.'),
                array('Connect Your Accounts', 'Connect the networks you post to. There is no limit on connected accounts.'),
                array('Plan a Week of Posts', 'Make the images or videos, let AI write the captions, and schedule each post.'),
            ),
            'fan_side_title' => 'What do your followers see?',
            'fan_side' => 'Your followers see a normal post on each network, written in your voice and sized for that network. AI-made media carries the AI label where the network asks for one.',
            'limits'   => array('seats', 'ai_credits', 'automations', 'storage_gb'),
            'faq' => array(
                array('q' => 'Can I make product photos without a photographer?', 'a' => 'Yes. Describe the shot in Generate Images with the No Influencer option and you get an image in the size you pick, in your brand colours.'),
                array('q' => 'Can I turn my own product photos into video?', 'a' => 'Yes. Upload the photo to your library and turn it into a short clip, then join clips in the clip editor if you want a longer video.'),
                array('q' => 'Where do post ideas come from?', 'a' => 'Ideas gathers the questions people ask about your topic online and turns each one into a post idea. Draft Post opens it in the editor, ready to finish.'),
                array('q' => 'Which networks can I post to?', 'a' => '{networks}. You can post to several at once and schedule posts ahead.'),
                array('q' => 'Can my team work in one account?', 'a' => 'Yes, on {seats_plans}. Each person gets a Viewer, Editor or Manager role and works on the same account.'),
                array('q' => 'Is AI content labelled?', 'a' => 'Yes. Media made with AI gets the AI label on TikTok and YouTube when it is posted.'),
                array('q' => 'Can I run it from Claude?', 'a' => 'Yes. Add {site} to Claude as a connector and ask it to make images, write posts or schedule them. The connector is included on every plan.'),
            ),
            'cta' => array('title' => 'Put your brand\'s content on autopilot', 'text' => 'AI images and video, captions in your voice, posts on schedule.'),
        ),
    );

    /** The visual for one page as trusted HTML, or ''. */
    public static function visual(string $slug): string {
        return FeaturePages::visual_from(self::LOOK[$slug] ?? array());
    }
}
