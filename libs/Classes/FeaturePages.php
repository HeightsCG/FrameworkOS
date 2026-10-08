<?php
/**
 * Feature pages under /features/<slug>, rendered by PagesController::featuresAction() through
 * app/views/pages/feature.php. Adding a page here puts it on the site, in the sitemap, in
 * llms.txt and in the article linker.
 */
class FeaturePages {

    const PAGES = array(

        'character-generation' => array(
            'nav_title'   => 'Character generation',
            'title'       => 'AI character generation',
            'description' => 'Train one AI character once, then generate photos and video of the same person on demand, and sell them from your page.',
            'hero' => array(
                'title' => 'One character. Unlimited photos and video.',
                'lead'  => 'Train a character once from your photos or from a description. After that, every image and clip is the same person, generated in about a minute and ready to post or sell.',
            ),
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
                array('q' => 'How long does training take?', 'a' => 'A few minutes, once per character. After that, images generate in about a minute and no retraining is needed.'),
                array('q' => 'Will every image look like the same person?', 'a' => 'That is the point of training. The character keeps the same face across scenes, outfits and lighting.'),
                array('q' => 'Can I make video as well?', 'a' => 'Yes. Any generated still can be turned into a short clip with a motion prompt.'),
                array('q' => 'What does it cost?', 'a' => 'Generation uses the AI credits included with your plan, and you can top up at any time. Training is included with your plan.'),
            ),
            'cta' => array('title' => 'Build your character today', 'text' => 'Train once, then generate and sell from one page.'),
        ),

        'dm-agent' => array(
            'nav_title'   => 'DM agent',
            'title'       => 'AI DM agent for your inbox',
            'description' => 'Answer fan messages in your voice, around the clock. Drafts wait for approval until you let the agent send on its own.',
            'hero' => array(
                'title' => 'Your inbox, answered in your voice',
                'lead'  => 'The DM agent reads a fan message, drafts a reply the way you write, and either holds it for your approval or sends it for you. It also sends welcome messages and can attach paid content.',
            ),
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
            'title'       => 'Creator payouts',
            'description' => 'Memberships, unlocks, bundles, services and events all land in one balance, and you cash out to your bank.',
            'hero' => array(
                'title' => 'Everything you earn, in one balance',
                'lead'  => 'Fans pay by card for memberships and with a credit wallet for everything else. Your earnings collect in one place, net of your plan fee, and you cash out to your bank whenever you want.',
            ),
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
            ),
            'cta' => array('title' => 'Start earning from one page', 'text' => 'Memberships, unlocks, services and events, paid out to your bank.'),
        ),
    );
}
