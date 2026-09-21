<?php
/**
 * Creator onboarding checklist. Steps are evaluated from real data (CreatorSetupModel checks),
 * completed steps are sticky, and incomplete ones are re-checked at most every TTL seconds
 * (always on /setup). The card in the shared layout and the /setup page both read progress().
 */
class SetupService {

    const TTL = 300;   // seconds between re-checks of incomplete steps on ordinary page loads

    /** Ordered step definitions. 'cta' is the Title Case button label. */
    public static function steps(): array {
        return array(
            'profile'    => array('title' => 'Complete your profile',            'text' => 'Add a display name and photo so fans recognize you.',                        'cta' => 'Complete Your Profile',     'url' => '/account/settings?section=creator',              'optional' => false),
            'brand'      => array('title' => 'Set your brand identity',          'text' => 'Tell the studio your voice and colors. Captions and AI replies use them.',   'cta' => 'Set Your Brand',            'url' => '/account/settings?section=brand',                'optional' => false),
            'tiers'      => array('title' => 'Create a membership tier',         'text' => 'Create at least one membership tier to sell subscriptions.',                'cta' => 'Create a Tier',             'url' => '/account/settings?section=plans',                'optional' => false),
            'payouts'    => array('title' => 'Set up payouts',                   'text' => 'Connect Stripe to receive your earnings.',                                   'cta' => 'Set Up Payouts',            'url' => '/account/settings?section=wallet&tab=cashout',   'optional' => false),
            'socials'    => array('title' => 'Connect a social account',         'text' => 'Connect a social account to cross-post from the studio.',                    'cta' => 'Connect a Social Account',  'url' => '/account/settings?section=connected',            'optional' => false),
            'first_post' => array('title' => 'Publish your first post',          'text' => 'It goes on your profile and the Home feed, and can cross-post to your socials.',                                       'cta' => 'Publish Your First Post',   'url' => '/studio',                                        'optional' => false),
            'inbox'      => array('title' => 'Turn on AI replies and a welcome message', 'text' => 'Let AI draft replies and send a welcome message to new fans.',       'cta' => 'Set Up AI Replies',         'url' => '/account/settings?section=inbox',                'optional' => true),
        );
    }

    /**
     * Progress for one owner creator.
     * @return array steps (ordered, each + key/done/done_at), required_done, required_total, complete, dismissed, next
     */
    public static function progress($creator_id, $force = false): array {
        $creator_id = (int) $creator_id;
        $model = new CreatorSetupModel();
        $row   = $model->get($creator_id);
        $done  = array();
        if ($row && !empty($row['steps_json'])) { $done = (array) json_decode((string) $row['steps_json'], true); }

        $stale = $force || !$row || empty($row['checked_at']) || (strtotime((string) $row['checked_at']) < time() - self::TTL);
        if ($stale && (!$row || empty($row['completed_at']))) {
            $now = date('Y-m-d H:i:s');
            foreach (self::steps() as $key => $meta) {
                if (isset($done[$key])) { continue; }
                if (self::check($model, $key, $creator_id)) { $done[$key] = $now; }
            }
            $model->upsert_steps($creator_id, $done, $now);
            if (self::all_required_done($done)) { $model->mark_completed($creator_id); }
            $row = $model->get($creator_id);
        }

        $steps = array(); $req_total = 0; $req_done = 0; $next = null;
        foreach (self::steps() as $key => $meta) {
            $is_done = isset($done[$key]);
            $s = $meta + array('key' => $key, 'done' => $is_done, 'done_at' => $is_done ? (string) $done[$key] : '');
            if (!$meta['optional']) {
                $req_total++;
                if ($is_done) { $req_done++; } elseif ($next === null) { $next = $s; }
            }
            $steps[] = $s;
        }
        if ($next === null) {   // every required step done: point at the optional one if it's open
            foreach ($steps as $s) { if (!$s['done']) { $next = $s; break; } }
        }
        return array(
            'steps'          => $steps,
            'required_done'  => $req_done,
            'required_total' => $req_total,
            'complete'       => $req_done >= $req_total,
            'dismissed'      => $row ? !empty($row['dismissed_at']) : false,
            'next'           => $next,
        );
    }

    private static function check(CreatorSetupModel $m, $key, $creator_id): bool {
        switch ($key) {
            case 'profile':    return $m->has_profile($creator_id);
            case 'brand':      return $m->has_brand($creator_id);
            case 'tiers':      return $m->has_active_tier($creator_id);
            case 'payouts':    return $m->has_payouts($creator_id);
            case 'socials':    return $m->has_connected_social($creator_id);
            case 'first_post': return $m->has_first_post($creator_id);
            case 'inbox':      return $m->has_inbox_ready($creator_id);
        }
        return false;
    }

    private static function all_required_done(array $done): bool {
        foreach (self::steps() as $key => $meta) {
            if (!$meta['optional'] && !isset($done[$key])) { return false; }
        }
        return true;
    }

    /** Is the viewer a creator (or collaborator) whose owner account has an active plan? */
    public static function eligible(): bool {
        if ((int) Session::get('user_id') <= 0 || !Permissions::can_act_as_creator()) { return false; }
        if (Permissions::is_team_member()) {
            $rows = (new UsersModel())->get_user_by_id(Permissions::creator_id());
            $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            return Plan::can_use_creator_features($owner);
        }
        return Plan::can_use_creator_features(array('subscription_status' => (string) Session::get('subscription_status')));
    }

    /** Progress for the layout card, or null when the card should not render. */
    public static function card(): ?array {
        try {
            if (!self::eligible()) { return null; }
            $p = self::progress(Permissions::creator_id());
            if ($p['complete'] || $p['dismissed']) { return null; }
            return $p;
        } catch (\Throwable $e) {
            error_log('[setup] card: ' . $e->getMessage());
            return null;
        }
    }
}
