<?php
/**
 * Refund one service booking (creator action): the buyer gets the price back in credits; the creator's earning is
 * clawed back only if it was already paid (on delivery), and the booking frees its spot. The conditional status update
 * is the mutex, so a double click can't refund twice. Mirrors EventRefunds.
 */
class ServiceRefunds {

    public static function refund_one(array $sv, $purchase_id): array {
        $model = new ServicesModel();
        $p = $model->purchase((int) $sv['id'], (int) $purchase_id);
        if (!$p || (string) $p['status'] !== 'paid') { return self::no('That booking is no longer active.'); }
        $paid = (int) $p['price_credits'];
        if ($paid <= 0) { return self::no('That booking was free, so there is nothing to refund.'); }
        if (!$model->set_purchase_status((int) $p['id'], 'paid', 'refunded')) { return self::no('That booking is no longer active.'); }

        $buyer = (int) $p['buyer_id']; $creator = (int) $sv['creator_id'];
        $credits = new CreditsModel();
        $credits->apply_delta($buyer, $paid, 'refund', 'Refund: service booking');
        // The creator is paid for a booking when they mark it delivered. Re-read now that it's marked refunded: if their
        // share was already paid, take it back in full (their balance may go negative, repaid from future earnings);
        // if not, they never had it and it simply won't be paid.
        $now_p = $model->purchase((int) $sv['id'], (int) $p['id']);
        $take = ($now_p && $now_p['earning_released_at'] !== null) ? (int) $p['net_credits'] : 0;
        if ($take > 0) { $credits->apply_delta($creator, -$take, 'refund_reversal', 'Refund reversal: service booking'); }
        (new RefundsModel())->log('service', (int) $p['id'], $creator, $buyer, $paid, $take, true, (int) Session::get('user_id'), 'Refunded by the creator');

        $t = mb_substr(html_entity_decode((string) $sv['name'], ENT_QUOTES, 'UTF-8'), 0, 60);
        Notify::send($buyer, 'refunds', 'Booking refunded', '"' . $t . '": ' . Notify::credit_count($paid) . ($paid === 1 ? ' was' : ' were') . ' returned to your wallet.',
            '/account/settings?section=wallet', 'fa-rotate-left');
        return array('ok' => true, 'refunded' => $paid, 'clawback' => $take, 'message' => Notify::credit_count($paid) . ' refunded');
    }

    private static function no($msg): array {
        return array('ok' => false, 'refunded' => 0, 'clawback' => 0, 'message' => (string) $msg);
    }
}
