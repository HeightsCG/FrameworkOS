<?php
/**
 * Photography for the public marketing pages (creators at work). Generated once on fal (ImageGenService) in one
 * house style, stored publicly on S3 under creator/site/, URLs kept in SiteImageUrls::URLS (tracked in git).
 * Views call SiteImages::url('features_hero'); a missing image renders the section without one.
 * Re-roll one: php cron/site_images.php --regen=features_hero
 */
class SiteImages {
    const STYLE = 'Candid editorial lifestyle photograph, shot on a 35mm lens, natural vivid colors, warm soft light, shallow depth of field, realistic skin and hands, modern creator home studio aesthetic. No readable screens, no user interface, no text, no letters, no logos, no watermark.';

    /** key => what the photo shows. Creators at work, never a product catalogue. */
    const SUBJECTS = array(
        'home_hero'        => 'a young woman content creator filming herself with a phone on a small tripod under a ring light in a colorful apartment studio with plants and a pastel backdrop, smiling mid-sentence',
        'home_sell'        => 'overhead flat lay of a creator desk with a mirrorless camera, a phone lying face down, a notebook with handwritten ideas, a coffee cup and colorful sticky notes',
        'home_publish'     => 'a creator holding a phone up to record a selfie video on a busy city street at golden hour, candid, motion in the background',
        'home_paid'        => 'a relaxed creator sitting on a sofa with a laptop turned away from the camera, laughing, bright sunny living room',
        'features_hero'    => 'a podcast and streaming setup: a man with headphones speaking into a studio microphone, a camera on a tripod and soft colored lights in the background',
        'features_page'    => 'a photographer shooting a portrait of a creator in a studio with a bold orange paper backdrop and softbox lights',
        'features_studio'  => 'a creator editing video at a desk at night, monitor turned away from the camera, camera and small lights on the desk, warm lamp glow',
        'features_payouts' => 'a creator leaning back in a chair at a desk at night, smiling at a phone held with its screen turned away from the camera, warm orange desk lamp glow in a dark room, a camera and microphone on the desk',
        'monetize_hero'    => 'a fitness creator filming a workout video in a bright studio, phone on a tripod in the foreground slightly out of focus',
        'monetize_fans'    => 'a creator hosting a small live workshop for an engaged audience of about ten people in a sunny loft space',
        'compare_hero'     => 'two cameras on tripods pointed at an empty colorful creator set with a stool, a plant and neon light',
        'best_hero'        => 'a musician creator recording guitar in a cozy home studio with a microphone and warm string lights',
    );

    private static $cache = null;

    private static function file(): string { return Main::app_path() . '/libs/Classes/SiteImageUrls.php'; }

    /** Rewrite SiteImageUrls.php with the current list (then commit it, so every environment gets the URLs). */
    private static function save(array $all): void {
        ksort($all);
        $rows = '';
        foreach ($all as $k => $u) { $rows .= '        ' . var_export((string) $k, true) . ' => ' . var_export((string) $u, true) . ",\n"; }
        $php = "<?php\n/**\n * Public URLs of the marketing-page photos (see SiteImages). Tracked in git so every environment gets them.\n"
             . " * Written by cron/site_images.php (SiteImages::save); after it changes this file, commit it.\n"
             . " * \"<key>.bg\" is the small WebP version used behind blurred hero sections.\n */\n"
             . "class SiteImageUrls {\n    const URLS = array(\n" . $rows . "    );\n}\n";
        file_put_contents(self::file(), $php, LOCK_EX);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate(self::file(), true); }
    }

    public static function all(): array {
        if (self::$cache === null) { self::$cache = class_exists('SiteImageUrls') ? (array) SiteImageUrls::URLS : array(); }
        return self::$cache;
    }

    public static function url(string $key): string { return (string) (self::all()[$key] ?? ''); }

    /**
     * For blurred hero backgrounds: a 640px WebP of the image (a few dozen KB instead of ~300 KB; at 36px blur
     * it looks the same), falling back to the original until one is built (php cron/site_images.php --bg).
     */
    public static function bg(string $key): string {
        $all = self::all();
        return (string) ($all[$key . '.bg'] ?? ($all[$key] ?? ''));
    }

    /** Build (or rebuild) the small background version of one image. Returns its URL or ''. */
    public static function make_bg(string $key): string {
        $src = self::url($key);
        if ($src === '') { return ''; }
        $bytes = @file_get_contents($src);
        $im = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if (!$im) { error_log('[site-images] bg ' . $key . ': could not read ' . $src); return ''; }
        $w = imagesx($im); $h = imagesy($im); $nw = min(640, $w); $nh = (int) round($h * $nw / $w);
        $small = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($small, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $tmp = tempnam(sys_get_temp_dir(), 'clsbg');
        imagewebp($small, $tmp, 60);
        imagedestroy($im); imagedestroy($small);
        $url = S3Service::upload_file('creator/site/' . $key . '-bg-' . bin2hex(random_bytes(5)) . '.webp', $tmp, 'image/webp', 'public, max-age=31536000, immutable');
        @unlink($tmp);
        if ($url === '') { return ''; }
        $all = self::all(); $old = (string) ($all[$key . '.bg'] ?? '');
        $all[$key . '.bg'] = $url; self::$cache = $all;
        self::save($all);
        if ($old !== '' && strpos($old, '/creator/site/') !== false) { S3Service::delete_by_url($old); }
        return $url;
    }

    /** Generate (or regenerate) one image and save its URL. Returns the URL or ''. */
    public static function generate(string $key): string {
        if (!isset(self::SUBJECTS[$key])) { return ''; }
        $img = ImageGenService::generate(self::SUBJECTS[$key] . '. ' . self::STYLE, 'landscape');
        if (empty($img['ok'])) { error_log('[site-images] ' . $key . ': ' . ($img['error'] ?? 'failed')); return ''; }
        $ext = in_array((string) $img['ext'], array('jpg', 'jpeg', 'png', 'webp'), true) ? (string) $img['ext'] : 'jpg';
        $tmp = tempnam(sys_get_temp_dir(), 'clssite');
        file_put_contents($tmp, $img['bytes']);
        $url = S3Service::upload_file('creator/site/' . $key . '-' . bin2hex(random_bytes(5)) . '.' . $ext, $tmp, (string) ($img['mime'] ?: 'image/jpeg'));
        @unlink($tmp);
        if ($url === '') { return ''; }
        $all = self::all(); $old = (string) ($all[$key] ?? '');
        $all[$key] = $url; self::$cache = $all;
        self::save($all);
        if ($old !== '' && strpos($old, '/creator/site/') !== false) { S3Service::delete_by_url($old); }
        self::make_bg($key);   // keep the small hero background in step with the new image
        return $url;
    }
}
