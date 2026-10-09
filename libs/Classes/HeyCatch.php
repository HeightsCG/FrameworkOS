<?php
/**
 * HeyCatch analytics (https://heycatch.ai/agents.md): the one switch and the settings.
 *
 * To DISCONTINUE: set ENABLED to false. Every page stops loading the SDK, nothing is sent, and the short-link
 * redirect in Bootstrap turns off. Nothing else needs to change.
 *
 * To REMOVE entirely (grep "heycatch" / "HeyCatch" finds every line):
 *   - delete libs/Classes/HeyCatch.php and libs/Layout/heycatch.php
 *   - delete the `include ... heycatch.php` line in each <head> (libs/Layout/*.php, profile/view.php, live/guest_frame.php)
 *   - delete the HeyCatch block in app/Bootstrap.php::start_app
 *   - the `user_id`/`email` fields on the verify_email API response are harmless and may stay
 *   - profile/view.php calls CLSHeyCatch(...) through a shim that is defined only by the include; drop that line too
 */
class HeyCatch {

    const ENABLED     = true;
    const PROJECT_KEY = 'hck_pk_8b8hkO2dCwrQ-ik7PJJemN63JvLC-Loo';   // publishable, inlined on purpose (guide step 2)
    const SDK_VERSION = '0.8.3';   // what the npm `latest` dist-tag resolves to; never a -dev.N / -beta.N build

    /**
     * The signed-in user for analytics.setIdentity, or null when nobody is signed in.
     * Keys the dashboard reads: email, name, plan, signup_date (set once).
     */
    public static function identity(): ?array
    {
        if (!self::ENABLED || !Permissions::is_logged_in()) { return null; }
        $rows = (new UsersModel())->get_user_by_id((int) Session::get('user_id'));
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$user) { return null; }

        $props = array(
            'email' => (string) ($user['user_email'] ?? ''),
            'name'  => trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')),
            'role'  => Permissions::role(),
        );
        $plan = Plan::tier($user);
        if ($plan !== '') { $props['plan'] = $plan; }

        $once = array();
        $created = !empty($user['created_at']) ? strtotime((string) $user['created_at'] . ' UTC') : false;
        if ($created !== false) { $once['signup_date'] = gmdate('c', $created); }

        return array('id' => (string) (int) $user['user_id'], 'props' => $props, 'once' => $once);
    }
}
