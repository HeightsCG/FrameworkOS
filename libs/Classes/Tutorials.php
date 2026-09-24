<?php
/**
 * Walkthrough videos: the Tutorials list on /support and the "Watch How" buttons on the
 * pages they cover. Files live in public/videos/tutorials as <id>.mp4 plus an <id>.jpg
 * poster; a video only appears once its .mp4 is there, so new ones go live by dropping
 * the file in and setting 'secs'. Steps are the narration script, shown as the transcript.
 */
class Tutorials {

    const DIR = '/videos/tutorials';

    const SECTIONS = array(
        'start'  => 'Getting Started and Settings',
        'studio' => 'Content Studio',
    );

    const VIDEOS = array(
        '01' => array('section' => 'start', 'title' => 'Become a Creator', 'secs' => 32, 'steps' => array(
            'Welcome to Creator Link Studio. Let\'s turn your account into a creator account so you can start posting and getting paid.',
            'Click your name in the top right, then Settings.',
            'Open Become a Creator.',
            'Read through the Creator Agreement and Content Policy.',
            'Check the box to accept it, then click Become a Creator.',
            'That\'s it. Your creator tools are unlocked, including Content Studio, Analytics, Audience and more.',
        )),
        '02' => array('section' => 'start', 'title' => 'Choose Your Plan', 'secs' => 65, 'steps' => array(
            'Your plan sets your platform fee and which AI tools you get. Here\'s how to pick one.',
            'Click your name, then Billing.',
            'Free has no monthly cost and a 20 percent platform fee.',
            'Creator is 49 dollars a month. Your fee drops to 10 percent and you get an AI influencer, 50 AI credits a month and scheduled automations.',
            'Studio is 199 dollars a month. Your fee drops to 3 percent, with 10 AI influencers, 300 AI credits a month, unlimited automations and 10 team seats.',
            'Every plan includes unlimited social connections, membership tiers, bundles, promo codes and analytics.',
            'Pick the plan that fits and click Choose. If you have a promo code, enter it at checkout.',
            'Your current plan always shows at the top of this page.',
        )),
        '03' => array('section' => 'start', 'title' => 'Set Up Your Creator Profile', 'secs' => 30, 'steps' => array(
            'Your creator profile is what fans see first. Let\'s set it up.',
            'In Settings, open Creator Profile.',
            'Upload a cover image and a profile photo.',
            'Add your display name, your location if you want it shown, and a short bio.',
            'Click Save Changes.',
            'Under Links, add anywhere else fans can find you.',
            'Want a verified badge? Click Request Verification at the top.',
        )),
        '04' => array('section' => 'start', 'title' => 'Brand Identity', 'secs' => 30, 'steps' => array(
            'Brand Identity teaches Creator Link Studio how your brand looks and sounds, so AI images and captions match your style.',
            'In Settings, open Brand Identity.',
            'Paste your website and click Generate. It drafts a brand kit from your own content.',
            'Review everything it filled in: brand name, tagline, description and voice.',
            'Adjust your brand colors and keywords. Add or remove anything that doesn\'t fit.',
            'Nothing is saved until you click Save Brand Identity.',
        )),
        '05' => array('section' => 'start', 'title' => 'Membership Plans, Codes and Bundles', 'secs' => 45, 'steps' => array(
            'This is where you set what fans pay for. Open Membership Plans in Settings.',
            'Click Add Plan to create a subscription tier.',
            'Give it a name and a monthly price. You can also make it a free tier or offer a free trial.',
            'Add a short description and your perks, one per line, then click Save Plan.',
            'Drag tiers to reorder them, and use the toggle to show or hide a tier on your profile.',
            'Under Discount Codes, click Add Code to create a percent off code fans use at checkout. You can set a redemption cap or an expiry date.',
            'Under Content Bundles, click Add Bundle to sell a group of photos and videos from your Library at one price.',
            'Name it, set a price, pick the content, and click Save Bundle. Buyers get every item in their Purchases.',
        )),
        '06' => array('section' => 'start', 'title' => 'Integrations', 'secs' => 31, 'steps' => array(
            'Integrations connects your social accounts so you can publish everywhere from one place.',
            'In Settings, open Integrations.',
            'Under Social Accounts, click Connect next to any platform. LinkedIn, Bluesky, X, Facebook, Instagram, Threads, TikTok, YouTube and Pinterest are all supported.',
            'Sign in on that platform and approve the connection.',
            'Connected accounts show up here, and you can add more than one per platform.',
            'Once connected, they appear in the Distribution step every time you create a post.',
        )),
        '07' => array('section' => 'start', 'title' => 'Inbox Automation', 'secs' => 47, 'steps' => array(
            'Inbox Automation answers fan messages in your voice, so you never leave a fan waiting.',
            'In Settings, open Inbox Automation, then the Settings tab.',
            'Turn on Reply to fan messages.',
            'Choose Approve first to review every draft, or Send automatically to let replies go out on their own.',
            'Set quiet hours, and cap how many replies go out in a row before it waits for you.',
            'Describe how you talk to fans, and list any topics to avoid. Those messages get held for you.',
            'Type a sample message under Try It and click Draft a Reply to test it. Then click Save.',
            'In the Welcome Messages tab, set automatic messages for new followers, new subscribers, first messages and new purchases.',
            'Drafts waiting for your approval show up in the Queue tab.',
        )),
        '08' => array('section' => 'start', 'title' => 'Account Profile', 'secs' => 24, 'steps' => array(
            'Your account settings control how you sign in and how we reach you.',
            'Click your name, then Settings. You\'ll land on Account.',
            'Upload a profile photo.',
            'Set your username. This is your public handle, and you can change it once every 30 days.',
            'Fill in your name and email, plus phone, business name and website if you want.',
            'Click Update Profile to save.',
        )),
        '09' => array('section' => 'start', 'title' => 'Security', 'secs' => 24, 'steps' => array(
            'Let\'s lock down your account. In Settings, open Security.',
            'Click Change Password to update your password.',
            'Turn on two-factor authentication for a second step at sign in.',
            'Use an authenticator app like Google Authenticator, Authy or 1Password.',
            'Or use email verification, and we\'ll send a one-time code to your email every time you sign in.',
        )),
        '10' => array('section' => 'start', 'title' => 'Notifications', 'secs' => 21, 'steps' => array(
            'Choose what you hear about and where. In Settings, open Notifications.',
            'Each row is a type of update: messages, creator activity, broadcasts, purchases, subscriptions, events, services, credits and auto top-ups.',
            'Use the toggles to get each one in the app, by email, both or neither.',
        )),
        '11' => array('section' => 'start', 'title' => 'Wallet and Credits', 'secs' => 22, 'steps' => array(
            'Credits power AI tools and unlocks. In Settings, open Wallet.',
            'Your current balance is at the top.',
            'Under Buy Credits, pick a pack. A small processing fee is added at checkout.',
            'History shows every credit you\'ve bought and spent.',
            'Auto-Replenishment tops up your balance automatically when it runs low. Click Manage to turn it on.',
        )),
        '12' => array('section' => 'start', 'title' => 'Restricted Content and Blocked Users', 'secs' => 19, 'steps' => array(
            'Two quick settings for what you see and who can reach you.',
            'Restricted Content controls whether adult content is shown. It\'s hidden by default, and you must be 18 or older in a permitted region to turn it on.',
            'Blocked Users lists anyone you\'ve blocked. You can unblock them from here at any time.',
        )),
        '13' => array('section' => 'studio', 'title' => 'Content Studio Tour', 'secs' => 49, 'steps' => array(
            'This is Content Studio, home base for everything you publish.',
            'Five tabs run across the top: Posts, Library, Calendar, Collections and Scheduler.',
            'Posts lists everything you\'ve made, with its status, audience, views, comments, earnings and shares.',
            'Search by caption, or filter to drafts, scheduled, published or archived.',
            'The three dot menu on any post lets you duplicate it, share it to your socials, archive it or remove it.',
            'Privacy blurs your thumbnails, so you can work in public or share your screen.',
            'Library holds every photo and video you\'ve uploaded or generated.',
            'Calendar shows what went out and what\'s coming up.',
            'Collections group related files so they\'re easy to find.',
            'Scheduler runs your automations and scheduled messages.',
            'And the Action button is your shortcut to create anything.',
        )),
        '14' => array('section' => 'studio', 'title' => 'Create and Publish a Post', 'secs' => 57, 'steps' => array(
            'Let\'s publish a post. Click Action, then New Post.',
            'Add media from your Library, or upload new files.',
            'Pick your photos or videos and click Add to Post. The first one becomes the cover.',
            'Write your caption, or click Write a caption to have AI draft one.',
            'The preview on the right shows exactly what fans will see.',
            'Next, Audience. Choose Everyone, Subscribers, or Pay-per-view.',
            'For pay-per-view, set your unlock price, anywhere from 3 to 500 dollars.',
            'Switch the preview to Public to see the locked version.',
            'Under Distribution, pick where it goes: your profile and any connected social accounts.',
            'Last, Publish. Choose Publish now and click Publish now.',
            'Your post is live, and it shows at the top of your Posts list.',
        )),
        '15' => array('section' => 'studio', 'title' => 'Schedule a Post', 'secs' => 19, 'steps' => array(
            'Want a post to go out later? Build it the same way, then open the Publish step.',
            'Choose Schedule.',
            'Pick the date and time. It uses your time zone, shown right below.',
            'Click Schedule post.',
            'It shows as Scheduled in your Posts list, and on your calendar.',
        )),
        '16' => array('section' => 'studio', 'title' => 'Media Library and Collections', 'secs' => 36, 'steps' => array(
            'Library is every photo and video on your account in one place.',
            'Search, or filter by type, collection, influencer, or whether a file has been used yet.',
            'Not used yet is great for finding content you haven\'t posted.',
            'Badges on each tile show how many posts use it.',
            'Click any file to open its details. Add a note about what it is and how you plan to use it.',
            'Watermark puts your name on the delivered image. Leave it on to protect your work.',
            'Add the file to a collection right from here.',
            'To make a new collection, click Action, then Create Collection, and give it a name.',
            'Your collections live in the Collections tab.',
        )),
        '17' => array('section' => 'studio', 'title' => 'The Content Calendar', 'secs' => 22, 'steps' => array(
            'Calendar lays out every post by day.',
            'Jump to today, or move back and forward a month at a time.',
            'This bar shows how many automations are posting on schedule.',
            'Green items already went out. Scheduled posts and upcoming automation runs show ahead.',
            'Switch to Week for a closer look.',
            'Click any post to open it and make changes.',
        )),
        '18' => array('section' => 'studio', 'title' => 'Automations', 'secs' => 39, 'steps' => array(
            'Automations create and publish posts for you on a schedule.',
            'Click Action, then New Automation.',
            'Give it a name.',
            'Describe the scene. List a few ideas and it rotates through them.',
            'Choose Brand photo or one of your AI influencers, then square, portrait or landscape.',
            'Under Publishing, pick the audience. AI captions writes the caption for you.',
            'Under Destinations, pick the social accounts it should post to.',
            'Set the schedule, daily or on the weekdays you choose, and the time.',
            'Click Create automation. It shows in Scheduler with its next run time.',
            'Click Run now to test it right away, the pencil to edit, or the trash can to delete.',
        )),
        '19' => array('section' => 'studio', 'title' => 'Scheduled Messages', 'secs' => 29, 'steps' => array(
            'Scheduled messages keep fans engaged without you being online.',
            'Click Action, then New Scheduled Message.',
            'Name it, then write your message.',
            'Or turn on Write with AI and give it a topic instead.',
            'Under Destinations, choose who gets it: everyone, followers, subscribers, expired subscribers or buyers.',
            'Set the schedule, daily or weekly on the days you pick.',
            'Click Create message. It shows in Scheduler, and Send now sends it right away.',
        )),
        '20' => array('section' => 'studio', 'title' => 'Generate Images with AI', 'secs' => 24, 'steps' => array(
            'Need a fresh image? Click Action, then Generate Image.',
            'Describe what you want. The more specific, the better.',
            'Pick a shape: square, portrait or landscape.',
            'Leave Use my brand checked so your colors, voice and keywords steer the look.',
            'Click Generate. It takes up to a minute.',
            'Your image is saved to your Library automatically. Click Use in a Post to start a post with it.',
        )),
    );

    /** One video with its URLs, or null when unknown or its file isn't uploaded yet. */
    public static function get($id){
        $id = (string) $id;
        if (!isset(self::VIDEOS[$id])) { return null; }
        $file = Main::app_path() . '/public' . self::DIR . '/' . $id . '.mp4';
        if (!is_file($file)) { return null; }
        $v = self::VIDEOS[$id];
        $v['id']     = $id;
        $v['src']    = self::DIR . '/' . $id . '.mp4?v=' . filemtime($file);
        $poster      = Main::app_path() . '/public' . self::DIR . '/' . $id . '.jpg';
        $v['poster'] = is_file($poster) ? self::DIR . '/' . $id . '.jpg?v=' . filemtime($poster) : '';
        $v['length'] = (int) $v['secs'] > 0 ? sprintf('%d:%02d', intdiv((int) $v['secs'], 60), (int) $v['secs'] % 60) : '';
        return $v;
    }

    /** Available videos grouped by section: section key => array('title' => ..., 'videos' => [...]). */
    public static function by_section(): array {
        $out = array();
        foreach (self::VIDEOS as $id => $v) {
            $video = self::get($id);
            if (!$video) { continue; }
            if (!isset($out[$v['section']])) { $out[$v['section']] = array('title' => self::SECTIONS[$v['section']], 'videos' => array()); }
            $out[$v['section']]['videos'][] = $video;
        }
        return $out;
    }

    /** The data-* attributes site.js reads to open the video player. */
    public static function attrs(array $v): string {
        $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        return 'data-tutorial="' . $e($v['id']) . '" data-tut-title="' . $e($v['title']) . '" data-tut-src="' . $e($v['src']) . '"'
             . ' data-tut-poster="' . $e($v['poster']) . '" data-tut-steps="' . $e(json_encode(array_values($v['steps']))) . '"';
    }

    /** "Watch How" button for one video; '' until the video is uploaded. $extra is raw attribute HTML. */
    public static function button($id, $extra = ''): string {
        $v = self::get($id);
        if (!$v) { return ''; }
        $len = $v['length'] !== '' ? '<span class="tut-btn__len">' . $v['length'] . '</span>' : '';
        return '<button type="button" class="tut-btn" ' . self::attrs($v) . ($extra !== '' ? ' ' . $extra : '') . '>'
             . '<i class="fa-regular fa-circle-play" aria-hidden="true"></i><span>Watch How</span>' . $len . '</button>';
    }

    /** The button in a right-aligned row, for the top of a section with no header of its own. */
    public static function bar($id, $extra = ''): string {
        $b = self::button($id, $extra);
        return $b === '' ? '' : '<div class="tut-bar">' . $b . '</div>';
    }
}
