<?php
/**
 * Feature pages under /features/<slug>, rendered by PagesController::featuresAction() through
 * app/views/pages/feature.php. Adding a page here puts it on the site, in the sitemap, in
 * llms.txt and in the article linker.
 */
class FeaturePages {

    /** Footer grouping, in display order: PAGES slugs under "Product"; PAGES slugs then every ROOT page under "AI". */
    const FOOTER_PRODUCT = array('memberships', 'pay-per-view', 'link-in-bio', 'publishing', 'automations', 'analytics', 'services-and-events', 'custom-domains', 'payouts');
    const FOOTER_AI      = array('ai-influencer', 'dm-agent');


    /**
     * How each feature page looks (site-wide redesign 2026-10-15): its hero photo (SitePhotos key), the hero layout, and the visual
     * placed in one of its rows: 'ui:<kind>' (Sections::ui schematic), 'trio:<a>,<b>,<c>' (one character in three scenes) or
     * 'gallery:<keys>' (a training set). Every page has its own photo; the AI pages share their one AI-generated character on purpose.
     */
    const LOOK = array(
        'ai-influencer'            => array('photo' => 'aichar', 'variant' => 'split', 'visual' => 'trio:aichar-a,aichar-b,aichar-c', 'row' => 1, 'caption' => 'One AI character, three scenes. Illustrative images made with AI.'),
        'dm-agent'                 => array('photo' => 'dmagent', 'variant' => 'split', 'visual' => 'ui:inbox', 'row' => 0),
        'payouts'                  => array('photo' => 'payouts', 'variant' => 'split', 'visual' => 'ui:payout', 'row' => 0),
        'memberships'              => array('photo' => 'memberships', 'variant' => 'split', 'visual' => 'ui:offers', 'row' => 0),
        'pay-per-view'             => array('photo' => 'ppv', 'variant' => 'split'),
        'link-in-bio'              => array('photo' => 'linkinbio', 'variant' => 'split', 'visual' => 'ui:linkpage', 'row' => 0),
        'publishing'               => array('photo' => 'publishing', 'variant' => 'full', 'visual' => 'ui:targets', 'row' => 0),
        'automations'              => array('photo' => 'automations', 'variant' => 'split', 'visual' => 'ui:automation', 'row' => 0),
        'analytics'                => array('photo' => 'analytics', 'variant' => 'split', 'visual' => 'ui:analytics', 'row' => 0),
        'services-and-events'      => array('photo' => 'services', 'variant' => 'split'),
        'custom-domains'           => array('photo' => 'domains', 'variant' => 'split', 'visual' => 'ui:domain', 'row' => 0),
        'lora-character-training'  => array('photo' => 'train-1', 'variant' => 'split', 'visual' => 'gallery:train-1,train-2,train-3,train-4,train-5,aichar', 'row' => 0, 'caption' => 'A training set: one AI character from six angles. Illustrative images made with AI.'),
        'consistent-ai-model-face' => array('photo' => 'aichar-c', 'variant' => 'split', 'visual' => 'trio:aichar,aichar-a,aichar-b', 'row' => 0, 'caption' => 'The same face in three scenes. Illustrative images made with AI.'),
        'ai-ofm-tools'             => array('photo' => 'ofm', 'variant' => 'full', 'visual' => 'ui:schedule', 'row' => 0),
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
            'title'       => 'AI Influencer Generator: Train, Create and Sell',
            'description' => 'Create an AI influencer: train one character from photos or a description, generate photos and video of the same person, and sell them from your page.',
            'hero' => array(
                'title' => 'An AI Influencer Generator That Also Sells',
                'lead'  => 'An AI influencer is a trained character you can generate photos and video of, and sell from your page. On {site} you create one once, from your photos or a description. After that, every image and clip is the same person, ready to post, or to sell as pay-per-view posts, paid messages or membership content. AI influencers included: {influencer_plans}.',
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
                        'Publish to your page and to ten platforms at once',
                        'Put a price on a post or send it in a paid message',
                        'Automations can generate and publish on a schedule',
                    ),
                    'link' => array('See how creators get paid', '/features/payouts'),
                ),
            ),
            'fan_side' => 'Fans never see the training or the prompts. They see photos and short videos of one consistent person on your page, open or blurred according to the audience you chose. A page that has published AI media carries a line saying some content is AI-generated.',   // what the fan sees
            'limits'   => array('influencers', 'ai_credits', 'storage_gb'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Do I need my own photos?', 'a' => 'No. You can describe a face instead and the studio generates a reference image, then builds the training set from it. Using your own photos gives you a character that looks like a specific person.'),
                array('q' => 'How long does training take?', 'a' => 'A few minutes, once per character. After that, every image is the same person and no retraining is needed.'),
                array('q' => 'Can I make video as well?', 'a' => 'Yes. Any generated still can be turned into a short clip with a motion prompt.'),
                array('q' => 'What does it cost?', 'a' => 'Generation uses the AI credits included with your plan, and you can top up at any time. Training is included with your plan.'),
            ),
            'cta' => array('title' => 'Build your AI influencer today', 'text' => 'Train once, then generate and sell from one page.'),
        ),

        'dm-agent' => array(
            'nav_title'   => 'DM agent',
            'title'       => 'AI DM Agent for Your Inbox',
            'description' => 'Answer fan messages in your voice, around the clock. Drafts wait for approval until you let the agent send on its own.',
            'hero' => array(
                'title' => 'Your inbox, answered in your voice',
                'lead'  => 'The DM agent is an inbox assistant that drafts replies in your voice; you approve each one, or switch it to send on its own. It also sends welcome messages and can attach paid content.',
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
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Set the Voice', 'Write how you talk, the topics the agent must never touch, your quiet hours and how many replies it may send in a row.'),
                array('Work the Queue', 'Each draft waits beside the fan\'s message. Approve it, edit it or dismiss it, and switch to automatic sending only when the drafts stop needing edits.'),
                array('Add Welcome Messages', 'Give new followers, new subscribers and first messages their own message, with library media and a price when you want one.'),
            ),
            'fan_side' => 'A fan writes to you from the inbox on your page. The first automated reply to that fan carries a line saying replies may be automated. Paid media in a message shows blurred with its price until the fan unlocks it with credits.',   // what the fan sees
            'limits'   => array('inbox_ai', 'ai_credits'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Does it send without me?', 'a' => 'Only if you turn that on. By default every reply waits in a queue for your approval.'),
                array('q' => 'Will fans know it is AI?', 'a' => 'Yes. The first automated reply to each fan carries a line saying replies may be automated, and the agent never denies being AI. If a fan asks whether a real person is replying, a draft that does not confirm it is AI is held for you. You decide what the agent can talk about, and you can take over any conversation at any time.'),
                array('q' => 'Can it sell content in a message?', 'a' => 'Yes. Welcome and trigger messages can carry media with a price, and the fan unlocks it with credits.'),
                array('q' => 'What stops it going off the rails?', 'a' => 'Topics to avoid, quiet hours, a maximum number of replies in a row, and the approval queue until you switch it off.'),
            ),
            'cta' => array('title' => 'Let your inbox answer itself', 'text' => 'Drafts in your voice, approval until you trust it, paid content when it fits.'),
        ),

        'payouts' => array(
            'nav_title'   => 'Payouts',
            'title'       => 'Creator Payouts',
            'description' => 'Every membership, unlock, bundle, service and event sale lands in one balance after your plan\'s platform fee. Cash out on request; event earnings release later.',
            'hero' => array(
                'title' => 'Everything you earn, in one balance',
                'lead'  => 'A creator payout on {site} is the transfer of your balance to your bank, made when you ask for it. Fans pay by card for memberships and with a credit wallet for everything else. Your earnings collect in one place, net of your plan\'s platform fee, and you cash out to your bank whenever you want.',
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
                    'title'  => 'One percentage per plan',
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
                        'Event earnings are released after the event has taken place',
                        'Available in every country our payment processor supports for creator payouts',
                        'A reversed sale or a dispute is shown in your balance, with the fan, the item and the date',
                    ),
                ),
            ),
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Connect Your Bank', 'Connect it once. Identity and bank details are handled by a secure payment processor, not stored on our servers.'),
                array('Sell From Your Page', 'Every membership payment, unlock, bundle, booking and ticket lands in the same balance, net of your plan\'s platform fee.'),
                array('Cash Out on Request', 'Ask for a payout whenever the balance is above {payout_min}. Each payout and its status is listed in your settings.'),
            ),
            'fan_side' => 'Fans never see your payout details. A fan pays for a membership by card and for everything else from a credit wallet, at 10 credits to the dollar. What the fan paid, for which item and on what date is listed on your side.',   // what the fan sees
            'limits'   => array('fee_percent'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'How do fans pay?', 'a' => 'Memberships bill by card on your schedule. Everything else is bought with a credit wallet, which fans top up in one tap.'),
                array('q' => 'When can I cash out?', 'a' => 'The minimum payout is ' . Price::PAYOUT_MIN_LABEL . ', and payouts are made on request only. Connect your bank once, then cash out whenever your balance is above the minimum. We apply no platform hold, so our payment processor\'s own payout timing applies. Earnings from an event are released after the event has taken place. Payouts are available in every country our payment processor supports for creator payouts.'),
                array('q' => 'What does the platform take?', 'a' => 'A percentage of what you earn, set by your plan and shown on the pricing page.'),
                array('q' => 'What happens if a sale is reversed?', 'a' => 'A reversed sale takes the credits back on both sides and removes the access it paid for, and your balance shows it straight away.'),
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
                'lead'  => 'A membership is a paid tier on your page that fans join by card, on the schedule you set. Subscriber posts unlock for members and stay blurred for everyone else, and every payment lands in one balance you cash out to your bank.',
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
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Create a Tier', 'Give it a name, a price, a billing interval and the perks it carries. Add a free trial if you want one.'),
                array('Publish for Subscribers', 'Choose subscribers as the audience when you publish, and aim the post at one tier or at several.'),
                array('Bring Fans In', 'Share your page address and set a welcome message, so a new subscriber hears from you the moment they join.'),
            ),
            'fan_side' => 'A fan opens the membership tab on your page, picks a tier and pays by card. Subscriber posts unblur for that fan straight away, and each new post reaches them as a notice. The card is billed again on the tier\'s interval.',   // what the fan sees
            'limits'   => array('fee_percent'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'How do fans pay for a membership?', 'a' => 'By card, billed automatically on the interval you set for the tier: weekly, monthly or yearly.'),
                array('q' => 'Can I offer a free trial?', 'a' => 'Yes. Any tier can start with a free trial, and you choose how long it lasts.'),
                array('q' => 'How many tiers can I have?', 'a' => 'As many as you want. Two is a sound place to start, an easy entry tier and one higher tier.'),
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
                'lead'  => 'A pay-per-view post is a single post with a price on it that a fan unlocks from their credit wallet. Put a price on any post, message or bundle. It shows blurred until a fan unlocks it with credits from their wallet, so you earn from fans who will never subscribe.',
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
                    'text'   => 'Fans top up a credit wallet once (' . Price::CREDITS_PER_DOLLAR . ' credits = $1) and unlock in one tap.',
                    'points' => array(
                        'Optional automatic top-ups, so a fan never stops at an empty wallet',
                        'You earn the price in credits, net of your plan\'s platform fee, and cash out to your bank',
                    ),
                    'link' => array('See how payouts work', '/features/payouts'),
                ),
            ),
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Price a Post', 'Choose pay-per-view when you publish and set a price from 10 to 5000 credits.'),
                array('Price a Message', 'Attach media and a price to a message or a broadcast. It stays blurred in the inbox until the fan pays.'),
                array('Build a Bundle', 'Group media from your library into one set with one price.'),
            ),
            'fan_side' => 'The fan sees a blurred preview with the price, taps unlock and pays from the credit wallet. A fan with an empty wallet tops it up by card first. A bought bundle sits in the fan\'s purchases, ready to download.',   // what the fan sees
            'limits'   => array('fee_percent'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'How do fans pay for an unlock?', 'a' => 'With credits from their wallet. ' . Price::CREDITS_PER_DOLLAR . ' credits cost $1, and a wallet is topped up by card in one step.'),
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
                'lead'  => 'A link-in-bio page is one public page at your handle that holds every link, with each click tracked. Your page at {host}/@handle holds your links and everything you sell: memberships, pay-per-view posts, bundles, services and events. Fans pay by card or credit wallet, and earnings pay out to your bank.',
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
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Set Up the Page', 'Pick your handle, then add your bio and your colors.'),
                array('Add Your Links', 'Add each link you would put in a bio tool. Turn one off at any time without deleting it.'),
                array('Put It in Every Bio', 'Use the one address on every social profile, so every visitor arrives at the page that also takes payment.'),
            ),
            'fan_side' => 'A visitor lands on your page at your handle and sees your links first, with your posts, tiers, services and events under them. Following you is free. Paying takes a card for a membership and the credit wallet for everything else.',   // what the fan sees
            'limits'   => array('custom_domain'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Is it a replacement for my current link-in-bio tool?', 'a' => 'Yes. It holds your links like any bio page, and it also takes payments for memberships, pay-per-view, bundles, services and events.'),
                array('q' => 'Can I see which links get clicked?', 'a' => 'Yes. Every link counts its clicks, and the totals show in your dashboard.'),
                array('q' => 'Can I use my own domain?', 'a' => 'Yes, on the {domain_plan} plan. Point your domain at us and your page answers on it.'),
                array('q' => 'How do I get paid?', 'a' => 'Every sale lands in one balance, net of your plan\'s platform fee, and you cash out to your bank on request with a ' . Price::PAYOUT_MIN_LABEL . ' minimum.'),
            ),
            'cta' => array('title' => 'Put one link in every bio', 'text' => 'Links, memberships and sales on one page.'),
        ),

        'publishing' => array(
            'nav_title'   => 'Publishing',
            'title'       => 'Post to Ten Platforms at Once',
            'description' => 'Publish to your page and ten platforms from one studio, with AI captions in your voice, drafts and scheduling.',
            'hero' => array(
                'title' => 'Publish Everywhere from One Studio',
                'lead'  => 'Publishing on {site} is one upload that goes to your page and your social accounts at the same time. The networks: {networks}. Captions are written in your voice and trimmed for each network.',
            ),
            'video'   => '',
            'related' => array('link-in-bio', 'ai-influencer', 'dm-agent'),
            'rows' => array(
                array(
                    'title'  => 'Ten platforms, one upload',
                    'text'   => 'Connect your accounts once. Each post goes to your page and to the networks you pick.',
                    'points' => array(
                        '{networks}',
                        'Cross-post to Fanvue as well',
                        'Choose per post whether it also appears on your page',
                    ),
                    'link'   => array('See All Ten Integrations', '/integrations'),
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
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Connect Your Accounts', 'Connect each social account once. There is no limit on how many you connect.'),
                array('Write the Post Once', 'Add the media, draft the caption in your voice, and pick the networks and the audience.'),
                array('Publish, Schedule or Save', 'Send it now, set a date and time in your own time zone, or keep it as a draft.'),
            ),
            'fan_side' => 'On your page the post appears in your feed, open or blurred according to the audience you chose. On each social network it appears as a normal post from your own account, with no outside link added to it.',   // what the fan sees
            'limits'   => array('automations', 'ai_credits'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Which networks can I post to?', 'a' => '{networks}, plus Fanvue cross-posting.'),
                array('q' => 'Do you add a link to my social posts?', 'a' => 'No. Posts go out as you wrote them, so the networks do not hold back reach for an outside link.'),
                array('q' => 'Can I schedule posts?', 'a' => 'Yes. Schedule any post for a date and time, keep it as a draft, or publish it now.'),
                array('q' => 'Who writes the captions?', 'a' => 'You do, or the studio drafts one in your brand voice and trims it for each network. You can edit every caption before it goes out.'),
            ),
            'cta' => array('title' => 'Post once, everywhere', 'text' => 'Your page and ten platforms from one studio.'),
        ),

        'services-and-events' => array(
            'nav_title'   => 'Services and events',
            'title'       => 'Sell Services, Events and 1:1 Video Calls',
            'description' => 'Take bookings for services, sell event tickets and host live calls in a built-in video room, all from your creator page.',
            'hero' => array(
                'title' => 'Bookings, Tickets and Live Calls',
                'lead'  => 'Services and events are bookings and tickets sold from your page, delivered in a built-in video room. Sell a service, a seat at a live event or a 1:1 session from your page. Calls run in a built-in video room, so fans join without a separate meeting app.',
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
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('List a Service', 'Write what you offer, set a price, and add the access details the buyer sees after paying.'),
                array('Create an Event', 'Set the date and the number of seats, and choose whether it is free or paid.'),
                array('Run the Call', 'Open the built-in video room, admit people from the waiting room and use chat, screen share and the host controls.'),
            ),
            'fan_side' => 'A fan books a service or buys a ticket from its own page, paying with credits. For a service, the access details you wrote appear after purchase. For an event, the fan gets reminder emails with the meeting link and joins the video room in the browser.',   // what the fan sees
            'limits'   => array('fee_percent'),   // PlanTiers rows and features that bear on this page
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
                'lead'  => 'A custom domain is your own web address pointing at your creator page. On the {domain_plan} plan your creator page can answer on a domain you own. Fans see your address, buy and join as usual, and stay signed in when they arrive from our site.',
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
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Add the Domain', 'Enter the domain in your settings. The bare domain and its www version are added together.'),
                array('Copy the Records', 'Copy the DNS records shown there into your domain provider, exactly as written.'),
                array('Wait for the Check', 'We check the records and switch the domain on. The secure certificate is issued automatically.'),
            ),
            'fan_side' => 'A fan who types your domain sees your page, your services and your events on your own address. A fan already signed in on our site arrives signed in. Payment works from the same card and the same wallet as before.',   // what the fan sees
            'limits'   => array('custom_domain'),   // PlanTiers rows and features that bear on this page
            'faq' => array(
                array('q' => 'Which plan includes custom domains?', 'a' => 'The {domain_plan} plan.'),
                array('q' => 'What do I need to change at my domain provider?', 'a' => 'A verification record plus the routing records we show you (two for a bare domain and its www version), all shown in settings with the exact values to copy.'),
                array('q' => 'Do I need my own certificate?', 'a' => 'No. A certificate is issued automatically once your records point at us.'),
                array('q' => 'Will fans have to sign in again?', 'a' => 'No. A signed-in fan is handed to your domain with their session, so they can buy and join straight away.'),
            ),
            'cta' => array('title' => 'Put your page on your domain', 'text' => 'Your address, our checkout and payouts.'),
        ),

        'automations' => array(   // 2026-10-15. Every line traces to cron/scheduler.php, SchedulerRulesModel, AutoPostService, MessageBlastService and InboxAutomationService.
            'nav_title'   => 'Automations',
            'title'       => 'Content Automations for Creators: Post and Message on Schedule',
            'description' => 'Set an automation once and it creates, captions and publishes a post every day or on the days you pick, or sends scheduled messages to the fans you choose.',
            'hero' => array(
                'title' => 'Your Content on Autopilot',
                'lead'  => 'An automation is a rule that runs on a schedule. A post automation makes an image or a short video, writes the caption and publishes it to your page and your social accounts. A message automation sends a message to the fans you pick. You set it once and it runs at your local time.',
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
                        'Free for everyone, or for subscribers or a chosen membership tier',
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
                    'text'   => 'A message automation sends to everyone, your followers, your subscribers, lapsed subscribers or your buyers, with your text or a message written by AI in your voice.',
                    'link'   => array('See the AI inbox', '/features/dm-agent'),
                ),
            ),
            'steps' => array(
                array('Create the Automation', 'Open the Studio, add an automation and choose a post or a message.'),
                array('Pick What It Makes', 'For a post: the image source, photo or video, the caption and who can see it. For a message: who receives it and the text.'),
                array('Set the Schedule', 'Choose every day or the weekdays you want and the time. It runs on its own from then on.'),
            ),
            'fan_side' => 'Fans see ordinary posts on your page and on your social accounts, published at a steady rhythm, and messages in their inbox. AI-made media carries the AI labels each platform asks for.',
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
            'title'       => 'Creator Analytics: Revenue, Content and Audience in One Dashboard',
            'description' => 'See revenue, subscribers, views and engagement over 7, 30 or 90 days, with engagement from your social accounts, link clicks and tracking links in one dashboard.',
            'hero' => array(
                'title' => 'Know What Works',
                'lead'  => 'The dashboard shows how your page and your posts are doing over the last 7, 30 or 90 days, each compared with the period before. Revenue, subscribers, followers, views, posts and engagement sit at the top, with a tab each for revenue, content, audience and customers.',
            ),
            'video'   => '',
            'related' => array('publishing', 'automations', 'payouts'),
            'rows' => array(
                array(
                    'title'  => 'Revenue you can trace',
                    'text'   => 'See revenue by offering and over time, with your recent sales listed and ready to export as a spreadsheet.',
                ),
                array(
                    'title'  => 'Every post and where it went',
                    'text'   => 'One table lists every post with its views, engagement and sales, and where it was shared. Engagement on the copies you cross-posted comes back from your social accounts.',
                    'points' => array(
                        'Delivery status for each platform',
                        'Social engagement refreshed every few hours',
                    ),
                ),
                array(
                    'title'  => 'Where your audience comes from',
                    'text'   => 'Follower growth, the sources and pages that send visitors, link clicks and tracking links that show which links bring signups and sales.',
                ),
                array(
                    'title'  => 'When fans are active',
                    'text'   => 'A heatmap shows the hours your audience is active, next to your customers, subscribers and the content that earns the most.',
                ),
            ),
            'steps' => array(
                array('Publish as Usual', 'Post to your page and your social accounts. Every view, like and sale is counted.'),
                array('Open the Dashboard', 'Pick 7, 30 or 90 days and see each number against the period before.'),
                array('Follow the Tabs', 'Revenue, content, audience and customers each have their own tab.'),
            ),
            'fan_side' => 'Fans see nothing of it. The dashboard is private to your account.',
            'faq' => array(
                array('q' => 'Which numbers are at the top of the dashboard?', 'a' => 'Revenue, subscribers, followers, views, posts and engagement, for the period you pick, each compared with the period before.'),
                array('q' => 'Does it include my social accounts?', 'a' => 'Yes. Engagement on the posts you cross-posted through the studio comes back from the connected accounts and refreshes every few hours.'),
                array('q' => 'Can I export my sales?', 'a' => 'Yes. Recent sales export as a CSV file from the revenue tab.'),
                array('q' => 'Can I track where signups come from?', 'a' => 'Yes. Create tracking links and the dashboard shows the clicks, signups and sales each one brings.'),
            ),
            'cta' => array('title' => 'See what is working', 'text' => 'Revenue, content and audience in one dashboard.'),
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
                'lead'  => 'LoRA character training teaches a model one face from {min_photos} to {max_photos} photos, so every later image is the same person. {site} trains it for you from those photos, or from a face you describe. You never touch a training script or a GPU, and every image after that is the same person.',
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
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Build the Set', 'Upload {min_photos} to {max_photos} photos of one person, or describe a face and approve the reference image.'),
                array('Train Once', 'Start the run. It takes a few minutes and is included with your plan.'),
                array('Generate', 'Describe a scene and get images of the character in it. Pin a seed to repeat a look exactly.'),
            ),
            'fan_side' => 'Fans never see the training. They see photos and short videos of one consistent character on your page. A page that has published AI media carries a line saying some content is AI-generated, and AI media is labeled when it is cross-posted.',   // what the fan sees
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
                'lead'  => 'A consistent AI model face is one character that stays recognisable across every generated photo and video. An AI model only works if fans see the same person every time. {site} trains a character on one face, so photos and videos stay on model across scenes, outfits and lighting.',
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
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Train One Face', 'Train a character on one person, from photos or from a face you describe.'),
                array('Keep the Matching Takes', 'Generate a scene, keep the images that are right, and pin the seed of a look you want again.'),
                array('Make the Video', 'Pick an approved still and describe the movement. The clip starts from that exact frame.'),
            ),
            'fan_side' => 'A fan scrolling your page sees the same person in every post, across scenes, outfits and lighting. A page that has published AI media carries a line saying some content is AI-generated.',   // what the fan sees
            'limits'   => array('influencers', 'ai_credits'),   // PlanTiers rows and features that bear on this page
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
            'description' => 'AI tools for running creator accounts: AI influencers, scheduled posting to ten platforms, a DM agent, pay-per-view and payouts in one place.',
            'hero' => array(
                'title' => 'AI OFM Tools for Running Creator Accounts',
                'lead'  => 'AI OFM tools are the software a creator account runs on: generation, posting, inbox replies and selling in one place. Create the content, post it everywhere, answer the inbox and get paid from one account. {site} brings AI influencers, publishing, a DM agent, memberships and pay-per-view together, with team seats for the people who help you.',
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
            'steps' => array(   // how a creator sets this up, in order (SEO audit 2026-10-12: feature pages were thin)
                array('Train the Character', 'Train an AI influencer once, from photos or a described face.'),
                array('Connect the Networks', 'Connect your social accounts, so one post reaches your page and all of them.'),
                array('Set the Inbox', 'Write the DM agent\'s voice and limits, and keep approval on at first.'),
                array('Price the Offers', 'Create membership tiers and put prices on your strongest posts.'),
            ),
            'fan_side' => 'A fan follows for free, joins a tier by card and unlocks single posts from a credit wallet. The first automated reply in the inbox says replies may be automated. A page that has published AI media says so.',   // what the fan sees
            'limits'   => array('seats', 'influencers', 'ai_credits', 'automations', 'inbox_ai'),   // PlanTiers rows and features that bear on this page
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
