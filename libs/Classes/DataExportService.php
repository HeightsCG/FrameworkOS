<?php
/**
 * "Download Your Data" (Settings -> Account). request() queues an export; DataExportJob calls build(),
 * which zips the account's data as JSON (account, posts, messages, purchases, subscriptions, credits)
 * plus the creator's original Library files, stores it privately at vault/<user_id>/exports/<id>.zip
 * and tells the user it's ready. download_url() signs a ready, unexpired export for its owner only.
 * Data: DataExportsModel (sql/2026-09-24_data_exports.sql).
 */
class DataExportService {

    const KEEP_DAYS = 7;          // a ready export can be downloaded for this long
    const COOLDOWN_HOURS = 24;    // one new export per day (a failed one can be retried at once)

    /** Queue an export for this user. ['ok', 'export' => row] or ['ok' => false, 'message']. */
    public static function request($user_id): array
    {
        $m = new DataExportsModel();
        $last = $m->latest_for_user($user_id);
        if ($last && in_array($last['status'], array('queued', 'running'), true)) {
            return array('ok' => false, 'message' => 'Your export is already being prepared.');
        }
        if ($last && $last['status'] === 'ready' && strtotime($last['created_at'] . ' UTC') > time() - self::COOLDOWN_HOURS * 3600) {
            return array('ok' => false, 'message' => 'You can request a new export once a day. Your last one is ready to download.');
        }
        $id = $m->create($user_id);
        (new DatabaseJobQueue())->dispatch('data_export', array('export_id' => $id), 'data_export:' . $id);
        return array('ok' => true, 'export' => $m->get($id));
    }

    /** What Settings shows: status plus dates in the viewer's time zone. */
    public static function state_json($row, $tz = 'UTC'): ?array
    {
        if (!$row) { return null; }
        $fmt = function ($utc) use ($tz) {
            if (empty($utc)) { return ''; }
            try { $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format('M j, Y g:i A'); }
            catch (\Throwable $e) { return (string) $utc; }
        };
        $expired = $row['status'] === 'ready' && !empty($row['expires_at']) && strtotime($row['expires_at'] . ' UTC') <= time();
        return array(
            'id' => (int) $row['id'], 'status' => $expired ? 'expired' : (string) $row['status'],
            'requested' => $fmt($row['created_at']), 'expires' => $fmt($row['expires_at']),
            'size' => $row['bytes'] !== null ? self::human_bytes((int) $row['bytes']) : '',
        );
    }

    /** Signed link to the user's own ready export, or ''. */
    public static function download_url($user_id, $export_id): string
    {
        $row = (new DataExportsModel())->get($export_id);
        if (!$row || (int) $row['user_id'] !== (int) $user_id || $row['status'] !== 'ready' || empty($row['s3_key'])) { return ''; }
        if (!empty($row['expires_at']) && strtotime($row['expires_at'] . ' UTC') <= time()) { return ''; }
        return S3Service::presigned_get_url((string) $row['s3_key'], 900, 'Creator Link Studio data ' . substr((string) $row['created_at'], 0, 10) . '.zip');
    }

    /** Build one export (called by DataExportJob). Returns a short result line for the job log. */
    public static function build($export_id): string
    {
        $m = new DataExportsModel();
        $row = $m->get($export_id);
        if (!$row) { return 'missing'; }
        if ($row['status'] === 'ready') { return 'already built'; }
        $uid = (int) $row['user_id'];
        $m->set($export_id, array('status' => 'running', 'error' => null));

        $zip = tempnam(sys_get_temp_dir(), 'export');
        try {
            $users = (new UsersModel())->get_user_by_id($uid);
            $user = (is_array($users) && count($users) === 1) ? $users[0] : null;
            if (!$user) { throw new RuntimeException('Account not found.'); }

            $json = function ($v) { return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); };
            $files = array(
                'README.txt'         => self::readme(),
                'account.json'       => $json(self::account($user)),
                'messages.json'      => $json(self::messages($uid)),
                'purchases.json'     => $json(self::purchases($uid)),
                'subscriptions.json' => $json(self::subscriptions($uid)),
                'credits.json'       => $json(array('balance' => (new CreditsModel())->get_balance($uid), 'transactions' => (new CreditsModel())->get_transactions($uid, 100000))),
            );
            $media = array();
            if (Plan::is_creator_row($user)) {
                $files['posts.json'] = $json(self::posts($uid));
                $media = array_values(array_filter((array) (new MediaAssetsModel())->get_for_creator($uid), function ($a) { return $a['status'] === 'ready'; }));
                $files['media.json'] = $json(array_map(function ($a) {
                    return array('id' => (int) $a['id'], 'file' => 'media/' . MediaService::download_name($a, 'original'), 'type' => $a['type'],
                        'description' => (string) ($a['description'] ?? ''), 'width' => $a['width'], 'height' => $a['height'], 'uploaded_at' => $a['created_at']);
                }, $media));
            }
            $n = MediaService::build_zip($zip, $media, 'original', $files, 'media');
            if (!is_file($zip) || filesize($zip) <= 0) { throw new RuntimeException('The zip could not be written.'); }

            $key = 'vault/' . $uid . '/exports/' . (int) $export_id . '.zip';
            if (!S3Service::put_private($key, $zip, 'application/zip')) { throw new RuntimeException('The export could not be stored.'); }
            $now = gmdate('Y-m-d H:i:s');
            $m->set($export_id, array('status' => 'ready', 's3_key' => $key, 'bytes' => filesize($zip), 'file_count' => $n,
                'completed_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', time() + self::KEEP_DAYS * 86400)));
            foreach ($m->stored_before($uid, $export_id) as $old) {   // only the newest export is kept
                S3Service::delete_key((string) $old['s3_key']);
                $m->set((int) $old['id'], array('s3_key' => null));
            }
            Notify::send($uid, 'security', 'Your data export is ready', 'Download it from Settings within ' . self::KEEP_DAYS . ' days.', '/account/settings?section=account', 'fa-file-zipper', false, true);
            @unlink($zip);
            return 'ready ' . $n . ' files';
        } catch (\Throwable $e) {
            @unlink($zip);
            $m->set($export_id, array('status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)));
            error_log('[data export] ' . (int) $export_id . ' failed: ' . $e->getMessage());
            return 'failed';
        }
    }

    /* ---- the pieces ---- */

    /** Account fields a person gets back about themselves. An allowlist: new columns (and secrets like the password hash) stay out until added here. */
    const ACCOUNT_FIELDS = array(
        'user_id', 'u_name', 'first_name', 'last_name', 'user_email', 'email_verified', 'user_phone', 'business_name', 'website_url',
        'user_status', 'verified', 'team_role', 'creator_since', 'creator_agreement_accepted_at', 'content_timezone', 'adult_content_enabled',
        'credit_balance', 'ai_credit_balance', 'watermark_enabled', 'watermark_text', 'watermark_position', 'watermark_opacity',
        'autoreplenish_enabled', 'autoreplenish_threshold', 'autoreplenish_amount_cents', 'u_name_changed_at', 'last_active_at', 'created_at', 'updated_at',
    );

    private static function account(array $u): array
    {
        $out = array();
        foreach (self::ACCOUNT_FIELDS as $k) { if (array_key_exists($k, $u)) { $out[$k] = $u[$k]; } }
        $out['role'] = Plan::is_creator_row($u) ? 'creator' : 'fan';
        return $out;
    }

    private static function posts($uid): array
    {
        $pm = new PostsModel();
        $out = array();
        foreach ((array) $pm->list_for_creator($uid) as $p) {
            $out[] = array('id' => (int) $p['id'], 'caption' => (string) $p['caption'], 'audience' => (string) $p['audience'], 'state' => (string) $p['state'],
                'ppv_price_credits' => $p['ppv_price_credits'] ?? null, 'scheduled_at' => $p['scheduled_at'] ?? null, 'published_at' => $p['published_at'] ?? null,
                'created_at' => $p['created_at'], 'media' => array_map(function ($a) { return array('id' => (int) $a['asset_id'], 'type' => $a['type']); }, (array) $pm->get_assets((int) $p['id'])));
        }
        return $out;
    }

    private static function messages($uid): array
    {
        $mm = new MessagesModel();
        $convs = $mm->inbox_rows($uid);
        $peers = array(); foreach ($convs as $c) { $peers[] = (int) $c['creator_id'] === $uid ? (int) $c['user_id'] : (int) $c['creator_id']; }
        $who = $mm->identity_map($peers);
        $out = array();
        foreach ($convs as $c) {
            $peer = (int) $c['creator_id'] === $uid ? (int) $c['user_id'] : (int) $c['creator_id'];
            $out[] = array('with' => array('user_id' => $peer, 'handle' => (string) ($who[$peer]['handle'] ?? ''), 'name' => (string) ($who[$peer]['name'] ?? '')),
                'messages' => array_map(function ($x) use ($uid) {
                    return array('from' => (int) $x['sender_id'] === $uid ? 'me' : 'them', 'body' => (string) $x['body'], 'price_credits' => (int) $x['price_credits'],
                        'media_count' => (int) $x['media_count'], 'sent_at' => $x['created_at']);
                }, $mm->thread((int) $c['id'], MessagesModel::cleared_id($c, $uid))));
        }
        return $out;
    }

    private static function purchases($uid): array
    {
        $out = array();
        foreach ((array) (new PpvUnlocksModel())->get_for_fan($uid) as $u) {
            $out[] = array('type' => 'pay-per-view post', 'title' => (string) $u['caption'], 'creator' => (string) $u['creator_name'], 'price_credits' => (int) $u['price_credits'], 'purchased_at' => $u['purchased_at']);
        }
        foreach ((array) (new ContentBundlesModel())->get_purchased_for_fan($uid) as $b) {
            $out[] = array('type' => 'bundle', 'title' => (string) $b['name'], 'creator' => (string) $b['creator_name'], 'price_credits' => (int) $b['paid_credits'], 'purchased_at' => $b['purchased_at']);
        }
        foreach ((array) (new MessageUnlocksModel())->get_for_fan($uid) as $u) {
            $out[] = array('type' => 'message', 'title' => (string) $u['body'], 'creator' => (string) $u['creator_name'], 'price_credits' => (int) $u['price_credits'], 'purchased_at' => $u['purchased_at']);
        }
        return $out;
    }

    private static function subscriptions($uid): array
    {
        return array_map(function ($s) {
            return array('creator' => (string) $s['creator_name'], 'handle' => (string) $s['creator_handle'], 'plan' => (string) $s['plan_name'], 'status' => (string) $s['status'],
                'started_at' => $s['created_at'], 'current_period_end' => $s['current_period_end'] ?? null);
        }, (array) (new CreatorSubscriptionsModel())->get_for_subscriber($uid));
    }

    private static function readme(): string
    {
        return "Your Creator Link Studio data, exported " . gmdate('Y-m-d H:i') . " UTC.\n\n"
             . "account.json        Your account details\n"
             . "posts.json          Your posts (creators)\n"
             . "media.json          Your Library files; the files themselves are in media/ (creators)\n"
             . "messages.json       Your conversations\n"
             . "purchases.json      Pay-per-view posts, bundles and messages you bought\n"
             . "subscriptions.json  Memberships you have with creators\n"
             . "credits.json        Your credit balance and history\n\n"
             . "Content you bought stays in Purchases, where you can download it.\n";
    }

    private static function human_bytes($b): string
    {
        if ($b >= 1073741824) { return round($b / 1073741824, 1) . ' GB'; }
        if ($b >= 1048576) { return round($b / 1048576, 1) . ' MB'; }
        return max(1, (int) round($b / 1024)) . ' KB';
    }
}
