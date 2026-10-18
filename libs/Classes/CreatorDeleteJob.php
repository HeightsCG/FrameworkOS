<?php
/**
 * Job handler: permanently delete a former creator's creator content (CreatorDeletion), queued by "Stop Being a
 * Creator". Refuses if the account is a creator again by the time it runs, so it can never wipe an active creator.
 * payload: user_id.
 */
class CreatorDeleteJob {

    public static function handle(array $payload): string {
        $uid  = (int) ($payload['user_id'] ?? 0);
        $rows = (new UsersModel())->get_user_by_id($uid);
        if ($uid <= 0 || !is_array($rows) || count($rows) !== 1) { return 'SKIP no such account'; }
        if ((int) $rows[0]['role_id'] !== (int) (new UsersModel())->get_role_id_by_name('User')) { return 'SKIP the account is a creator again'; }
        $r = CreatorDeletion::run($uid);
        return 'OK ' . json_encode($r);
    }
}
