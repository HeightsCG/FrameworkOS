<?php
/** Job handler: deliver a logged broadcast to everyone in its segments who has not received it yet. */
class BroadcastSendJob {

    public static function handle(array $payload): string {
        $bid = (int) ($payload['broadcast_id'] ?? 0);
        if ($bid <= 0) { throw new InvalidArgumentException('broadcast_id missing'); }
        $n = (new BroadcastsModel())->deliver_pending($bid);
        return 'delivered to ' . $n;
    }
}
