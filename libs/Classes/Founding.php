<?php
/**
 * Founding creator offer (/founding). The first SPOTS accounts that start the Creator plan through it get the first
 * month free (the system promo CODE: 100% off one period, no Stripe coupon) and their platform fee locked at the
 * Studio plan's fee for as long as the Creator plan stays active or past due. In return they agree to share a
 * testimonial, to be featured in the directory and on the home page, and to stay listed in the directory.
 *
 * A spot is held at checkout (BillingService::change_plan, founding_claims 'claimed', released if the charge fails),
 * becomes 'active' when the plan starts (BillingService::apply) and 'lapsed' when the account leaves the Creator plan
 * (BillingModel::save_plan_mirror). Spots in use = claimed + active.
 */
class Founding {

    const SPOTS = 100;
    const CODE  = 'FOUNDING';
    const PLAN  = 'creator';
    const FEE_FROM = 'studio';   // the tier whose fee is locked in
    const TESTIMONIAL_DAYS = 14;
    const FULL_MESSAGE = 'All founding spots are taken.';

    /** Test seam: a different number of spots for this process only (tests/founding_test.php). */
    private static $spots = null;
    public static function set_spots_for_test($n): void { self::$spots = $n === null ? null : (int) $n; }

    /** The founding flag came with this request (founding=1 or the cls_founding cookie). Without it the code is refused, so FOUNDING is never a typed promo. */
    private static $presented = false;
    public static function present($on = true): void { self::$presented = (bool) $on; }
    public static function presented(): bool { return self::$presented; }

    /** Per-request cache of "has an active claim" (fee_override), cleared whenever a claim changes. */
    private static $active = array();

    public static function spots(): int { return self::$spots !== null ? self::$spots : self::SPOTS; }

    /** The locked fee: the Studio plan's fee_percent from PlanTiers. */
    public static function fee(): float
    {
        $t = PlanTiers::get(self::FEE_FROM);
        return (float) ($t['limits']['fee_percent'] ?? 0);
    }

    public static function plan_name(): string
    {
        $t = PlanTiers::get(self::PLAN);
        return (string) ($t['name'] ?? 'Creator');
    }

    /** "3%" (no trailing .00). */
    public static function fee_label(): string { return rtrim(rtrim(number_format(self::fee(), 2), '0'), '.') . '%'; }

    /** The note on the billing plan tab and the quote line label. */
    public static function note(): string { return 'Founding offer: first billing period free, ' . self::fee_label() . ' fee locked'; }

    public static function taken(): int { return (new FoundingClaimsModel())->taken(); }

    public static function remaining(): int { return max(0, self::spots() - self::taken()); }

    public static function open(): bool { return self::remaining() > 0; }

    /** Spots left for the public page: the count is cached for 60 seconds (cleared whenever a claim changes). */
    public static function remaining_cached(): int
    {
        $f = self::cache_file();
        if (self::$spots === null && is_file($f) && filemtime($f) > time() - 60) { return max(0, self::spots() - (int) file_get_contents($f)); }
        $n = self::taken();
        @file_put_contents($f, (string) $n, LOCK_EX);
        return max(0, self::spots() - $n);
    }

    private static function cache_file(): string { return sys_get_temp_dir() . '/cls_founding_taken.txt'; }
    private static function forget(): void { @unlink(self::cache_file()); self::$active = array(); }

    /** May this account take the offer now (shown on the billing plan tab): spots left, not on a paid plan, never claimed. */
    public static function eligible($user_id): bool
    {
        $c = (new FoundingClaimsModel())->for_user((int) $user_id);
        if ($c && (string) $c['status'] !== 'claimed') { return false; }
        if (BillingService::is_paid(BillingService::account((int) $user_id))) { return false; }
        return $c ? true : self::open();
    }

    /**
     * The founding promo for a plan quote, in BillingService::resolve_promo()'s shape. Only a new Creator plan
     * (subscribe from Free), once per account, while spots remain.
     */
    public static function promo($user_id, $plan, array $q): array
    {
        if (!self::$presented) { return array('ok' => false, 'message' => 'That promo code is not valid.'); }
        if ((string) $plan !== self::PLAN || (string) ($q['mode'] ?? '') !== 'subscribe') { return array('ok' => false, 'message' => 'The founding offer is for starting the ' . self::plan_name() . ' plan.'); }
        $c = (new FoundingClaimsModel())->for_user((int) $user_id);
        if ($c && (string) $c['status'] !== 'claimed') { return array('ok' => false, 'message' => 'The founding offer can be used once per account.'); }
        if (!$c && !self::open()) { return array('ok' => false, 'message' => self::FULL_MESSAGE); }
        return array('ok' => true, 'label' => 'first billing period free', 'promo' => array('promo_code' => self::CODE, 'promo_percent' => 100, 'promo_amount_cents' => null, 'promo_periods_left' => 1));
    }

    /** Hold a spot before any charge: '' when held, else the message to show (no spot left, or busy). */
    public static function claim($user_id): string
    {
        $r = (new FoundingClaimsModel())->claim((int) $user_id, self::spots());
        self::forget();
        return $r === 'ok' ? '' : ($r === 'busy' ? 'Please try again.' : self::FULL_MESSAGE);
    }

    /** Release held spots whose checkout never finished (cron/billing.php). */
    public static function release_stale($minutes = 60): int
    {
        $n = (int) (new FoundingClaimsModel())->release_stale((int) $minutes);
        if ($n > 0) { self::forget(); }
        return $n;
    }

    /** The checkout failed: give the spot back. */
    public static function release($user_id): void
    {
        (new FoundingClaimsModel())->release((int) $user_id);
        self::forget();
    }

    /** The Creator plan started on the founding promo: claim active, account flagged, fee locked. */
    public static function activate($user_id): void
    {
        $uid = (int) $user_id;
        if ((new FoundingClaimsModel())->activate($uid) < 1) { return; }   // no held spot: nothing to grant
        (new UsersModel())->set_founding($uid, true);
        (new BillingAccountsModel())->save($uid, array('fee_percent_override' => self::fee()));
        self::forget();
    }

    /**
     * Called with every plan mirror write: leaving the Creator plan (another plan, Free, or a lapse) ends the
     * founding terms. Cheap when there is nothing to end (one indexed update).
     */
    public static function on_plan_mirror($user_id, $tier, $status): void
    {
        if ((string) $tier === self::PLAN && in_array((string) $status, array('active', 'past_due'), true)) { return; }
        try {
            if ((new FoundingClaimsModel())->lapse((int) $user_id) > 0) { self::clear((int) $user_id); }
        } catch (\Throwable $e) {
            error_log('[founding] lapse ' . (int) $user_id . ': ' . $e->getMessage());
        }
    }

    /** Staff took the spot back: the claim is refused and the fee lock ends. */
    public static function refuse(array $claim): void
    {
        (new FoundingClaimsModel())->set_status((int) $claim['id'], 'refused');
        self::clear((int) $claim['user_id']);
    }

    private static function clear($user_id): void
    {
        (new UsersModel())->set_founding((int) $user_id, false);
        (new BillingAccountsModel())->save((int) $user_id, array('fee_percent_override' => null));
        self::forget();
        (new DatabaseJobQueue())->dispatch('membership_fee', array('user_id' => (int) $user_id), 'membership_fee:' . (int) $user_id);   // the fee changed: existing fan memberships follow
    }

    /** The locked fee for a founding creator row (Plan::fee_percent), or null. Only with an active claim, so a missed lapse never keeps the lock. */
    public static function fee_override(array $creator)
    {
        $uid = (int) ($creator['user_id'] ?? 0);
        if ((int) ($creator['is_founding'] ?? 0) !== 1 || $uid <= 0) { return null; }
        if (!isset(self::$active[$uid])) { $c = (new FoundingClaimsModel())->for_user($uid); self::$active[$uid] = $c && (string) $c['status'] === 'active'; }
        return self::$active[$uid] ? (new BillingAccountsModel())->fee_override($uid) : null;
    }

    /** Where founding creators write their testimonial (FoundingController). */
    const TESTIMONIAL_PATH = '/founding/testimonial';

    /**
     * Ask a founding creator for a testimonial: in-app notice + email (Notify, 'system'), both linking to the testimonial
     * page. Once per claim: stamped first so two senders cannot both send, the stamp is taken back if sending throws.
     */
    public static function request_testimonial(array $claim): bool
    {
        $m = new FoundingClaimsModel();
        if ($m->mark_testimonial((int) $claim['id']) < 1) { return false; }
        try {
            Notify::send((int) $claim['user_id'], 'system', 'Would you share a sentence about ' . Main::site_name() . '?',
                'You were one of the first creators to join ' . Main::site_name() . ', and we would love to hear how it is going. If you have a minute, tell us in a sentence or two what it has done for you. With your permission we will show it to creators who are deciding whether to join.',
                self::TESTIMONIAL_PATH, 'fa-star');
            return true;
        } catch (\Throwable $e) {
            $m->clear_testimonial((int) $claim['id']);
            error_log('[founding] testimonial ' . (int) $claim['id'] . ': ' . $e->getMessage());
            return false;
        }
    }

    /** Daily check (cron/billing.php): testimonial requests TESTIMONIAL_DAYS after activation. Returns how many went out. */
    public static function send_due_testimonials(): int
    {
        $n = 0;
        foreach ((new FoundingClaimsModel())->testimonial_due(self::TESTIMONIAL_DAYS) as $c) {
            try { if (self::request_testimonial($c)) { $n++; } }
            catch (\Throwable $e) { error_log('[founding] testimonial ' . (int) $c['id'] . ': ' . $e->getMessage()); }
        }
        return $n;
    }
}
