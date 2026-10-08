<?php
/**
 * Product screenshots for the public pages, behind one flag. Files live at public/images/shots/<file>.webp
 * once approved. img() returns '' while ENABLED is false, for an unknown key, or when the file is missing,
 * so a placement can be wired before the images ship.
 */
class Screenshots {

    const ENABLED = false;

    const DIR = '/public/images/shots/';
    const URL = '/images/shots/';

    // placement key => file (no extension), alt text, display size. file '' = no capture yet.
    const PLACEMENTS = array(
        'features-hero'                => array('file' => 'studio-posts',         'alt' => 'Content Studio posts list with a published post, its audience and publish time', 'width' => 1440, 'height' => 900),
        'feature-memberships'          => array('file' => 'creator-page',         'alt' => 'A creator page with Follow and Subscribe buttons and a Membership tab', 'width' => 1440, 'height' => 900),
        'feature-pay-per-view'         => array('file' => 'post-composer',        'alt' => 'The post editor with media, caption, audience settings and a live subscriber preview', 'width' => 1440, 'height' => 900),
        'feature-link-in-bio'          => array('file' => 'creator-page',         'alt' => 'A creator page with profile, posts and membership in one link', 'width' => 1440, 'height' => 900),
        'feature-publishing'           => array('file' => 'scheduling-calendar',  'alt' => 'Content Studio calendar showing a post placed on its publish day', 'width' => 1440, 'height' => 900),
        'feature-services-and-events'  => array('file' => '',                     'alt' => '', 'width' => 1440, 'height' => 900),
        'feature-custom-domains'       => array('file' => '',                     'alt' => '', 'width' => 1440, 'height' => 900),
        'feature-ai-influencer'        => array('file' => 'character-generation', 'alt' => 'Generate Images for an AI character, a grid of photos of the same person with the prompt bar below', 'width' => 1440, 'height' => 900),
        'feature-dm-agent'             => array('file' => 'dm-agent',             'alt' => 'Inbox Automation settings with approve first or send automatically, quiet hours and reply style', 'width' => 1440, 'height' => 900),
        'feature-payouts'              => array('file' => 'payouts',              'alt' => 'Wallet with the Cash Out tab and the payout setup step', 'width' => 1440, 'height' => 900),
        'home-create'                  => array('file' => 'character-generation', 'alt' => 'Generate Images for an AI character, a grid of photos of the same person with the prompt bar below', 'width' => 1440, 'height' => 900),
        'home-share'                   => array('file' => 'post-composer',        'alt' => 'The post editor with media, caption and a live preview before publishing', 'width' => 1440, 'height' => 900),
        'home-earn'                    => array('file' => 'payouts',              'alt' => 'Wallet with the Cash Out tab and the payout setup step', 'width' => 1440, 'height' => 900),
    );

    /** <picture> markup for one placement, or '' when disabled, unknown or missing. */
    public static function img($key, $lazy = true){
        if (!self::ENABLED) { return ''; }
        $p = self::PLACEMENTS[(string) $key] ?? null;
        if (!is_array($p) || (string) $p['file'] === '') { return ''; }
        $path = Main::app_path() . self::DIR . $p['file'] . '.webp';
        if (!is_file($path)) { return ''; }
        $src = self::URL . $p['file'] . '.webp?v=' . filemtime($path);
        return '<picture class="shot"><source type="image/webp" srcset="' . htmlspecialchars($src, ENT_QUOTES) . '">'
            . '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="' . htmlspecialchars((string) $p['alt'], ENT_QUOTES) . '"'
            . ' width="' . (int) $p['width'] . '" height="' . (int) $p['height'] . '"'
            . ($lazy ? ' loading="lazy"' : ' fetchpriority="high"') . ' decoding="async"></picture>';
    }
}
