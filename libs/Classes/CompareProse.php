<?php
/**
 * The page-specific prose on the compare, alternatives, best-of and monetize pages (HeyCatch c_thin, 2026-10-12):
 * at least 800 words of paragraphs per page that exist nowhere else on the site. Every competitor fact is one that
 * PagesController::COMPETITORS already carries with its source and check date; the paragraphs link the same pages.
 * Our own numbers are placeholders filled from config ({site}, {fee_short}, {fee_creator}, {fee_studio}, {creator_price},
 * {studio_price}, {payout_min}, {credits_creator}, {credits_studio}, {storage_creator}, {storage_studio}, {inf_creator},
 * {inf_studio}), so nothing here goes stale. The worked scenario is computed (scenario()), never typed.
 * Copy rules: plain, specific, creators' words; the all-in-one platform first, the fee as a supporting point; nothing invented.
 */
class CompareProse {

    /** The one line that replaces the shared product blocks every page used to repeat. */
    public static function links_line(): string {
        return Sections::open('white') . '<p class="sx-p">Everything about ' . Sections::e(Main::site_name()) . ' itself lives on its own pages: <a href="/features">features</a>, <a href="/pricing">pricing and limits</a> and <a href="/features/payouts">payouts</a>.</p>' . Sections::close();
    }

    /** Placeholders -> config values. */
    public static function vars(): array {
        $v = array('{site}' => Main::site_name(), '{fee_short}' => PagesController::fee_short(), '{payout_min}' => Price::PAYOUT_MIN_LABEL, '{credits_per_dollar}' => (string) (int) Price::CREDITS_PER_DOLLAR);
        foreach (PagesController::selling_tiers() as $t) {
            $k = strtolower((string) $t['key']);
            $v['{fee_' . $k . '}'] = (string) (int) $t['limits']['fee_percent'];
            $v['{' . $k . '_price}'] = '$' . number_format((int) $t['price']);
            $v['{credits_' . $k . '}'] = number_format((int) ($t['limits']['ai_credits'] ?? 0));
            $v['{storage_' . $k . '}'] = (string) (int) ($t['limits']['storage_gb'] ?? 0) . ' GB';
            $v['{inf_' . $k . '}'] = (string) (int) ($t['limits']['influencers'] ?? 0);
        }
        return $v;
    }

    public static function fill(string $s): string { return strtr($s, self::vars()); }

    /** Render one page's sections: each ['h' => heading, 'lead' => optional, 'p' => [paragraphs]] alternating backgrounds. */
    public static function render(array $sections, string $first_bg = 'white'): string {
        $out = ''; $bg = $first_bg;
        foreach ($sections as $sec) {
            $paras = array_map(array(__CLASS__, 'fill'), (array) $sec['p']);
            $out .= Sections::prose($bg, self::fill((string) $sec['h']), $paras, self::fill((string) ($sec['lead'] ?? '')));
            $bg = $bg === 'white' ? 'alt' : 'white';
        }
        return $out;
    }

    /** "source, checked September 25, 2026" link text for a competitor row. */
    private static function src(array $c, string $row, string $label = 'source'): string {
        $url = (string) ($c[$row]['source'] ?? $c['url']);
        return '[' . $label . '](' . $url . ')' . (!empty($c[$row]['reported']) ? ' (third-party reporting)' : '');
    }

    /**
     * The worked scenario, computed from the competitor's sourced rate (fee_math) and our plans: what a typical creator on
     * that platform keeps there and here on the same gross, with card processing named as separate everywhere.
     */
    public static function scenario(array $c, int $gross, string $what): array {
        $site = Main::site_name(); $money = function ($n) { return '$' . number_format((float) $n, 0); };
        $paras = array();
        $opts = (array) ($c['fee_math'] ?? array());
        $lines = array();
        foreach ($opts as $o) {
            $fee = $gross * ((float) $o['percent']) / 100 + (float) ($o['monthly'] ?? 0);
            $lines[] = $o['label'] . ' keeps ' . $money($fee) . ' of that' . ((float) ($o['monthly'] ?? 0) > 0 ? ' (' . (int) $o['percent'] . '% plus the plan price)' : ' (' . (int) $o['percent'] . '%)') . ', so ' . $money($gross - $fee) . ' is left before card processing, by its [own pricing page](' . $o['source'] . ').';
        }
        $ours = array(); $tiers = PagesController::selling_tiers(); usort($tiers, function ($a, $b) { return (int) $a['rank'] <=> (int) $b['rank']; });
        foreach ($tiers as $t) {
            $fee = $gross * ((int) $t['limits']['fee_percent']) / 100 + (int) $t['price'];
            $ours[] = 'the ' . $t['name'] . ' plan takes ' . (int) $t['limits']['fee_percent'] . '% plus $' . number_format((int) $t['price']) . ' a month, ' . $money($fee) . ' in total, leaving ' . $money($gross - $fee);
        }
        $paras[] = 'Take a creator on ' . $c['name'] . ' with ' . $money($gross) . ' a month in ' . $what . '. The numbers are round and hypothetical, chosen to show the arithmetic rather than to promise anything.';
        if ($lines) { $paras[] = implode(' ', $lines); }
        $paras[] = 'On ' . $site . ' the same ' . $money($gross) . ' works like this: ' . implode('; ', $ours) . '. Card processing is charged separately on every option above, so the gap between platforms is the platform fee and the plan price, nothing else.';
        $low = reset($tiers); $high = end($tiers);
        if ($low && $high && $low !== $high) {
            $d = ((int) $low['limits']['fee_percent'] - (int) $high['limits']['fee_percent']) / 100;
            if ($d > 0) {
                $cross = ceil(((int) $high['price'] - (int) $low['price']) / $d / 100) * 100;
                $pick = $gross < $cross ? $low['name'] : $high['name'];
                $paras[] = 'The two ' . $site . ' plans cross at about ' . $money($cross) . ' a month in sales. At ' . $money($gross) . ' this ' . $c['name'] . ' creator is ' . ($gross < $cross ? 'below' : 'above') . ' that line, so ' . $pick . ' is the cheaper plan for them, by ' . $money(abs(($gross * ((int) $low['limits']['fee_percent']) / 100 + (int) $low['price']) - ($gross * ((int) $high['limits']['fee_percent']) / 100 + (int) $high['price']))) . ' a month. The plan also sets the included AI credits, storage and AI influencers, so heavy use of the AI tools can tip the choice before the fee does.';
            }
        }
        return $paras;
    }

    /** The sections for one compare page. */
    public static function compare(string $slug): array {
        $c = PagesController::COMPETITORS[$slug] ?? null;
        if (!$c) { return array(); }
        $checked = date('F j, Y', strtotime($c['checked']));
        $m = 'compare_' . $slug;
        return method_exists(__CLASS__, $m) ? self::$m($c, $checked) : array();
    }

    // ---------------------------------------------------------------------------------------------------- Fanvue
    private static function compare_fanvue(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Fanvue for, and why?', 'p' => array(
                'Fanvue is a subscription platform where fans pay for posts, tips and paid messages. Its recent features are built around AI: voice calls, voice notes and analytics are listed on its own site (' . self::src($c, 'ai') . ', checked ' . $checked . '). A creator who sells one subscription and wants those AI tools inside the same app is the creator Fanvue is built for. It also welcomes AI-generated creator accounts, which most subscription platforms do not say out loud.',
                'The fit is weaker when your income comes from more than one thing. Fanvue does not publish social posting or a services and events layer (' . self::src($c, 'socials') . '). A creator who also sells coaching calls, ticketed sessions or bundles of past work runs those somewhere else and links out. That is the difference {site} is built around. The page, the paid posts, the bundles, the services, the events and the inbox sit in one place. The studio publishes each post to nine social networks at once.',
                'Keep Fanvue if your audience is already there, you sell nothing but the subscription and paid messages, and the AI voice tools matter to your fans. Consider the move if you want one page that sells every format and a fee that falls as you grow.',
            )),
            array('h' => 'What does moving from Fanvue to {site} involve?', 'p' => array(
                'Start with your own files, not with Fanvue. Fanvue does not publish whether a creator can export the fan list (' . self::src($c, 'ownership') . '), so plan on telling fans yourself: a pinned post, a paid-message broadcast and the new link in every social bio. Upload your library to {site} first, set two membership tiers and put your strongest pieces behind pay-per-view, so the page has something to buy on the day you announce it.',
                'Money already earned on Fanvue follows Fanvue\'s terms. Its standard pending period is seven days and can run to twenty-eight (' . self::src($c, 'payout') . '), and it pays by bank transfer, crypto or third-party wallet. Request what is withdrawable before you stop posting there, and expect the last balance to clear on that schedule rather than on yours. On {site} every sale lands in one balance, net of your plan\'s fee, and you cash out to your bank on request from a {payout_min} balance with no platform hold.',
                'Run both for one full billing cycle. Fans who renew on Fanvue keep renewing until you turn the page off, and {site} can cross-post to Fanvue from the same studio, so the Fanvue page stays fed while people move. Nothing has to be cancelled on day one, and renewals on the old page lapse on their own once you stop posting there.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Fanvue keeps 20% of gross revenue from paid services and the creator receives 80% (' . self::src($c, 'fee') . ', checked ' . $checked . '). {site} charges {fee_short} plus a monthly plan price. A small page pays a plan price it would not pay on Fanvue, and a larger page pays a much smaller share. The worked scenario below shows where that crosses.',
                'Fanvue\'s AI tools are inside the fan app: a fan can hear a voice note or take an AI voice call on the platform (' . self::src($c, 'ai') . '). {site}\'s AI sits on the creator side. You train an AI influencer from photos or a description and generate images and short videos of that character. AI captions come with every post, and a DM agent drafts replies in your voice for you to approve. If your fans buy the voice calls themselves, that is a feature you give up; if you want to generate and sell the content, that is the feature you gain.',
                'Fanvue publishes an open API and an app store for third-party tools, which suits creators who run their business through other software. {site} offers a Claude connector instead, so an AI assistant can draft posts, read your analytics and manage your page through the same account. Both are ways to automate; they point at different tools.',
            )),
            array('h' => 'A worked scenario: a Fanvue creator with 300 subscribers', 'p' => self::scenario($c, 3000, 'subscriptions and paid messages (300 fans at $10)')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- OnlyFans
    private static function compare_onlyfans(array $c, string $checked): array {
        return array(
            array('h' => 'Who is OnlyFans for, and why?', 'p' => array(
                'OnlyFans is the largest subscription platform, and its size is the reason to be there: third-party reporting puts it at more than 370 million registered users and 4 million creators in 2024 (' . self::src($c, 'content') . '). Fans already know how to pay there, the subscription, tips and pay-per-view model is familiar, and creators from athletes to musicians to adult creators use it. If your audience searches for you on OnlyFans by name, the platform does the first part of the selling for you.',
                'Everything on OnlyFans happens inside OnlyFans. It does not publish automatic posting to your social accounts, an AI toolset or an export of your subscriber list (' . self::src($c, 'ownership') . '). The page is the product, and the fan relationship lives in its app. That is fine for a creator who sells one subscription and promotes it by hand, and it is the limit a creator hits when the business grows past one format.',
                '{site} is built for the second case. One public page sells memberships, pay-per-view posts, bundles, services and live events. A studio publishes to nine social networks, AI influencers and an AI-assisted inbox come with it, and payouts go to your bank. Its platform fee is a supporting point, {fee_short} against the 20% OnlyFans deducts from each fan payment under its own terms (' . self::src($c, 'fee') . ', checked October 11, 2026).',
            )),
            array('h' => 'What does moving from OnlyFans to {site} involve?', 'p' => array(
                'Because OnlyFans publishes no subscriber export, the audience moves one fan at a time, through your own messages. Pin a post with the new link, send a mass message that says what the new page adds (bundles, services, events, the things OnlyFans does not sell), and change every social bio to the new address. Creators who make the new page better rather than merely cheaper keep more of the audience.',
                'Payouts already earned stay on OnlyFans\' schedule. Third-party reporting describes bank transfer and e-wallet payouts after a holding period of about seven days (' . self::src($c, 'payout') . '). OnlyFans\' own terms say earnings become available for withdrawal once reflected in the account and above its minimum payout amount (' . self::src($c, 'fee', 'terms') . '). Withdraw what is available before you wind the page down. On {site}, sales land in one balance net of your fee and pay out to your bank on request from {payout_min}, with no platform hold on content, membership or service sales.',
                'Keep the OnlyFans page live for a full billing cycle while fans move. Give the new page something OnlyFans never had: a bundle of your back catalogue, a service with a fixed price, or a ticketed live session. Renewals on OnlyFans lapse on their own as you stop posting there; nothing has to be cancelled on day one.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'You trade the biggest audience for a page you own. OnlyFans brings discovery to creators whose fans look for them there. {site} brings a page whose audience list and media you can export at any time. Its studio pushes every post to the networks where discovery actually happens for most creators. If your traffic comes from OnlyFans search, the move costs you that; if it comes from your socials, the move costs you nothing and gives you the publishing tool.',
                'You trade a single familiar format for five. OnlyFans sells subscriptions, tips and pay-per-view (' . self::src($c, 'content') . '). {site} adds bundles, services and events, and prices each one in credits from a wallet fans top up, so a fan who already loaded the wallet buys the next thing without re-entering a card. Memberships stay on the card, billed monthly, as fans expect.',
                'You trade a flat 20% for a plan price plus a lower share. The share is {fee_short}, and plan charges are monthly and non-refundable, so the fee comparison depends on volume. The scenario below runs the numbers on a typical page.',
            )),
            array('h' => 'A worked scenario: an OnlyFans creator with 500 subscribers', 'p' => self::scenario($c, 5000, 'subscriptions, tips and pay-per-view (500 fans at $10)')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Patreon
    private static function compare_patreon(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Patreon for, and why?', 'p' => array(
                'Patreon is a membership platform for creators who publish on a schedule: video makers, podcasters, writers and artists. They get monthly or annual paid tiers, digital products, hosted video and community chats in one place (' . self::src($c, 'content') . ', checked ' . $checked . '). A podcaster with bonus episodes or a writer with a members-only newsletter is exactly who it is built for.',
                'Patreon\'s model is the patron, not the purchase. Pay-per-view posts, paid messages, ticketed live sessions and services are not what it sells, so a creator whose income depends on single unlocks or on booked time runs those elsewhere. Patreon also does not publish automatic posting to social accounts; its share menu lets you copy the page link yourself (' . self::src($c, 'socials') . ').',
                '{site} covers the same memberships, with as many tiers as you want. It adds the formats Patreon leaves out: pay-per-view posts, bundles, services, events and paid messages, all on one page. A studio publishes to nine social networks, and the inbox sells. For a creator who only needs tiers and a community, Patreon is a complete answer; for one who sells in several formats, it is one of several tools.',
            )),
            array('h' => 'What does moving from Patreon to {site} involve?', 'p' => array(
                'Patreon lets you take your members with you. The Relationship manager exports the member list, including emails, as a CSV (' . self::src($c, 'ownership') . '). Download it before you announce anything, then email members with the new link and the reason: the tier they know, plus the posts, bundles or sessions they could not buy on Patreon. {site} imports nothing automatically, so the email is the migration.',
                'Money on Patreon follows Patreon\'s payout rules. Withdrawals are creator-initiated, by bank transfer or online wallet; a five-day hold applies after you add a payout method, and one-time purchase funds stay pending up to seven days, or seventy-five for iOS in-app purchases (' . self::src($c, 'payout') . '). Clear the balance before you move. On {site} every sale lands in one balance net of your plan\'s fee, and you cash out on request from {payout_min} with no platform hold.',
                'Rebuild the tiers before the email goes out: the same names, the same prices, the same perks, so a member sees the page they expected. Keep Patreon live for one billing cycle so nobody loses access mid-month, and let the Patreon renewals lapse as members re-join on the new page.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Patreon charges a 10% platform fee for pages published after August 4, 2025, with payment processing from 2.9% plus $0.30, currency conversion and payout fees on top; older pages sit on legacy rates of 5%, 8% or 11% (' . self::src($c, 'fee') . ', checked ' . $checked . '). {site}\'s Creator plan also takes 10%, so at that level the comparison is the plan price against Patreon\'s extra fees, and the Studio plan\'s 3% is where the share drops. The scenario below runs it.',
                'Patreon hosts your video and runs community chats and polls inside the membership. {site} does not host a community chat. Its inbox handles one-to-one and broadcast messages with paid media attached, and its events sell seats to live sessions held in a built-in video room. A creator whose members gather in a chat loses that; a creator whose members pay for sessions gains the room.',
                'Patreon\'s AI is Clips, which suggests shareable clips from videos over eight minutes on iOS (' . self::src($c, 'ai') . '). {site}\'s AI drafts captions, answers DMs for your approval and builds an AI influencer whose images and videos you can sell. They are different tools for different work.',
                'Annual memberships are a Patreon feature worth naming. A member who prepays a year is a member you keep for a year. {site} bills memberships monthly, with free trials and promo codes to bring people in, so a creator whose income leans on annual prepayment gives that up and gains the trials instead.',
            )),
            array('h' => 'A worked scenario: a Patreon creator with 400 patrons', 'p' => self::scenario($c, 2400, 'memberships (400 patrons at $6)')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Fansly
    private static function compare_fansly(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Fansly for, and why?', 'p' => array(
                'Fansly is a subscription platform for creators, adult creators included. It sells subscriptions, pay-per-view posts, bundles, livestreams and paid messages, with public, ticketed and one-to-one streams on its own list of formats (' . self::src($c, 'content') . ', checked ' . $checked . '). Its For You feed shows creators to new viewers inside the app, which is the one thing most subscription platforms do not offer.',
                'A creator who streams, sells clips and wants in-app discovery is the Fansly creator. The platform keeps the audience inside its own feed, so growth there depends on that feed and on your own promotion. Fansly does not publish social posting, an AI toolset or an export of your fan list (' . self::src($c, 'ownership') . ').',
                '{site} is for the creator who sells more than the stream: memberships, pay-per-view, bundles, services and events on one page, published to nine social networks from one studio, with an AI-assisted inbox and AI influencers. It has no discovery feed of its own; the creator directory and the social publishing do that work instead, which suits creators whose audience already lives on their socials.',
            )),
            array('h' => 'What does moving from Fansly to {site} involve?', 'p' => array(
                'Fansly does not publish a fan export, so the move runs through your own announcements: a pinned post, a paid-message broadcast to every subscriber and the new link in every bio. Build the new page first so the announcement has somewhere to send people: the same tiers, the strongest pieces behind pay-per-view, a bundle of the back catalogue and, if you stream, a ticketed live session on the calendar.',
                'Fansly pays by ACH or SEPA bank transfer or e-wallet. Once verified, a creator can withdraw amounts above $20 as often as they like, and bank transfers typically arrive within three business days (' . self::src($c, 'payout') . '). Take the balance out before you stop posting. On {site} every sale lands in one balance net of your plan\'s fee, and you cash out on request from {payout_min} with no platform hold; event earnings release after the event has taken place.',
                'Keep both pages live for a billing cycle. Fansly\'s automated and keyword-triggered messages can keep telling subscribers where the new page is, and {site}\'s studio publishes to your socials so the move does not depend on the Fansly feed. Renewals on Fansly lapse on their own as you stop posting there.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Fansly takes a 20% fee and the creator keeps 80% of subscription and direct sales earnings (' . self::src($c, 'fee') . ', checked ' . $checked . '). {site} charges {fee_short} plus a plan price. A page with a few hundred dollars a month pays more here in plan price than it saves in fee. A page with a few thousand a month keeps noticeably more. The scenario below runs both.',
                'Livestreaming is the real difference. Fansly streams to fans in its app with public, ticketed and one-to-one options. {site} sells tickets to live events and one-to-one sessions and hosts the call in a built-in video room with a waiting room, chat, screen share and host controls; it is a scheduled session, not an always-on stream. A creator who streams daily to a feed loses that format; a creator who sells sessions gains a room that does not depend on Zoom.',
                'Discovery is the other. Fansly\'s For You feed can surface a new creator to viewers who never searched for them. {site} relies on the social networks: every post goes to nine of them from one studio, with the paid version behind the lock on the page. If your growth comes from Fansly\'s feed, test the move before you commit to it; if it comes from your socials, the studio does more for you than the feed did.',
            )),
            array('h' => 'A worked scenario: a Fansly creator with 250 subscribers', 'p' => self::scenario($c, 2500, 'subscriptions and pay-per-view (250 fans at $10)')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Ko-fi
    private static function compare_kofi(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Ko-fi for, and why?', 'p' => array(
                'Ko-fi is a creator support platform for artists, writers, musicians and streamers. One-time and recurring tips, membership tiers, a shop for physical and digital items, commissions and stream alerts all run from one page (' . self::src($c, 'content') . ', checked ' . $checked . '). Its defining choice is that it does not hold your money: payments go straight into your own connected payment account, with no payout schedule and no minimum balance (' . self::src($c, 'payout') . ').',
                'Ko-fi Free charges no service fee on one-time tips and goals and 5% on memberships, monthly tips, shop sales and commissions; Standard mode charges 5% on everything, with payment processing on top in both cases (' . self::src($c, 'fee') . '). For a creator whose income is mostly tips and occasional commissions, that is close to the cheapest way to take money online, and nothing on this page argues otherwise.',
                'What Ko-fi does not do is publish to your socials, run an inbox that sells, or host paid events and sessions (' . self::src($c, 'socials') . '). {site} is for the creator who has moved past tips. Memberships with as many tiers as you want, pay-per-view posts, bundles, services and events sit on one page. A studio publishes every post to nine networks, and an AI-assisted inbox and AI influencers come with it.',
            )),
            array('h' => 'What does moving from Ko-fi to {site} involve?', 'p' => array(
                'Ko-fi lets you leave with your people. Supporter and member lists, including supporter emails, download as CSV files (' . self::src($c, 'ownership') . '), so the migration is an email: the new link, the tier they know at the same price, and the things they could not buy before. Rebuild the tiers and the shop items as bundles or pay-per-view posts before you send it.',
                'There is no balance to wait for. Ko-fi paid you as each supporter paid, so there is nothing pending on Ko-fi\'s side when you move. On {site} the model is different. Sales land in one balance net of your plan\'s fee. Memberships bill the fan\'s card monthly and everything else is paid from a credit wallet. You cash out to your bank on request from {payout_min} with no platform hold.',
                'Keep the Ko-fi page as a tip jar if you like; many creators do. Point the memberships, the shop and the commissions at the new page, where a commission becomes a service with a fixed price and a delivery window, and keep the Ko-fi link for one-off thanks. Nothing has to be closed on day one.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'On fee alone, Ko-fi wins at small volume. Its 0% on one-time tips and 5% on the rest beats {site}\'s {fee_short} plus a monthly plan price until a page sells enough for the plan price to be small next to the sales. The scenario below shows the crossing point; below it, the reason to move is the formats and the publishing, not the fee.',
                'Ko-fi pays instantly to your own account. {site} pays on request from a balance, which adds a step. The balance is also one ledger across memberships, unlocks, bundles, services and events, with refunds and disputes handled there rather than in your processor dashboard. A creator who likes the money arriving as it is paid will prefer Ko-fi; a creator who wants one place to see every sale will prefer the balance.',
                'The publishing and the inbox are the gain. Ko-fi has no social scheduler and no AI (' . self::src($c, 'ai') . '). {site} publishes each post to nine networks with AI captions and drafts DM replies for your approval. Welcome and trigger messages go out with paid media attached, and ticketed live sessions run in its own video room. If none of that is work you do, Ko-fi remains the simpler tool.',
            )),
            array('h' => 'A worked scenario: a Ko-fi creator with 150 members', 'p' => self::scenario($c, 1500, 'memberships and shop sales (150 members at $10)')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Linktree
    private static function compare_linktree(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Linktree for, and why?', 'p' => array(
                'Linktree is the link-in-bio page most people recognise: one URL in your social bios that lists your links, collects contacts and, in selected countries, sells digital products and courses (' . self::src($c, 'content') . ', checked ' . $checked . '). Its Social Planner auto-posts to Instagram, TikTok, Facebook, LinkedIn, Pinterest and YouTube Shorts, with social scheduling listed on the Premium plan (' . self::src($c, 'socials') . ').',
                'A creator who needs a tidy link page, a mailing list and the occasional eBook or template sale is the Linktree creator, and the free plan with unlimited basic links costs nothing. Recurring memberships and bookings are not in its selling guide (' . self::src($c, 'content') . '), and paid access to private communities is listed for the US only. A creator whose income is monthly tiers or booked time builds that somewhere else and links to it.',
                '{site} is the link page that takes the payment itself. Your page at your handle holds tracked links plus memberships, pay-per-view posts, bundles, services and events. Fans pay by card or credit wallet, and the studio publishes to nine social networks. It replaces the link page and the thing the link page was pointing at.',
            )),
            array('h' => 'What does moving from Linktree to {site} involve?', 'p' => array(
                'The contacts come with you: Linktree exports audience contacts as a CSV from the Audience tab on every plan (' . self::src($c, 'ownership') . '). Download it, recreate the links on your {site} page as tracked links, and email the list once the memberships or products are live. The social bios change last, once the new page has something to sell.',
                'Sales already made settle on Linktree\'s terms. Product and course sales pay out to the bank account connected to your payment account on the daily, weekly or monthly schedule you chose, and affiliate commissions, US only, are usually paid monthly once they reach $10 (' . self::src($c, 'payout') . '). There is little to wait for. On {site} every sale lands in one balance net of your plan\'s fee and pays out on request from {payout_min} with no platform hold.',
                'Keep the Linktree page until the socials point at the new address, then let it go; there is no renewal to lapse. Social Planner users lose their scheduler at that point, which is why the studio\'s publishing to nine networks is usually the first thing a Linktree creator sets up on the new page.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Linktree\'s fee depends on the plan: 12% of digital product and course sales on Free, 9% on Starter and Pro, and 0% on Premium at $35 a month or $30 billed annually. Card processing and a $0.25 per-payout charge apply on every plan (' . self::src($c, 'fee') . ', checked ' . $checked . '). A Premium subscriber selling a few products pays no share at all, so on fee alone Linktree Premium beats {site} for product sales; the scenario below shows both options next to our plans.',
                'The formats are the difference. Linktree sells one-time digital products and courses; {site} sells memberships, pay-per-view, bundles, services and events, with an inbox that attaches paid media to messages. A creator whose income is a $20 template a few times a week has no reason to move; a creator who wants monthly tiers and booked sessions has every reason.',
                'Both publish to social networks. Linktree\'s planner covers six, including YouTube Shorts; {site}\'s studio covers nine and puts the paid version of each post behind the lock on your page, so the social post and the sale are one action. Linktree\'s AI writes captions and auto-replies to Instagram comments and DMs (' . self::src($c, 'ai') . '); {site}\'s AI does the captions, drafts DM replies for approval across the inbox, and builds AI influencers whose images you sell.',
            )),
            array('h' => 'A worked scenario: a Linktree creator selling $1,200 of products a month', 'p' => self::scenario($c, 1200, 'digital product and course sales')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Beacons
    private static function compare_beacons(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Beacons for, and why?', 'p' => array(
                'Beacons is a creator business platform: a link-in-bio page, a website, a store, email marketing and a media kit for brand deals, in one account (' . self::src($c, 'content') . ', checked ' . $checked . '). Digital products, appointments and imported physical products are on every plan; memberships billed weekly, monthly or yearly and unlimited courses with video hosting start on Creator Plus (' . self::src($c, 'fee') . ').',
                'The creator who does brand partnerships is the Beacons creator: the media kit updates itself, the audience manager runs the email list, and the store sells the products. Its social features track and analyse posts across connected accounts and run Instagram DM automations, but post publishing is not listed (' . self::src($c, 'socials') . '), so the posting itself still happens app by app.',
                '{site} is for the creator who sells to fans directly rather than to brands. Memberships, pay-per-view posts, bundles, services and events sit on one page. The studio publishes every post to nine social networks, the inbox attaches paid media to messages, and AI influencers supply images and videos you can sell. There is no media kit here, and no email marketing tool; broadcasts go to audience segments inside the platform instead.',
            )),
            array('h' => 'What does moving from Beacons to {site} involve?', 'p' => array(
                'Your audience comes with you: contacts download as a CSV from Beacons\' Audience Manager (' . self::src($c, 'ownership') . '), while automatic export to email tools is a paid feature. Export the CSV, build the page, then email the list with the new link and what it adds. Products become bundles or pay-per-view posts, appointments become services with a fixed price and a delivery window, and a membership tier keeps its name and price.',
                'Store funds on Beacons go to your connected payment account and from there to your bank on a daily, weekly or custom schedule; affiliate commissions cash out from the Payouts tab (' . self::src($c, 'payout') . '). Nothing much is pending when you leave. On {site} sales land in one balance net of your plan\'s fee, and you cash out on request from {payout_min} with no platform hold.',
                'The media kit is the thing to keep if brand deals pay your bills. Many creators keep a Beacons page for the kit and the brand outreach and move the fan-facing selling to {site}, with the link page on {site} pointing back at the kit. Both can run at once; nothing has to be cancelled.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Beacons charges a 9% seller fee on the Free and Creator plans ($10 a month) and 0% on Creator Plus at $30 a month and Creator Max at $100 (' . self::src($c, 'fee') . ', checked ' . $checked . '). A Creator Plus subscriber pays no share of sales, so for product sales Beacons Plus is the cheaper option on fee; {site} charges {fee_short} plus its plan price. The scenario below puts both Beacons options beside our plans.',
                'Beacons\' AI is on the business side: Beam, the assistant, plus AI text, image, email and page generation and brand outreach emails, metered by daily credits, 30 on Free, 300 on Creator and unlimited from Creator Plus (' . self::src($c, 'ai') . '). {site}\'s AI is on the content side. An AI influencer is trained from your photos or a description, with images and short videos of that character. Captions come with every post, and a DM agent drafts replies in your voice.',
                'Publishing is the clearest gap. Beacons analyses posts you made elsewhere; {site} makes the post once and sends it to nine networks from the studio, with the paid version behind the lock. If the posting is the part of your week you want back, that is the feature that decides it.',
            )),
            array('h' => 'A worked scenario: a Beacons creator selling $1,800 a month', 'p' => self::scenario($c, 1800, 'digital products, appointments and a membership')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Stan
    private static function compare_stan(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Stan for, and why?', 'p' => array(
                'Stan Store is a storefront for coaches and educators. Courses, digital products, bookings, recurring subscriptions, communities and lead magnets are on the Creator plan; payment plans, upsells, discount codes, affiliates and email broadcasts are on Creator Pro (' . self::src($c, 'content') . ', checked ' . $checked . '). Its pricing is the pitch: $29 a month or $300 a year for Creator, $99 a month or $948 a year for Creator Pro, and no transaction fee on sales, with card processing still applying (' . self::src($c, 'fee') . ').',
                'A coach selling a $400 course and booked calls is who Stan is for. A flat price with no share of sales suits that creator exactly. Stan\'s AI Twin, a subscribable AI version of the creator trained on their content, is in beta, and Stan AutoDM automates Instagram replies. Stanley, a separately priced product, helps repurpose content for LinkedIn, Instagram, X, Threads and Substack (' . self::src($c, 'ai') . '). The store plans themselves do not list post publishing (' . self::src($c, 'socials') . ').',
                '{site} is for the creator whose fans buy little and often rather than one course at a time. Memberships, pay-per-view posts, bundles, services and live events sit on one page, paid from a credit wallet. One studio publishes to nine networks, the inbox sells, and AI influencers come with the plan. Services and events are where the two overlap; the paid posts, bundles and publishing are where they differ.',
            )),
            array('h' => 'What does moving from Stan to {site} involve?', 'p' => array(
                'Customer and order data download as a CSV from Stan\'s Income tab (' . self::src($c, 'ownership') . '), so your buyers come with you. Recreate the bookings as services, the subscription as a membership tier, the course as a bundle of posts or as a pay-per-view series, and email the list once the page sells those things. Courses with hosted video are the hardest part to move; {site} stores media in your library rather than as a course player.',
                'Cash out of Stan first. A creator withdraws the full available balance, minimum $10, from the Income tab; it reaches the bank in 24 to 48 hours once sales have finished processing, which takes two to seven business days in the US (' . self::src($c, 'payout') . '). On {site} sales land in one balance net of your plan\'s fee, and you cash out on request from {payout_min}, with no platform hold on content, membership or service sales.',
                'Run both while the course buyers finish. Stan\'s plan is monthly, so the store can stay open for a cycle while the new page takes the bookings and the memberships; point the social bios at the new page when the calendar there is live.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'On fee, Stan\'s model and ours are different shapes. Stan charges a flat $29 or $99 a month and no share of sales. {site} charges {fee_short} on top of its plan price. For a creator selling a few thousand dollars of courses a month, Stan\'s flat price is the cheaper option, and this page does not pretend otherwise. The scenario below shows the arithmetic at a typical volume.',
                'The formats are the reason to move, or not to. Stan sells one-time products, bookings and subscriptions, and its Pro plan adds the sales tools a course business wants. {site} sells the small, repeated purchases a fan audience makes: a paid post, a bundle of past work, a ticket to a live session, a paid message. All of those come from a wallet the fan already loaded, and memberships stay on the card.',
                'Publishing and the inbox are the other reasons. Stan Store does not publish posts; Stanley is a separate product for repurposing content. {site}\'s studio publishes each post to nine networks with the paid version behind the lock, and its inbox drafts replies for your approval and attaches paid media to messages. A coach who sells through a funnel will not need those; a creator who sells through a feed will.',
            )),
            array('h' => 'A worked scenario: a Stan creator with $3,600 a month in sales', 'p' => self::scenario($c, 3600, 'courses, bookings and a subscription')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Eromify
    private static function compare_eromify(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Eromify for, and why?', 'p' => array(
                'Eromify is an AI influencer generator, not a place to sell. You train a persona, then generate images and videos of it with a wide choice of image and video models, motion control, upscaling, a workflow canvas, an AI agent and a Claude connector (' . self::src($c, 'ai') . ', checked ' . $checked . '). Pricing is monthly plans with a credit allowance, from 500 to 6,000 credits a month on Builder, Launch, Growth and Creator, each model costing a set number of credits per image or clip (' . self::src($c, 'fee') . ').',
                'The creator who wants the widest choice of generation models and already sells somewhere else is who Eromify suits. Its own site describes monetizing through social media, brand promotions and private media on other platforms, and lists no fan payments or payouts (' . self::src($c, 'payout') . '). Outputs are not watermarked, which matters when the images are the product.',
                '{site} covers the generation and the selling on one page. An AI influencer is trained from photos or a description, with images and short videos of the same character. Then come the formats that turn them into income: pay-per-view posts, bundles, memberships and paid messages. A DM agent answers fans in the character\'s voice for your approval, and a studio publishes to nine social networks. Eromify generates; {site} generates and sells.',
            )),
            array('h' => 'What does moving from Eromify to {site} involve?', 'p' => array(
                'There is no fan page to migrate and no balance to withdraw, because Eromify sells nothing to fans. The move is the images: download what you generated, upload them to your {site} library, and sell them as pay-per-view posts, bundles or membership content. New images are generated here from the same character, trained again from the photos you already have.',
                'Train the character on {site} before you cancel anything. Training takes a set of photos of one face; the influencer feature page lists how many. Once the character holds across images, the generation moves here, and the Eromify plan can lapse at the end of its month. Each {site} plan includes AI credits every month, {credits_creator} on Creator and {credits_studio} on Studio, and more can be bought at any time at {credits_per_dollar} credits per dollar.',
                'Then build the page the images sell from: two membership tiers, the strongest sets behind pay-per-view, a bundle of the archive, and the DM agent switched on with your approval required. The studio publishes each post to the networks where the character\'s audience lives, with the paid version behind the lock.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Eromify offers more generation models than {site} does, and a workflow canvas for batch work. A creator who experiments across many models, or who generates in volume for several personas, may keep Eromify for that and bring the results here to sell. {site} runs its generation through its own provider and prices it in credits; the number of AI influencers is {inf_creator} on Creator and {inf_studio} on Studio, with more available as a monthly add-on.',
                'Eromify has no platform fee because it has no sales; its cost is the plan and the credits. {site} charges {fee_short} on sales plus the plan price, and the plan includes the credits. The comparison is a tool cost against a tool cost plus a selling fee, so it only makes sense once the images are earning something.',
                'Ownership of the character is the long-term question. On {site} the trained character, its images and your audience list are yours to export at any time. On Eromify the outputs are downloadable and the fan relationship does not exist, because the fans are on whatever platform you sell through. If that platform is this one, the character and the audience end up in the same place.',
            )),
            array('h' => 'A worked scenario: an Eromify creator who starts selling here', 'p' => array(
                'Take a creator who generates on Eromify and sells 200 pay-per-view unlocks a month at $5 on another platform. The numbers are round and hypothetical. That is $1,000 a month gross wherever it is sold, and the selling platform\'s fee comes off it there; Eromify\'s plan and credits are a separate cost on top, because Eromify takes no part in the sale.',
                'Moved to {site}, the same 200 unlocks are paid from fans\' credit wallets at {credits_per_dollar} credits per dollar. On the Creator plan the fee is {fee_creator}% plus {creator_price} a month, and the plan includes {credits_creator} AI credits for generating the next month\'s images, so the generation tool and the selling fee are one bill. On Studio the fee is {fee_studio}% plus {studio_price}, with {credits_studio} credits and {inf_studio} AI influencers included.',
                'Whether that beats Eromify plus a separate selling platform depends on that platform\'s fee and on how many credits the creator burns; the scenario cannot be run on Eromify\'s side because it publishes no fan sales. What it does show is the shape of the decision: one bill for generating and selling, against two.',
            )),
        );
    }

    // ============================================================================================ list and guide pages
    /** /onlyfans-alternatives and /fanvue-alternatives: unique prose per page about choosing and switching. */
    public static function alternatives(string $key): array {
        $m = 'alt_' . $key;
        return method_exists(__CLASS__, $m) ? self::$m() : array();
    }

    private static function alt_onlyfans(): array {
        $of = PagesController::COMPETITORS['onlyfans']; $checked = date('F j, Y', strtotime($of['checked']));
        return array(
            array('h' => 'How do you choose among these alternatives?', 'p' => array(
                'Start from what you sell, not from the platform names. Every option on this list is one of three kinds. A subscription platform copies the OnlyFans model with a different fee or feed. A membership platform is built for patrons. A page like {site} sells several formats at once. A creator whose fans buy one subscription and nothing else is choosing between the first kind; a creator who also sells sessions, bundles or events is choosing the third.',
                'Then ask three questions of each candidate. Can you take your audience with you if you leave, in a file you can download? What does it sell beyond the subscription, and does it publish to your socials or leave the promotion to you? How does the money reach you: on what schedule, after what hold, from what minimum? The compare pages linked from each card answer those with the platform\'s own sources; the cards themselves describe the kind of platform only, because prices and fees change.',
                'Weigh the fee last. A lower share of sales matters once a page earns a few thousand dollars a month and barely matters below that, where the plan price and the formats decide. {site} charges {fee_short} plus a plan price, and the worked example further down this page shows where that crosses a flat 20%.',
            )),
            array('h' => 'What does leaving OnlyFans actually involve?', 'p' => array(
                'OnlyFans publishes no export of your subscriber list and no automatic posting to social accounts (' . self::src($of, 'ownership') . '), so the audience moves through your own messages: a pinned post, a mass message, the new link in every social bio. Third-party reporting puts it at more than 370 million registered users (' . self::src($of, 'content') . '). The fans who found you through its search are the ones a move can cost. The fans who found you through your socials are the ones it cannot.',
                'The money follows OnlyFans\' terms: a 20% fee is deducted from each fan payment, and creator earnings are available for withdrawal once reflected in the account and above its minimum payout amount (' . self::src($of, 'fee', 'terms') . ', checked October 11, 2026). Third-party reporting describes a holding period of about seven days (' . self::src($of, 'payout') . '). Withdraw what is available before the page goes quiet.',
                'Give the new page something the old one could not sell before you announce it. A bundle of the back catalogue, a service with a fixed price and a delivery window, a ticketed live session: those are the reasons a fan follows you to a new address. OnlyFans does not offer any of them. Then keep both pages live for a billing cycle and let the old renewals lapse on their own.',
            )),
            array('h' => 'What changes on {site}, in practice?', 'p' => array(
                'The page becomes the business. Memberships bill the fan\'s card monthly, with as many tiers as you want. Pay-per-view posts, bundles, services, event tickets and paid messages are paid from a credit wallet fans top up at {credits_per_dollar} credits per dollar. A second purchase needs no card. Every sale lands in one balance net of your plan\'s fee, and you cash out to your bank on request from {payout_min} with no platform hold; event earnings release after the event has taken place.',
                'The studio does the promotion you used to do by hand. A post written once goes to your page and to nine social networks at the same time, with the paid version behind the lock, and AI captions drafted for each. The inbox drafts replies in your voice for your approval, sends welcome and trigger messages with paid media attached, and runs broadcasts to audience segments.',
                'The audience list and your media are yours to export at any time, which is the difference that matters most on the day you decide to leave again. Adult content is allowed behind an age check: fans opt in and verify once, creators verify before publishing adult media, and every upload is checked automatically.',
            )),
            array('h' => 'A worked scenario: 400 subscribers leaving OnlyFans', 'p' => self::scenario($of, 4000, 'subscriptions and pay-per-view (400 fans at $10)')),
        );
    }

    private static function alt_fanvue(): array {
        $fv = PagesController::COMPETITORS['fanvue']; $checked = date('F j, Y', strtotime($fv['checked']));
        return array(
            array('h' => 'How do you choose among these alternatives?', 'p' => array(
                'Fanvue creators usually leave for one of two reasons: they want a page they own, with an audience they can take with them, or they want to sell in more formats than a subscription and paid messages. The list above is sorted by kind of platform for that reason. The subscription platforms keep the model you know; the membership platforms suit regular publishing; {site} is the page that sells every format and publishes everywhere.',
                'AI creators have a particular question to ask: where does the character live, and who owns it? Fanvue welcomes AI-generated creator accounts and sells fans AI voice calls and voice notes (' . self::src($fv, 'ai') . ', checked ' . $checked . '). A platform that only hosts the page leaves the generation to another tool. {site} trains the character, generates the images and short videos, and sells them from the same page. A DM agent answers in the character\'s voice for your approval.',
                'For every candidate, check the three things that decide a switch: whether the fan list exports, what the platform sells beyond the subscription, and how payouts work. The compare pages linked from the cards carry those facts with sources; the cards name only the kind of platform, because prices and fees move.',
            )),
            array('h' => 'What does leaving Fanvue actually involve?', 'p' => array(
                'Fanvue does not publish whether a creator can export the fan list (' . self::src($fv, 'ownership') . '), so the audience moves through your own messages and bios. {site} can cross-post to Fanvue from the same studio, which keeps the Fanvue page fed while fans move, so the old page need not go quiet on day one. Keep both live for a full billing cycle.',
                'Fanvue keeps 20% of gross revenue from paid services and pays by bank transfer, crypto or third-party wallet after a standard pending period of seven days that can run to twenty-eight (' . self::src($fv, 'payout') . ', checked ' . $checked . '). Request what is withdrawable before you stop posting, and expect the last balance to clear on that schedule.',
                'If the character is AI, train it on {site} before the move: a set of photos of one face, then test that it holds across images, then generate the first month of posts. Each plan includes AI credits every month, {credits_creator} on Creator and {credits_studio} on Studio, and more can be bought at {credits_per_dollar} credits per dollar. The page is ready when it has two tiers, a few pay-per-view posts and a bundle of the archive.',
            )),
            array('h' => 'What changes on {site}, in practice?', 'p' => array(
                'The formats widen. Alongside memberships and paid messages, the page sells pay-per-view posts, bundles of past work and services with a fixed price and a delivery window. Tickets to live sessions are held in a built-in video room with a waiting room, chat and host controls. Fans pay memberships by card and everything else from a credit wallet, so a second purchase takes one tap.',
                'The promotion stops being manual. The studio publishes each post to nine social networks and to your page at once, with the paid version behind the lock. The inbox drafts replies for your approval and sends welcome and trigger messages with paid media attached. Fanvue\'s AI voice calls for fans do not exist here; the AI works on the creator\'s side instead.',
                'The money is one balance. Every sale lands net of your plan\'s fee, {fee_short}, and pays out to your bank on request from {payout_min} with no platform hold; event earnings release after the event. The audience list and your media export at any time.',
                'Adult content is allowed behind an age check, which matters to many Fanvue creators. Fans opt in and verify once, creators verify before publishing adult media, and every upload is checked automatically. Nothing adult is shown to a fan who has not opted in, and the public page stays safe for work.',
            )),
            array('h' => 'A worked scenario: 200 subscribers leaving Fanvue', 'p' => self::scenario($fv, 2000, 'subscriptions and paid messages (200 fans at $10)')),
        );
    }

    /** /best-creator-monetization-platforms: how to read the comparison, with the sourced rows. */
    public static function best_platforms(): array {
        $c = PagesController::COMPETITORS; $d = function ($k) use ($c) { return date('F j, Y', strtotime($c[$k]['checked'])); };
        return array(
            array('h' => 'How should you read the fee column?', 'p' => array(
                'The fee column mixes two shapes of pricing, and the shape matters more than the number. Subscription platforms take a share of every sale and charge no plan price: Fanvue keeps 20% of gross revenue (' . self::src($c['fanvue'], 'fee') . ', checked ' . $d('fanvue') . '), Fansly keeps 20% (' . self::src($c['fansly'], 'fee') . ') and OnlyFans deducts 20% of each fan payment under its terms (' . self::src($c['onlyfans'], 'fee') . ', checked October 11, 2026). Storefronts invert it: Stan charges $29 or $99 a month and no transaction fee (' . self::src($c['stan'], 'fee') . '), Beacons 0% on Creator Plus at $30 a month and 9% on its free plan (' . self::src($c['beacons'], 'fee') . '), Linktree 12% on Free down to 0% on Premium at $35 (' . self::src($c['linktree'], 'fee') . ').',
                'Patreon and Ko-fi sit between. Patreon charges 10% for pages published after August 4, 2025, plus processing, conversion and payout fees (' . self::src($c['patreon'], 'fee') . '); Ko-fi Free charges nothing on one-time tips and 5% on memberships, shop sales and commissions (' . self::src($c['kofi'], 'fee') . '). {site} is a share plus a plan price: {fee_short}, on top of a monthly plan, with card processing separate as everywhere.',
                'So the honest comparison is at a volume. A page earning a few hundred dollars a month is cheapest on Ko-fi or a free storefront. A page earning a few thousand is where a share of 20% starts to cost more than a plan price plus a smaller share. The fee table further down runs two volumes across every platform with a published rate.',
            )),
            array('h' => 'How do payout terms differ?', 'p' => array(
                'Ko-fi pays immediately into the creator\'s own connected payment account, with no schedule and no minimum (' . self::src($c['kofi'], 'payout') . '). Stan pays the full available balance, minimum $10, in 24 to 48 hours once sales have finished processing (' . self::src($c['stan'], 'payout') . '). Linktree and Beacons settle store sales to the connected account on a schedule the creator chooses (' . self::src($c['linktree'], 'payout') . '; ' . self::src($c['beacons'], 'payout') . ').',
                'The subscription platforms hold money longer. Fanvue\'s standard pending period is seven days and can extend to twenty-eight (' . self::src($c['fanvue'], 'payout') . '); Fansly pays amounts above $20 as often as you like, typically within three business days by bank transfer (' . self::src($c['fansly'], 'payout') . '); OnlyFans is reported to pay after a holding period of about seven days (' . self::src($c['onlyfans'], 'payout') . '). Patreon applies a five-day hold after a payout method is added and keeps one-time purchases pending up to seven days (' . self::src($c['patreon'], 'payout') . ').',
                '{site} pays on request from a {payout_min} balance to your bank, with no platform hold on content, membership or service sales; earnings from an event release after the event has taken place. One balance covers memberships, unlocks, bundles, services and tickets, so there is one number to watch rather than one per product.',
            )),
            array('h' => 'Who owns the audience when you leave?', 'p' => array(
                'Patreon exports the member list with emails as a CSV (' . self::src($c['patreon'], 'ownership') . '); Ko-fi exports supporter and member lists (' . self::src($c['kofi'], 'ownership') . '); Linktree exports contacts on every plan (' . self::src($c['linktree'], 'ownership') . '); Beacons exports from its Audience Manager (' . self::src($c['beacons'], 'ownership') . '); Stan exports customer and order data from the Income tab (' . self::src($c['stan'], 'ownership') . '). OnlyFans, Fanvue and Fansly do not publish an export.',
                'That line divides the list more sharply than the fee does. A creator on a platform with no export rebuilds the audience through messages and bios on every move; a creator with a CSV emails it. {site} exports the audience list and your media at any time, and the inbox can broadcast to segments of that audience with paid media attached, which is how a list becomes income rather than a file.',
                'Publishing follows the same line. Linktree\'s planner posts to six networks and the rest of the list leaves social posting to you; {site}\'s studio publishes each post to nine networks with the paid version behind the lock on your page, so the promotion and the sale are one action.',
            )),
            array('h' => 'A worked scenario: the same creator on three platforms', 'p' => array_merge(
                array('Take a creator with $2,800 a month across memberships and single purchases, round and hypothetical. On a 20% subscription platform the platform keeps $560 and $2,240 is left before card processing. On Stan Creator the platform keeps $29 and $2,771 is left, because Stan takes no share of sales (' . self::src($c['stan'], 'fee') . ').'),
                array_slice(self::scenario($c['fanvue'], 2800, 'memberships and single purchases'), 2)
            )),
        );
    }

    /** /monetize-your-content: the five ways in prose, then what a sale turns into. */
    public static function monetize(): array {
        return array(
            array('h' => 'What is each of the five ways good for?', 'p' => array(
                'A membership is a promise you repeat: a monthly price for what arrives next month, not for the archive. Two tiers cover most creators, an entry tier priced where a fan says yes without thinking and a higher tier for the people who want more of you, and the gap between them is where the value shows. Members pay by card on a schedule, so they think before joining and rarely think again, which is why a weekly post matters more than any single one.',
                'A pay-per-view post is one piece at one price for everyone, members included. It suits your strongest single pieces, the ones that took the most effort, and it is priced by effort and scarcity rather than length. Because fans pay it from a credit wallet they have already loaded, it behaves like an impulse purchase: keep it cheap enough to decide in a second, and never below a quarter of the entry tier, or the tier stops making sense.',
                'A bundle turns the back catalogue into a product: a themed set of past media at a discount to the sum of the parts. It needs no new work, only a title and a price, and it is the easiest upsell after a single unlock. A service is custom work at a fixed price with a clear scope and a delivery window you can keep, and it is where a small audience earns the most per fan. An event sells seats to a live session ahead of time, caps the room, and can be recorded and sold afterwards as a bundle.',
            )),
            array('h' => 'What does a sale turn into on {site}?', 'p' => array(
                'Every sale lands in one balance, net of your plan\'s platform fee of {fee_short}. Memberships bill the fan\'s card; unlocks, bundles, services and tickets are paid from a credit wallet at {credits_per_dollar} credits per dollar. Card processing is separate, as on every platform, and the plan price is a fixed monthly cost that the fee example below puts next to the sales.',
                'You cash out on request from a {payout_min} balance to your bank, with no platform hold on content, membership or service sales. Earnings from an event release after the event has taken place, so a cancelled session never leaves a fan unpaid. Content sales are final; plan charges and credit purchases are non-refundable, and a refund you choose to give a fan comes out of the same balance.',
                'The balance is also the ledger. Every membership, unlock, bundle, service and ticket shows in one place with the fan who paid, which is what makes the audience tab useful: the people who bought are tagged, and a broadcast to buyers is a few clicks rather than a spreadsheet.',
            )),
            array('h' => 'How do you keep the prices right over time?', 'p' => array(
                'Raise prices for new members only, and announce the change a week ahead so fans on the fence join at the current price; the deadline does more than any discount. Add something to the tier on the day the price changes, a weekly post or early access to pay-per-view drops, so the price rise reads as a change in what the tier is rather than a change in what it costs.',
                'Use a promo code for a short window instead of a permanent lower price. A code ends on a date and a lower price is hard to take back; the same goes for a free trial, which costs you nothing if the tier has a reason to stay. Watch renewals for one full billing cycle before you change anything else, because one change at a time tells you what worked.',
                'Cap the services and events you take each month so the delivery window stays one you can keep. A full calendar is the sign to raise the price, not to add slots, and a recorded session sold as a bundle afterwards earns from the same hour twice.',
            )),
            array('h' => 'A worked scenario: a creator starting with two tiers and one unlock', 'p' => self::scenario(array('name' => 'another platform', 'fee_math' => array()), 1000, 'memberships and single unlocks (80 members at $10 plus 40 unlocks at $5)')),
        );
    }
}
