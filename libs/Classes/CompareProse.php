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
    /** Two independent primary sources every comparison page cites in its prose (a_external_sources): tax on creator income and sponsorship disclosure. Pages with a voice word it their own way (VOICE[slug]['sources']). */
    const DEFAULT_SOURCES = 'Two rules sit outside every platform on this page. Creator income is self-employment income, and the [IRS self-employed individuals tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) explains the estimated payments; a sponsored post needs a disclosure, which the [FTC\'s Disclosures 101 for influencers](https://www.ftc.gov/business-guidance/resources/disclosures-101-social-media-influencers) sets out.';

    public static function links_line(string $voice = ''): string {
        $src = (string) (self::VOICE[$voice]['sources'] ?? self::DEFAULT_SOURCES);
        $t = (string) (self::VOICE[$voice]['links'] ?? '');
        if ($t === '') { return Sections::prose('white', '', array('Everything about ' . Main::site_name() . ' itself lives on its own pages: [features](/features), [pricing and limits](/pricing) and [payouts](/features/payouts).', $src)); }
        return Sections::prose('white', '', array(str_replace(array('({/features})', '({/pricing})', '({/features/payouts})'), array('(/features)', '(/pricing)', '(/features/payouts)'), $t), $src));
    }

    /** Up-link to the full comparison and links to the other platforms of the same kind, so compare pages are not dead ends (SEO audit 2026-10-12). */
    public static function siblings_line(string $slug): string {
        $c = PagesController::COMPETITORS[$slug] ?? null; if (!$c) { return ''; }
        $sib = array();
        foreach (PagesController::COMPETITORS as $k => $o) { if ($k !== $slug && ($o['type'] ?? '') === ($c['type'] ?? '')) { $sib[] = '[' . Main::site_name() . ' vs ' . $o['name'] . '](/compare/' . $k . ')'; } }
        $up = isset(PagesController::COMPETITOR_GROUPS[$c['type'] ?? '']) ? 'Every platform sits side by side in [best creator monetization platforms](/best-creator-monetization-platforms).' : 'The tools around a generated character are set out in [AI OFM tools](/ai-ofm-tools).';
        return Sections::prose('alt', 'Which other comparisons are worth reading?', array((empty($sib) ? '' : 'Platforms of the same kind are compared in ' . implode(', ', $sib) . '. ') . $up));
    }

    /** One voice string for the page ('' when the competitor has none). */
    public static function voice(string $slug, string $key): string { return (string) (self::VOICE[$slug][$key] ?? ''); }
    public static function voice_list(string $slug, string $key): array { return (array) (self::VOICE[$slug][$key] ?? array()); }

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
    /**
     * The worked scenario, computed from the competitor's sourced rate (fee_math) and our plans. The figures are placeholders
     * filled into a wording that is written per competitor (VOICE[slug]['scenario']); pages without a voice use the default
     * wording. Card processing is named as separate everywhere.
     */
    public static function scenario(array $c, int $gross, string $what, string $voice = ''): array {
        $site = Main::site_name(); $money = function ($n) { return '$' . number_format((float) $n, 0); };
        $v = array('{site}' => $site, '{gross}' => $money($gross), '{what}' => $what, '{name}' => (string) $c['name']);
        $opts = array_values((array) ($c['fee_math'] ?? array()));
        foreach (array(0, 1) as $i) {
            $o = $opts[$i] ?? null; $k = $i === 0 ? 'them' : 'them2';
            if ($o) {
                $fee = $gross * ((float) $o['percent']) / 100;   // the share of sales alone: a competitor's monthly price is a separate line too, never folded in ({them_monthly})
                $v += array('{' . $k . '}' => (string) $o['label'], '{' . $k . '_pct}' => rtrim(rtrim(number_format((float) $o['percent'], 2), '0'), '.') . '%', '{' . $k . '_fee}' => $money($fee), '{' . $k . '_left}' => $money($gross - $fee), '{' . $k . '_src}' => (string) $o['source'], '{' . $k . '_monthly}' => $money((float) ($o['monthly'] ?? 0)));
            }
        }
        $tiers = PagesController::selling_tiers(); usort($tiers, function ($a, $b) { return (int) $a['rank'] <=> (int) $b['rank']; });
        $low = reset($tiers); $high = end($tiers);
        foreach (array('c' => $low, 's' => $high) as $k => $t) {
            if (!$t) { continue; }
            $fee = $gross * ((int) $t['limits']['fee_percent']) / 100;   // the platform share alone: a plan price is never folded into a fee figure (Daniel 2026-10-12)
            $v += array('{' . $k . '_name}' => (string) $t['name'], '{' . $k . '_pct}' => (int) $t['limits']['fee_percent'] . '%', '{' . $k . '_price}' => $money((int) $t['price']), '{' . $k . '_total}' => $money($fee), '{' . $k . '_left}' => $money($gross - $fee));
        }
        if ($low && $high && $low !== $high) {   // {diff}: the gap between the two shares on this month; {pick}: the lower share
            $v += array('{pick}' => $high['name'], '{other}' => $low['name'], '{diff}' => $money(abs($gross * ((int) $low['limits']['fee_percent']) / 100 - $gross * ((int) $high['limits']['fee_percent']) / 100)));
        }
        $tpl = (array) (self::VOICE[$voice]['scenario'] ?? self::DEFAULT_SCENARIO);
        if (count($opts) < 1) { $tpl = array_values(array_filter($tpl, function ($t) { return strpos($t, '{them') === false; })); }
        if (count($opts) < 2) { $tpl = array_values(array_filter($tpl, function ($t) { return strpos($t, '{them2') === false; })); }
        $out = array();
        foreach ($tpl as $t) { $out[] = strtr($t, $v); }
        return $out;
    }

    /** The generic scenario wording (alternatives, best-of and monetize pages). */
    const DEFAULT_SCENARIO = array(
        'Take a creator on {name} with {gross} a month in {what}. The numbers are round and hypothetical, chosen to show the arithmetic rather than to promise anything.',
        '{them} keeps {them_fee} of that ({them_pct}), so {them_left} is left before card processing, by its [own pricing page]({them_src}).',
        'On {site} the same {gross} works like this: the {c_name} plan keeps {c_pct}, {c_total}, leaving {c_left}; the {s_name} plan keeps {s_pct}, {s_total}, leaving {s_left}. Card processing is charged separately on every option above. The monthly plan prices are a separate line, shown on the pricing page, not in these figures.',
        'The plan also sets the included AI credits, storage and AI influencers, which are listed on the pricing page.',
    );

    /**
     * Per-competitor wording for every templated spot on a compare page (the scenario, the fee table lead and note, the
     * "why choose" card, the table caption, the hero tail, the "both at once" FAQ answer, the links line, the closing band).
     * Written separately per page so no two compare pages share phrasing once names and numbers are removed.
     */
    const VOICE = array(
        'fanvue' => array(
            'scenario' => array(
                'Picture a Fanvue creator clearing {gross} a month from {what}; round figures, invented for the arithmetic, not a forecast.',
                'Fanvue retains {them_pct} of gross revenue under its standard rate, which is {them_fee} here, leaving {them_left} before the card processor takes its part ([Fanvue legal]({them_src})).',
                'Move the same {gross} to {site} and the sums change shape: {c_name} keeps {c_pct} of sales, so {c_total} goes out and {c_left} stays; {s_name} keeps {s_pct}, so {s_total} goes out and {s_left} stays. Card processing still sits on top of each line, and the monthly plan price is its own line on the pricing page.',
                'The two {site} plans also differ in AI credits, storage and influencer slots, which are listed on the pricing page.',
            ),
            'fee_lead' => 'Put a month of example sales through both shares and the difference is plain: on {s0}, Fanvue keeps {them_fee}, while the {c_name} plan here keeps {us_fee}. Plan prices are a separate monthly line.',
            'fee_note' => 'Figures are rounded and hypothetical. Fanvue\'s rate is the one on its legal page, linked in the row; our rows show the share of sales alone, and the plan prices are on the pricing page.',
            'why_text' => 'Your fans pay for more than a subscription, and you would rather post once than nine times.',
            'why_points' => array('Memberships, pay-per-view, bundles, services and events from the same page', 'One studio that publishes every post to ten platforms, paid version behind the lock', 'An inbox that drafts replies in your voice and attaches paid media'),
            'checked' => 'The Fanvue column was checked on {date} against the pages linked in each row.',
            'hero_tail' => 'Below: the feature table with sources, who each platform suits, what a move involves, and the fee on real numbers.',
            'both' => 'Yes. {site} cross-posts to Fanvue from the same studio, so the Fanvue page keeps getting your posts while fans find the new one. Run both for a billing cycle, put the new link in every bio, and let the old renewals lapse on their own.',
            'links' => 'The product itself is described on three pages rather than repeated here: the [features]({/features}), [pricing and limits]({/pricing}) and [payouts]({/features/payouts}).',
            'sources' => 'Two rules apply on either platform and come from neither of them. What reaches your bank is self-employment income, and the [IRS self-employed tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) sets out the quarterly estimates; a sponsored post on your socials needs a clear disclosure, which the [FTC\'s Disclosures 101](https://www.ftc.gov/business-guidance/resources/disclosures-101-social-media-influencers) spells out.',
            'cta' => 'Build the page while your Fanvue page keeps running, then move your fans at their own pace.',
        ),
        'onlyfans' => array(
            'scenario' => array(
                'Say an OnlyFans creator brings in {gross} a month across {what}. Treat every figure as illustrative.',
                'OnlyFans deducts {them_pct} from each fan payment under its own terms, {them_fee} on this month, which leaves {them_left} before payment processing ([terms]({them_src})).',
                'Here the same month keeps more of itself: {c_name} takes {c_pct}, {c_total}, with {c_left} left over; {s_name} takes {s_pct}, {s_total}, with {s_left} left over. Processing is extra in every case, on both platforms, and the plan prices sit on the pricing page rather than in these sums.',
                'The two plans also differ in seats, AI credits and storage, which the pricing page lists.',
            ),
            'fee_lead' => 'The flat 20% is easy to remember and expensive at scale: on {s0} a month OnlyFans keeps {them_fee}; the {c_name} plan here keeps {us_fee}, with the plan price a separate monthly line.',
            'fee_note' => 'Hypothetical round sums. OnlyFans\' rate is the figure in its own terms of service, linked in the row; our rows are the share of sales alone.',
            'why_text' => 'You want the page, the audience list and the media to be yours, and your socials to feed it automatically.',
            'why_points' => array('Export your audience and your media whenever you choose', 'Five ways to sell on one page, not one subscription', 'Every post published to ten platforms from the studio'),
            'checked' => 'OnlyFans facts: terms page checked October 11, 2026; third-party reporting dated {date} where the site blocks automated access.',
            'hero_tail' => 'Here: what each platform charges and sells, with sources, who OnlyFans suits, what leaving involves, and the arithmetic on an example page.',
            'both' => 'Yes. Running both for a full billing cycle is the safe way to move. Keep posting on OnlyFans, add the new address to every bio and pinned post, and give the new page something OnlyFans cannot sell so fans have a reason to follow.',
            'links' => 'Our own side is documented once, on the [features]({/features}), [pricing]({/pricing}) and [payouts]({/features/payouts}) pages, rather than repeated on each comparison.',
            'sources' => 'Whichever page collects the money, the income is yours to report: the [IRS self-employed individuals tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) explains estimated payments, and the [IRS page on Form 1099-K](https://www.irs.gov/businesses/understanding-your-form-1099-k) explains what a payment processor reports about your sales.',
            'cta' => 'Keep your OnlyFans page live while you set up; fans move when the new page gives them a reason.',
        ),
        'patreon' => array(
            'scenario' => array(
                'Consider a Patreon creator with {gross} a month in {what}, round numbers chosen for the sums.',
                'Patreon\'s {them_pct} platform fee for pages published after August 4, 2025 takes {them_fee}, leaving {them_left}; payment processing, currency conversion and payout fees come off after that ([fees overview]({them_src})).',
                'On {site}, {c_name} also takes {c_pct}: {c_total}, leaving {c_left}. {s_name} takes {s_pct}: {s_total}, leaving {s_left}. Card processing is separate here as well; the monthly plan price is listed on the pricing page, not inside these sums.',
                'What each plan includes beyond the share, from seats to AI credits, is on the pricing page.',
            ),
            'fee_lead' => 'Patreon and the {c_name} plan share a 10% rate, so on {s0} both keep {us_fee} before Patreon\'s processing and payout charges; the plan price here is a separate monthly line.',
            'fee_note' => 'Round numbers. Patreon\'s rate is from its fees overview, linked in the row; its processing, conversion and payout fees are not in the figure, ours are not either, because card processing is separate on both.',
            'why_text' => 'Your members want more than tiers: paid posts, bundles, booked sessions, live events.',
            'why_points' => array('Tiers plus pay-per-view, bundles, services and events on one page', 'Posts published to ten platforms instead of a share link', 'A DM agent that drafts replies you approve'),
            'checked' => 'Patreon\'s rows were verified on {date}; every value links to the Patreon help article it came from.',
            'hero_tail' => 'What follows: the sourced table, who Patreon serves best, how a move works, the trade-offs, and a worked month.',
            'both' => 'Yes. Export your member list from Patreon, rebuild the tiers here with the same names and prices, and keep Patreon open for one billing cycle so nobody loses access mid-month.',
            'links' => 'Details of our plans, features and payout terms live on their own pages: [features]({/features}), [pricing]({/pricing}), [payouts]({/features/payouts}).',
            'sources' => 'Independent of both platforms, a membership income is self-employment income; the [IRS self-employed tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) covers the quarterly estimate. If you promote a sponsor to your members, the [FTC endorsement guides](https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking) explain what a disclosure has to say.',
            'cta' => 'Rebuild your tiers here in an afternoon and let members re-join at their own pace.',
        ),
        'fansly' => array(
            'scenario' => array(
                'A Fansly creator with {gross} a month from {what} is a fair example; the numbers are made up for the arithmetic.',
                'Fansly\'s {them_pct} comes to {them_fee} on that month and leaves {them_left}, before card processing ([Fansly]({them_src})).',
                'The same {gross} on {site}: {c_name} is {c_pct} of sales, {c_total}, with {c_left} remaining; {s_name} is {s_pct}, {s_total}, with {s_left} remaining. Add card processing to all three; the plan prices are a separate monthly line.',
                'Seats, AI credits and storage differ between the two plans as well, as the pricing page sets out.',
            ),
            'fee_lead' => 'Both platforms are measured on the same month of sales here: Fansly\'s {them_pct} takes {them_fee} of {s0}, and {c_name} takes {us_fee}; the plan price is a separate monthly line.',
            'fee_note' => 'Hypothetical totals. Fansly\'s rate is the one on its creator application page, linked in the row; our rows are the share of sales alone.',
            'why_text' => 'Streams are your strength, and you want the sessions, the posts and the sales on a page of your own.',
            'why_points' => array('Ticketed live sessions in a built-in video room', 'Pay-per-view, bundles, services and memberships together', 'Publishing to ten platforms instead of relying on one feed'),
            'checked' => 'Fansly values checked {date}; the source link beside each one is the page it was read from.',
            'hero_tail' => 'On this page: the sourced feature table, who Fansly fits, the move, the trade-offs, and one creator\'s month in numbers.',
            'both' => 'Yes. Keep streaming and posting on Fansly through a billing cycle while the new page fills, use Fansly\'s own messages to point subscribers at it, and let renewals there lapse as people move.',
            'links' => 'Everything on our side of the table is set out once, on [features]({/features}), [pricing]({/pricing}) and [payouts]({/features/payouts}).',
            'sources' => 'Neither platform handles your taxes or your sponsorship disclosures. The [IRS self-employed individuals tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) sets out the estimates due on creator income, and the [FTC\'s Disclosures 101 for influencers](https://www.ftc.gov/business-guidance/resources/disclosures-101-social-media-influencers) covers the wording of a paid post.',
            'cta' => 'Set up your tiers and your first session here while Fansly keeps running.',
        ),
        'kofi' => array(
            'scenario' => array(
                'Imagine a Ko-fi creator taking {gross} a month in {what}, with round figures standing in for real ones.',
                'Ko-fi Free charges {them_pct} on memberships, shop sales and commissions, {them_fee} on this month, so {them_left} reaches the creator\'s own payment account, less processing ([Ko-fi help]({them_src})).',
                'The {site} version: {c_name} takes {c_pct}, {c_total}, leaving {c_left}; {s_name} takes {s_pct}, {s_total}, leaving {s_left}. Card processing is extra on each, and the monthly plan price is a separate line on the pricing page.',
                'So on the share alone Ko-fi keeps less of this month than either of our plans. A Ko-fi creator moves for the formats and the publishing, not for the fee.',
            ),
            'fee_lead' => 'Ko-fi keeps the smaller share: {them_fee} on {s0} against {us_fee} for {c_name}; the plan price is a separate monthly line, and the table is here so the trade is visible, not hidden.',
            'fee_note' => 'Round numbers. Ko-fi\'s rates come from its own help article, linked in the row; ours are the share of sales alone.',
            'why_text' => 'Tips were the start; now you sell memberships, services and events and want them published for you.',
            'why_points' => array('Services with a fixed price and delivery window, not open commissions', 'Pay-per-view, bundles, memberships and events on one page', 'A studio that posts to ten platforms and an inbox that sells'),
            'checked' => 'Ko-fi rows verified {date} from the help-centre articles linked beside them.',
            'hero_tail' => 'Here you will find the sourced table, who Ko-fi is right for, how a move works, what you give up, and the sums for an example page.',
            'both' => 'Yes, and many creators keep Ko-fi as the tip jar. Download the supporter list, rebuild memberships and shop items here, and point the bios at the new page when it has something to sell.',
            'links' => 'Our features, plans and payout rules are documented on [features]({/features}), [pricing]({/pricing}) and [payouts]({/features/payouts}).',
            'sources' => 'Two outside references matter more than either fee column. Supporter income is self-employment income under the [IRS self-employed tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) rules, and a processor that moves your money reports it on a [Form 1099-K](https://www.irs.gov/businesses/understanding-your-form-1099-k) once you pass the threshold on that page.',
            'cta' => 'Keep the tip jar, move the memberships: build the page and send your supporters the link.',
        ),
        'linktree' => array(
            'scenario' => array(
                'Take a Linktree creator selling {gross} a month of {what}, in round hypothetical figures.',
                'On Linktree\'s free plan the {them_pct} fee is {them_fee} and {them_left} remains; {them2} takes no share of sales, so the full {gross} remains, and its monthly price is a separate line. Card processing and the per-payout charge come off both ([Linktree help]({them_src})).',
                'On {site} the month looks like this: {c_name}, at {c_pct}, keeps {c_total} and leaves {c_left}; {s_name}, at {s_pct}, keeps {s_total} and leaves {s_left}. Card processing applies to every option, and the plan prices are their own monthly line.',
                'For product sales alone Linktree Premium takes no share. The reason to move is the memberships, services and events Linktree does not sell.',
            ),
            'fee_lead' => 'Linktree\'s share depends on the plan, so the table shows two Linktree lines against our two plans on the same {s0} of sales; Linktree Free keeps {them_fee}, and {c_name} keeps {us_fee}. Monthly plan prices, theirs and ours, are separate lines.',
            'fee_note' => 'Hypothetical sums. Linktree\'s rates are from its own fees article and pricing page, linked in the rows; every row is the share of sales alone.',
            'why_text' => 'The link page should take the payment itself, and monthly tiers should be possible.',
            'why_points' => array('Tracked links plus memberships, pay-per-view, bundles, services and events', 'Ten platforms from one studio, paid version behind the lock', 'Fans pay by card or credit wallet without leaving the page'),
            'checked' => 'Linktree details were read on {date} from the help articles and pricing page linked in each row.',
            'hero_tail' => 'Covered below: the sourced table, who Linktree suits, the move, the trade-offs, and the fee on a month of product sales.',
            'both' => 'Yes. Export your contacts from Linktree, rebuild the links here as tracked links, and switch the social bios once the memberships or products are live. There is no renewal to wait for.',
            'links' => 'Our side of the comparison is written up on [features]({/features}), [pricing]({/pricing}) and [payouts]({/features/payouts}).',
            'sources' => 'The storefront does not change the obligations behind it. The [IRS self-employed tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) explains the estimated tax on product sales, and the [FTC\'s Disclosures 101](https://www.ftc.gov/business-guidance/resources/disclosures-101-social-media-influencers) covers the disclosure on any affiliate link in your bio.',
            'cta' => 'Turn the link page into the shop: build it here, then change the bios.',
        ),
        'beacons' => array(
            'scenario' => array(
                'Suppose a Beacons creator makes {gross} a month from {what}; the figures are round and illustrative.',
                'Beacons Free takes {them_pct} ({them_fee}) and leaves {them_left}; {them2} takes no share of sales, so the full {gross} is left, and its monthly price is a separate line. Card processing is on top of both ([Beacons pricing]({them_src})).',
                'The {site} lines on the same month: {c_name} at {c_pct} keeps {c_total} and leaves {c_left}; {s_name} at {s_pct} keeps {s_total} and leaves {s_left}. Card processing again on top; plan prices are listed separately.',
                'Beacons Creator Plus takes no share of sales. A Beacons creator moves for direct fan sales and publishing, not for the fee.',
            ),
            'fee_lead' => 'The table carries two Beacons lines because its share depends on the plan: on {s0} of sales Beacons Free keeps {them_fee}, and our {c_name} plan keeps {us_fee}. Plan prices are a separate monthly line on both sides.',
            'fee_note' => 'Round numbers. Beacons\' seller fees are from its pricing page, linked in the rows; every row is the share of sales alone.',
            'why_text' => 'Fans, not brands, pay your bills, and you want them paying on your page.',
            'why_points' => array('Memberships, pay-per-view, bundles, services and events sold to fans directly', 'Posts published to ten platforms, not only analysed', 'AI influencers whose images and videos you can sell'),
            'checked' => 'Beacons rows checked {date} (plan prices rechecked October 11, 2026) against the pages linked in each row.',
            'hero_tail' => 'This page: the sourced table, who Beacons serves, how a move works, what changes, and one month in figures.',
            'both' => 'Yes. Keep Beacons for the media kit and brand outreach if deals pay your bills, move the fan-facing selling here, and let the link page here point back at the kit.',
            'links' => 'Our plans, features and payout rules sit on [features]({/features}), [pricing]({/pricing}) and [payouts]({/features/payouts}).',
            'sources' => 'Brand deals and fan sales both sit under the same two rules, from outside either platform: the [FTC endorsement guides](https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking) on disclosing a paid partnership, and the [IRS self-employed individuals tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) on reporting the income.',
            'cta' => 'Keep the media kit, move the fan sales: build your page and send the list the link.',
        ),
        'stan' => array(
            'scenario' => array(
                'A Stan Store creator with {gross} a month in {what} makes a clean example; the sums are hypothetical.',
                'Stan Store\'s Creator plan takes no share of sales, so the full {gross} is left before card processing; its {them_monthly} a month is a separate line ([Stan Store pricing]({them_src})).',
                'On {site}: {c_name} takes {c_pct}, {c_total}, leaving {c_left}; {s_name} takes {s_pct}, {s_total}, leaving {s_left}. Card processing is extra on all three, and every monthly plan price, ours and Stan Store\'s, is a separate line.',
                'Stan Store takes no share of sales. The formats and the publishing are what a Stan Store creator comes here for.',
            ),
            'fee_lead' => 'Stan Store takes no share of {s0}, and our {c_name} plan keeps {us_fee}. The monthly prices on both sides are separate lines.',
            'fee_note' => 'Hypothetical figures. Stan Store\'s no-transaction-fee policy is from its pricing page, linked in the row; our rows are the share of sales alone.',
            'why_text' => 'Your fans buy small things often: a paid post, a bundle, a ticket, a message.',
            'why_points' => array('Pay-per-view, bundles and paid messages from a credit wallet fans load once', 'Memberships, services and events on the same page', 'Ten platforms from one studio and a DM agent you approve'),
            'checked' => 'Stan Store rows verified {date}, prices rechecked October 11, 2026; each links to the help article or pricing page it came from.',
            'hero_tail' => 'Below: the feature table with sources, who Stan Store is for, the move, the trade-offs, and a month of sales on each side.',
            'both' => 'Yes. Stan Store\'s plan is monthly, so keep the store open for a cycle while the bookings and the membership move here, then switch the bios when the calendar is live.',
            'links' => 'Our own features, plans and payout rules are on [features]({/features}), [pricing]({/pricing}) and [payouts]({/features/payouts}).',
            'sources' => 'What the store collects is reported and taxed the same way on both sides: the [IRS self-employed tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) sets out estimated payments, and the [IRS Form 1099-K page](https://www.irs.gov/businesses/understanding-your-form-1099-k) explains the processor\'s report of your sales.',
            'cta' => 'Bring the bookings and the membership here while the store runs out its month.',
        ),
        'eromify' => array(
            'scenario' => array(),
            'fee_lead' => '', 'fee_note' => '',
            'why_text' => 'You want to generate the character and sell what it makes on the same page.',
            'why_points' => array('Train the influencer, generate images and video, sell them as paid posts and bundles', 'A DM agent in the character\'s voice, every reply approved by you', 'Posts published to ten platforms from the same studio'),
            'checked' => 'Eromify rows were read on {date} from its pricing page and site, linked beside each value.',
            'hero_tail' => 'What follows: the sourced table, who Eromify suits, what moving the character involves, and the cost shape of generating and selling in one place.',
            'both' => 'Yes. Keep generating in Eromify while the character trains here; download your images, upload them to your library, and publish or sell them from the studio.',
            'links' => 'The selling side is described on [features]({/features}), [pricing]({/pricing}) and [payouts]({/features/payouts}).',
            'sources' => 'Selling what a character makes carries the same two obligations as any creator income, on any platform: self-employment tax under the [IRS self-employed individuals tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center), and a disclosure on sponsored posts under the [FTC\'s Disclosures 101](https://www.ftc.gov/business-guidance/resources/disclosures-101-social-media-influencers).',
            'cta' => 'Train the character here, then sell every image from the page it lives on.',
        ),
    );

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
                'The fit is weaker when your income comes from more than one thing. Fanvue does not publish social posting or a services and events layer (' . self::src($c, 'socials') . '). A creator who also sells coaching calls, ticketed sessions or bundles of past work runs those somewhere else and links out. That is the difference {site} is built around. The page, the paid posts, the bundles, the services, the events and the inbox sit in one place. The studio publishes each post to ten platforms at once.',
                'Keep Fanvue if your audience is already there, you sell nothing but the subscription and paid messages, and the AI voice tools matter to your fans. Consider the move if you want one page that sells every format and a lower share on the Studio plan.',
            )),
            array('h' => 'What does moving from Fanvue to {site} involve?', 'p' => array(
                'Start with your own files, not with Fanvue. Fanvue does not publish whether a creator can export the fan list (' . self::src($c, 'ownership') . '), so plan on telling fans yourself: a pinned post, a paid-message broadcast and the new link in every social bio. Upload your library to {site} first, set two membership tiers and put your strongest pieces behind pay-per-view, so the page has something to buy on the day you announce it.',
                'Money already earned on Fanvue follows Fanvue\'s terms. Its standard pending period is seven days and can run to twenty-eight (' . self::src($c, 'payout') . '), and it pays by bank transfer, crypto or third-party wallet. Request what is withdrawable before you stop posting there, and expect the last balance to clear on that schedule rather than on yours. On {site} every sale lands in one balance, net of your plan\'s platform fee, and you cash out to your bank on request from a {payout_min} balance with no platform hold; event earnings release after the event.',
                'Run both for one full billing cycle. Fans who renew on Fanvue keep renewing until you turn the page off, and {site} can cross-post to Fanvue from the same studio, so the Fanvue page stays fed while people move. Nothing has to be cancelled on day one, and renewals on the old page lapse on their own once you stop posting there.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Fanvue keeps 20% of gross revenue from paid services and the creator receives 80% (' . self::src($c, 'fee') . ', checked ' . $checked . '). {site} charges {fee_short}. Its monthly plan price is a separate line, which Fanvue does not have. The worked scenario on this page puts both side by side.',
                'Fanvue\'s AI tools are inside the fan app: a fan can hear a voice note or take an AI voice call on the platform (' . self::src($c, 'ai') . '). {site}\'s AI sits on the creator side. You train an AI influencer from photos or a description and generate images and short videos of that character. AI captions come with every post, and a DM agent drafts replies in your voice for you to approve. If your fans buy the voice calls themselves, that is a feature you give up; if you want to generate and sell the content, that is the feature you gain.',
                'Fanvue publishes an open API and an app store for third-party tools, which suits creators who run their business through other software. {site} offers a Claude connector instead, so an AI assistant can draft posts, read your analytics and manage your page through the same account. Both are ways to automate; they point at different tools.',
            )),
            array('h' => 'A worked scenario: a Fanvue creator with 300 subscribers', 'p' => self::scenario($c, 3000, 'subscriptions and paid messages (300 fans at $10)', 'fanvue')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- OnlyFans
    private static function compare_onlyfans(array $c, string $checked): array {
        return array(
            array('h' => 'Who is OnlyFans for, and why?', 'p' => array(
                'OnlyFans is the largest subscription platform, and its size is the reason to be there: third-party reporting puts it at more than 370 million registered users and 4 million creators in 2024 (' . self::src($c, 'content') . '). Fans already know how to pay there, the subscription, tips and pay-per-view model is familiar, and creators from athletes to musicians to adult creators use it. If your audience searches for you on OnlyFans by name, the platform does the first part of the selling for you.',
                'Everything on OnlyFans happens inside OnlyFans. It does not publish automatic posting to your social accounts, an AI toolset or an export of your subscriber list (' . self::src($c, 'ownership') . '). The page is the product, and the fan relationship lives in its app. That is fine for a creator who sells one subscription and promotes it by hand, and it is the limit a creator hits when the business grows past one format.',
                '{site} is built for the second case. One public page sells memberships, pay-per-view posts, bundles, services and live events. A studio publishes to ten platforms, AI influencers and an AI-assisted inbox come with it, and payouts go to your bank. Its platform fee is a supporting point, {fee_short} against the 20% OnlyFans deducts from each fan payment under its own terms (' . self::src($c, 'fee') . ', checked October 11, 2026).',
            )),
            array('h' => 'What does moving from OnlyFans to {site} involve?', 'p' => array(
                'Because OnlyFans publishes no subscriber export, the audience moves one fan at a time, through your own messages. Pin a post with the new link, send a mass message that says what the new page adds (bundles, services, events, the things OnlyFans does not sell), and change every social bio to the new address. Creators who make the new page better rather than merely cheaper keep more of the audience.',
                'Payouts already earned stay on OnlyFans\' schedule. Third-party reporting describes bank transfer and e-wallet payouts after a holding period of about seven days (' . self::src($c, 'payout') . '). OnlyFans\' own terms say earnings become available for withdrawal once reflected in the account and above its minimum payout amount (' . self::src($c, 'fee', 'terms') . '). Withdraw what is available before you wind the page down. On {site}, sales land in one balance net of your fee and pay out to your bank on request from {payout_min}, with no platform hold on content, membership or service sales; event earnings release after the event.',
                'Keep the OnlyFans page live for a full billing cycle while fans move. Give the new page something OnlyFans never had: a bundle of your back catalogue, a service with a fixed price, or a ticketed live session. Renewals on OnlyFans lapse on their own as you stop posting there; nothing has to be cancelled on day one.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'You trade the biggest audience for a page you own. OnlyFans brings discovery to creators whose fans look for them there. {site} brings a page whose audience list and media you can export at any time. Its studio pushes every post to the social networks you already post on. If your traffic comes from OnlyFans search, the move costs you that; if it comes from your socials, the move costs you nothing and gives you the publishing tool.',
                'You trade a single familiar format for five. OnlyFans sells subscriptions, tips and pay-per-view (' . self::src($c, 'content') . '). {site} adds bundles, services and events, and prices each one in credits from a wallet fans top up, so a fan who already loaded the wallet buys the next thing without re-entering a card. Memberships stay on the card, billed monthly, as fans expect.',
                'You trade a flat 20% share for a lower one. The share here is {fee_short}. Plan charges are a separate monthly line. The scenario below runs the shares on an example page.',
            )),
            array('h' => 'A worked scenario: an OnlyFans creator with 500 subscribers', 'p' => self::scenario($c, 5000, 'subscriptions, tips and pay-per-view (500 fans at $10)', 'onlyfans')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Patreon
    private static function compare_patreon(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Patreon for, and why?', 'p' => array(
                'Patreon is a membership platform for creators who publish on a schedule: video makers, podcasters, writers and artists. They get monthly or annual paid tiers, digital products, hosted video and community chats in one place (' . self::src($c, 'content') . ', checked ' . $checked . '). A podcaster with bonus episodes or a writer with a members-only newsletter is exactly who it is built for.',
                'Patreon\'s model is the patron, not the purchase. Pay-per-view posts, paid messages, ticketed live sessions and services are not what it sells, so a creator whose income depends on single unlocks or on booked time runs those elsewhere. Patreon also does not publish automatic posting to social accounts; its share menu lets you copy the page link yourself (' . self::src($c, 'socials') . ').',
                '{site} covers the same memberships, with as many tiers as you want. It adds the formats Patreon leaves out: pay-per-view posts, bundles, services, events and paid messages, all on one page. A studio publishes to ten platforms, and the inbox sells. For a creator who only needs tiers and a community, Patreon is a complete answer; for one who sells in several formats, it is one of several tools.',
            )),
            array('h' => 'What does moving from Patreon to {site} involve?', 'p' => array(
                'Patreon lets you take your members with you. The Relationship manager exports the member list, including emails, as a CSV (' . self::src($c, 'ownership') . '). Download it before you announce anything, then email members with the new link and the reason: the tier they know, plus the posts, bundles or sessions they could not buy on Patreon. {site} imports nothing automatically, so the email is the migration.',
                'Money on Patreon follows Patreon\'s payout rules. Withdrawals are creator-initiated, by bank transfer or online wallet; a five-day hold applies after you add a payout method, and one-time purchase funds stay pending up to seven days, or seventy-five for iOS in-app purchases (' . self::src($c, 'payout') . '). Clear the balance before you move. On {site} every sale lands in one balance net of your plan\'s platform fee, and you cash out on request from {payout_min} with no platform hold; event earnings release after the event.',
                'Rebuild the tiers before the email goes out: the same names, the same prices, the same perks, so a member sees the page they expected. Keep Patreon live for one billing cycle so nobody loses access mid-month, and let the Patreon renewals lapse as members re-join on the new page.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Patreon charges a 10% platform fee for pages published after August 4, 2025, with payment processing from 2.9% plus $0.30, currency conversion and payout fees on top; older pages sit on legacy rates of 5%, 8% or 11% (' . self::src($c, 'fee') . ', checked ' . $checked . '). {site}\'s Creator plan also takes 10%, and the Studio plan takes 3%. Plan prices are a separate monthly line. The scenario below runs the shares.',
                'Patreon hosts your video and runs community chats and polls inside the membership. {site} does not host a community chat. Its inbox handles one-to-one and broadcast messages with paid media attached, and its events sell seats to live sessions held in a built-in video room. A creator whose members gather in a chat loses that; a creator whose members pay for sessions gains the room.',
                'Patreon\'s AI is Clips, which suggests shareable clips from videos over eight minutes on iOS (' . self::src($c, 'ai') . '). {site}\'s AI drafts captions, answers DMs for your approval and builds an AI influencer whose images and videos you can sell. They are different tools for different work.',
                'Annual memberships are a Patreon feature worth naming. A member who prepays a year is a member you keep for a year. {site} bills memberships monthly, with free trials and promo codes to bring people in, so a creator whose income leans on annual prepayment gives that up and gains the trials instead.',
            )),
            array('h' => 'A worked scenario: a Patreon creator with 400 patrons', 'p' => self::scenario($c, 2400, 'memberships (400 patrons at $6)', 'patreon')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Fansly
    private static function compare_fansly(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Fansly for, and why?', 'p' => array(
                'Fansly is a subscription platform for creators, adult creators included. It sells subscriptions, pay-per-view posts, bundles, livestreams and paid messages, with public, ticketed and one-to-one streams on its own list of formats (' . self::src($c, 'content') . ', checked ' . $checked . '). Its For You feed shows creators to new viewers inside the app, which is the one thing most subscription platforms do not offer.',
                'A creator who streams, sells clips and wants in-app discovery is the Fansly creator. The platform keeps the audience inside its own feed, so growth there depends on that feed and on your own promotion. Fansly does not publish social posting, an AI toolset or an export of your fan list (' . self::src($c, 'ownership') . ').',
                '{site} is for the creator who sells more than the stream: memberships, pay-per-view, bundles, services and events on one page, published to ten platforms from one studio, with an AI-assisted inbox and AI influencers. It has no discovery feed of its own; the creator directory and the social publishing do that work instead, which suits creators whose audience already lives on their socials.',
            )),
            array('h' => 'What does moving from Fansly to {site} involve?', 'p' => array(
                'Fansly does not publish a fan export, so the move runs through your own announcements: a pinned post, a paid-message broadcast to every subscriber and the new link in every bio. Build the new page first so the announcement has somewhere to send people: the same tiers, the strongest pieces behind pay-per-view, a bundle of the back catalogue and, if you stream, a ticketed live session on the calendar.',
                'Fansly pays by ACH or SEPA bank transfer or e-wallet. Once verified, a creator can withdraw amounts above $20 as often as they like, and bank transfers typically arrive within three business days (' . self::src($c, 'payout') . '). Take the balance out before you stop posting. On {site} every sale lands in one balance net of your plan\'s platform fee, and you cash out on request from {payout_min} with no platform hold; event earnings release after the event has taken place.',
                'Keep both pages live for a billing cycle. Fansly\'s automated and keyword-triggered messages can keep telling subscribers where the new page is, and {site}\'s studio publishes to your socials so the move does not depend on the Fansly feed. Renewals on Fansly lapse on their own as you stop posting there.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Fansly takes a 20% fee and the creator keeps 80% of subscription and direct sales earnings (' . self::src($c, 'fee') . ', checked ' . $checked . '). {site}\'s platform fee is {fee_short}. Its plans also carry a monthly price, which is a separate line and is listed on the pricing page. The scenario below runs both shares on the same sales.',
                'Livestreaming is the real difference. Fansly streams to fans in its app with public, ticketed and one-to-one options. {site} sells tickets to live events and one-to-one sessions and hosts the call in a built-in video room with a waiting room, chat, screen share and host controls; it is a scheduled session, not an always-on stream. A creator who streams daily to a feed loses that format; a creator who sells sessions gains a room that does not depend on Zoom.',
                'Discovery is the other. Fansly\'s For You feed can surface a new creator to viewers who never searched for them. {site} relies on the social networks: every post goes to nine of them from one studio, with the paid version behind the lock on the page. If your growth comes from Fansly\'s feed, test the move before you commit to it; if it comes from your socials, the studio does more for you than the feed did.',
            )),
            array('h' => 'A worked scenario: a Fansly creator with 250 subscribers', 'p' => self::scenario($c, 2500, 'subscriptions and pay-per-view (250 fans at $10)', 'fansly')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Ko-fi
    private static function compare_kofi(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Ko-fi for, and why?', 'p' => array(
                'Ko-fi is a creator support platform for artists, writers, musicians and streamers. One-time and recurring tips, membership tiers, a shop for physical and digital items, commissions and stream alerts all run from one page (' . self::src($c, 'content') . ', checked ' . $checked . '). Its defining choice is that it does not hold your money: payments go straight into your own connected payment account, with no payout schedule and no minimum balance (' . self::src($c, 'payout') . ').',
                'Ko-fi Free charges no service fee on one-time tips and goals and 5% on memberships, monthly tips, shop sales and commissions; Standard mode charges 5% on everything, with payment processing on top in both cases (' . self::src($c, 'fee') . '). For a creator whose income is mostly tips and occasional commissions, that is close to the cheapest way to take money online, and nothing on this page argues otherwise.',
                'What Ko-fi does not do is publish to your socials, run an inbox that sells, or host paid events and sessions (' . self::src($c, 'socials') . '). {site} is for the creator who has moved past tips. Memberships with as many tiers as you want, pay-per-view posts, bundles, services and events sit on one page. A studio publishes every post to ten platforms, and an AI-assisted inbox and AI influencers come with it.',
            )),
            array('h' => 'What does moving from Ko-fi to {site} involve?', 'p' => array(
                'Ko-fi lets you leave with your people. Supporter and member lists, including supporter emails, download as CSV files (' . self::src($c, 'ownership') . '), so the migration is an email: the new link, the tier they know at the same price, and the things they could not buy before. Rebuild the tiers and the shop items as bundles or pay-per-view posts before you send it.',
                'There is no balance to wait for. Ko-fi paid you as each supporter paid, so there is nothing pending on Ko-fi\'s side when you move. On {site} the model is different. Sales land in one balance net of your plan\'s platform fee. Memberships bill the fan\'s card monthly and everything else is paid from a credit wallet. You cash out to your bank on request from {payout_min} with no platform hold; event earnings release after the event.',
                'Keep the Ko-fi page as a tip jar if you like; many creators do. Point the memberships, the shop and the commissions at the new page, where a commission becomes a service with a fixed price and a delivery window, and keep the Ko-fi link for one-off thanks. Nothing has to be closed on day one.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'On fee alone, Ko-fi keeps the smaller share. Its 0% on one-time tips and 5% on the rest is a lower share than {site}\'s {fee_short}, and Ko-fi Free has no monthly price. The reason to move is the formats and the publishing, not the fee.',
                'Ko-fi pays instantly to your own account. {site} pays on request from a balance, which adds a step. The balance is also one ledger across memberships, unlocks, bundles, services and events, rather than a processor dashboard. A creator who likes the money arriving as it is paid will prefer Ko-fi; a creator who wants one place to see every sale will prefer the balance.',
                'The publishing and the inbox are the gain. Ko-fi has no social scheduler and no AI (' . self::src($c, 'ai') . '). {site} publishes each post to ten platforms with AI captions and drafts DM replies for your approval. Welcome and trigger messages go out with paid media attached, and ticketed live sessions run in its own video room. If none of that is work you do, Ko-fi remains the simpler tool.',
            )),
            array('h' => 'A worked scenario: a Ko-fi creator with 150 members', 'p' => self::scenario($c, 1500, 'memberships and shop sales (150 members at $10)', 'kofi')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Linktree
    private static function compare_linktree(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Linktree for, and why?', 'p' => array(
                'Linktree is the link-in-bio page most people recognise: one URL in your social bios that lists your links, collects contacts and, in selected countries, sells digital products and courses (' . self::src($c, 'content') . ', checked ' . $checked . '). Its Social Planner auto-posts to Instagram, TikTok, Facebook, LinkedIn, Pinterest and YouTube Shorts, with social scheduling listed on the Premium plan (' . self::src($c, 'socials') . ').',
                'A creator who needs a tidy link page, a mailing list and the occasional eBook or template sale is the Linktree creator, and the free plan with unlimited basic links costs nothing. Recurring memberships and bookings are not in its selling guide (' . self::src($c, 'content') . '), and paid access to private communities is listed for the US only. A creator whose income is monthly tiers or booked time builds that somewhere else and links to it.',
                '{site} is the link page that takes the payment itself. Your page at your handle holds tracked links plus memberships, pay-per-view posts, bundles, services and events. Fans pay by card or credit wallet, and the studio publishes to ten platforms. It replaces the link page and the thing the link page was pointing at.',
            )),
            array('h' => 'What does moving from Linktree to {site} involve?', 'p' => array(
                'The contacts come with you: Linktree exports audience contacts as a CSV from the Audience tab on every plan (' . self::src($c, 'ownership') . '). Download it, recreate the links on your {site} page as tracked links, and email the list once the memberships or products are live. The social bios change last, once the new page has something to sell.',
                'Sales already made settle on Linktree\'s terms. Product and course sales pay out to the bank account connected to your payment account on the daily, weekly or monthly schedule you chose, and affiliate commissions, US only, are usually paid monthly once they reach $10 (' . self::src($c, 'payout') . '). There is little to wait for. On {site} every sale lands in one balance net of your plan\'s platform fee and pays out on request from {payout_min} with no platform hold; event earnings release after the event.',
                'Keep the Linktree page until the socials point at the new address, then let it go; there is no renewal to lapse. Social Planner users lose their scheduler at that point, which is why the studio\'s publishing to ten platforms is usually the first thing a Linktree creator sets up on the new page.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Linktree\'s fee depends on the plan: 12% of digital product and course sales on Free, 9% on Starter and Pro, and 0% on Premium at $35 a month or $30 billed annually. Card processing and a $0.25 per-payout charge apply on every plan (' . self::src($c, 'fee') . ', checked ' . $checked . '). A Premium subscriber selling a few products pays no share at all, so on fee alone Linktree Premium beats {site} for product sales; the scenario below shows both options next to our plans.',
                'The formats are the difference. Linktree sells one-time digital products and courses; {site} sells memberships, pay-per-view, bundles, services and events, with an inbox that attaches paid media to messages. A creator whose income is a $20 template a few times a week has no reason to move; a creator who wants monthly tiers and booked sessions has every reason.',
                'Both publish to social networks. Linktree\'s planner covers six, including YouTube Shorts; {site}\'s studio covers nine and puts the paid version of each post behind the lock on your page, so the social post and the sale are one action. Linktree\'s AI writes captions and auto-replies to Instagram comments and DMs (' . self::src($c, 'ai') . '); {site}\'s AI does the captions, drafts DM replies for approval across the inbox, and builds AI influencers whose images you sell.',
            )),
            array('h' => 'A worked scenario: a Linktree creator selling $1,200 of products a month', 'p' => self::scenario($c, 1200, 'digital product and course sales', 'linktree')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Beacons
    private static function compare_beacons(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Beacons for, and why?', 'p' => array(
                'Beacons is a creator business platform: a link-in-bio page, a website, a store, email marketing and a media kit for brand deals, in one account (' . self::src($c, 'content') . ', checked ' . $checked . '). Digital products, appointments and imported physical products are on every plan; memberships billed weekly, monthly or yearly and unlimited courses with video hosting start on Creator Plus (' . self::src($c, 'fee') . ').',
                'The creator who does brand partnerships is the Beacons creator: the media kit updates itself, the audience manager runs the email list, and the store sells the products. Its social features track and analyse posts across connected accounts and run Instagram DM automations, but post publishing is not listed (' . self::src($c, 'socials') . '), so the posting itself still happens app by app.',
                '{site} is for the creator who sells to fans directly rather than to brands. Memberships, pay-per-view posts, bundles, services and events sit on one page. The studio publishes every post to ten platforms, the inbox attaches paid media to messages, and AI influencers supply images and videos you can sell. There is no media kit here, and no email marketing tool; broadcasts go to audience segments inside the platform instead.',
            )),
            array('h' => 'What does moving from Beacons to {site} involve?', 'p' => array(
                'Your audience comes with you: contacts download as a CSV from Beacons\' Audience Manager (' . self::src($c, 'ownership') . '), while automatic export to email tools is a paid feature. Export the CSV, build the page, then email the list with the new link and what it adds. Products become bundles or pay-per-view posts, appointments become services with a fixed price and a delivery window, and a membership tier keeps its name and price.',
                'Store funds on Beacons go to your connected payment account and from there to your bank on a daily, weekly or custom schedule; affiliate commissions cash out from the Payouts tab (' . self::src($c, 'payout') . '). Nothing much is pending when you leave. On {site} sales land in one balance net of your plan\'s platform fee, and you cash out on request from {payout_min} with no platform hold; event earnings release after the event.',
                'The media kit is the thing to keep if brand deals pay your bills. Many creators keep a Beacons page for the kit and the brand outreach and move the fan-facing selling to {site}, with the link page on {site} pointing back at the kit. Both can run at once; nothing has to be cancelled.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Beacons charges a 9% seller fee on the Free and Creator plans ($10 a month) and 0% on Creator Plus at $30 a month and Creator Max at $100 (' . self::src($c, 'fee') . ', checked ' . $checked . '). A Creator Plus subscriber pays no share of sales, so for product sales Beacons Plus is the cheaper option on fee; {site} charges {fee_short}, and its plan price is a separate monthly line. The scenario below puts both Beacons options beside our plans.',
                'Beacons\' AI is on the business side: Beam, the assistant, plus AI text, image, email and page generation and brand outreach emails, metered by daily credits, 30 on Free, 300 on Creator and unlimited from Creator Plus (' . self::src($c, 'ai') . '). {site}\'s AI is on the content side. An AI influencer is trained from your photos or a description, with images and short videos of that character. Captions come with every post, and a DM agent drafts replies in your voice.',
                'Publishing is the clearest gap. Beacons analyses posts you made elsewhere; {site} makes the post once and sends it to ten platforms from the studio, with the paid version behind the lock. If the posting is the part of your week you want back, that is the feature that decides it.',
            )),
            array('h' => 'A worked scenario: a Beacons creator selling $1,800 a month', 'p' => self::scenario($c, 1800, 'digital products, appointments and a membership', 'beacons')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Stan Store
    private static function compare_stan(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Stan Store for, and why?', 'p' => array(
                'Stan Store is a storefront for coaches and educators. Courses, digital products, bookings, recurring subscriptions, communities and lead magnets are on the Creator plan; payment plans, upsells, discount codes, affiliates and email broadcasts are on Creator Pro (' . self::src($c, 'content') . ', checked ' . $checked . '). Its pricing is the pitch: $29 a month or $300 a year for Creator, $99 a month or $948 a year for Creator Pro, and no transaction fee on sales, with card processing still applying (' . self::src($c, 'fee') . ').',
                'A coach selling a $400 course and booked calls is who Stan Store is for. A flat price with no share of sales suits that creator exactly. Stan Store\'s AI Twin, a subscribable AI version of the creator trained on their content, is in beta, and Stan AutoDM automates Instagram replies. Stanley, a separately priced product, helps repurpose content for LinkedIn, Instagram, X, Threads and Substack (' . self::src($c, 'ai') . '). The store plans themselves do not list post publishing (' . self::src($c, 'socials') . ').',
                '{site} is for the creator whose fans buy little and often rather than one course at a time. Memberships, pay-per-view posts, bundles, services and live events sit on one page, paid from a credit wallet. One studio publishes to ten platforms, the inbox sells, and AI influencers come with the plan. Services and events are where the two overlap; the paid posts, bundles and publishing are where they differ.',
            )),
            array('h' => 'What does moving from Stan Store to {site} involve?', 'p' => array(
                'Customer and order data download as a CSV from Stan Store\'s Income tab (' . self::src($c, 'ownership') . '), so your buyers come with you. Recreate the bookings as services, the subscription as a membership tier, the course as a bundle of posts or as a pay-per-view series, and email the list once the page sells those things. Courses with hosted video are the hardest part to move; {site} stores media in your library rather than as a course player.',
                'Cash out of Stan Store first. A creator withdraws the full available balance, minimum $10, from the Income tab; it reaches the bank in 24 to 48 hours once sales have finished processing, which takes two to seven business days in the US (' . self::src($c, 'payout') . '). On {site} sales land in one balance net of your plan\'s platform fee, and you cash out on request from {payout_min}, with no platform hold on content, membership or service sales; event earnings release after the event.',
                'Run both while the course buyers finish. Stan Store\'s plan is monthly, so the store can stay open for a cycle while the new page takes the bookings and the memberships; point the social bios at the new page when the calendar there is live.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'On fee, Stan Store\'s model and ours are different shapes. Stan Store charges a flat $29 or $99 a month and no share of sales. {site}\'s platform fee is {fee_short}, and its plan price is a separate monthly line. Stan Store keeps no share of sales, so on the fee alone a creator keeps more of each sale there, and this page does not pretend otherwise. The scenario below shows the arithmetic on example sales.',
                'The formats are the reason to move, or not to. Stan Store sells one-time products, bookings and subscriptions, and its Pro plan adds the sales tools a course business wants. {site} sells the small, repeated purchases a fan audience makes: a paid post, a bundle of past work, a ticket to a live session, a paid message. All of those come from a wallet the fan already loaded, and memberships stay on the card.',
                'Publishing and the inbox are the other reasons. Stan Store does not publish posts; Stanley is a separate product for repurposing content. {site}\'s studio publishes each post to ten platforms with the paid version behind the lock, and its inbox drafts replies for your approval and attaches paid media to messages. A coach who sells through a funnel will not need those; a creator who sells through a feed will.',
            )),
            array('h' => 'A worked scenario: a Stan Store creator with $3,600 a month in sales', 'p' => self::scenario($c, 3600, 'courses, bookings and a subscription', 'stan')),
        );
    }

    // ---------------------------------------------------------------------------------------------------- Eromify
    private static function compare_eromify(array $c, string $checked): array {
        return array(
            array('h' => 'Who is Eromify for, and why?', 'p' => array(
                'Eromify is an AI influencer generator, not a place to sell. You train a persona, then generate images and videos of it with a wide choice of image and video models, motion control, upscaling, a workflow canvas, an AI agent and a Claude connector (' . self::src($c, 'ai') . ', checked ' . $checked . '). Pricing is monthly plans with a credit allowance, from 500 to 6,000 credits a month on Builder, Launch, Growth and Creator, each model costing a set number of credits per image or clip (' . self::src($c, 'fee') . ').',
                'The creator who wants the widest choice of generation models and already sells somewhere else is who Eromify suits. Its own site describes monetizing through social media, brand promotions and private media on other platforms, and lists no fan payments or payouts (' . self::src($c, 'payout') . '). Outputs are not watermarked, which matters when the images are the product.',
                '{site} covers the generation and the selling on one page. An AI influencer is trained from photos or a description, with images and short videos of the same character. Then come the formats that turn them into income: pay-per-view posts, bundles, memberships and paid messages. A DM agent answers fans in the character\'s voice for your approval, and a studio publishes to ten platforms. Eromify generates; {site} generates and sells.',
            )),
            array('h' => 'What does moving from Eromify to {site} involve?', 'p' => array(
                'There is no fan page to migrate and no balance to withdraw, because Eromify sells nothing to fans. The move is the images: download what you generated, upload them to your {site} library, and sell them as pay-per-view posts, bundles or membership content. New images are generated here from the same character, trained again from the photos you already have.',
                'Train the character on {site} before you cancel anything. Training takes a set of photos of one face; the influencer feature page lists how many. Once the character holds across images, the generation moves here, and the Eromify plan can lapse at the end of its month. Each {site} plan includes AI credits every month, {credits_creator} on Creator and {credits_studio} on Studio, and more can be bought at any time at {credits_per_dollar} credits per dollar.',
                'Then build the page the images sell from: two membership tiers, the strongest sets behind pay-per-view, a bundle of the archive, and the DM agent switched on with your approval required. The studio publishes each post to the networks where the character\'s audience lives, with the paid version behind the lock.',
            )),
            array('h' => 'What are the trade-offs in plain terms?', 'p' => array(
                'Eromify offers more generation models than {site} does, and a workflow canvas for batch work. A creator who experiments across many models, or who generates in volume for several personas, may keep Eromify for that and bring the results here to sell. {site} runs its generation through its own provider and prices it in credits; the number of AI influencers is {inf_creator} on Creator and {inf_studio} on Studio, with more available as a monthly add-on.',
                'Eromify has no platform fee because it has no sales; its cost is the plan and the credits. {site} charges {fee_short} on sales. Its plan price is a separate monthly line, and the plan includes the credits. Eromify is a generation tool, and {site} is a generation tool with a selling side.',
                'Ownership of the character is the long-term question. On {site} the trained character, its images and your audience list are yours to export at any time. On Eromify the outputs are downloadable and the fan relationship does not exist, because the fans are on whatever platform you sell through. If that platform is this one, the character and the audience end up in the same place.',
            )),
            array('h' => 'A worked scenario: an Eromify creator who starts selling here', 'p' => array(
                'Take a creator who generates on Eromify and sells 200 pay-per-view unlocks a month at $5 on another platform. The numbers are round and hypothetical. That is $1,000 a month gross wherever it is sold, and the selling platform\'s fee comes off it there. Eromify takes no part in the sale, so its plan and credits are a separate line.',
                'Moved to {site}, the same 200 unlocks are paid from fans\' credit wallets at {credits_per_dollar} credits per dollar. The Creator plan keeps {fee_creator}% of each sale and includes {credits_creator} AI credits a month for generating the next month\'s images, so the generation tool and the selling fee are one account; Studio keeps {fee_studio}% and includes {credits_studio} credits and {inf_studio} AI influencers. The monthly plan prices are a separate line on the pricing page.',
                'The scenario cannot be run on Eromify\'s side because it publishes no fan sales. What it does show is where each product sits: one tool for generating and selling, against two.',
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
            array('h' => 'How do you choose among these OnlyFans alternatives?', 'p' => array(
                'Start from what you sell, not from the platform names. Every option on this list is one of three kinds. A subscription platform copies the OnlyFans model with a different fee or feed. A membership platform is built for patrons. A page like {site} sells several formats at once. A creator whose fans buy one subscription and nothing else is choosing between the first kind; a creator who also sells sessions, bundles or events is choosing the third.',
                'Then ask three questions of each candidate. Can you take your audience with you if you leave, in a file you can download? What does it sell beyond the subscription, and does it publish to your socials or leave the promotion to you? How does the money reach you: on what schedule, after what hold, from what minimum? The compare pages linked from each card answer those with the platform\'s own sources, and each card is a short version of the same record.',
                'Weigh the fee last, after the formats and the ownership terms. {site}\'s platform fee is {fee_short}, and the worked example on this page puts those shares beside a flat 20%. Plan prices are a separate monthly line on the pricing page.',
            )),
            array('h' => 'What does leaving OnlyFans actually involve?', 'p' => array(
                'OnlyFans publishes no export of your subscriber list and no automatic posting to social accounts (' . self::src($of, 'ownership') . '), so the audience moves through your own messages: a pinned post, a mass message, the new link in every social bio. Third-party reporting puts it at more than 370 million registered users (' . self::src($of, 'content') . '). The fans who found you through its search are the ones a move can cost. The fans who found you through your socials are the ones it cannot.',
                'The money follows OnlyFans\' terms: a 20% fee is deducted from each fan payment, and creator earnings are available for withdrawal once reflected in the account and above its minimum payout amount (' . self::src($of, 'fee', 'terms') . ', checked October 11, 2026). Third-party reporting describes a holding period of about seven days (' . self::src($of, 'payout') . '). Withdraw what is available before the page goes quiet.',
                'Give the new page something the old one could not sell before you announce it. A bundle of the back catalogue, a service with a fixed price and a delivery window, a ticketed live session: those are the reasons a fan follows you to a new address. OnlyFans does not offer any of them. Then keep both pages live for a billing cycle and let the old renewals lapse on their own.',
            )),
            array('h' => 'What changes on {site} for a OnlyFans creator?', 'p' => array(
                'The page becomes the business. Memberships bill the fan\'s card monthly, with as many tiers as you want. Pay-per-view posts, bundles, services, event tickets and paid messages are paid from a credit wallet fans top up at {credits_per_dollar} credits per dollar. A second purchase needs no card. Every sale lands in one balance net of your plan\'s platform fee, and you cash out to your bank on request from {payout_min} with no platform hold; event earnings release after the event has taken place.',
                'The studio does the promotion you used to do by hand. A post written once goes to your page and to ten platforms at the same time, with the paid version behind the lock, and AI captions drafted for each. The inbox drafts replies in your voice for your approval, sends welcome and trigger messages with paid media attached, and runs broadcasts to audience segments.',
                'The audience list and your media are yours to export at any time, which is the difference that matters most on the day you decide to leave again. Adult content is allowed behind an age check: fans opt in and verify once, creators verify before publishing adult media, and every upload is checked automatically.',
            )),
            array('h' => 'A worked scenario: 400 subscribers leaving OnlyFans', 'p' => self::scenario($of, 4000, 'subscriptions and pay-per-view (400 fans at $10)', 'onlyfans')),
        );
    }

    private static function alt_fanvue(): array {
        $fv = PagesController::COMPETITORS['fanvue']; $checked = date('F j, Y', strtotime($fv['checked']));
        return array(
            array('h' => 'How do you choose among these Fanvue alternatives?', 'p' => array(
                'Fanvue creators usually leave for one of two reasons: they want a page they own, with an audience they can take with them, or they want to sell in more formats than a subscription and paid messages. The list above is sorted by kind of platform for that reason. The subscription platforms keep the model you know; the membership platforms suit regular publishing; {site} is the page that sells every format and publishes everywhere.',
                'AI creators have a particular question to ask: where does the character live, and who owns it? Fanvue welcomes AI-generated creator accounts and sells fans AI voice calls and voice notes (' . self::src($fv, 'ai') . ', checked ' . $checked . '). A platform that only hosts the page leaves the generation to another tool. {site} trains the character, generates the images and short videos, and sells them from the same page. A DM agent answers in the character\'s voice for your approval.',
                'For every candidate, check the three things that decide a switch: whether the fan list exports, what the platform sells beyond the subscription, and how payouts work. The compare pages linked from the cards carry those facts with sources, and each card is a short version of the same record.',
            )),
            array('h' => 'What does leaving Fanvue actually involve?', 'p' => array(
                'Fanvue does not publish whether a creator can export the fan list (' . self::src($fv, 'ownership') . '), so the audience moves through your own messages and bios. {site} can cross-post to Fanvue from the same studio, which keeps the Fanvue page fed while fans move, so the old page need not go quiet on day one. Keep both live for a full billing cycle.',
                'Fanvue keeps 20% of gross revenue from paid services and pays by bank transfer, crypto or third-party wallet after a standard pending period of seven days that can run to twenty-eight (' . self::src($fv, 'payout') . ', checked ' . $checked . '). Request what is withdrawable before you stop posting, and expect the last balance to clear on that schedule.',
                'If the character is AI, train it on {site} before the move: a set of photos of one face, then test that it holds across images, then generate the first month of posts. Each plan includes AI credits every month, {credits_creator} on Creator and {credits_studio} on Studio, and more can be bought at {credits_per_dollar} credits per dollar. The page is ready when it has two tiers, a few pay-per-view posts and a bundle of the archive.',
            )),
            array('h' => 'What changes on {site} for a Fanvue creator?', 'p' => array(
                'The formats widen. Alongside memberships and paid messages, the page sells pay-per-view posts, bundles of past work and services with a fixed price and a delivery window. Tickets to live sessions are held in a built-in video room with a waiting room, chat and host controls. Fans pay memberships by card and everything else from a credit wallet, so a second purchase takes one tap.',
                'The promotion stops being manual. The studio publishes each post to ten platforms and to your page at once, with the paid version behind the lock. The inbox drafts replies for your approval and sends welcome and trigger messages with paid media attached. Fanvue\'s AI voice calls for fans do not exist here; the AI works on the creator\'s side instead.',
                'The money is one balance. Every sale lands net of your plan\'s platform fee, {fee_short}, and pays out to your bank on request from {payout_min} with no platform hold; event earnings release after the event. The audience list and your media export at any time.',
                'Adult content is allowed behind an age check, which matters to many Fanvue creators. Fans opt in and verify once, creators verify before publishing adult media, and every upload is checked automatically. Nothing adult is shown to a fan who has not opted in, and the public page stays safe for work.',
            )),
            array('h' => 'A worked scenario: 200 subscribers leaving Fanvue', 'p' => self::scenario($fv, 2000, 'subscriptions and paid messages (200 fans at $10)', 'fanvue')),
        );
    }

    private static function alt_patreon(): array {
        $pt = PagesController::COMPETITORS['patreon']; $checked = date('F j, Y', strtotime($pt['checked']));
        return array(
            array('h' => 'How do you choose among these Patreon alternatives?', 'p' => array(
                'Start from what your fans pay for. Patreon is built around a recurring tier, so the alternatives fall into three kinds. Tipping and membership tools keep the tier and add one-off support. Storefronts sell products and bookings from a link page. A page like {site} sells memberships beside pay-per-view posts, bundles, services and event tickets.',
                'Then ask each candidate the same three questions. Does the member list export, with emails, in a file you can download? What does it sell beyond the monthly tier? Does it publish to your social accounts, or is promotion left to you? The compare pages linked from each card answer those from the platform\'s own pages.',
                'Weigh the fee last, after the formats and the ownership terms. {site}\'s platform fee is {fee_short}, and the worked example on this page puts those shares beside Patreon\'s. Plan prices are a separate monthly line on the pricing page.',
            )),
            array('h' => 'What does leaving Patreon actually involve?', 'p' => array(
                'Patreon lets you take the list with you: the member list, including emails, downloads as a CSV from the Relationship manager (' . self::src($pt, 'ownership') . ', checked ' . $checked . '). Download it before you announce anything. Memberships do not transfer, so each member joins again on the new page, and an email with the new address is how most of them will hear about it.',
                'The money follows Patreon\'s payout rules. Withdrawals are creator-initiated, a five-day hold applies after a payout method is added, and one-time purchase funds stay pending for up to seven days (' . self::src($pt, 'payout') . '). Clear the balance before the page goes quiet.',
                'Rebuild the tiers before the email goes out, with the same names and prices so nobody has to relearn the offer. Keep Patreon live for one billing cycle and let the renewals lapse on their own.',
            )),
            array('h' => 'What changes on {site} for a Patreon creator?', 'p' => array(
                'The formats widen. Beside membership tiers the page sells pay-per-view posts, bundles of past work, services with a fixed price and tickets to live sessions held in a built-in video room. Members pay by card on a schedule, and everything else is paid from a credit wallet at {credits_per_dollar} credits per dollar.',
                'Promotion moves into the same tool. Patreon offers a share menu and publishes no automatic posting to social accounts (' . self::src($pt, 'socials') . '). On {site} a post written once goes to your page and to ten platforms together, with the paid version behind the lock.',
                'The money is one balance. Every sale lands net of your plan\'s platform fee, {fee_short}, and pays out to your bank on request from {payout_min} with no platform hold; event earnings release after the event. The audience list and your media export at any time.',
            )),
            array('h' => 'A worked scenario: 300 members leaving Patreon', 'p' => self::scenario($pt, 2400, 'memberships (300 members at $8)', 'patreon')),
        );
    }

    private static function alt_linktree(): array {
        $lt = PagesController::COMPETITORS['linktree']; $checked = date('F j, Y', strtotime($lt['checked']));
        return array(
            array('h' => 'How do you choose among these Linktree alternatives?', 'p' => array(
                'Start from what the page has to do after the click. A link page sends the visitor somewhere else to buy. A storefront takes payment on the page for products, courses and bookings. A page like {site} takes payment for memberships, pay-per-view posts, bundles, services and event tickets, and keeps tracked links beside them.',
                'Then check what each candidate actually sells. Linktree\'s selling guide lists one-time digital products, courses, affiliate links and sponsored links, and does not list recurring memberships or bookings (' . self::src($lt, 'content') . ', checked ' . $checked . '). A creator whose income is recurring needs a page that bills on a schedule.',
                'Weigh the fee last. {site}\'s platform fee is {fee_short}, and the worked example on this page puts those shares beside Linktree\'s. Plan prices are a separate monthly line on the pricing page.',
            )),
            array('h' => 'What does leaving Linktree actually involve?', 'p' => array(
                'A link page is the easiest thing to move, because the audience follows the link in your bio and not the tool behind it. Export your contacts first: Linktree lets creators download audience contacts as a CSV from the Audience tab on all plans (' . self::src($lt, 'ownership') . ').',
                'Product and course sales settle to the bank account connected to your payment account, on the daily, weekly or monthly schedule you chose (' . self::src($lt, 'payout') . '). Let the last sales settle, then change the link in each bio to the new page.',
                'Rebuild the links in the same order, so a returning visitor finds what they expect, then add the first thing the old page could not sell.',
            )),
            array('h' => 'What changes on {site} for a Linktree user?', 'p' => array(
                'The page sells where it used to point. Memberships bill the fan\'s card on a schedule, and pay-per-view posts, bundles, services and event tickets are paid from a credit wallet at {credits_per_dollar} credits per dollar. Every link on the page is tracked, so you can see which ones are clicked.',
                'Publishing sits in the same studio. A post written once goes to your page and to ten platforms together, with AI captions drafted for each and the paid version behind the lock.',
                'Earnings from every kind of sale meet in a single balance, after the platform fee of {fee_short}. You move that balance to your bank when you choose, from {payout_min} upward. Your contacts and your media can be exported whenever you like.',
            )),
            array('h' => 'A worked scenario: a link page that starts selling', 'p' => self::scenario($lt, 1500, 'digital products and bookings', 'linktree')),
        );
    }

    /** /best-creator-monetization-platforms: how to read the comparison, with the sourced rows. */
    public static function best_platforms(): array {
        $c = PagesController::COMPETITORS; $d = function ($k) use ($c) { return date('F j, Y', strtotime($c[$k]['checked'])); };
        return array(
            array('h' => 'How should you read the fee column?', 'p' => array(
                'The fee column mixes two shapes of pricing, and the shape matters more than the number. Subscription platforms take a share of every sale and charge no plan price: Fanvue keeps 20% of gross revenue (' . self::src($c['fanvue'], 'fee') . ', checked ' . $d('fanvue') . '), Fansly keeps 20% (' . self::src($c['fansly'], 'fee') . ') and OnlyFans deducts 20% of each fan payment under its terms (' . self::src($c['onlyfans'], 'fee') . ', checked October 11, 2026). Storefronts invert it: Stan Store charges $29 or $99 a month and no transaction fee (' . self::src($c['stan'], 'fee') . '), Beacons 0% on Creator Plus at $30 a month and 9% on its free plan (' . self::src($c['beacons'], 'fee') . '), Linktree 12% on Free down to 0% on Premium at $35 (' . self::src($c['linktree'], 'fee') . ').',
                'Patreon and Ko-fi sit between. Patreon charges 10% for pages published after August 4, 2025, plus processing, conversion and payout fees (' . self::src($c['patreon'], 'fee') . '); Ko-fi Free charges nothing on one-time tips and 5% on memberships, shop sales and commissions (' . self::src($c['kofi'], 'fee') . '). {site}\'s platform fee is {fee_short}. Its monthly plan price is a separate line, and card processing is separate as everywhere.',
                'So read the share and the monthly price as two separate columns. Ko-fi and the free storefront tiers charge no monthly price. The subscription platforms keep about 20% of each sale and charge no monthly price either. The fee table further down runs two sales volumes through every platform with a published rate, on the percentage alone.',
            )),
            array('h' => 'How do payout terms differ?', 'p' => array(
                'Ko-fi pays immediately into the creator\'s own connected payment account, with no schedule and no minimum (' . self::src($c['kofi'], 'payout') . '). Stan Store pays the full available balance, minimum $10, in 24 to 48 hours once sales have finished processing (' . self::src($c['stan'], 'payout') . '). Linktree and Beacons settle store sales to the connected account on a schedule the creator chooses (' . self::src($c['linktree'], 'payout') . '; ' . self::src($c['beacons'], 'payout') . ').',
                'The subscription platforms hold money longer. Fanvue\'s standard pending period is seven days and can extend to twenty-eight (' . self::src($c['fanvue'], 'payout') . '); Fansly pays amounts above $20 as often as you like, typically within three business days by bank transfer (' . self::src($c['fansly'], 'payout') . '); OnlyFans is reported to pay after a holding period of about seven days (' . self::src($c['onlyfans'], 'payout') . '). Patreon applies a five-day hold after a payout method is added and keeps one-time purchases pending up to seven days (' . self::src($c['patreon'], 'payout') . ').',
                '{site} pays on request from a {payout_min} balance to your bank, with no platform hold on content, membership or service sales; earnings from an event release after the event has taken place. One balance covers memberships, unlocks, bundles, services and tickets, so there is one number to watch rather than one per product.',
            )),
            array('h' => 'Who owns the audience when you leave?', 'p' => array(
                'Patreon exports the member list with emails as a CSV (' . self::src($c['patreon'], 'ownership') . '); Ko-fi exports supporter and member lists (' . self::src($c['kofi'], 'ownership') . '); Linktree exports contacts on every plan (' . self::src($c['linktree'], 'ownership') . '); Beacons exports from its Audience Manager (' . self::src($c['beacons'], 'ownership') . '); Stan Store exports customer and order data from the Income tab (' . self::src($c['stan'], 'ownership') . '). OnlyFans, Fanvue and Fansly do not publish an export.',
                'That line divides the list more sharply than the fee does. A creator on a platform with no export rebuilds the audience through messages and bios on every move; a creator with a CSV emails it. {site} exports the audience list and your media at any time, and the inbox can broadcast to segments of that audience with paid media attached, which is how a list becomes income rather than a file.',
                'Publishing follows the same line. Linktree\'s planner posts to six networks and the rest of the list leaves social posting to you; {site}\'s studio publishes each post to ten platforms with the paid version behind the lock on your page, so the promotion and the sale are one action.',
            )),
            array('h' => 'A worked scenario: the same creator on three platforms', 'p' => array_merge(
                array('Take a creator with $2,800 a month across memberships and single purchases, round and hypothetical. On a 20% subscription platform the platform keeps $560 and $2,240 is left before card processing. On Stan Store Creator the platform takes no share of sales, so the $2,800 is left before card processing, and its $29 a month is a separate line (' . self::src($c['stan'], 'fee') . ').'),
                array_slice(self::scenario($c['fanvue'], 2800, 'memberships and single purchases'), 2)
            )),
        );
    }

    /** /monetize-your-content: the five ways in prose, then what a sale turns into. */
    public static function monetize(): array {
        return array(
            array('h' => 'What is each of the five ways good for?', 'p' => array(
                'A membership is a promise you repeat: a monthly price for what arrives next month, not for the archive. Two tiers are a sound place to start, an entry tier priced where a fan says yes without thinking and a higher tier for the people who want more of you, and the gap between them is where the value shows. Members pay by card on a schedule, so they think before joining and rarely think again, which is why a weekly post matters more than any single one.',
                'A pay-per-view post is one piece at one price for everyone, members included. It suits your strongest single pieces, the ones that took the most effort, and it is priced by effort and scarcity rather than length. Because fans pay it from a credit wallet they have already loaded, it behaves like an impulse purchase: keep it cheap enough to decide in a second, and never below a quarter of the entry tier, or the tier stops making sense.',
                'A bundle turns the back catalogue into a product: a themed set of past media at a discount to the sum of the parts. It needs no new work, only a title and a price, and it is the easiest upsell after a single unlock. A service is custom work at a fixed price with a clear scope and a delivery window you can keep, and it is where a small audience earns the most per fan. An event sells seats to a live session ahead of time, caps the room, and can be recorded and sold afterwards as a bundle.',
            )),
            array('h' => 'What does a sale turn into on {site}?', 'p' => array(
                'Every sale lands in one balance, net of your plan\'s platform fee of {fee_short}. Memberships bill the fan\'s card; unlocks, bundles, services and tickets are paid from a credit wallet at {credits_per_dollar} credits per dollar. Card processing is separate, as on every platform, and the plan price is a separate monthly line.',
                'You cash out on request from a {payout_min} balance to your bank, with no platform hold on content, membership or service sales. Earnings from an event release after the event has taken place, so a cancelled session never leaves a fan unpaid. Content sales are final. If a sale is reversed, the amount comes out of the same balance.',
                'What reaches the bank is self-employment income. The [IRS self-employed individuals tax center](https://www.irs.gov/businesses/small-businesses-self-employed/self-employed-individuals-tax-center) sets out the estimated payments due on it, and the [IRS page on Form 1099-K](https://www.irs.gov/businesses/understanding-your-form-1099-k) explains what a payment processor reports about your sales once you pass the threshold on that page. A sponsored post on your socials needs a disclosure, in the wording the [FTC\'s Disclosures 101 for influencers](https://www.ftc.gov/business-guidance/resources/disclosures-101-social-media-influencers) describes.',
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
