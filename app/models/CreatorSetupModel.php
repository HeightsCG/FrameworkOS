<?php
/**
 * Creator onboarding progress. One row per OWNER creator (collaborators read the owner's).
 * steps_json holds completed_at per step key and is sticky: once a step is done it stays
 * done even if the underlying data is later removed. The checks below are the source of
 * truth for "done" — nothing is self-reported.
 */
class CreatorSetupModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get($user_id){
        $rows = parent::select("SELECT * FROM creator_setup WHERE user_id = :u", array('u' => (int) $user_id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Write the completed-step map and the time incomplete steps were last re-checked. */
    public function upsert_steps($user_id, array $steps, $checked_at){
        $now = date('Y-m-d H:i:s');
        return parent::sql(
            "INSERT INTO creator_setup (user_id, steps_json, checked_at, created_at, updated_at)
             VALUES (:u, :steps, :checked, :created, :updated)
             ON DUPLICATE KEY UPDATE steps_json = VALUES(steps_json), checked_at = VALUES(checked_at), updated_at = VALUES(updated_at)",
            array(':u' => (int) $user_id, ':steps' => json_encode($steps), ':checked' => $checked_at, ':created' => $now, ':updated' => $now)
        );
    }

    public function dismiss($user_id){
        $now = date('Y-m-d H:i:s');
        return parent::sql(
            "INSERT INTO creator_setup (user_id, dismissed_at, created_at, updated_at) VALUES (:u, :d, :c, :up)
             ON DUPLICATE KEY UPDATE dismissed_at = VALUES(dismissed_at), updated_at = VALUES(updated_at)",
            array(':u' => (int) $user_id, ':d' => $now, ':c' => $now, ':up' => $now)
        );
    }

    /** Hide one step for good (counts as satisfied for completion). */
    public function skip_step($user_id, $key){
        $now = date('Y-m-d H:i:s');
        $row = $this->get($user_id);
        $skipped = ($row && !empty($row['skipped_json'])) ? (array) json_decode((string) $row['skipped_json'], true) : array();
        $skipped[(string) $key] = $now;
        return parent::sql(
            "INSERT INTO creator_setup (user_id, skipped_json, created_at, updated_at) VALUES (:u, :sk, :c, :up)
             ON DUPLICATE KEY UPDATE skipped_json = VALUES(skipped_json), updated_at = VALUES(updated_at)",
            array(':u' => (int) $user_id, ':sk' => json_encode($skipped), ':c' => $now, ':up' => $now)
        );
    }

    public function mark_completed($user_id){
        $now = date('Y-m-d H:i:s');
        return parent::update('creator_setup',
            array('completed_at' => $now, 'updated_at' => $now),
            'user_id = :u AND completed_at IS NULL', array('u' => (int) $user_id));
    }

    /* ---- the checks (one cheap query each) ---- */

    public function has_profile($user_id){
        $r = parent::select("SELECT 1 FROM creator_profiles WHERE user_id = :u AND COALESCE(display_name,'') <> '' AND COALESCE(avatar_url,'') <> '' LIMIT 1", array('u' => (int) $user_id));
        return !empty($r);
    }

    public function has_brand($user_id){
        $r = parent::select("SELECT 1 FROM creator_brand WHERE user_id = :u AND (COALESCE(brand_name,'') <> '' OR COALESCE(voice,'') <> '' OR COALESCE(tagline,'') <> '' OR COALESCE(description,'') <> '') LIMIT 1", array('u' => (int) $user_id));
        return !empty($r);
    }

    public function has_active_tier($user_id){
        $r = parent::select("SELECT 1 FROM creator_plans WHERE user_id = :u AND is_active = 1 LIMIT 1", array('u' => (int) $user_id));
        return !empty($r);
    }

    /** Stripe Connect account exists and Stripe reports payouts enabled. The only check that leaves the DB. */
    public function has_payouts($user_id){
        $r = parent::select("SELECT stripe_connect_account_id FROM user_accounts WHERE user_id = :u", array('u' => (int) $user_id));
        $acct = (is_array($r) && count($r) === 1) ? (string) ($r[0]['stripe_connect_account_id'] ?? '') : '';
        if ($acct === '') { return false; }
        try {
            $st = StripeService::connect_account_status($acct);
            return !empty($st['payouts_enabled']);
        } catch (\Throwable $e) {
            error_log('[setup] payouts check: ' . $e->getMessage());
            return false;
        }
    }

    public function has_connected_social($user_id){
        $r = parent::select("SELECT 1 FROM user_social_accounts WHERE user_id = :u AND status = 'connected' LIMIT 1", array('u' => (int) $user_id));
        return !empty($r);
    }

    public function has_first_post($user_id){
        $r = parent::select("SELECT 1 FROM posts WHERE creator_id = :u AND state IN ('published','scheduled') LIMIT 1", array('u' => (int) $user_id));
        return !empty($r);
    }

    /** AI replies switched on for Creator Link Studio DMs (a welcome message is suggested, not required). */
    public function has_inbox_ready($user_id){
        $s = parent::select("SELECT 1 FROM inbox_settings WHERE creator_id = :u AND cls_enabled = 1 LIMIT 1", array('u' => (int) $user_id));
        return !empty($s);
    }

    /** Current handle of the account (the handle step). */
    public function current_username($user_id){
        $r = parent::select("SELECT u_name FROM user_accounts WHERE user_id = :u LIMIT 1", array('u' => (int) $user_id));
        return (is_array($r) && count($r) === 1) ? (string) $r[0]['u_name'] : '';
    }

    /** Did this account start on a neutral signup handle and change it since (archived in username_history)? */
    public function had_neutral_username($user_id){
        $r = parent::select("SELECT 1 FROM username_history WHERE user_id = :u AND u_name REGEXP '^creator[0-9]{6}$' LIMIT 1", array('u' => (int) $user_id));
        return !empty($r);
    }
}
