<?php
/**
 * /start: the paid-traffic landing page for TikTok ads (2026-10-16), rendered by PagesController::startAction from
 * app/views/pages/start.php (its own standalone document: no site nav, no footer, Terms and Privacy only).
 *
 * The media is configurable here; every file lives in public/landing/. The character is Aria, the model in the TikTok
 * ad (Desktop/CLS/ad20.png): the stills were made from that ad with fal Nano Banana edits, the videos with Kling v3
 * image-to-video, the hero's captions burned in with ffmpeg. Swap her by replacing the files under the same names.
 */
class StartPage
{
    /** The hero: a muted, looping vertical video with its poster. Phones get the 540-wide encode (415KB: the first frame is the LCP), wider screens the 720-wide one. */
    const HERO = array('video' => '/landing/hero.mp4', 'video_sm' => '/landing/hero-sm.mp4', 'poster' => '/landing/hero.jpg', 'w' => 540, 'h' => 946,
        'label' => 'Video of Aria, an AI-generated woman, smiling into the camera. Captions: Hi, I\'m Aria. I\'m 100% AI. I was made in a studio. So can yours.');

    /** Step 2, "Lock in her face". */
    const FACE = array('src' => '/landing/face.jpg', 'w' => 560, 'h' => 720, 'alt' => 'Studio reference portrait of Aria, the AI character, used to lock in her face');

    /** Step 1, "Describe her": the prompt that types out. */
    const PROMPT = 'Aria, 25. Long wavy dark-brown hair, green eyes, warm confident smile. Black off-shoulder top. Shot on a phone.';

    /** Gallery images: gallery-N.jpg (560 wide) and gallery-N-320.jpg, all 560x720. */
    const IMG_W = 560;
    const IMG_H = 720;
    /** Short clips inline in the gallery: clip-N.mp4 with poster clip-N.jpg, 480x840. */
    const CLIP_W = 480;
    const CLIP_H = 840;

    /** The gallery in order: array(key, alt, 'img' | 'clip'). */
    const GALLERY = array(
        array('gallery-1', 'Aria laughing at a cafe table with an iced latte', 'img'),
        array('gallery-2', 'Aria walking on a beach at golden hour', 'img'),
        array('clip-1', 'Short clip: Aria laughing and sipping coffee in a cafe', 'clip'),
        array('gallery-3', 'Aria taking a gym mirror selfie', 'img'),
        array('gallery-4', 'Aria on a rooftop at night with the city behind her', 'img'),
        array('gallery-5', 'Aria on an autumn street with a coffee', 'img'),
        array('clip-2', 'Short clip: Aria walking toward the camera on a city street at dusk', 'clip'),
        array('gallery-6', 'Aria on a couch with a laptop', 'img'),
        array('gallery-7', 'Aria cooking in a kitchen', 'img'),
        array('gallery-8', 'Aria in an airport lounge by the window', 'img'),
    );

    /** The ten platforms, in the order the ad brief lists them (IntegrationPages slugs; Fanvue has no logo file and shows its name). */
    const PLATFORMS = array('linkedin', 'bluesky', 'x', 'facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'pinterest', 'fanvue');

    public static function img(string $key, int $w = 560): string { return '/landing/' . $key . ($w === 560 ? '' : '-' . $w) . '.jpg'; }
    public static function srcset(string $key): string { return self::img($key, 320) . ' 320w, ' . self::img($key) . ' 560w'; }

    /** The 14-day trial is the founding offer, open while founding spots remain. */
    public static function trial_open(): bool {
        try { return Founding::remaining_cached() > 0; } catch (\Throwable $e) { error_log('[start] founding: ' . $e->getMessage()); return false; }
    }
}
