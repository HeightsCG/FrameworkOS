<?php
/**
 * Feature pages under /features/<slug>, rendered by PagesController::featuresAction() through
 * app/views/pages/feature.php. Adding a page here puts it on the site, in the sitemap, in
 * llms.txt and in the article linker.
 */
class FeaturePages {

    /** Footer grouping, in display order: PAGES slugs under "Product"; PAGES slugs then every ROOT page under "AI". */
    const FOOTER_PRODUCT = array('memberships', 'pay-per-view', 'link-in-bio', 'publishing', 'services-and-events', 'custom-domains', 'payouts');
    const FOOTER_AI      = array('ai-influencer', 'dm-agent');

    const PAGES = array(

        'ai-influencer' => array(   // was /features/character-generation (301 in PagesController::featuresAction)
            'nav_title'   => 'AI influencer',
            'title'       => 'AI Influencer Creator: Train and Monetize',
            'description' => 'Create an AI influencer: train one character from photos or a description, generate photos and video of the same person, and sell them from your page.',
            'hero' => array(
                'title' => 'Create an AI Influencer You Can Monetize',
                'lead'  => 'On {site} you create an AI influencer once, from your photos or a description. After that, every image and clip is the same person, ready to post, or to sell as pay-per-view posts, paid messages or membership content. AI influencers included: {influencer_plans}.',
            ),
            'video'   => '',
            'related' => array('lora-character-training', 'consistent-ai-model-face', 'ai-ofm-tools'),
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
                    'title'  => 'Post it, schedule it, or sell it',
                    'text'   => 'Every generation lands in your library, ready for a post on your page, a cross-post to your social accounts, or a pay-per-view unlock.',
                    'points' => array(
                        'Publish to your page and to nine social networks at once',
                        'Put a price on a post or send it in a paid message',
                        'Automations can generate and publish on a schedule',
                    ),
                    'link' => array('See how creators get paid', '/features/payouts'),
                ),
            ),
            'faq' => array(
                array('q' => 'Do I need my own photos?', 'a' => 'No. You can describe a face instead and the studio generates a reference image, then builds the training set from it. Using your own photos gives you a character that looks like a specific person.'),
                array('q' => 'How long does training take?', 'a' => 'A few minutes, once per character. After that, every image is the same person and no retraining is needed.'),
                array('q' => 'Can I make video as well?', 'a' => 'Yes. Any generated still can be turned into a short clip with a motion prompt.'),
                array('q' => 'What does it cost?', 'a' => 'Generation uses the AI credits included with your plan, and you can top up at any time. Training is included with your plan. {final_note}'),
            ),
            'cta' => array('title' => 'Build your AI influencer today', 'text' => 'Train once, then generate and sell from one page.'),
        ),

        'dm-agent' => array(
            'nav_title'   => 'DM agent',
            'title'       => 'AI DM Agent for Your Inbox',
            'description' => 'Answer fan messages in your voice, around the clock. Drafts wait for approval until you let the agent send on its own.',
            'hero' => array(
                'title' => 'Your inbox, answered in your voice',
                'lead'  => 'The DM agent reads a fan message, drafts a reply the way you write, and either holds it for your approval or sends it for you. It also sends welcome messages and can attach paid content.',
            ),
            'related' => array('pay-per-view', 'memberships', 'ai-ofm-tools'),
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
                    'text'   => 'New follower, new subscriber, first message: each can get its own message automatically, with media and a price attached.',
                    'points' => array(
                        'Attach library media and set a price, blurred until the fan pays',
                        'Scheduled blasts to followers, subscribers, past subscribers or buyers',
                    ),
                    'link' => array('See every way to get paid', '/features/payouts'),
                ),
            ),
            'faq' => array(
                array('q' => 'Does it send without me?', 'a' => 'Only if you turn that on. By default every reply waits in a queue for your approval.'),
                array('q' => 'Will fans know it is AI?', 'a' => 'Replies go out in your voice from your account. You decide what it can and cannot talk about, and you can take over any conversation at any time.'),
                array('q' => 'Can it sell content in a message?', 'a' => 'Yes. Welcome and trigger messages can carry media with a price, and the fan unlocks it with credits.'),
                array('q' => 'What stops it going off the rails?', 'a' => 'Topics to avoid, quiet hours, a maximum number of replies in a row, and the approval queue until you switch it off.'),
            ),
            'cta' => array('title' => 'Let your inbox answer itself', 'text' => 'Drafts in your voice, approval until you trust it, paid content when it fits.'),
        ),

        'payouts' => array(
            'nav_title'   => 'Payouts',
            'title'       => 'Creator Payouts',
            'description' => 'Memberships, unlocks, bundles, services and events all land in one balance, and you cash out to your bank.',
            'hero' => array(
                'title' => 'Everything you earn, in one balance',
                'lead'  => 'Fans pay by card for memberships and with a credit wallet for everything else. Your earnings collect in one place, net of your plan fee, and you cash out to your bank whenever you want.',
            ),
            'related' => array('memberships', 'pay-per-view', 'services-and-events'),
            'rows' => array(
                array(
                    'title'  => 'One balance for every kind of sale',
                    'text'   => 'Membership payments, pay-per-view unlocks, bundles, paid messages, services and event seats all pay into the same balance.',
                    'points' => array(
                        'See what each post, bundle, service and event earned',
                        'Every sale listed with the fan, the item and the date',
                    ),
                ),
                array(
                    'title'  => 'A fee that falls as you grow',
                    'text'   => '{fee_sentence} Nothing is deducted twice, and there are no per-payout fees from us.',
                    'points' => array(
                        'The rate for your plan is shown on the pricing page',
                        'Prices, balances and earnings are all in credits',
                    ),
                    'link' => array('See plans and rates', '/pricing'),
                ),
                array(
                    'title'  => 'Cash out to your bank',
                    'text'   => 'Connect your bank once. After that, cash out your balance whenever it suits you and the money goes to your account.',
                    'points' => array(
                        'Minimum payout is ' . Price::PAYOUT_MIN_LABEL . ', paid on request',
                        'No platform hold, our payment processor\'s own payout timing applies',
                        'Available in every country our payment processor supports for creator payouts',
                        'Refunds and disputes are handled for you and reflected in your balance',
                    ),
                ),
            ),
            'faq' => array(
                array('q' => 'How do fans pay?', 'a' => 'Memberships bill by card on your schedule. Everything else is bought with a credit wallet, which fans top up in one tap.'),
                array('q' => 'When can I cash out?', 'a' => 'The minimum payout is ' . Price::PAYOUT_MIN_LABEL . ', and payouts are made on request only. Connect your bank once, then cash out whenever your balance is above the minimum. We apply no platform hold, so our payment processor\'s own payout timing applies. Payouts are available in every country our payment processor supports for creator payouts.'),
                array('q' => 'What does the platform take?', 'a' => 'A percentage of what you earn, set by your plan and shown on the pricing page. It falls as you move up.'),
                array('q' => 'What happens with refunds?', 'a' => 'A refund reverses the credits on both sides and removes the access it paid for, and your balance reflects it straight away.'),
                array('q' => 'How is my data and money handled?', 'a' => PagesController::DATA_MONEY_ANSWER),
            ),
            'cta' => array('title' => 'Start earning from one page', 'text' => 'Memberships, unlocks, services and events, paid out to your bank.'),
        ),

        'memberships' => array(
            'nav_title'   => 'Memberships',
            'title'       => 'Creator Memberships with Tiers and Trials',
            'description' => 'Sell memberships from your page: as many tiers as you want, each with its own price, billing interval, free trial, perks and promo codes.',
            'hero' => array(
                'title' => 'Memberships for Your Regulars',
                'lead'  => 'Fans join a tier on your page and pay by card on the schedule you set. Subscriber posts unlock for members and stay blurred for everyone else, and every payment lands in one balance you cash out to your bank.',
            ),
            'video'   => '',
            'related' => array('pay-per-view', 'payouts', 'link-in-bio'),
            'rows' => array(
                array(
                    'title'  => 'Tiers that fit your audience',
                    'text'   => 'Create as many tiers as you need. Each one has its own name, price, perks and description.',
                    'points' => array(
                        'Weekly, monthly or yearly billing per tier',
                        'An optional free trial on any tier',
                        'Promo codes for launches and win-backs',
                    ),
                ),
                array(
                    'title'  => 'Members-only posts',
                    'text'   => 'Mark a post for subscribers and it shows blurred on your page and in the feed until a fan joins.',
                    'points' => array(
                        'Free posts stay open to everyone, so new fans can find you',
                        'Members hear about every new post automatically',
                    ),
                ),
                array(
                    'title'  => 'Know who your members are',
                    'text'   => 'Followers, subscribers, past subscribers and buyers sit in one audience list with tags and notes.',
                    'points' => array(
                        'Message one segment or several at once, now or on a schedule',
                        'Welcome messages for new subscribers, with media and a price if you want',
                    ),
                    'link' => array('See the DM agent', '/features/dm-agent'),
                ),
                array(
                    'title'  => 'Paid out with everything else',
                    'text'   => 'Membership payments land in the same balance as your other sales. Platform fee: {fee_short}.',
                    'points' => array(
                        'Cash out to your bank on request, minimum ' . Price::PAYOUT_MIN_LABEL,
                        'Every payment listed with the fan, the tier and the date',
                    ),
                    'link' => array('See how payouts work', '/features/payouts'),
                ),
            ),
            'faq' => array(
                array('q' => 'How do fans pay for a membership?', 'a' => 'By card, billed automatically on the interval you set for the tier: weekly, monthly or yearly.'),
                array('q' => 'Can I offer a free trial?', 'a' => 'Yes. Any tier can start with a free trial, and you choose how long it lasts.'),
                array('q' => 'How many tiers can I have?', 'a' => 'As many as you want. Most creators start with two, an easy entry tier and one higher tier.'),
                array('q' => 'What does the platform take?', 'a' => '{fee_sentence}'),
            ),
            'cta' => array('title' => 'Launch your first tier', 'text' => 'Set a price, add perks and share your page.'),
        ),

        'pay-per-view' => array(
            'nav_title'   => 'Pay-per-view',
            'title'       => 'Pay-Per-View Posts and Paid Messages',
            'description' => 'Put a price on any post or message. Fans unlock it in one tap from their credit wallet, and the earnings land in your balance.',
            'hero' => array(
                'title' => 'Sell Single Pieces with Pay-Per-View',
                'lead'  => 'Put a price on any post, message or bundle. It shows blurred until a fan unlocks it with credits from their wallet, so you earn from fans who will never subscribe.',
            ),
            'video'   => '',
            'related' => array('memberships', 'dm-agent', 'payouts'),
            'rows' => array(
                array(
                    'title'  => 'A price on any post',
                    'text'   => 'Choose pay-per-view when you publish. The post sits on your page and in the feed with a blurred preview and the price.',
                    'points' => array(
                        'Prices from ' . Price::MIN_CREDITS . ' to ' . Price::MAX_CREDITS . ' credits',
                        'Members pay for pay-per-view too, so your best pieces earn from everyone',
                    ),
                ),
                array(
                    'title'  => 'Paid messages',
                    'text'   => 'Attach media and a price to any message or broadcast. It stays blurred in the inbox until the fan pays.',
                    'points' => array(
                        'Welcome and trigger messages can carry paid media',
                        'Broadcast to followers, subscribers or buyers with one price',
                    ),
                    'link' => array('See the DM agent', '/features/dm-agent'),
                ),
                array(
                    'title'  => 'Bundles',
                    'text'   => 'Group media from your library into one set with one price. Buyers find it in their purchases and can download it.',
                ),
                array(
                    'title'  => 'One wallet for fans',
                    'text'   => 'Fans top up a credit wallet once (' . Price::CREDITS_PER_DOLLAR . ' credits = $1) and unlock in one tap. Credit purchases are final and non-refundable.',
                    'points' => array(
                        'Optional automatic top-ups, so a fan never stops at an empty wallet',
                        'You earn the price in credits, net of your plan fee, and cash out to your bank',
                    ),
                    'link' => array('See how payouts work', '/features/payouts'),
                ),
            ),
            'faq' => array(
                array('q' => 'How do fans pay for an unlock?', 'a' => 'With credits from their wallet. ' . Price::CREDITS_PER_DOLLAR . ' credits cost $1, and a wallet is topped up by card in one step. Credit purchases are final and non-refundable.'),
                array('q' => 'What can I charge?', 'a' => 'Any price from ' . Price::MIN_CREDITS . ' to ' . Price::MAX_CREDITS . ' credits on a post, a message or a bundle.'),
                array('q' => 'Do members get pay-per-view posts free?', 'a' => 'No. Pay-per-view is priced for everyone. Put your regular content on your membership tiers and keep pay-per-view for your strongest single pieces.'),
                array('q' => 'What does the platform take?', 'a' => '{fee_sentence}'),
            ),
            'cta' => array('title' => 'Put a price on your next post', 'text' => 'Posts, messages and bundles, unlocked in one tap.'),
        ),

        'link-in-bio' => array(
            'nav_title'   => 'Link in bio',
            'title'       => 'Link in Bio That Takes Payments',
            'description' => 'One link-in-bio page at your handle with tracked links, memberships, pay-per-view, bundles, services and events, paid out to your bank.',
            'hero' => array(
                'title' => 'A Link in Bio That Sells',
                'lead'  => 'Your page at {host}/@handle holds your links and everything you sell: memberships, pay-per-view posts, bundles, services and events. Fans pay by card or credit wallet, and earnings pay out to your bank.',
            ),
            'video'   => '',
            'related' => array('memberships', 'publishing', 'custom-domains'),
            'rows' => array(
                array(
                    'title'  => 'Links with click counts',
                    'text'   => 'Add the links you would put in any bio tool. Every click is counted, so you can see which ones work.',
                    'points' => array(
                        'Turn a link off without deleting it',
                        'Clicks show up in your dashboard next to sales',
                    ),
                ),
                array(
                    'title'  => 'Everything you sell, on the same page',
                    'text'   => 'Your posts, membership tiers, services and events sit under your links, so a fan can join or buy without leaving.',
                    'points' => array(
                        'Subscriber and pay-per-view posts show blurred until a fan pays',
                        'Services and events have their own tabs and pages',
                    ),
                    'link' => array('See memberships', '/features/memberships'),
                ),
                array(
                    'title'  => 'Your brand, your address',
                    'text'   => 'Your colors, bio and links carry across the page. On the {domain_plan} plan your page can run on your own domain.',
                    'link'   => array('See custom domains', '/features/custom-domains'),
                ),
                array(
                    'title'  => 'Found beyond your bio',
                    'text'   => 'Fans also discover you in the home feed and in search across creators and content, and follow you for free.',
                ),
            ),
            'faq' => array(
                array('q' => 'Is it a replacement for my current link-in-bio tool?', 'a' => 'Yes. It holds your links like any bio page, and it also takes payments for memberships, pay-per-view, bundles, services and events.'),
                array('q' => 'Can I see which links get clicked?', 'a' => 'Yes. Every link counts its clicks, and the totals show in your dashboard.'),
                array('q' => 'Can I use my own domain?', 'a' => 'Yes, on the {domain_plan} plan. Point your domain at us and your page answers on it. {final_note}'),
                array('q' => 'How do I get paid?', 'a' => 'Every sale lands in one balance, net of your plan fee, and you cash out to your bank on request with a ' . Price::PAYOUT_MIN_LABEL . ' minimum.'),
            ),
            'cta' => array('title' => 'Put one link in every bio', 'text' => 'Links, memberships and sales on one page.'),
        ),

        'publishing' => array(
            'nav_title'   => 'Publishing',
            'title'       => 'Post to Nine Social Networks at Once',
            'description' => 'Publish to your page and nine social networks from one studio, with AI captions in your voice, drafts and scheduling.',
            'hero' => array(
                'title' => 'Publish Everywhere from One Studio',
                'lead'  => 'Upload once and post to your page and your social accounts at the same time: {networks}. Captions are written in your voice and trimmed for each network.',
            ),
            'video'   => '',
            'related' => array('link-in-bio', 'ai-influencer', 'dm-agent'),
            'rows' => array(
                array(
                    'title'  => 'Nine networks, one upload',
                    'text'   => 'Connect your accounts once. Each post goes to your page and to the networks you pick.',
                    'points' => array(
                        '{networks}',
                        'Cross-post to Fanvue as well',
                        'Choose per post whether it also appears on your page',
                    ),
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
                    'text'   => 'Engagement from your social posts comes back into your dashboard, next to views and sales on your page.',
                ),
            ),
            'faq' => array(
                array('q' => 'Which networks can I post to?', 'a' => '{networks}, plus Fanvue cross-posting.'),
                array('q' => 'Do you add a link to my social posts?', 'a' => 'No. Posts go out as you wrote them, so the networks do not hold back reach for an outside link.'),
                array('q' => 'Can I schedule posts?', 'a' => 'Yes. Schedule any post for a date and time, keep it as a draft, or publish it now.'),
                array('q' => 'Who writes the captions?', 'a' => 'You do, or the studio drafts one in your brand voice and trims it for each network. You can edit every caption before it goes out.'),
            ),
            'cta' => array('title' => 'Post once, everywhere', 'text' => 'Your page and nine networks from one studio.'),
        ),

        'services-and-events' => array(
            'nav_title'   => 'Services and events',
            'title'       => 'Sell Services, Events and 1:1 Video Calls',
            'description' => 'Take bookings for services, sell event tickets and host live calls in a built-in video room, all from your creator page.',
            'hero' => array(
                'title' => 'Bookings, Tickets and Live Calls',
                'lead'  => 'Sell a service, a seat at a live event or a 1:1 session from your page. Calls run in a built-in video room, so fans join without a separate meeting app.',
            ),
            'video'   => '',
            'related' => array('memberships', 'payouts', 'link-in-bio'),
            'rows' => array(
                array(
                    'title'  => 'Services fans can book',
                    'text'   => 'List what you offer with a price and a description. Access details are shared with the buyer only after purchase.',
                    'points' => array(
                        'Each service has its own page and a tab on your profile',
                        'Every booking listed with the fan and the date',
                    ),
                ),
                array(
                    'title'  => 'Event tickets',
                    'text'   => 'Create a free or paid event with a date and a seat count. Attendees get reminder emails before it starts.',
                    'points' => array(
                        'The meeting link is sent by email only, never shown on the public page',
                        'Message attendees from the event workspace',
                    ),
                ),
                array(
                    'title'  => 'A built-in video room',
                    'text'   => 'Events and 1:1 sessions run on our own video calls, with a waiting room, chat, screen share and host controls.',
                    'points' => array(
                        'Admit people from the waiting room, lock the room, spotlight a speaker',
                        'Raised hands and an optional call password',
                    ),
                ),
            ),
            'faq' => array(
                array('q' => 'Do fans need Zoom or another app?', 'a' => 'No. Calls run in a built-in video room in the browser, with a waiting room, chat and screen share.'),
                array('q' => 'Can events be free?', 'a' => 'Yes. An event can be free or paid. Anyone can join a free open event.'),
                array('q' => 'When do I get paid for an event?', 'a' => 'Ticket earnings are released to your balance after the event. From there you cash out to your bank on request.'),
                array('q' => 'How do buyers get access to a service?', 'a' => 'The access details you write are revealed to the buyer only after they purchase.'),
            ),
            'cta' => array('title' => 'Sell your time from one page', 'text' => 'Services, tickets and live calls, paid out to your bank.'),
        ),

        'custom-domains' => array(
            'nav_title'   => 'Custom domains',
            'title'       => 'Custom Domain for Your Creator Page',
            'description' => 'Run your creator page on your own domain. Add a couple of DNS records, get a certificate automatically, and keep fans signed in.',
            'hero' => array(
                'title' => 'Your Page on Your Own Domain',
                'lead'  => 'On the {domain_plan} plan your creator page can answer on a domain you own. Fans see your address, buy and join as usual, and stay signed in when they arrive from our site.',
            ),
            'video'   => '',
            'related' => array('link-in-bio', 'memberships', 'publishing'),
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
                    'title'  => 'Instant handoff for signed-in fans',
                    'text'   => 'A fan who is signed in on our site is handed straight to your domain, so they buy and join without signing in again.',
                ),
                array(
                    'title'  => 'Your profile, services and events',
                    'text'   => 'Your page, your services and your events all answer on your domain, and fans pay from the same wallet.',
                    'link'   => array('See link in bio', '/features/link-in-bio'),
                ),
            ),
            'faq' => array(
                array('q' => 'Which plan includes custom domains?', 'a' => 'The {domain_plan} plan. {final_note}'),
                array('q' => 'What do I need to change at my domain provider?', 'a' => 'A verification record plus the routing records we show you (two for a bare domain and its www version), all shown in settings with the exact values to copy.'),
                array('q' => 'Do I need my own certificate?', 'a' => 'No. A certificate is issued automatically once your records point at us.'),
                array('q' => 'Will fans have to sign in again?', 'a' => 'No. A signed-in fan is handed to your domain with their session, so they can buy and join straight away.'),
            ),
            'cta' => array('title' => 'Put your page on your domain', 'text' => 'Your address, our checkout and payouts.'),
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
            'description' => 'Train a LoRA character model from 10 to 50 photos or a described face, then generate on-model photos and video and sell them from one page.',
            'hero' => array(
                'title' => 'LoRA Character Training without the Setup',
                'lead'  => '{site} trains a LoRA for your character from 10 to 50 photos, or from a face you describe. You never touch a training script or a GPU, and every image after that is the same person.',
            ),
            'video'   => '',
            'related' => array('ai-influencer', 'consistent-ai-model-face', 'ai-ofm-tools'),
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
                    'title'  => 'Then generate and sell',
                    'text'   => 'Generate photos and short videos of the character, and sell them as pay-per-view posts, paid messages or membership content.',
                    'link'   => array('See the AI influencer generator', '/features/ai-influencer'),
                ),
            ),
            'faq' => array(
                array('q' => 'What is a LoRA?', 'a' => 'A small add-on to an image model, trained on one person, so the model can draw that person in any scene. {site} trains and stores it for you.'),
                array('q' => 'How many photos do I need?', 'a' => 'Between 10 and 50 photos of the same person, with different angles and lighting. If you have none, describe a face and the studio builds the set.'),
                array('q' => 'How long does training take?', 'a' => 'A few minutes, once per character. You do not retrain to make new images.'),
                array('q' => 'What does it cost?', 'a' => 'Training is included with your plan. Generating images and video uses the AI credits included with your plan, and you can top up at any time. {final_note}'),
            ),
            'cta' => array('title' => 'Train your first character', 'text' => 'Photos or a description in, a consistent character out.'),
        ),

        'consistent-ai-model-face' => array(
            'nav_title'   => 'Consistent AI face',
            'title'       => 'Consistent AI Model Face in Every Image',
            'description' => 'Keep the same AI model face across every photo and video: a trained character, pinned seeds and video made from the exact frame you approved.',
            'hero' => array(
                'title' => 'The Same Face in Every Image',
                'lead'  => 'An AI model only works if fans see the same person every time. {site} trains a character on one face, so photos and videos stay on model across scenes, outfits and lighting.',
            ),
            'video'   => '',
            'related' => array('lora-character-training', 'ai-influencer', 'ai-ofm-tools'),
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
            'faq' => array(
                array('q' => 'Why do AI faces change between images?', 'a' => 'A prompt alone describes a type of person, not one person. Training a character on one face is what keeps it the same.'),
                array('q' => 'Does the face stay the same in video?', 'a' => 'Yes. Video is made from a still you generated and approved, so it starts from the same face.'),
                array('q' => 'Can I change outfits and scenes?', 'a' => 'Yes. Scenes, outfits and lighting change with the prompt, and the face stays the same.'),
                array('q' => 'What if a character is not right?', 'a' => 'Retrain it with a better set. The current model keeps working until the new one is ready.'),
            ),
            'cta' => array('title' => 'Keep your AI model on model', 'text' => 'Train one face, generate it anywhere.'),
        ),

        'ai-ofm-tools' => array(
            'nav_title'   => 'AI OFM tools',
            'title'       => 'AI OFM Tools: Create, Post, Chat and Sell',
            'description' => 'AI tools for running creator accounts: AI influencers, scheduled posting to nine networks, a DM agent, pay-per-view and payouts in one place.',
            'hero' => array(
                'title' => 'AI Tools for Running Creator Accounts',
                'lead'  => 'Create the content, post it everywhere, answer the inbox and get paid from one account. {site} brings AI influencers, publishing, a DM agent, memberships and pay-per-view together, with team seats for the people who help you.',
            ),
            'video'   => '',
            'related' => array('ai-influencer', 'dm-agent', 'publishing'),
            'rows' => array(
                array(
                    'title'  => 'Create',
                    'text'   => 'Train AI influencers and generate photos and short videos of them on demand, or upload your own media.',
                    'link'   => array('See the AI influencer generator', '/features/ai-influencer'),
                ),
                array(
                    'title'  => 'Post',
                    'text'   => 'Publish to your page and {networks}, with AI captions, drafts, scheduling and automations that post for you.',
                    'link'   => array('See publishing', '/features/publishing'),
                ),
                array(
                    'title'  => 'Chat',
                    'text'   => 'The DM agent drafts replies in your voice, holds them for approval until you trust it, and sends welcome messages with paid media.',
                    'link'   => array('See the DM agent', '/features/dm-agent'),
                ),
                array(
                    'title'  => 'Sell and get paid',
                    'text'   => 'Memberships, pay-per-view posts, paid messages and bundles pay into one balance. Platform fee: {fee_short}.',
                    'points' => array(
                        'Team seats on the {seats_plans} plan, so collaborators work on your account',
                        'Cash out to your bank on request, minimum ' . Price::PAYOUT_MIN_LABEL,
                    ),
                    'link'   => array('See how payouts work', '/features/payouts'),
                ),
            ),
            'faq' => array(
                array('q' => 'What does OFM mean?', 'a' => 'Online fan management: running the content, posting, messaging and sales for creator accounts. These tools cover each of those jobs in one place.'),
                array('q' => 'Can a team work on one account?', 'a' => 'Yes, on the {seats_plans} plan. Invite collaborators to work on your account with their own sign-in, without sharing your password.'),
                array('q' => 'Does the DM agent send on its own?', 'a' => 'Only if you turn that on. By default every reply waits for approval.'),
                array('q' => 'What does it cost?', 'a' => '{fee_sentence}'),
            ),
            'cta' => array('title' => 'Run your creator accounts from one place', 'text' => 'Create, post, chat and sell.'),
        ),
    );
}
