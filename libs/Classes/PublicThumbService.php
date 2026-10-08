<?php
/**
 * Public, cacheable WebP renditions for media anyone may see: images (and video poster frames) in a PUBLISHED, FREE
 * post on the site that the moderator approved as not adult (MediaAssetsModel::public_ok_sql(), which also needs the creator's page to be public). Everything else (gated,
 * adult, pending, blurred) keeps the short-lived signed URLs from MediaService, and a card whose public rendition is
 * missing falls back to the signed one, so an image is never broken.
 *
 * Keys sit under creator/* (public read in the bucket policy) with a random token, so they can't be guessed from an
 * asset id: creator/{creator_id}/media/{asset_id}/public_{width}_{token}.webp. Built from the watermarked display
 * rendition (poster for a video) and stored with a one-year immutable Cache-Control.
 *
 * Blog covers get the same treatment: {cover}-960.webp + {cover}-480.webp next to the generated JPEG, each <= 80 KB.
 */
class PublicThumbService {

    const GRID  = 480;    // profile grid and small home tiles
    const FEED  = 960;    // large home tile, blog cover
    const CACHE = 'public, max-age=31536000, immutable';
    const COVER_MAX_BYTES = 81920;

    /* ---- urls ---- */

    /**
     * Public URL of an asset's rendition ($size 'grid' or 'feed'), or '' when it has none or the row no longer looks
     * safe. The CALLER decides the post is free and published; this only re-checks the asset's own moderation.
     */
    public static function url(array $asset, $size = 'grid'): string
    {
        if ((string) ($asset['moderation_status'] ?? '') !== 'approved' || !empty($asset['is_adult'])) { return ''; }
        $key = (string) ($asset[$size === 'feed' ? 'public_display_key' : 'public_thumb_key'] ?? '');
        return $key === '' ? '' : S3Service::public_url($key);
    }

    /** [width, height] of a rendition, for <img> attributes; [0, 0] when unknown. */
    public static function size(array $asset, $size = 'grid'): array
    {
        $w = (int) ($asset['public_width'] ?? 0); $h = (int) ($asset['public_height'] ?? 0);
        if ($w <= 0 || $h <= 0) { return array(0, 0); }
        if ($size === 'feed') { return array($w, $h); }
        $tw = min($w, self::GRID);
        return array($tw, max(1, (int) round($h * $tw / $w)));
    }

    /** Alt text: the caption's first 100 characters, else the creator's name. */
    public static function alt($caption, $name): string
    {
        $cap = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $caption), ENT_QUOTES, 'UTF-8')));
        if ($cap === '') { return trim((string) $name); }
        return mb_strlen($cap) > 100 ? rtrim(mb_substr($cap, 0, 99)) . '…' : $cap;
    }

    /* ---- media renditions ---- */

    /** Build the public renditions for every qualifying asset of one post (or every one, $post_id 0). Returns how many. */
    public static function sync_post($post_id, $limit = 200): int
    {
        $n = 0;
        foreach ((new MediaAssetsModel())->public_missing((int) $post_id, $limit) as $a) {
            if (self::make($a)) { $n++; }
        }
        return $n;
    }

    /** Build both public WebP renditions of one asset row and save their keys. True on success. */
    public static function make(array $asset): bool
    {
        $src_key = (string) ($asset['type'] === 'video' ? ($asset['poster_key'] ?? '') : ($asset['display_key'] ?? ''));
        if ($src_key === '') { $src_key = (string) ($asset['thumb_key'] ?? ''); }
        if (!function_exists('imagewebp')) { return false; }
        if ($src_key === '') { (new MediaAssetsModel())->set_public_error((int) $asset['id'], 'no source rendition'); return false; }
        $tmp = tempnam(sys_get_temp_dir(), 'pub');
        if ($tmp === false || !S3Service::get_private_to_file($src_key, $tmp)) { @unlink($tmp); error_log('[public_thumb] asset ' . (int) $asset['id'] . ': source unreadable'); return false; }
        $img = @imagecreatefromstring((string) file_get_contents($tmp));
        @unlink($tmp);
        if ($img === false) { error_log('[public_thumb] asset ' . (int) $asset['id'] . ': source not an image'); (new MediaAssetsModel())->set_public_error((int) $asset['id'], 'source not an image'); return false; }

        $token = bin2hex(random_bytes(6));
        $base  = 'creator/' . (int) $asset['creator_id'] . '/media/' . (int) $asset['id'] . '/public_';
        $out = array();
        foreach (array(self::GRID, self::FEED) as $w) {
            $r = self::resize_to_width($img, $w);
            $out[$w] = array('key' => $base . $w . '_' . $token . '.webp', 'w' => imagesx($r), 'h' => imagesy($r), 'bytes' => self::webp($r, 78));
            imagedestroy($r);
        }
        imagedestroy($img);
        foreach ($out as $o) {
            if (S3Service::put_public_bytes($o['key'], $o['bytes'], 'image/webp', self::CACHE) === '') {
                foreach ($out as $x) { S3Service::delete_key($x['key']); }
                return false;
            }
        }
        // a rebuilt asset drops its old copies.
        foreach (array('public_thumb_key', 'public_display_key') as $col) { if (!empty($asset[$col])) { S3Service::delete_key((string) $asset[$col]); } }
        (new MediaAssetsModel())->set_public((int) $asset['id'], $out[self::GRID]['key'], $out[self::FEED]['key'], $out[self::FEED]['w'], $out[self::FEED]['h']);
        return true;
    }

    /**
     * Take down public copies that no longer qualify, for one scope: array('post' => id), array('creator' => id) or
     * array('assets' => ids). Queued (the S3 deletes run in the worker) so the request that changed things stays fast;
     * falls back to doing it inline if the queue is unavailable. Never throws.
     */
    public static function queue_purge(array $scope): void
    {
        try {
            $scope = array_filter($scope);
            if (empty($scope)) { return; }
            $key = 'public_purge:' . md5(json_encode($scope));
            if (class_exists('DatabaseJobQueue')) { (new DatabaseJobQueue())->dispatch('public_thumb', array('purge' => $scope), $key); return; }
            self::purge_stale($scope);
        } catch (\Throwable $e) {
            error_log('[public_thumb] queue_purge ' . json_encode($scope) . ': ' . $e->getMessage());
        }
    }

    /** Purge every public copy in $scope (see queue_purge) that no longer qualifies. Returns how many. */
    public static function purge_stale(array $scope = array()): int
    {
        $n = 0;
        foreach ((new MediaAssetsModel())->public_stale(1000, $scope) as $a) { self::purge($a); $n++; }
        return $n;
    }

    /** Delete an asset's public renditions and clear the columns. */
    public static function purge(array $asset): void
    {
        foreach (array('public_thumb_key', 'public_display_key') as $col) { if (!empty($asset[$col])) { S3Service::delete_key((string) $asset[$col]); } }
        (new MediaAssetsModel())->set_public((int) $asset['id'], '', '', 0, 0);
    }

    /* ---- blog covers ---- */

    /** The 480 wide sibling of a stored 960 cover URL. */
    public static function cover_small($webp_url): string
    {
        return (string) preg_replace('/-960\.webp$/', '-480.webp', (string) $webp_url);
    }

    /**
     * WebP copies of an article's cover (960 and 480 wide, each <= 80 KB) next to the JPEG. Saves cover_webp_url
     * (the 960 one) and removes the previous pair. Returns the new URL, '' on failure. Never throws.
     */
    public static function blog_cover($article_id, $cover_url, $old_webp_url = ''): string
    {
        try {
            $prefix = S3Service::public_url('x'); $prefix = substr($prefix, 0, -1);
            $cover_url = (string) $cover_url;
            if ($cover_url === '' || strpos($cover_url, $prefix) !== 0 || !function_exists('imagewebp')) { return ''; }
            $key = substr($cover_url, strlen($prefix));
            $tmp = tempnam(sys_get_temp_dir(), 'pubc');
            if ($tmp === false || !S3Service::get_private_to_file($key, $tmp)) { @unlink($tmp); return ''; }
            $img = @imagecreatefromstring((string) file_get_contents($tmp));
            @unlink($tmp);
            if ($img === false) { return ''; }
            $stem = preg_replace('/\.[a-z0-9]+$/i', '', $key);
            $url = '';
            foreach (array(self::FEED, self::GRID) as $w) {
                $bytes = self::fit_webp($img, $w, self::COVER_MAX_BYTES);
                $u = S3Service::put_public_bytes($stem . '-' . $w . '.webp', $bytes, 'image/webp', self::CACHE);
                if ($u === '') { imagedestroy($img); return ''; }
                if ($w === self::FEED) { $url = $u; }
            }
            imagedestroy($img);
            (new SeoArticlesModel())->update_fields((int) $article_id, array('cover_webp_url' => $url));
            $old_webp_url = (string) $old_webp_url;
            if ($old_webp_url !== '' && $old_webp_url !== $url) { S3Service::delete_by_url($old_webp_url); S3Service::delete_by_url(self::cover_small($old_webp_url)); }
            return $url;
        } catch (\Throwable $e) {
            error_log('[public_thumb] blog cover ' . (int) $article_id . ': ' . $e->getMessage());
            return '';
        }
    }

    /* ---- profile photo and cover ---- */

    /** The 800 wide sibling of a stored 1600 cover webp URL. */
    public static function cover_800($webp_url): string
    {
        return (string) preg_replace('/-1600\.webp$/', '-800.webp', (string) $webp_url);
    }

    /**
     * WebP copies of a creator's avatar (320 wide) or cover (1600 + 800 wide) next to the uploaded image, with the
     * immutable cache header. Saves avatar_webp_url / cover_webp_url (the cover's 1600 one) while $image_url is still
     * current, and removes $old_webp_url's files. GIFs are skipped (animation). Returns the new URL, '' on failure.
     */
    public static function profile_image($user_id, $kind, $image_url, $old_webp_url = ''): string
    {
        try {
            $image_url = (string) $image_url;
            $old_webp_url = (string) $old_webp_url;
            if ($old_webp_url !== '') {
                S3Service::delete_by_url($old_webp_url);
                if ($kind === 'cover') { S3Service::delete_by_url(self::cover_800($old_webp_url)); }
            }
            $prefix = substr(S3Service::public_url('x'), 0, -1);
            if ($image_url === '' || strpos($image_url, $prefix) !== 0 || preg_match('/\.gif$/i', $image_url) || !function_exists('imagewebp')) { return ''; }
            $key = substr($image_url, strlen($prefix));
            $tmp = tempnam(sys_get_temp_dir(), 'pubp');
            if ($tmp === false || !S3Service::get_private_to_file($key, $tmp)) { @unlink($tmp); return ''; }
            $img = @imagecreatefromstring((string) file_get_contents($tmp));
            @unlink($tmp);
            if ($img === false) { return ''; }
            $stem = preg_replace('/\.[a-z0-9]+$/i', '', $key);
            $url = '';
            foreach (($kind === 'avatar' ? array(320) : array(1600, 800)) as $w) {
                $r = self::resize_to_width($img, $w);
                $u = S3Service::put_public_bytes($stem . '-' . $w . '.webp', self::webp($r, 80), 'image/webp', self::CACHE);
                imagedestroy($r);
                if ($u === '') { imagedestroy($img); return ''; }
                if ($url === '') { $url = $u; }
            }
            imagedestroy($img);
            (new CreatorProfileModel())->set_image_webp((int) $user_id, $kind === 'avatar' ? 'avatar_url' : 'cover_url', $image_url, $url);
            return $url;
        } catch (\Throwable $e) {
            error_log('[public_thumb] profile ' . $kind . ' ' . (int) $user_id . ': ' . $e->getMessage());
            return '';
        }
    }

    /* ---- GD ---- */

    /**
     * WebP of $img at $w wide that fits in $max_bytes: quality steps 80 -> 50, then the width shrinks 15% at a time
     * (a blog cover keeps its -960/-480 name; srcset only chooses between the two). Returns the bytes.
     */
    public static function fit_webp($img, $w, $max_bytes): string
    {
        $r = self::resize_to_width($img, $w);
        $q = 80; $bytes = self::webp($r, $q);
        while (strlen($bytes) > $max_bytes && $q > 50) { $q -= 10; $bytes = self::webp($r, $q); }
        $sw = imagesx($r);
        while (strlen($bytes) > $max_bytes && $sw > 160) {
            $sw = (int) round($sw * .85); imagedestroy($r); $r = self::resize_to_width($img, $sw); $bytes = self::webp($r, $q);
        }
        imagedestroy($r);
        return $bytes;
    }

    /** Scale to $w wide (never upscaled), flattening alpha onto white. */
    private static function resize_to_width($img, $w)
    {
        $sw = imagesx($img); $sh = imagesy($img);
        $nw = min($sw, (int) $w); $nh = max(1, (int) round($sh * $nw / $sw));
        $out = imagecreatetruecolor($nw, $nh);
        imagefilledrectangle($out, 0, 0, $nw, $nh, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
        return $out;
    }

    private static function webp($img, $quality): string
    {
        ob_start();
        imagewebp($img, null, (int) $quality);
        return (string) ob_get_clean();
    }
}
