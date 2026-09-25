<?php
/**
 * Creator directory (/creators, /creators/<category>). Opt-in and safe-for-work only.
 * A creator shows when they switched it on, picked a category, their profile photo and cover passed the
 * adult-image check as they are now, and they have at least one published, non-adult page post
 * (CreatorProfileModel::directory_where). Changing a photo queues a re-check (DirectoryRecheckJob), and
 * until it passes the creator is left out. No paying to rank: verified first, then most recently active.
 */
class DirectoryService {

    /** slug => label. Pages exist only for categories that have listed creators. */
    const CATEGORIES = array(
        'fitness'   => 'Fitness',
        'music'     => 'Music',
        'cooking'   => 'Food & Cooking',
        'gaming'    => 'Gaming',
        'beauty'    => 'Beauty & Fashion',
        'art'       => 'Art & Design',
        'education' => 'Education',
        'lifestyle' => 'Lifestyle',
        'travel'    => 'Travel',
        'business'  => 'Business',
        'other'     => 'Other',
    );

    const PER_PAGE = 24;

    /** The hash stored when the current images pass; the directory compares it with the live images. */
    public static function media_hash(array $profile): string {
        return sha1((string) ($profile['avatar_url'] ?? '') . '|' . (string) ($profile['cover_url'] ?? ''));
    }

    /**
     * Turn the listing on or off from Settings. On needs a category and a profile photo, and both images
     * must pass the adult check. ['ok', 'message'] or ['ok' => false, 'message'].
     */
    public static function save($user_id, $listed, $category): array {
        $m = new CreatorProfileModel();
        if (!$listed) {
            $m->set_directory($user_id, false, isset(self::CATEGORIES[$category]) ? $category : '');
            return array('ok' => true, 'message' => 'You\'re no longer listed in the directory.');
        }
        if (!isset(self::CATEGORIES[$category])) { return array('ok' => false, 'message' => 'Choose a category.'); }
        $p = $m->get_for_user($user_id);
        if ((string) ($p['avatar_url'] ?? '') === '') { return array('ok' => false, 'message' => 'Add a profile photo first. The directory shows it on your card.'); }
        $check = self::check_images($p);
        if (!$check['ok']) { return array('ok' => false, 'message' => $check['message']); }
        $m->set_directory($user_id, true, $category);
        $m->set_directory_media($user_id, self::media_hash($p));
        return array('ok' => true, 'message' => $m->directory_eligible($user_id)
            ? 'You\'re listed in the Creator Directory.'
            : 'Saved. You\'ll appear in the directory once you have a published post whose images aren\'t adult content.');
    }

    /** Photo or cover changed: queue a re-check for a listed creator (the directory drops them until it passes). */
    public static function queue_recheck($user_id): void {
        $p = (new CreatorProfileModel())->get_for_user($user_id);
        if (empty($p['directory_listed'])) { return; }
        (new DatabaseJobQueue())->dispatch('directory_recheck', array('user_id' => (int) $user_id), 'directory_recheck:' . (int) $user_id);
    }

    /** Job body: check the current images again; store the pass, or clear it (and tell the creator) on a fail. */
    public static function recheck($user_id): string {
        $m = new CreatorProfileModel();
        $p = $m->get_for_user($user_id);
        if (empty($p['directory_listed'])) { return 'not listed'; }
        $check = self::check_images($p);
        if ($check['ok']) { $m->set_directory_media($user_id, self::media_hash($p)); return 'passed'; }
        if (!empty($check['retry'])) { throw new RuntimeException('moderation unavailable: ' . $check['message']); }   // the queue retries later
        $m->set_directory_media($user_id, null);
        Notify::send((int) $user_id, 'security', 'You\'re hidden from the Creator Directory', $check['message'], '/account/settings?section=creator', 'fa-eye-slash', false, true);
        return 'failed';
    }

    /** Both images must be clearly not adult. A scan that can't run is a "not now", never a pass. */
    private static function check_images(array $p): array {
        foreach (array('avatar_url' => 'profile photo', 'cover_url' => 'cover image') as $col => $label) {
            $url = (string) ($p[$col] ?? '');
            if ($url === '') { continue; }
            $r = ModerationService::classify_image($url);
            if (empty($r['ok'])) { return array('ok' => false, 'retry' => true, 'message' => 'We couldn\'t check your ' . $label . ' right now. Try again in a few minutes.'); }
            if (!empty($r['adult']) || !empty($r['minors'])) { return array('ok' => false, 'message' => 'Your ' . $label . ' can\'t be shown in the directory, which is safe for work only. Choose a different image.'); }
        }
        return array('ok' => true);
    }
}
