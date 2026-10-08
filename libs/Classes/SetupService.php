<?php
/**
 * Creator onboarding checklist. Steps are evaluated from real data (CreatorSetupModel checks),
 * completed steps are sticky, and incomplete ones are re-checked at most every TTL seconds
 * (always on /setup). The card in the shared layout and the /setup page both read progress().
 */
class SetupService {

    const TTL = 300;   // seconds between Stripe payouts re-checks on ordinary page loads (DB checks are instant)

    /** Ordered step definitions. 'cta' is the Title Case button label. */
    public static function steps(): array {
        return array(
            // agreement step: selling and payouts wait on it (CreatorAgreement), so it can't be skipped.
            'agreement'  => array('title' => 'Accept the Creator Agreement',     'text' => 'Accept it once to sell content, memberships and bookings and to get paid.',  'cta' => 'Accept Agreement',          'url' => CreatorAgreement::URL,                            'optional' => false, 'skippable' => false),
            // handle step (begin): only for accounts still on the neutral signup handle, see handle_applies().
            'handle'     => array('title' => 'Choose your handle',               'text' => 'Pick the name fans see in your profile link.',                               'cta' => 'Choose Your Handle',        'url' => '/account/settings?section=account',              'optional' => false, 'skippable' => false),
            // handle step (end)
            'profile'    => array('title' => 'Complete your profile',            'text' => 'Add a display name and photo so fans recognize you.',                        'cta' => 'Complete Your Profile',     'url' => '/account/settings?section=creator',              'optional' => false),
            'brand'      => array('title' => 'Set your brand identity',          'text' => 'Tell the studio your voice and colors. Captions and AI replies use them.',   'cta' => 'Set Your Brand',            'url' => '/account/settings?section=brand',                'optional' => false),
            'tiers'      => array('title' => 'Create a membership tier',         'text' => 'Create at least one membership tier to sell subscriptions.',                'cta' => 'Create a Tier',             'url' => '/account/settings?section=plans',                'optional' => false),
            'payouts'    => array('title' => 'Set up payouts',                   'text' => 'Connect Stripe to receive your earnings.',                                   'cta' => 'Set Up Payouts',            'url' => '/account/settings?section=wallet&tab=cashout',   'optional' => false),
            'socials'    => array('title' => 'Connect a social account',         'text' => 'Connect a social account to cross-post from the studio.',                    'cta' => 'Connect a Social Account',  'url' => '/account/settings?section=connected',            'optional' => false),
            'first_post' => array('title' => 'Publish your first post',          'text' => 'It goes on your profile and the Home feed, and can cross-post to your socials.',                                       'cta' => 'Publish Your First Post',   'url' => '/studio',                                        'optional' => false),
            'inbox'      => array('title' => 'Turn on AI replies',               'text' => 'Let AI draft replies to fan DMs. Add a welcome message while you are there.',  'cta' => 'Turn On AI Replies',        'url' => '/account/settings?section=inbox',                'optional' => false),
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
        // handle step (begin): every other account never sees it.
        $defs  = self::steps();
        if (!self::handle_applies($model, $creator_id)) { unset($defs['handle']); }
        // handle step (end)
        $done  = array(); $skipped = array();
        if ($row && !empty($row['steps_json']))   { $done    = (array) json_decode((string) $row['steps_json'], true); }
        if ($row && !empty($row['skipped_json'])) { $skipped = (array) json_decode((string) $row['skipped_json'], true); }

        // Cheap DB checks run on every load so a step ticks the moment it's done — including after
        // the checklist was once marked complete (steps can be added later). Only the Stripe payouts
        // call is throttled (TTL), except when forced or returning from Stripe.
        if (count(array_intersect_key($done, $defs)) < count($defs)) {
            $now = date('Y-m-d H:i:s');
            $stripe_ok = $force || !$row || empty($row['checked_at'])
                || (strtotime((string) $row['checked_at']) < time() - self::TTL)
                || isset($_GET['payout_return']) || isset($_GET['payout_refresh']);
            $changed = false;
            foreach ($defs as $key => $meta) {
                if (isset($done[$key])) { continue; }
                if ($key === 'payouts' && !$stripe_ok) { continue; }
                if (self::check($model, $key, $creator_id)) { $done[$key] = $now; $changed = true; }
            }
            if ($changed || $stripe_ok || !$row) {
                $model->upsert_steps($creator_id, $done, $stripe_ok ? $now : (string) ($row['checked_at'] ?? $now));
            }
            if (self::all_required_done($done + $skipped, $defs)) { $model->mark_completed($creator_id); }
            $row = $model->get($creator_id);
        }

        // A skipped step is hidden from the widget and counts as settled for completion.
        $steps = array(); $req_total = 0; $req_done = 0; $next = null;
        foreach ($defs as $key => $meta) {
            $is_done = isset($done[$key]); $is_skipped = !$is_done && isset($skipped[$key]);
            $s = $meta + array('key' => $key, 'done' => $is_done, 'skipped' => $is_skipped, 'done_at' => $is_done ? (string) $done[$key] : '');
            if (!$meta['optional']) {
                $req_total++;
                if ($is_done || $is_skipped) { $req_done++; } elseif ($next === null) { $next = $s; }
            }
            $steps[] = $s;
        }
        if ($next === null) {   // every required step settled: point at the optional one if it's open
            foreach ($steps as $s) { if (!$s['done'] && !$s['skipped']) { $next = $s; break; } }
        }
        if ($req_done >= $req_total && $row && empty($row['completed_at'])) { $model->mark_completed($creator_id); }
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
            case 'agreement':  return CreatorAgreement::accepted((int) $creator_id);   // agreement step
            case 'handle':     return !UsersModel::is_neutral_username($m->current_username($creator_id));   // handle step
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

    private static function all_required_done(array $done, array $defs): bool {
        foreach ($defs as $key => $meta) {
            if (!$meta['optional'] && !isset($done[$key])) { return false; }
        }
        return true;
    }

    // handle step (begin)
    /** The handle step shows only while the account is on its neutral signup handle, or after it left one (so it ticks). */
    private static function handle_applies(CreatorSetupModel $m, $creator_id): bool {
        return UsersModel::is_neutral_username($m->current_username($creator_id)) || $m->had_neutral_username($creator_id);
    }
    // handle step (end)

    /** Is the viewer a creator (or collaborator) whose owner account has an active plan? */
    public static function eligible(): bool {
        if ((int) Session::get('user_id') <= 0 || !Permissions::can_act_as_creator()) { return false; }
        if (Permissions::is_team_member()) {
            $rows = (new UsersModel())->get_user_by_id(Permissions::creator_id());
            $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            return Plan::can_use_creator_features($owner);
        }
        $rows = (new UsersModel())->get_user_by_id((int) Session::get('user_id'));   // the full row: is_creator_row needs the role
        return Plan::can_use_creator_features((is_array($rows) && count($rows) === 1) ? $rows[0] : null);
    }

    /** Progress for the layout widget, or null when it should not render (not eligible, or hidden by the creator). */
    public static function card(): ?array {
        try {
            if (!self::eligible()) { return null; }
            $p = self::progress(Permissions::creator_id());
            if ($p['dismissed']) { return null; }   // a completed checklist stays as an "all set" state until closed
            return $p;
        } catch (\Throwable $e) {
            error_log('[setup] card: ' . $e->getMessage());
            return null;
        }
    }
}
