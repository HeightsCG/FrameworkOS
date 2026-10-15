<?php
/**
 * /integrations and /integrations/<slug> (PagesController::integrationsAction): the ten platforms the studio
 * publishes to, one page each. Every line here matches what the code does, so check the source before adding one:
 *  - nine social networks go through Post for Me (SocialShareService): the public caption plus a safe preview
 *    (a blurred one for paid posts; free posts send their media), no link added, caption limits in
 *    SocialShareService::LIMITS, media counts in MEDIA_LIMITS / MIXED_MEDIA / NEEDS_MEDIA, Stories in
 *    AiDisclosure::STORY_PLATFORMS, the AI label in AiDisclosure::PLATFORM_FLAGS (else a disclosure line),
 *    engagement back through cron/social_metrics.php;
 *  - Fanvue mirrors the whole post (FanvueShareService): caption, media into the Fanvue vault, audience, price, schedule.
 * Connecting happens in Settings > Integrations; cross-posting is on the Creator and Studio plans (Plan::can_social_post).
 * Logos: public/images/integrations/<slug>.svg (Simple Icons, each brand's own mark), index grid only. Fanvue has none.
 */
class IntegrationPages {

    /** Shared lines, so every social page says the same thing in the same words. */
    const TEASER  = 'Publish the public version of each post: the caption and a preview image. Paid posts send a blurred preview, so what fans pay for stays on your page.';
    const NO_LINK = 'No link is added to the post. Your page link belongs in your bio.';
    const SCHED   = 'Publish now or schedule it for a time you pick, along with your page.';
    const STATS   = 'Views, likes, comments and shares come back into your analytics dashboard next to the post.';
    const LINE    = 'Posts with AI media get your AI disclosure line added to the caption.';

    /** Platform => page. 'can': what you can do (each line true today); 'faq': platform questions (the shared ones are added in faq()); 'tools': related free tools. */
    const PAGES = array(
        'linkedin' => array(
            'name' => 'LinkedIn', 'logo' => 'linkedin',
            'lead' => 'Share each new post to your LinkedIn company page from the same editor you publish your page with.',
            'can'  => array(
                'Connect a LinkedIn company page.',
                self::TEASER,
                'Attach up to 9 photos to one post.',
                self::SCHED,
                self::LINE,
                self::STATS,
            ),
            'faq' => array(
                array('q' => 'Can I post to my personal LinkedIn profile?', 'a' => 'Not yet. Creator Link Studio connects LinkedIn company pages.'),
                array('q' => 'How many images can a LinkedIn post carry?', 'a' => 'Up to 9. Extra images in the post stay on your page.'),
            ),
            'tools' => array('ai-disclosure-caption-checker', 'ai-influencer-bio-generator'),
        ),
        'bluesky' => array(
            'name' => 'Bluesky', 'logo' => 'bluesky',
            'lead' => 'Share each new post to Bluesky, with the caption fitted to its length limit for you.',
            'can'  => array(
                'Connect with your Bluesky handle and an app password.',
                self::TEASER,
                'Captions longer than 300 characters are shortened at a word break.',
                'Attach up to 4 photos to one post.',
                self::SCHED,
                self::LINE,
                self::STATS,
            ),
            'faq' => array(
                array('q' => 'Why does Bluesky ask for an app password?', 'a' => 'Bluesky connects third-party apps with an app password instead of a sign-in screen. Create one in your Bluesky settings and you can revoke it there at any time.'),
                array('q' => 'What happens to a long caption?', 'a' => 'It is shortened at a word break to fit Bluesky\'s 300 characters. The full caption stays on your page.'),
            ),
            'tools' => array('ai-influencer-bio-generator', 'ai-disclosure-caption-checker'),
        ),
        'x' => array(
            'name' => 'X', 'logo' => 'x',
            'lead' => 'Share each new post to X, with the caption fitted to 280 characters for you.',
            'can'  => array(
                'Connect your X account.',
                self::TEASER,
                'Captions longer than 280 characters are shortened at a word break, counting the way X counts.',
                'Attach up to 4 photos to one post.',
                self::SCHED,
                self::LINE,
                self::STATS,
            ),
            'faq' => array(
                array('q' => 'Why is no link added to my X post?', 'a' => 'Posts with outside links tend to reach fewer people on X. Your page link belongs in your bio instead.'),
                array('q' => 'What happens to a long caption?', 'a' => 'It is shortened at a word break to fit 280 characters. The full caption stays on your page.'),
            ),
            'tools' => array('ai-influencer-bio-generator', 'ai-disclosure-caption-checker'),
        ),
        'facebook' => array(
            'name' => 'Facebook', 'logo' => 'facebook',
            'lead' => 'Share each new post to Facebook as a feed post, a Story or both.',
            'can'  => array(
                'Connect Facebook from Settings.',
                self::TEASER,
                'Attach up to 10 photos and videos to one post, mixed.',
                'Send a post to the feed, to Stories or to both.',
                self::SCHED,
                self::LINE,
                self::STATS,
            ),
            'faq' => array(
                array('q' => 'Can I post Facebook Stories?', 'a' => 'Yes. Choose Feed, Story or Both for each post. Stories need a 9:16 image or video and carry no caption.'),
                array('q' => 'Can one post mix photos and video?', 'a' => 'Yes. Facebook takes up to 10 items in one post, photos and videos together.'),
            ),
            'tools' => array('ai-disclosure-caption-checker', 'ai-influencer-name-generator'),
        ),
        'instagram' => array(
            'name' => 'Instagram', 'logo' => 'instagram',
            'lead' => 'Share each new post to Instagram as a feed post, a Story or both.',
            'can'  => array(
                'Connect with your Instagram login.',
                self::TEASER,
                'Attach up to 10 photos and videos to one carousel, mixed.',
                'Send a post to the feed, to Stories or to both.',
                self::SCHED,
                self::LINE,
                self::STATS,
            ),
            'faq' => array(
                array('q' => 'Does an Instagram post need media?', 'a' => 'Yes. Instagram does not accept text-only posts, so a post goes to Instagram only when it has a photo or video.'),
                array('q' => 'Can I post Instagram Stories?', 'a' => 'Yes. Choose Feed, Story or Both for each post. Stories need a 9:16 image or video and carry no caption.'),
            ),
            'tools' => array('ai-influencer-bio-generator', 'ai-disclosure-caption-checker', 'ai-influencer-name-generator'),
        ),
        'threads' => array(
            'name' => 'Threads', 'logo' => 'threads',
            'lead' => 'Share each new post to Threads, with the caption fitted to its 500 characters for you.',
            'can'  => array(
                'Connect your Threads account.',
                self::TEASER,
                'Captions longer than 500 characters are shortened at a word break.',
                'Attach up to 10 photos and videos to one post, mixed.',
                self::SCHED,
                self::LINE,
                self::STATS,
            ),
            'faq' => array(
                array('q' => 'What happens to a long caption?', 'a' => 'It is shortened at a word break to fit 500 characters. The full caption stays on your page.'),
                array('q' => 'Can one post mix photos and video?', 'a' => 'Yes. Threads takes up to 10 items in one post, photos and videos together.'),
            ),
            'tools' => array('ai-influencer-bio-generator', 'ai-disclosure-caption-checker'),
        ),
        'tiktok' => array(
            'name' => 'TikTok', 'logo' => 'tiktok',
            'lead' => 'Share each new post to TikTok, with TikTok\'s own AI-generated label set when the post has AI media.',
            'can'  => array(
                'Connect your TikTok account.',
                self::TEASER,
                'Send several photos or one video in a post.',
                'Posts with AI media get TikTok\'s own AI-generated content label.',
                self::SCHED,
                self::STATS,
            ),
            'faq' => array(
                array('q' => 'Does a TikTok post need media?', 'a' => 'Yes. TikTok does not accept text-only posts, so a post goes to TikTok only when it has a photo or video.'),
                array('q' => 'How is AI content labelled on TikTok?', 'a' => 'When a post has AI-generated or AI-edited media, Creator Link Studio sets TikTok\'s own AI-generated content label on it.'),
            ),
            'tools' => array('ai-disclosure-caption-checker', 'ai-influencer-bio-generator', 'ai-influencer-name-generator'),
        ),
        'youtube' => array(
            'name' => 'YouTube', 'logo' => 'youtube',
            'lead' => 'Share each new video to YouTube, with YouTube\'s altered or synthetic content setting turned on when the video has AI media.',
            'can'  => array(
                'Connect your YouTube channel.',
                'Publish the public version of posts that carry a video, with the caption.',
                'Posts with AI media get YouTube\'s own altered or synthetic content setting.',
                self::SCHED,
                self::STATS,
            ),
            'faq' => array(
                array('q' => 'Does a YouTube post need a video?', 'a' => 'Yes. A post goes to YouTube only when it has media.'),
                array('q' => 'How is AI content labelled on YouTube?', 'a' => 'When a post has AI-generated or AI-edited media, Creator Link Studio turns on YouTube\'s altered or synthetic content setting for it.'),
            ),
            'tools' => array('ai-disclosure-caption-checker'),
        ),
        'pinterest' => array(
            'name' => 'Pinterest', 'logo' => 'pinterest',
            'lead' => 'Share each new post to Pinterest as a Pin, published or scheduled with the rest of your post.',
            'can'  => array(
                'Connect your Pinterest account.',
                self::TEASER,
                'Each post goes out as one Pin with its cover image or video.',
                self::SCHED,
                self::LINE,
                self::STATS,
            ),
            'faq' => array(
                array('q' => 'Does a Pin need media?', 'a' => 'Yes. A post goes to Pinterest only when it has a photo or video.'),
                array('q' => 'How many images does a Pin carry?', 'a' => 'One. The post\'s cover goes to Pinterest and the rest stays on your page.'),
            ),
            'tools' => array('ai-disclosure-caption-checker', 'ai-influencer-name-generator'),
        ),
        'fanvue' => array(
            'name' => 'Fanvue', 'logo' => '',
            'lead' => 'Mirror each post to Fanvue in full: the caption, the media, who can see it and its price.',
            'can'  => array(
                'Connect your Fanvue account with Fanvue\'s own sign-in.',
                'Mirror the whole post, not a preview: the caption and the real media.',
                'Media is uploaded into your Fanvue vault.',
                'The post keeps its audience and its pay-per-view price.',
                'Publish now or schedule it for a time you pick, along with your page.',
                'Videos upload in the background, and you get a notification if one fails.',
            ),
            'faq' => array(
                array('q' => 'Why does Fanvue get the full post when other platforms get a preview?', 'a' => 'Fanvue is a paid content platform like your page, so the post goes across as it is, price included. Social networks get the public preview instead.'),
                array('q' => 'Where does the media go on Fanvue?', 'a' => 'Into your Fanvue vault, attached to the mirrored post.'),
            ),
            'tools' => array('ai-influencer-name-generator', 'ai-influencer-bio-generator'),
        ),
    );

    /** The FAQ for one page: its own questions, then the shared ones. */
    public static function faq(string $slug): array {
        $p = self::PAGES[$slug]; $name = $p['name']; $site = Main::site_name();
        $out = (array) $p['faq'];
        $out[] = array('q' => 'Which plans include publishing to ' . $name . '?', 'a' => 'Publishing to connected platforms is part of the ' . ToolsController::paid_plans() . ' plans. Every account starts on Free, where you can build your page and drafts.');
        $out[] = array('q' => 'Do I still need to post on ' . $name . ' myself?', 'a' => 'No. Write the post once in ' . $site . ', tick ' . $name . ', and it goes out with your page post or at the time you schedule.');
        return $out;
    }

    /** The three steps, the same on every page. */
    public static function steps(string $slug): array {
        $name = self::PAGES[$slug]['name'];
        return array(
            array('Connect ' . $name, 'Open Settings, then Integrations, and connect your ' . $name . ' account.'),
            array('Write the post once', 'Create your post in the editor and tick ' . $name . ' under Distribution.'),
            array('Publish or schedule', 'Publish now or pick a time. Your page and ' . $name . ' update together.'),
        );
    }

    /** The brand mark as inline SVG (currentColor), or '' when there is none. */
    public static function logo(string $slug, int $size = 24): string {
        $f = (string) (self::PAGES[$slug]['logo'] ?? '');
        if ($f === '') { return ''; }
        $svg = @file_get_contents(Main::app_path() . '/public/images/integrations/' . $f . '.svg');
        if (!is_string($svg) || $svg === '') { return ''; }
        return str_replace('<svg ', '<svg width="' . $size . '" height="' . $size . '" ', $svg);
    }
}
