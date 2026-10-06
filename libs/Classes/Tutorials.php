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
        'influencers' => 'AI Influencers',
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
        '02' => array('section' => 'start', 'title' => 'Choose Your Plan', 'secs' => 80, 'steps' => array(
            'Your plan decides what you can do on Creator Link Studio. Here\'s how to pick one.',
            'Click your name, then Billing, then the Plan tab.',
            'Free is where everyone starts. Follow creators, join memberships, unlock posts, buy tickets and bookings, and message creators. There\'s no card needed.',
            'To sell, upgrade to Creator or Studio. Both include your page, memberships, pay-per-view, publishing and payouts.',
            'Creator is 49 dollars a month. You keep 90 percent of every sale, and you get an AI influencer, 500 AI credits a month, 5 scheduled automations, 25 gigabytes of storage, and inbox automation with AI replies.',
            'Studio is 199 dollars a month. You keep 97 percent of every sale, with 10 AI influencers, 3,000 AI credits a month, unlimited automations, 500 gigabytes of storage, 10 team seats and your own custom domain.',
            'Need more AI credits? On Creator and Studio you can buy more any time. One dollar buys 10 credits.',
            'Pick the plan that fits and click its button. If you have a promo code, enter it at checkout.',
            'Your current plan is always marked Your plan, with its renewal date right below.',
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
        '11' => array('section' => 'start', 'title' => 'Your Wallet', 'secs' => 33, 'steps' => array(
            'Your wallet credits pay for unlocks, tickets and bookings. In Settings, open Wallet.',
            'Your balance is at the top. Creators also see their AI credits here, which are separate and used for making images and videos.',
            'Under Buy Credits, pick an amount. A small processing fee is added at checkout, and credits are non-refundable.',
            'History shows everything you\'ve added, spent and earned.',
            'Auto-Replenishment buys credits automatically when your balance runs low. Click Manage to turn it on.',
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
        '14' => array('section' => 'studio', 'title' => 'Create and Publish a Post', 'secs' => 52, 'steps' => array(
            'Let\'s publish a post. Click Action, then New Post.',
            'Add media from your Library, or upload new files.',
            'Pick your photos or videos and click Add to Post. The first one becomes the cover.',
            'Write your caption, or click Write a caption to have AI draft one.',
            'The preview on the right shows exactly what fans will see.',
            'Next, Audience. Choose Everyone, Subscribers, or Pay-per-view.',
            'For pay-per-view, set your unlock price, anywhere from 10 to 5,000 credits.',
            'Switch the preview to Public to see the locked version.',
            'Under Distribution, pick where it goes: your profile and any connected social accounts.',
            'Last, Publish. Select Publish now, then click the Publish now button.',
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
        '20' => array('section' => 'influencers', 'title' => 'Generate Images with AI', 'secs' => 33, 'steps' => array(
            'Need a fresh image with nobody in it? Open Influencers, then Generate Images, and choose No Influencer in the switcher at the top right.',
            'Describe what you want. The more specific, the better.',
            'Pick a size: portrait, feed, story, square or landscape.',
            'Leave Use My Brand on so your colors, voice and keywords steer the look.',
            'Click Generate. It takes up to a minute.',
            'Your image is saved to your Library automatically. Click Use In Post to start a post with it.',
        )),
        // AI influencer pipeline. Stubs: each appears once its <id>.mp4 is uploaded and 'secs' is set.
        '21' => array('section' => 'influencers', 'title' => 'Give Your Influencer a Persona', 'secs' => 30, 'steps' => array(
            'A persona tells every AI writer who your influencer is, so captions, messages and automations sound like one person.',
            'Open Influencers, click the menu on your influencer, then Settings.',
            'Under Persona, describe who she is in a paragraph or two.',
            'Fill in her personality, how she speaks, her niche and what makes her vulnerable.',
            'Click Save. New captions and replies are written in her voice from now on.',
        )),
        '22' => array('section' => 'influencers', 'title' => 'Build Her Angle Reference Set', 'secs' => 28, 'steps' => array(
            'Angle references keep your influencer looking the same from every side.',
            'Open Influencers, then References.',
            'Click Generate Angle Set. You get three front close-ups, both profiles, a back view and two full body shots.',
            'Click Regenerate on any that do not look like her. The rest are used automatically.',
            'All of her angles are used automatically in Replicate Photo, Carousel and video.',
        )),
        '23' => array('section' => 'influencers', 'title' => 'Replicate a Photo', 'secs' => 27, 'steps' => array(
            'Replicate Photo recreates any photo with your influencer in it.',
            'Open Influencers, Generate Images, then Replicate Photo.',
            'Choose a source photo from your Library or upload one. The face in it is hidden automatically.',
            'Pick Style to recreate the scene and pose, or Exact to swap her in and keep the composition.',
            'Edit the prompt if you want, choose a size, and click Replicate.',
        )),
        '24' => array('section' => 'influencers', 'title' => 'Generate a Carousel', 'secs' => 28, 'steps' => array(
            'A carousel is several shots of one moment, with the outfit and location held the same.',
            'Open Influencers, Generate Images, then Carousel.',
            'Add a seed image or describe the scene, then pick what should vary and how many images you want.',
            'Click Generate Carousel. Reorder, drop or regenerate any image.',
            'Click Use In Post to open a draft with the images in that order.',
        )),
        '25' => array('section' => 'studio', 'title' => 'Edit an Image by Instruction', 'secs' => 19, 'steps' => array(
            'Change one thing in a photo by describing it.',
            'Open an image in your Library and click Edit.',
            'Type the change, for example make the dress red, and click Apply Edit.',
            'The edit is saved as a new version. The original stays in your Library.',
        )),
        '26' => array('section' => 'influencers', 'title' => 'Use Scene Templates', 'secs' => 25, 'steps' => array(
            'Scenes are ready-made ideas you can run with any of your influencers.',
            'Open Influencers, Generate Images, then Scenes. Click New Scene to save an idea of your own; the platform scenes sit beside it.',
            'Pick a scene and click Generate. You get four variants of your influencer in it.',
            'Give a thumbs up or down to each one, then use your favourite in a post.',
        )),
        '27' => array('section' => 'influencers', 'title' => 'Motion Control', 'secs' => 30, 'steps' => array(
            'Motion Control makes your influencer move like the person in any video.',
            'Open Influencers, Generate Videos, then Motion Control.',
            'Choose a motion video from your Library. It can be 3 to 30 seconds long.',
            'Choose a first frame, or click Make First Frame to recreate the video\'s opening shot with her in it.',
            'Pick 720p or 1080p and click Generate Video. The result is as long as the motion video.',
        )),
        '28' => array('section' => 'influencers', 'title' => 'Replace a Character in a Video', 'secs' => 28, 'steps' => array(
            'Replace Character puts your influencer in place of one person in a video you own.',
            'Open Influencers, Generate Videos, then Replace Character.',
            'Choose a source video of up to 15 seconds and say who to replace.',
            'Choose whether she keeps the video\'s outfit, and whether other people and on-screen text are left alone.',
            'Confirm that you own the video or have the rights to use it, then click Replace Character.',
        )),
        '29' => array('section' => 'influencers', 'title' => 'Create a Dialogue Scene', 'secs' => 27, 'steps' => array(
            'A scene is one continuous take where your influencer speaks the lines you write.',
            'Open Influencers, Generate Videos, then Scene.',
            'Add a second character if you want one: another influencer, or someone you describe.',
            'Write each line, choose who says it, and add an acting cue or a pronunciation note where it helps.',
            'Start with Draft to check the take, then generate it again as Final.',
        )),
        '30' => array('section' => 'studio', 'title' => 'Export a Frame From a Video', 'secs' => 18, 'steps' => array(
            'Any moment of a video can become an image.',
            'Open a video in your Library and scrub to the moment you want.',
            'Click Export This Frame. The image is saved to your Library.',
            'From there you can edit it or use it as the source for Replicate Photo.',
        )),
        '31' => array('section' => 'influencers', 'title' => 'Give Your Influencer a Voice', 'secs' => 31, 'steps' => array(
            'A voice lets your influencer speak in videos and audio.',
            'Open Influencers, then Voice.',
            'Under Design A Voice, describe her age and vibe, pick a keyword, and set her accent by city and country.',
            'Click Design Voice. Listen to the three candidates and save the one you like.',
            'To make audio, write a script, add audio tags or click Enhance, and click Generate Speech. Save the take you prefer to your Library.',
        )),
        '32' => array('section' => 'influencers', 'title' => 'Make a Talking Video', 'secs' => 23, 'steps' => array(
            'A talking video is a close-up of your influencer speaking your script.',
            'Open Influencers, Generate Videos, then Talking.',
            'Choose a close-up image with a clear face.',
            'Write the script, or switch to Audio File and choose one from your Library.',
            'Click Generate Video. Long scripts are rendered in parts and joined for you.',
        )),
        '33' => array('section' => 'studio', 'title' => 'Edit Clips Together', 'secs' => 26, 'steps' => array(
            'The clip editor joins your videos and images into one finished video.',
            'In Content Studio, click Action, then New Edit.',
            'Add clips and stills, drag them into order, and trim each one.',
            'Add text and image overlays, and an audio track if you want one.',
            'Pick 9:16 or 3:4 and click Export. The finished video is saved to your Library.',
        )),
        '34' => array('section' => 'studio', 'title' => 'Run a Launch Campaign', 'secs' => 27, 'steps' => array(
            'A launch campaign is a run of posts and messages that build up to one launch.',
            'Open Audience and click Launch Campaign.',
            'Say what you are launching, pick the launch time and how many days of anticipation you want.',
            'Click Write Drafts. Edit any post or message, or clear one to leave it out.',
            'Click Schedule Campaign. Every post and message is scheduled at once.',
        )),
        '35' => array('section' => 'studio', 'title' => 'Caption Modes, Stories and AI Disclosure', 'secs' => 30, 'steps' => array(
            'When you write a post, pick a caption mode next to Write a Caption: Standard, Continuation, Comment Bait or Hook Overlay.',
            'Hook Overlay also gives you a short line to put on the video itself.',
            'In Distribution, tick an Instagram or Facebook account and switch on Post As Story to send 9:16 media as a Story.',
            'Posts with AI media carry an AI disclosure on every platform. You can switch it off per post.',
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
