<?php
/**
 * What a fan has spent with a creator: one definition for every screen that shows it (Inbox fan panel, Audience,
 * Dashboard customers). Every one-time purchase: pay-per-view, bundles, paid messages, event tickets and service
 * bookings, net of refunds (a refunded unlock is deleted; refunded tickets and bookings are left out). Memberships
 * are recurring card charges and are not part of it. Credits ($1 = 10).
 */
class FanSpendModel extends Model {

    /** Every paid one-time purchase from one creator: buyer, credits, created_at. $fan narrows it to one fan. */
    private function purchases_sql($fan = null): string
    {
        $f = $fan === null ? '' : ' AND %s = :f%d';
        $parts = array(
            "SELECT fan_id AS buyer, price_credits AS credits, created_at FROM ppv_unlocks WHERE creator_id = :c1 AND price_credits > 0" . ($fan === null ? '' : sprintf($f, 'fan_id', 1)),
            "SELECT fan_id, price_credits, created_at FROM bundle_unlocks WHERE creator_id = :c2 AND price_credits > 0" . ($fan === null ? '' : sprintf($f, 'fan_id', 2)),
            "SELECT fan_id, price_credits, created_at FROM message_unlocks WHERE creator_id = :c3 AND price_credits > 0" . ($fan === null ? '' : sprintf($f, 'fan_id', 3)),
            "SELECT er.user_id, er.price_credits, er.created_at FROM event_registrations er JOIN events e ON e.id = er.event_id
               WHERE e.creator_id = :c4 AND er.status <> 'refunded' AND er.price_credits > 0" . ($fan === null ? '' : sprintf($f, 'er.user_id', 4)),
            "SELECT sp.buyer_id, sp.price_credits, sp.created_at FROM service_purchases sp JOIN services s ON s.id = sp.service_id
               WHERE s.creator_id = :c5 AND sp.status = 'paid' AND sp.price_credits > 0" . ($fan === null ? '' : sprintf($f, 'sp.buyer_id', 5)),
            "SELECT fan_id, credits, created_at FROM live_tips WHERE creator_id = :c6" . ($fan === null ? '' : sprintf($f, 'fan_id', 6)),
            "SELECT fan_id, price_credits, created_at FROM replay_unlocks WHERE creator_id = :c7" . ($fan === null ? '' : sprintf($f, 'fan_id', 7)),
        );
        return implode(' UNION ALL ', $parts);
    }

    private function params($creator_id, $fan_id = null): array
    {
        $p = array();
        for ($i = 1; $i <= 7; $i++) {
            $p['c' . $i] = (int) $creator_id;
            if ($fan_id !== null) { $p['f' . $i] = (int) $fan_id; }
        }
        return $p;
    }

    /** One fan: ['purchases' => count, 'credits' => total]. */
    public function for_fan($creator_id, $fan_id): array
    {
        $r = parent::select("SELECT COUNT(*) AS n, COALESCE(SUM(credits), 0) AS credits FROM (" . $this->purchases_sql($fan_id) . ") x",
            $this->params($creator_id, $fan_id));
        return array('purchases' => (int) ($r[0]['n'] ?? 0), 'credits' => (int) ($r[0]['credits'] ?? 0));
    }

    /** Every buyer: rows of buyer, purchases, credits, last (the latest purchase). */
    public function by_fan($creator_id): array
    {
        return (array) parent::select("SELECT buyer, COUNT(*) AS purchases, SUM(credits) AS credits, MAX(created_at) AS last
                                       FROM (" . $this->purchases_sql() . ") x GROUP BY buyer", $this->params($creator_id));
    }
}
