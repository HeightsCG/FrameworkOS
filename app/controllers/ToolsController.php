<?php
/**
 * Free lead tools, public and indexable: /tools/fan-questions and /tools/ai-influencer-persona. The form posts to
 * /api/tool_run (ApiToolsController), which records the lead and queues ToolRunJob; the result arrives by email.
 * Listed in SeoController::public_pages() (sitemap, llms.txt) and the footer's Learn column.
 */
class ToolsController extends Controller {

    public $protected = 0;

    /** URL slug => the tool. 'source' is the lead source and the signup ref. FAQ text is filled in faq(). */
    const TOOLS = array(
        'fan-questions' => array(
            'source'      => 'fan-questions',
            'nav_title'   => 'Fan Question Finder',
            'title'       => 'Free Fan Question Finder for Creators',
            'h1'          => 'Find the Questions Your Fans Are Asking',
            'description' => 'Enter your niche and get 30 to 40 post ideas built from real questions people ask online, plus the communities worth watching. Free, by email.',
            'lead'        => 'Enter your niche. We read the recent questions people ask in its biggest online communities and email you 30 to 40 post ideas, grouped by theme.',
            'button'      => 'Find Questions',
        ),
        'ai-influencer-persona' => array(
            'source'      => 'persona-tool',
            'nav_title'   => 'AI Influencer Persona Generator',
            'title'       => 'Free AI Influencer Persona Generator',
            'h1'          => 'Design Your AI Influencer Persona',
            'description' => 'Get five original AI influencer persona concepts for your niche: names, handles, bio, traits, content pillars and a starter image prompt. Free, by email.',
            'lead'        => 'Tell us your niche and the vibe you want. We email you five original persona concepts, each with handles, a bio, content pillars and a starter image prompt.',
            'button'      => 'Get Personas',
        ),
    );

    public function __construct(){
        parent::__construct();
        header('Cache-Control: private, max-age=300');
    }

    public function indexAction(){ Errors::page_not_found(); }

    /** /tools/fan-questions (the router lowercases the segment and drops the hyphen). */
    public function fanquestionsAction(){ $this->render_tool('fan-questions'); }

    /** /tools/ai-influencer-persona */
    public function aiinfluencerpersonaAction(){ $this->render_tool('ai-influencer-persona'); }

    /** The plans that include the AI tools, by name from config ("Creator and Studio"). */
    public static function paid_plans(): string {
        $names = array_map(function ($t) { return $t['name']; }, PagesController::selling_tiers());
        $last = array_pop($names);
        return count($names) > 0 ? implode(', ', $names) . ' and ' . $last : (string) $last;
    }

    public static function faq(string $slug): array {
        $site = Main::site_name(); $plans = self::paid_plans();
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
                'offers' => array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'), 'publisher' => SeoMeta::org()),
            SeoMeta::faq($faq),
            SeoMeta::breadcrumbs(array(array('name' => 'Home', 'url' => '/'), array('name' => $tool['nav_title'], 'url' => $path))),
            SeoMeta::org(),
        );
        $meta = array('url' => SeoMeta::base() . $path, 'title' => $tool['title'], 'description' => $tool['description'], 'type' => 'website',
            'jsonld' => $jsonld, 'sections' => true, 'no_band' => true);
        $this->view->public_page(Main::app_path() . '/app/views/tools/tool.php', $meta, array('tool' => $tool, 'slug' => $slug, 'faq' => $faq, 'unavailable' => $slug === 'fan-questions' && !ToolRunJob::fan_questions_ready()));
    }
}
