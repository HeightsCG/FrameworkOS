<?php
/** Job handler: put a creator's current platform fee on all their fans' memberships (AccountBilling::sync_membership_fees). */
class MembershipFeeJob {

    public static function handle(array $payload): string {
        $uid = (int) ($payload['user_id'] ?? 0);
        if ($uid <= 0) { throw new InvalidArgumentException('user_id required'); }
        return AccountBilling::sync_membership_fees($uid);
    }
}
