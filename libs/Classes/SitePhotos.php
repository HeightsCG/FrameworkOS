<?php
/**
 * Public-site photography (site-wide redesign 2026-10-15). One entry per photo: the folder under public/images, the WebP widths
 * on disk (<dir>/<file>-<w>.webp), the intrinsic size of the largest file, the alt text and the crop focus (object-position).
 * All are generated, illustrative images (fal Flux Pro 1.1 + the Polished recipe); none is presented as a customer.
 * Each photo belongs to one page family; the home woman (home-*) stays on the home. Rendered by Sections::photo().
 */
class SitePhotos
{
    const WIDE = array(640, 960, 1440);   // 16:9 sources, 1440x816
    const TALL = array(480, 768, 1152);   // 4:5 sources, 1152x1440

    const PHOTOS = array(
        // home (public/images/home, built 2026-10-15)
        'home-hero'     => array('home', 'hero', array(640, 960, 1152), 1152, 1440, 'A content creator sitting on the edge of her bed in warm window light, looking into the camera', '50% 22%'),
        'home-final'    => array('home', 'final', array(960, 1440), 1440, 816, 'A creator at her desk at night reviewing photos on a large monitor', '70% 50%'),

        // inner pages (public/images/site)
        'toolsport'     => array('site', 'toolsport', array(640, 960, 1152), 1152, 1440, 'A creator writing ideas in a notebook at his desk at night, smiling into the camera', '50% 22%'),
        'featport'      => array('site', 'featport', array(640, 960, 1152), 1152, 1440, 'A creator with a camera in her home studio, looking into the lens', '50% 22%'),
        'features'      => array('site', 'features', self::WIDE, 1440, 816, 'A creator in her home studio adjusting a softbox light beside a camera on a tripod', '70% 40%'),
        'aichar'        => array('site', 'aichar', self::TALL, 1152, 1440, 'An AI-generated character: a man in a cream sweater by a window, looking into the camera', '55% 25%'),
        'aichar-a'      => array('site', 'aichar-a', array(480, 896), 896, 1152, 'The same AI-generated man on a beach at sunset in a linen shirt', '50% 30%'),
        'aichar-b'      => array('site', 'aichar-b', array(480, 896), 896, 1152, 'The same AI-generated man resting between sets in a gym', '50% 30%'),
        'aichar-c'      => array('site', 'aichar-c', array(480, 896), 896, 1152, 'The same AI-generated man laughing at a kitchen table with a coffee', '50% 30%'),
        'train-1'       => array('site', 'train-1', array(480, 896), 896, 1152, 'Training photo: the AI-generated man, head and shoulders against a grey wall', '50% 30%'),
        'train-2'       => array('site', 'train-2', array(480, 896), 896, 1152, 'Training photo: the AI-generated man in three-quarter view in a park', '50% 30%'),
        'train-3'       => array('site', 'train-3', array(480, 896), 896, 1152, 'Training photo: the AI-generated man in side profile on a street at night', '50% 30%'),
        'train-4'       => array('site', 'train-4', array(480, 896), 896, 1152, 'Training photo: the AI-generated man standing full length on a sidewalk', '50% 30%'),
        'train-5'       => array('site', 'train-5', array(480, 896), 896, 1152, 'Training photo: the AI-generated man in a cap on a hiking trail', '50% 30%'),
        'publishing'    => array('site', 'publishing', self::WIDE, 1440, 816, 'A creator filming himself with a phone on a gimbal on a city rooftop at dusk', '72% 40%'),
        'automations'   => array('site', 'automations', self::TALL, 1152, 1440, 'A creator with a coffee on her balcony early in the morning, away from her desk', '50% 30%'),
        'analytics'     => array('site', 'analytics', self::TALL, 1152, 1440, 'A podcaster checking a tablet next to his microphone', '50% 30%'),
        'monetize'      => array('site', 'monetize', self::WIDE, 1440, 816, 'A musician playing guitar in her home studio with a camera pointed at her', '70% 40%'),
        'memberships'   => array('site', 'memberships', self::TALL, 1152, 1440, 'A fitness coach recording a workout video on his phone in a home gym', '50% 30%'),
        'ppv'           => array('site', 'ppv', self::TALL, 1152, 1440, 'A photographer reviewing shots on her camera in a studio', '50% 30%'),
        'services'      => array('site', 'services', self::TALL, 1152, 1440, 'A yoga teacher leading a live online class from her living room', '50% 35%'),
        'payouts'       => array('site', 'payouts', self::TALL, 1152, 1440, 'A creator relaxing in an armchair with tea, looking at his phone', '50% 30%'),
        'linkinbio'     => array('site', 'linkinbio', self::TALL, 1152, 1440, 'A travel creator on old stone steps at dusk smiling at her phone', '50% 35%'),
        'domains'       => array('site', 'domains', self::TALL, 1152, 1440, 'A designer sketching at a tidy desk under a warm lamp', '50% 30%'),
        'dmagent'       => array('site', 'dmagent', self::TALL, 1152, 1440, 'A creator on his couch at night replying to messages on his phone', '50% 30%'),
        'ofm'           => array('site', 'ofm', self::WIDE, 1440, 816, 'A small agency team reviewing photos on a laptop around a table', '60% 45%'),
        'compare'       => array('site', 'compare', self::WIDE, 1440, 816, 'A cooking creator plating food in front of a phone on a tripod', '72% 40%'),
        'alternatives'  => array('site', 'alternatives', self::WIDE, 1440, 816, 'A skateboarder creator holding a camera on a ledge at dusk', '72% 40%'),
        'about'         => array('site', 'about', self::WIDE, 1440, 816, 'A shared workspace at dusk with laptops, a camera and notebooks on a long table', '50% 50%'),
        'contact'       => array('site', 'contact', self::TALL, 1152, 1440, 'A woman smiling on a phone call at her desk by a window', '50% 30%'),
        'press'         => array('site', 'press', self::WIDE, 1440, 816, 'A podcast microphone, headphones, a camera and a notebook on a dark desk', '60% 50%'),
        'founding'      => array('site', 'founding', self::WIDE, 1440, 816, 'Four creators laughing together on a rooftop terrace at dusk', '55% 40%'),
        'affiliates'    => array('site', 'affiliates', self::WIDE, 1440, 816, 'Two creators at a cafe table at night, one showing the other her phone', '60% 40%'),
        'integrations'  => array('site', 'integrations', self::WIDE, 1440, 816, 'A creator checking one of four phones standing on tripods in a row', '72% 40%'),
        'integration'   => array('site', 'integration', self::WIDE, 1440, 816, 'A barista filming latte art with his phone above the cup', '72% 40%'),
        'tools'         => array('site', 'tools', self::WIDE, 1440, 816, 'Hands writing ideas in a notebook beside a laptop and a coffee at night', '60% 50%'),
        'pricing'       => array('site', 'pricing', self::WIDE, 1440, 816, 'A ceramics creator shaping a bowl on a pottery wheel while her phone films', '72% 40%'),
    );

    /** True when the entry exists and its largest file is on disk (a missing photo never breaks a page; the hero falls back to text only). */
    public static function has(string $key): bool {
        if (!isset(self::PHOTOS[$key])) { return false; }
        $p = self::PHOTOS[$key];
        return is_file(Main::app_path() . '/public/images/' . $p[0] . '/' . $p[1] . '-' . end($p[2]) . '.webp');
    }

    /** srcset, src and the largest URL for the preload hint. */
    public static function srcset(string $key): string {
        $p = self::PHOTOS[$key]; $set = array();
        foreach ($p[2] as $w) { $set[] = '/images/' . $p[0] . '/' . $p[1] . '-' . $w . '.webp ' . $w . 'w'; }
        return implode(', ', $set);
    }
    public static function src(string $key): string {
        $p = self::PHOTOS[$key];
        return '/images/' . $p[0] . '/' . $p[1] . '-' . end($p[2]) . '.webp';
    }
}
