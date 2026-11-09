<?php
/**
 * The public site's main menu (2026-10-15): grouped panels for product discovery, rendered by libs/Layout/public_nav.php.
 * Every href is a live public route; descriptions are one short line about what the page covers. The footer
 * (libs/Layout/public_footer.php) stays the full index; this menu carries the destinations that matter most for discovery.
 * Each group: label, icon-free top button, items => array(href, title, line, icon), foot => array(href, label) (optional).
 */
class SiteNav
{
    const GROUPS = array(
        'platform' => array(
            'label' => 'Platform',
            'items' => array(
                array('/features', 'All Features', 'Every tool in the AI content studio', 'list'),
                array('/features/publishing', 'Publishing', 'One post to your page and nine platforms', 'send'),
                array('/features/automations', 'Automations', 'Posts and messages on a schedule', 'repeat'),
                array('/features/analytics', 'Analytics', 'Content, audience and clicks in one view', 'chart'),
                array('/features/link-in-bio', 'Link in Bio', 'One page at your handle', 'link'),
            ),
            'foot' => array('/integrations', 'See all nine integrations'),
        ),
        'ai' => array(
            'label' => 'AI Tools',
            'items' => array(
                array('/features/ai-influencer', 'AI Influencer', 'Create a character, then photos and video', 'sparkles'),
                array('/consistent-ai-model-face', 'Consistent AI Face', 'The same person in every image', 'face'),
                array('/lora-character-training', 'Character Training', 'Train one face from your photos', 'image'),
                array('/features/dm-agent', 'DM Agent', 'Replies to messages in your voice', 'message'),
            ),
            'foot' => array('/alternatives', 'AI influencer tools compared'),
        ),
        'tools' => array(
            'label' => 'Free Tools',
            'items' => array(
                array('/tools/fan-questions', 'Fan Question Finder', 'Post ideas from real audience questions', 'search'),
                array('/tools/ai-influencer-persona', 'AI Influencer Persona Generator', 'Five persona concepts for your niche', 'users'),
                array('/tools/ai-influencer-name-generator', 'AI Influencer Name Generator', 'Twenty names with handles', 'tag'),
                array('/tools/ai-influencer-bio-generator', 'AI Influencer Bio Generator', 'Bios sized for five platforms', 'page'),
                array('/tools/ai-disclosure-caption-checker', 'AI Disclosure Caption Checker', 'Check a caption labels AI clearly', 'shield'),
            ),
            'foot' => array('/tools', 'View all free tools'),
        ),
        'resources' => array(
            'label' => 'Resources',
            'items' => array(
                array('/blog', 'Blog', 'Guides on AI content, publishing and growth', 'page'),
                array('/creators', 'Creator Directory', 'Creators with a public page', 'star'),
                array('/affiliates', 'Affiliate Program', 'Earn for every creator you refer', 'user-plus'),
                array('/about', 'About', 'Who builds it and why', 'shield'),
                array('/contact', 'Contact', 'Questions, billing and support', 'message'),
            ),
        ),
    );

    /** The group a path belongs to, for the active state on the top button ('' when none). */
    public static function group_of(string $path): string {
        if ($path === '/pricing') { return 'pricing'; }
        foreach (self::GROUPS as $k => $g) {
            foreach ($g['items'] as $it) { if ($it[0] === $path || ($it[0] !== '/features' && strpos($path, $it[0] . '/') === 0)) { return $k; } }
            if (!empty($g['foot']) && ($g['foot'][0] === $path || strpos($path, $g['foot'][0] . '/') === 0)) { return $k; }
        }
        if (strpos($path, '/tools') === 0) { return 'tools'; }
        if (strpos($path, '/blog') === 0) { return 'resources'; }
        if (strpos($path, '/features') === 0 || strpos($path, '/integrations') === 0) { return 'platform'; }
        return '';
    }
}
