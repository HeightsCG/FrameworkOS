<?php
/** Job handler: re-check a listed creator's profile photo and cover for the directory (DirectoryService::recheck). */
class DirectoryRecheckJob {

    public static function handle(array $payload): string {
        $uid = (int) ($payload['user_id'] ?? 0);
        if ($uid <= 0) { throw new InvalidArgumentException('user_id required'); }
        return DirectoryService::recheck($uid);
    }
}
