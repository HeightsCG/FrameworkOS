<?php
/**
 * Simple per-key brute-force throttle backed by the `rate_limits` table.
 *
 *   $rl = new RateLimit();
 *   if ($rl->is_locked($key)) { ...reject... }
 *   $rl->register_failure($key, 5, 15);   // lock after 5 hits, for 15 minutes
 *   $rl->clear($key);                      // on success
 *
 * Keys are caller-defined, e.g. "login:<username>" or "forgot:<username>".
 */
class RateLimit extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** Is this key currently locked out? */
    public function is_locked($rate_key){
        $rows = parent::select(
            "SELECT
                r.*
            FROM
                rate_limits r
            WHERE
                r.rate_key = :rate_key
                and
                r.locked_until > :now",
            array('rate_key' => $rate_key, 'now' => date('Y-m-d H:i:s'))
        );
        return is_array($rows) && count($rows) === 1;
    }

    /** Record a hit; lock the key for $lockout_minutes once $max_attempts is reached. */
    public function register_failure($rate_key, $max_attempts, $lockout_minutes){
        $now  = date('Y-m-d H:i:s');
        $rows = parent::select(
            "SELECT
                r.*
            FROM
                rate_limits r
            WHERE
                r.rate_key = :rate_key",
            array('rate_key' => $rate_key)
        );

        if (is_array($rows) && count($rows) === 1) {
            $row = $rows[0];
            // A previous lockout that has expired starts a fresh window.
            $attempts = (!empty($row['locked_until']) && $row['locked_until'] <= $now)
                ? 1
                : (int) $row['attempts'] + 1;
            $locked_until = ($attempts >= (int) $max_attempts)
                ? date('Y-m-d H:i:s', strtotime('+' . (int) $lockout_minutes . ' minutes'))
                : null;
            parent::update('rate_limits',
                array('attempts' => $attempts, 'locked_until' => $locked_until),
                'rate_key = :w_rate_key', array('w_rate_key' => $rate_key));
        } else {
            $attempts     = 1;
            $locked_until = ((int) $max_attempts <= 1)
                ? date('Y-m-d H:i:s', strtotime('+' . (int) $lockout_minutes . ' minutes'))
                : null;
            parent::insert('rate_limits',
                array('rate_key' => $rate_key, 'attempts' => $attempts, 'locked_until' => $locked_until));
        }
    }

    /** Clear the key (call on success). */
    public function clear($rate_key){
        return parent::delete_all('rate_limits', 'rate_key = :w_rate_key', array('w_rate_key' => $rate_key));
    }

}
