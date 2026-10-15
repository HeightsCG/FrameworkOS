<?php
/**
 * Free tools, public and indexable. The lead tools (/tools/fan-questions, /tools/ai-influencer-persona) post to
 * /api/tool_run (ApiToolsController), which records the lead and queues ToolRunJob; the result arrives by email.
 * The 'local' tools run entirely in the browser (public/js/free-tools.js).
 * Listed in SeoController::public_pages() (sitemap, llms.txt) and the footer's Learn column.
 */
class ToolsController extends Controller {

    public $protected = 0;

    /** URL slug => the tool. 'source' is the lead source and the signup ref. FAQ text is filled in faq(). */
    /** The free tools a hub page links to: array(path, nav title, description) per tool whose 'pages' lists $path. */
    public static function for_page(string $path): array {
        $out = array();
        foreach (self::TOOLS as $slug => $t) { foreach ((array) ($t['pages'] ?? array()) as $pg) { if ((string) $pg[0] === $path) { $out[] = array('/tools/' . $slug, (string) $t['nav_title'], (string) $t['description']); } } }
        return $out;
    }

    const TOOLS = array(
        'fan-questions' => array(
            'source'      => 'fan-questions',
            'nav_title'   => 'Fan Question Finder',
            'title'       => 'Free Fan Question Finder for Creators',
            'h1'          => 'Find the Questions Your Fans Are Asking',
            'description' => 'Enter your niche and get 30 to 40 post ideas built from real questions people ask online, plus the communities worth watching. Free, by email.',
            'lead'        => 'The Fan Question Finder is a free tool that reads the recent questions people ask in your niche\'s biggest online communities and emails you 30 to 40 post ideas, grouped by theme. Enter your niche to start.',
            'button'      => 'Find Questions',
            'pages'       => array(array('/features/publishing', 'publishing to ten platforms'), array('/monetize-your-content', 'how to monetize your content')),   // the hub pages that link to this tool, and that it links back to
        ),
        'ai-influencer-persona' => array(
            'source'      => 'persona-tool',
            'nav_title'   => 'AI Influencer Persona Generator',
            'title'       => 'Free AI Influencer Persona Generator',
            'h1'          => 'Design Your AI Influencer Persona',
            'description' => 'Get five original AI influencer persona concepts for your niche: names, handles, bio, traits, content pillars and a starter image prompt. Free, by email.',
            'lead'        => 'The AI Influencer Persona Generator is a free tool that emails you five original persona concepts for your niche, each with handles, a bio, content pillars and a starter image prompt. Tell us your niche and the vibe you want.',
            'button'      => 'Get Personas',
            'pages'       => array(array('/features/ai-influencer', 'AI influencers'), array('/ai-ofm-tools', 'AI OFM tools')),
        ),
        // Browser-only tools ('kind' => 'local'): public/js/free-tools.js runs them on the page, with no API call, no lead form and no credits.
        // They render through views/tools/local-tool.php and can be embedded on other sites with public/js/embed.js.
        'ai-influencer-name-generator' => array(
            'kind'        => 'local',
            'nav_title'   => 'AI Influencer Name Generator',
            'title'       => 'Free AI Influencer Name Generator',
            'h1'          => 'AI Influencer Name Generator',
            'description' => 'Get 20 name ideas for an AI influencer from your niche and the vibe you want, each with a handle to check. Free, instant, no sign-up.',
            'lead'        => 'Pick a niche and a vibe and get 20 name ideas for your AI influencer, each with a matching handle. Shuffle for a new set as often as you like.',
            'pages'       => array(array('/features/ai-influencer', 'AI influencers')),
            'related'     => array('instagram', 'tiktok', 'fanvue'),
        ),
        'ai-influencer-bio-generator' => array(
            'kind'        => 'local',
            'nav_title'   => 'AI Influencer Bio Generator',
            'title'       => 'Free AI Influencer Bio Generator for 5 Platforms',
            'h1'          => 'AI Influencer Bio Generator',
            'description' => 'Write five profile bios sized for Instagram, TikTok, X, Threads and Bluesky, with a live character count for each. Free, instant, no sign-up.',
            'lead'        => 'Enter a name, a niche and a tone, and get a bio sized for each of five platforms, with the character count against each limit.',
            'pages'       => array(array('/features/link-in-bio', 'link in bio')),
            'related'     => array('instagram', 'tiktok', 'x', 'threads', 'bluesky'),
        ),
        'ai-disclosure-caption-checker' => array(
            'kind'        => 'local',
            'nav_title'   => 'AI Disclosure Caption Checker',
            'title'       => 'Free AI Disclosure Caption Checker',
            'h1'          => 'AI Disclosure Caption Checker',
            'description' => 'Paste a caption, pick a platform and check whether it discloses AI content clearly, with suggested wording. Free, instant, no sign-up.',
            'lead'        => 'Paste a caption and pick where it is going. The checker looks for a clear AI disclosure, checks where it sits, and suggests wording when it falls short.',
            'pages'       => array(array('/features/publishing', 'publishing to ten platforms')),
            'related'     => array('tiktok', 'youtube', 'instagram'),
        ),
    );

    public function __construct(){
        parent::__construct();
        Session::cache_header(300);
    }

    public function indexAction(){ Errors::page_not_found(); }

    /** /tools/fan-questions (the router lowercases the segment and drops the hyphen). */
    public function fanquestionsAction(){ $this->render_tool('fan-questions'); }

    /** /tools/ai-influencer-persona */
    public function aiinfluencerpersonaAction(){ $this->render_tool('ai-influencer-persona'); }

    /** /tools/ai-influencer-name-generator */
    public function aiinfluencernamegeneratorAction(){ $this->render_tool('ai-influencer-name-generator'); }

    /** /tools/ai-influencer-bio-generator */
    public function aiinfluencerbiogeneratorAction(){ $this->render_tool('ai-influencer-bio-generator'); }

    /** /tools/ai-disclosure-caption-checker */
    public function aidisclosurecaptioncheckerAction(){ $this->render_tool('ai-disclosure-caption-checker'); }

    /** The plans that include the AI tools, by name from config ("Creator and Studio"). */
    public static function paid_plans(): string {
        $names = array_map(function ($t) { return $t['name']; }, PagesController::selling_tiers());
        $last = array_pop($names);
        return count($names) > 0 ? implode(', ', $names) . ' and ' . $last : (string) $last;
    }

    public static function faq(string $slug): array {
        $site = Main::site_name(); $plans = self::paid_plans();
        if ($slug === 'ai-influencer-name-generator') {
            return array(
                array('q' => 'How does the name generator work?', 'a' => 'It combines first names and surnames picked for the vibe you choose, plus handle ideas built from your niche. Everything runs in your browser, so nothing you type is sent anywhere.'),
                array('q' => 'Are the names free to use?', 'a' => 'Yes. Check that the name and handle are free on each platform before you claim them, and avoid names that copy a real person or brand.'),
                array('q' => 'Why give an AI influencer a human name?', 'a' => 'A name makes a character easy to remember and to talk about. Say in the bio that the character is AI, so followers know what they are following.'),
                array('q' => 'What do I do once I have a name?', 'a' => 'Write the bio with the AI Influencer Bio Generator, then create the character on ' . $site . ' and generate photos and video of them. The AI tools are on the ' . $plans . ' plans.'),
            );
        }
        if ($slug === 'ai-influencer-bio-generator') {
            return array(
                array('q' => 'What are the bio limits on each platform?', 'a' => 'Instagram 150 characters, TikTok 80, X 160, Threads 150 and Bluesky 256. Each bio shows its count against that limit.'),
                array('q' => 'Should an AI influencer bio say it is AI?', 'a' => 'Yes. Keep Say It Is AI ticked and each bio states it plainly, so followers know from the profile what they are following.'),
                array('q' => 'Is anything I type sent to a server?', 'a' => 'No. The bios are written from templates in your browser.'),
                array('q' => 'Where should my page link go?', 'a' => 'In the link field of each profile. On ' . $site . ' that link is your public page, where fans follow, subscribe and buy.'),
            );
        }
        if ($slug === 'ai-disclosure-caption-checker') {
            return array(
                array('q' => 'What does the checker look for?', 'a' => 'A plain disclosure such as AI-generated or Made with AI, whether it appears near the start of the caption, and whether it is only a vague hashtag. It then suggests wording.'),
                array('q' => 'Is this legal advice?', 'a' => 'No. It is a quick check against common good practice. Platform rules and local laws change, so read each platform\'s current policy.'),
                array('q' => 'Do TikTok and YouTube need more than a caption?', 'a' => 'Both have their own setting for AI content. When you publish AI media through ' . $site . ', that setting is turned on for you.'),
                array('q' => 'Is my caption sent anywhere?', 'a' => 'No. The check runs in your browser.'),
            );
        }
        if ($slug === 'fan-questions') {
            return array(
                array('q' => 'What does the Fan Question Finder do?', 'a' => 'It finds the biggest online communities about your niche, reads the recent questions people asked there, and emails you 30 to 40 post ideas grouped by theme, plus the communities worth watching.'),
                array('q' => 'Where do the questions come from?', 'a' => 'Public YouTube videos and comments about your niche, read through the official YouTube Data API, with Reddit as a second source where available. Only questions are used, and adult content is left out.'),
                array('q' => 'Is it free?', 'a' => 'Yes. You can run it three times a day with the same email address.'),
                array('q' => 'How long does it take?', 'a' => 'A few minutes. Your ideas arrive by email, so you can close the page.'),
                array('q' => 'Can I run it inside ' . $site . '?', 'a' => 'Yes. On the ' . $plans . ' plans it is called Ideas. You can run it as often as you like and turn any idea into a draft post in one click.'),
            );
        }
        return array(
            array('q' => 'What is an AI influencer persona?', 'a' => 'The character behind an AI influencer: a name, a look, a personality and the topics they post about. A clear persona keeps every photo and caption consistent.'),
            array('q' => 'What do I get?', 'a' => 'Five persona concepts by email. Each has a name, three handle ideas, a short bio, personality traits, content pillars and a starter image prompt written for a realistic phone photo look.'),
            array('q' => 'Are the personas based on real people?', 'a' => 'No. Each one is an original character, and the image prompts never describe a real person or a celebrity likeness.'),
            array('q' => 'Is it free?', 'a' => 'Yes. You can run it three times a day with the same email address.'),
            array('q' => 'How do I turn a persona into an AI influencer?', 'a' => 'On ' . $site . ' you create an AI influencer from a description, approve the face, then generate photos and videos for your posts. The AI tools are on the ' . $plans . ' plans.'),
        );
    }

    private function render_tool(string $slug){
        if (count(Main::get_url()) > 2 || (string) (Main::get_url()[1] ?? '') !== $slug) { Errors::page_not_found(); return; }   // one URL per tool
        $tool = self::TOOLS[$slug];
        $path = '/tools/' . $slug;
        $faq = self::faq($slug);
        $jsonld = array(
            array('@context' => 'https://schema.org', '@type' => 'WebApplication', 'name' => $tool['nav_title'], 'description' => $tool['description'],
                'url' => SeoMeta::base() . $path, 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'isAccessibleForFree' => true,
                'offers' => array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'), 'publisher' => SeoMeta::org_ref()),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => $tool['nav_title'], 'url' => $path))),
            SeoMeta::org(),
            SeoMeta::webpage(array('url' => SeoMeta::base() . $path, 'title' => $tool['title'], 'description' => $tool['description'])),
        );
        $meta = array('url' => SeoMeta::base() . $path, 'title' => $tool['title'], 'description' => $tool['description'], 'type' => 'website',
            'jsonld' => $jsonld, 'sections' => true, 'no_band' => true);
        if (($tool['kind'] ?? '') === 'local') {   // browser-only tool: no form post, no lead
            $this->view->public_page(Main::app_path() . '/app/views/tools/local-tool.php', $meta, array('tool' => $tool, 'slug' => $slug, 'faq' => $faq));
            return;
        }
        $this->view->public_page(Main::app_path() . '/app/views/tools/tool.php', $meta, array('tool' => $tool, 'slug' => $slug, 'faq' => $faq, 'unavailable' => $slug === 'fan-questions' && !ToolRunJob::fan_questions_ready()));
    }
}
