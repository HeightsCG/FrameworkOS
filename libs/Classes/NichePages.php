<?php
/**
 * Niche landing pages under /for/<slug> (2026-10-24): one page per kind of creator, on the feature template
 * (app/views/pages/feature.php via PagesController::forNicheAction). Same shape as FeaturePages::PAGES plus
 * 'rows_title'; placeholders like {fee_sentence} are filled by PagesController::fill_feature_text. Every claim
 * traces to a shipped feature page (memberships, pay-per-view, services-and-events, link-in-bio, publishing,
 * dm-agent, analytics). Media is images and video only. Each page also works as an ad landing page.
 * Adding a page here puts it on the site, in the sitemap, in llms.txt, in the footer and in the article linker.
 */
class NichePages {

    /** Hero photo (SitePhotos key), hero layout and the row visual, as FeaturePages::LOOK. The photos are shared with the matching feature page. */
    const LOOK = array(
        'fitness-coaches'  => array('photo' => 'memberships', 'variant' => 'split', 'visual' => 'ui:offers', 'row' => 0),
        'musicians'        => array('photo' => 'monetize', 'variant' => 'full', 'visual' => 'ui:linkpage', 'row' => 3),
        'coaches'          => array('photo' => 'services', 'variant' => 'split', 'visual' => 'ui:inbox', 'row' => 3),
        'photographers'    => array('photo' => 'ppv', 'variant' => 'split', 'visual' => 'ui:targets', 'row' => 3),
        'travel-creators'  => array('photo' => 'linkinbio', 'variant' => 'split', 'visual' => 'ui:linkpage', 'row' => 0),
        'brands'           => array('photo' => 'automations', 'variant' => 'split', 'visual' => 'ui:targets', 'row' => 3),
    );

    const PAGES = array(

        'fitness-coaches' => array(
            'nav_title'   => 'Fitness coaches',
            'title'       => 'Platform for Fitness Coaches: Members, Programs, Calls',
            'description' => 'Sell workout memberships, training programs and 1:1 coaching calls from one page, and post your training clips to every network at once.',
            'hero' => array(
                'title' => 'Coach Online and Get Paid from One Page',
                'lead'  => 'Fitness coaches on {site} sell monthly memberships for weekly workouts, training programs as one-price bundles and booked coaching calls, all from one page. Clients pay by card or with credits, and you cash out to your bank.',
            ),
            'rows_title' => 'What can a fitness coach sell here?',
            'steps_title' => 'How do you start as a fitness coach?',
            'related' => array('memberships', 'services-and-events', 'publishing'),
            'rows' => array(
                array(
                    'title'  => 'Memberships for weekly workouts',
                    'text'   => 'Put your weekly workouts behind a membership tier. Members see every subscriber post, and everyone else sees a blurred preview.',
                    'points' => array(
                        'Weekly, monthly or yearly billing per tier',
                        'A free trial on any tier, so a new client can try a week',
                        'Promo codes for a January push or a win-back',
                    ),
                    'link' => array('See how memberships work', '/features/memberships'),
                ),
                array(
                    'title'  => 'Programs as one-price bundles',
                    'text'   => 'Group the videos and photos of a full program into one bundle with one price. Buyers find it in their purchases and can download it.',
                    'points' => array(
                        'Sell the same program to members and non-members',
                        'A single workout can carry its own pay-per-view price',
                    ),
                ),
                array(
                    'title'  => '1:1 calls and live classes',
                    'text'   => 'List a coaching call as a service, or sell seats in a live group class. Both run in a built-in video room in the browser.',
                    'points' => array(
                        'Form checks and check-ins without a separate meeting app',
                        'Class reminders go out by email before it starts',
                    ),
                    'link' => array('See services and events', '/features/services-and-events'),
                ),
                array(
                    'title'  => 'Training clips on every network',
                    'text'   => 'Post a clip once and send it to {networks}. Schedule a week of posts in one sitting.',
                    'points' => array(
                        'Paid posts send a blurred preview, so the full workout stays on your page',
                    ),
                    'link' => array('See publishing', '/features/publishing'),
                ),
            ),
            'steps' => array(
                array('Create a Tier', 'Name it, set a price and a billing interval, and add a free trial if you want one.'),
                array('Bundle a Program', 'Pick the program videos from your library, put them in one bundle and give it a price.'),
                array('List a Coaching Call', 'Add a service with a price and the details a client sees after booking.'),
            ),
            'fan_side' => 'A client opens your page, joins a tier by card and starts watching the workouts straight away. A program bought as a bundle sits in their purchases, ready to download. A booked call opens in the browser at the time you set.',
            'limits'   => array('fee_percent'),
            'faq' => array(
                array('q' => 'Can I run live group classes?', 'a' => 'Yes. Create an event with a date and a seat count, free or paid, and run it in the built-in video room with chat and screen share.'),
                array('q' => 'Can clients download a program?', 'a' => 'Yes. A program sold as a bundle lands in the buyer\'s purchases, where they can download it.'),
                array('q' => 'Do I need a separate booking app?', 'a' => 'No. Coaching calls are sold as services on your page, and the call runs in the built-in video room.'),
                array('q' => 'What does the platform take?', 'a' => '{fee_sentence}'),
            ),
            'cta' => array('title' => 'Put your coaching on one page', 'text' => 'Memberships, programs and calls, paid out to your bank.'),
        ),

        'musicians' => array(
            'nav_title'   => 'Musicians',
            'title'       => 'Platform for Musicians: Fan Clubs, Exclusives, Live Shows',
            'description' => 'Run a paid fan club, sell exclusive videos and tickets to live online shows, and keep every streaming link on one page.',
            'hero' => array(
                'title' => 'A Paid Fan Club with All Your Links',
                'lead'  => 'Musicians on {site} run a paid fan club, sell exclusive videos one at a time and sell tickets to live online shows, from the same page that holds their streaming and merch links.',
            ),
            'rows_title' => 'What can a musician sell here?',
            'steps_title' => 'How do you start as a musician?',
            'related' => array('memberships', 'link-in-bio', 'services-and-events'),
            'rows' => array(
                array(
                    'title'  => 'A fan club with tiers',
                    'text'   => 'Studio diaries, rehearsal clips and early looks go to members. Set one tier for listeners and a higher one for your closest fans.',
                    'points' => array(
                        'Weekly, monthly or yearly billing per tier',
                        'Members hear about every new post automatically',
                    ),
                    'link' => array('See how memberships work', '/features/memberships'),
                ),
                array(
                    'title'  => 'Exclusives with pay-per-view',
                    'text'   => 'Put a price on a single video or photo set: an acoustic take, a live cut, a behind-the-scenes reel. Fans unlock it in one tap.',
                    'points' => array(
                        'Paid messages carry media and a price too',
                    ),
                    'link' => array('See pay-per-view', '/features/pay-per-view'),
                ),
                array(
                    'title'  => 'Live online shows',
                    'text'   => 'Sell tickets to a listening party or a live set, free or paid, with a seat count. The show runs in a built-in video room.',
                    'points' => array(
                        'The meeting link goes out by email only',
                        'Reminder emails before the show starts',
                    ),
                    'link' => array('See services and events', '/features/services-and-events'),
                ),
                array(
                    'title'  => 'Every streaming link on one page',
                    'text'   => 'Your page holds the links you used to keep on a link-in-bio page: streaming, merch, tour dates. Bring them over from Linktree in one step.',
                    'points' => array(
                        'Tracking links show which link brings signups and sales',
                    ),
                    'link' => array('See the link page', '/features/link-in-bio'),
                ),
            ),
            'steps' => array(
                array('Bring Your Links', 'Import your Linktree, Beacons or Stan page, or add your streaming and merch links by hand.'),
                array('Open the Fan Club', 'Create a tier with a price and the perks members get.'),
                array('Book a Show', 'Create an event with a date and a seat count and share the ticket page.'),
            ),
            'fan_side' => 'A fan lands on your page from your bio, finds your streaming links at the top and joins the fan club by card. Exclusives unlock with credits from their wallet, and a show ticket brings reminder emails with the link to the room.',
            'limits'   => array('fee_percent'),
            'faq' => array(
                array('q' => 'Can I sell audio files?', 'a' => 'Not as files. Pages carry images and video, so share a performance video or a lyric video, and keep streaming links for the audio.'),
                array('q' => 'Can I move my Linktree links over?', 'a' => 'Yes. Paste your Linktree, Beacons or Stan address in Settings and pick the links, photo and bio to bring over.'),
                array('q' => 'Do fans need an app for a live show?', 'a' => 'No. Shows run in the browser in a built-in video room with chat and a waiting room.'),
                array('q' => 'What does the platform take?', 'a' => '{fee_sentence}'),
            ),
            'cta' => array('title' => 'Open your fan club', 'text' => 'Tiers, exclusives and shows, next to all your links.'),
        ),

        'coaches' => array(
            'nav_title'   => 'Coaches and teachers',
            'title'       => 'Platform for Coaches and Teachers: Calls, Classes, Members',
            'description' => 'Sell 1:1 sessions, live classes and a membership from one page. Calls run in a built-in video room, and AI drafts replies to your DMs.',
            'hero' => array(
                'title' => 'Sell Sessions and Classes from One Page',
                'lead'  => 'Coaches and teachers on {site} sell 1:1 sessions, seats in live classes and a membership for ongoing lessons. Sessions run in a built-in video room, so clients join from the browser.',
            ),
            'rows_title' => 'What can a coach or teacher sell here?',
            'steps_title' => 'How do you start as a coach or teacher?',
            'related' => array('services-and-events', 'memberships', 'dm-agent'),
            'rows' => array(
                array(
                    'title'  => '1:1 sessions as services',
                    'text'   => 'List each session type with a price and a description. The access details you write are shown to the client only after they buy.',
                    'points' => array(
                        'Each service has its own page and a tab on your profile',
                        'Every booking listed with the client and the date',
                    ),
                    'link' => array('See services and events', '/features/services-and-events'),
                ),
                array(
                    'title'  => 'Live classes with seats',
                    'text'   => 'Create a class with a date and a seat count, free or paid. Run it with a waiting room, chat, screen share and raised hands.',
                    'points' => array(
                        'An optional call password',
                        'Message everyone who signed up from the event workspace',
                    ),
                ),
                array(
                    'title'  => 'A membership for ongoing lessons',
                    'text'   => 'Post lessons and worksheets as images or video for members, and keep a free post or two open so new students can find you.',
                    'link'   => array('See how memberships work', '/features/memberships'),
                ),
                array(
                    'title'  => 'Replies drafted for you',
                    'text'   => 'Turn on AI replies and the inbox drafts answers to student questions in your voice. Set a welcome message for every new member.',
                    'points' => array(
                        'Followers, members and buyers in one audience list with tags and notes',
                    ),
                    'link' => array('See the DM agent', '/features/dm-agent'),
                ),
            ),
            'steps' => array(
                array('List a Session', 'Add a service with a price and the access details a client sees after buying.'),
                array('Schedule a Class', 'Create an event with a date and a seat count, free or paid.'),
                array('Turn On AI Replies', 'Let the inbox draft answers to questions and greet new members.'),
            ),
            'fan_side' => 'A client picks a session or a class seat on your page and pays with credits. Session details appear after purchase, class reminders arrive by email, and both open in the browser with no app to install.',
            'limits'   => array('fee_percent'),
            'faq' => array(
                array('q' => 'Do clients need Zoom?', 'a' => 'No. Sessions and classes run in a built-in video room in the browser, with a waiting room, chat and screen share.'),
                array('q' => 'When are class earnings paid?', 'a' => 'Ticket earnings are released to your balance after the event. From there you cash out to your bank on request.'),
                array('q' => 'Can I share files with students?', 'a' => 'Images and video, as posts, paid messages or bundles that buyers can download.'),
                array('q' => 'What does the platform take?', 'a' => '{fee_sentence}'),
            ),
            'cta' => array('title' => 'Sell your next session', 'text' => 'Sessions, classes and a membership on one page.'),
        ),

        'photographers' => array(
            'nav_title'   => 'Photographers',
            'title'       => 'Platform for Photographers: Sell Photo Sets and Sessions',
            'description' => 'Sell photo sets as downloadable bundles, single shots as pay-per-view and booked sessions, with watermarks on your images.',
            'hero' => array(
                'title' => 'Sell Your Photo Sets Directly to Fans',
                'lead'  => 'Photographers on {site} sell full sets as downloadable bundles, single shots as pay-per-view, a membership for behind-the-scenes work and booked sessions, all from one page.',
            ),
            'rows_title' => 'What can a photographer sell here?',
            'steps_title' => 'How do you start as a photographer?',
            'related' => array('pay-per-view', 'memberships', 'publishing'),
            'rows' => array(
                array(
                    'title'  => 'Sets as downloadable bundles',
                    'text'   => 'Group a shoot into one bundle with one price. Buyers find it in their purchases and download the full set.',
                    'points' => array(
                        'Prices from ' . Price::MIN_CREDITS . ' to ' . Price::MAX_CREDITS . ' credits',
                    ),
                ),
                array(
                    'title'  => 'Single shots with pay-per-view',
                    'text'   => 'Price one image or a short set in a post or a message. It shows blurred until a fan unlocks it.',
                    'points' => array(
                        'A watermark on any image, switched on per file',
                    ),
                    'link' => array('See pay-per-view', '/features/pay-per-view'),
                ),
                array(
                    'title'  => 'Behind the scenes for members',
                    'text'   => 'Edits, outtakes and lighting setups go to members, so your regulars pay monthly instead of once.',
                    'link'   => array('See how memberships work', '/features/memberships'),
                ),
                array(
                    'title'  => 'Previews on every network',
                    'text'   => 'Post once and send the public version to {networks}. Paid posts go out as a blurred preview, so the full image stays on your page.',
                    'link'   => array('See publishing', '/features/publishing'),
                ),
                array(
                    'title'  => 'Booked sessions',
                    'text'   => 'List a portrait session, a portfolio review or a lesson as a service with a price. Details are shared after booking.',
                    'link'   => array('See services and events', '/features/services-and-events'),
                ),
            ),
            'steps' => array(
                array('Upload a Shoot', 'Add the images to your library and switch on the watermark where you want it.'),
                array('Bundle the Set', 'Put the shoot in one bundle with one price.'),
                array('Post the Preview', 'Publish a pay-per-view post and send the blurred preview to your networks.'),
            ),
            'fan_side' => 'A fan sees a blurred preview with the price, unlocks it with credits and the full image appears. A bought set sits in their purchases, ready to download.',
            'limits'   => array('fee_percent'),
            'faq' => array(
                array('q' => 'Can I watermark my images?', 'a' => 'Yes. Switch the watermark on per image in your library. Videos carry a watermark overlay on the player.'),
                array('q' => 'Do buyers get full-size files?', 'a' => 'A set sold as a bundle can be downloaded from the buyer\'s purchases.'),
                array('q' => 'Can I sell prints?', 'a' => 'No. Sales are digital: images, video, sessions and memberships.'),
                array('q' => 'What does the platform take?', 'a' => '{fee_sentence}'),
            ),
            'cta' => array('title' => 'Sell your next set', 'text' => 'Bundles, pay-per-view and sessions from one page.'),
        ),

        'travel-creators' => array(
            'nav_title'   => 'Travel creators',
            'title'       => 'Platform for Travel Creators: Links, Guides, Trip Calls',
            'description' => 'One page for your booking and affiliate links, paid travel guides in photos and video, trip-planning calls and a membership.',
            'hero' => array(
                'title' => 'Your Links, Guides and Trip Calls on One Page',
                'lead'  => 'Travel creators on {site} keep their booking and affiliate links on one page, sell guides as photo and video bundles, take trip-planning calls and run a membership for followers who travel with them.',
            ),
            'rows_title' => 'What can a travel creator sell here?',
            'steps_title' => 'How do you start as a travel creator?',
            'related' => array('link-in-bio', 'analytics', 'services-and-events'),
            'rows' => array(
                array(
                    'title'  => 'A link page that counts clicks',
                    'text'   => 'Your booking, gear and affiliate links sit at the top of your page. Bring them over from Linktree, Beacons or Stan in one step.',
                    'points' => array(
                        'Link clicks in your dashboard',
                        'Tracking links show which post brings signups and sales',
                    ),
                    'link' => array('See analytics', '/features/analytics'),
                ),
                array(
                    'title'  => 'Guides as photo and video bundles',
                    'text'   => 'Turn a city or a route into a bundle of photos and videos with one price. Buyers download it to their phone before they go.',
                    'link'   => array('See pay-per-view and bundles', '/features/pay-per-view'),
                ),
                array(
                    'title'  => 'Trip-planning calls',
                    'text'   => 'Sell a planning session as a service. The call runs in a built-in video room in the browser.',
                    'link'   => array('See services and events', '/features/services-and-events'),
                ),
                array(
                    'title'  => 'Post from the road',
                    'text'   => 'Schedule posts ahead and send them to {networks}, so your feeds keep moving on travel days.',
                    'link'   => array('See publishing', '/features/publishing'),
                ),
            ),
            'steps' => array(
                array('Bring Your Links', 'Import your current link page, or add your booking and affiliate links by hand.'),
                array('Bundle a Guide', 'Put the photos and videos for one place into a bundle with a price.'),
                array('List a Planning Call', 'Add a service with a price and the details a buyer sees after booking.'),
            ),
            'fan_side' => 'A follower taps your bio link, finds your booking links at the top of your page and buys a guide with credits. The guide sits in their purchases, ready to download before the trip.',
            'limits'   => array('fee_percent'),
            'faq' => array(
                array('q' => 'Can I keep my affiliate links?', 'a' => 'Yes. Any link goes on your page, and link clicks show in your dashboard.'),
                array('q' => 'Can I move my Linktree over?', 'a' => 'Yes. Paste your Linktree, Beacons or Stan address in Settings and pick the links, photo and bio to bring over.'),
                array('q' => 'Can a guide be a PDF?', 'a' => 'No. Guides are bundles of images and video, which buyers can download.'),
                array('q' => 'What does the platform take?', 'a' => '{fee_sentence}'),
            ),
            'cta' => array('title' => 'Put your travel links to work', 'text' => 'Links, guides and calls on one page.'),
        ),

        // 2026-10-26: brands using the tools for content and social, not fan sales. Claims trace to ImageGenService (No Influencer
        // mode), BrandService (brand kit from a website), PostForMeService/SocialShareService, cron/scheduler.php, IdeasController,
        // cron/social_metrics.php, TrackingLinks, Permissions::team_allows and McpController. Left out on purpose: the inbox AI
        // (its prompt is written for fans), Fanvue and the adult character source.
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
                    'text'   => 'Views, likes, comments, shares, saves and reach come back from each network for every post. Tracking links show the clicks, signups and sales each campaign link brought in.',
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
            'limits'   => array('seats', 'ai_credits', 'automations', 'storage_gb', 'custom_domain'),
            'faq' => array(
                array('q' => 'Can I make product photos without a photographer?', 'a' => 'Yes. Describe the shot in Generate Images with the No Influencer option and you get an image in the size you pick, in your brand colours.'),
                array('q' => 'Can I turn my own product photos into video?', 'a' => 'Yes. Upload the photo to your library and turn it into a short clip, then join clips in the clip editor if you want a longer video.'),
                array('q' => 'Where do post ideas come from?', 'a' => 'Ideas gathers the questions people ask about your topic online and turns each one into a post idea. Draft Post opens it in the editor, ready to finish.'),
                array('q' => 'Which networks can I post to?', 'a' => '{networks}. You can post to several at once and schedule posts ahead.'),
                array('q' => 'Can my team work in one account?', 'a' => 'Yes, on {seats_plans}. Each person gets a Viewer, Editor or Manager role and works on the same account.'),
                array('q' => 'Can I use my own domain?', 'a' => 'Yes, on {domain_plan}. Your page then runs on your own address.'),
                array('q' => 'Is AI content labelled?', 'a' => 'Yes. Media made with AI gets the AI label on TikTok and YouTube when it is posted.'),
                array('q' => 'Can I run it from Claude?', 'a' => 'Yes. Add {site} to Claude as a connector and ask it to make images, write posts or schedule them. The connector is included on every plan.'),
                array('q' => 'Can a brand sell from its page?', 'a' => 'Yes. You can sell services, event tickets and bundles of digital content. {fee_sentence}'),
            ),
            'cta' => array('title' => 'Put your brand\'s content on autopilot', 'text' => 'AI images and video, captions in your voice, posts on schedule.'),
        ),
    );

    /** The visual for one page as trusted HTML, or ''. */
    public static function visual(string $slug): string {
        return FeaturePages::visual_from(self::LOOK[$slug] ?? array());
    }
}
