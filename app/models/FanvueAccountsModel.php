<?php
/**
 * A creator's connected Fanvue account (one per user). OAuth tokens are stored
 * encrypted (AES-256-GCM, key from app.ini fanvue_token_key or derived from
 * db_pass) so a DB read never yields a usable credential.
 */
class FanvueAccountsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** The user's Fanvue row (any status), or null. Tokens come back decrypted. */
    public function get_for_user($user_id){
        $rows = parent::select(
            "SELECT * FROM user_fanvue_accounts WHERE user_id = :user_id",
            array('user_id' => (int) $user_id)
        );
        if (!is_array($rows) || count($rows) !== 1) { return null; }
        $row = $rows[0];
        $row['access_token']  = self::decrypt((string) $row['access_token']);
        $row['refresh_token'] = self::decrypt((string) $row['refresh_token']);
        return $row;
    }

    /** Webhook lookup: the connected row owning this Fanvue user uuid, or null. */
    public function get_by_fanvue_uuid($uuid){
        $uuid = trim((string) $uuid);
        if ($uuid === '') { return null; }
        $rows = parent::select(
            "SELECT * FROM user_fanvue_accounts WHERE fanvue_user_uuid = :u AND status = 'connected' LIMIT 2",
            array('u' => $uuid)
        );
        if (!is_array($rows) || count($rows) !== 1) { return null; }
        $row = $rows[0];
        $row['access_token']  = self::decrypt((string) $row['access_token']);
        $row['refresh_token'] = self::decrypt((string) $row['refresh_token']);
        return $row;
    }

    /** Does the stored grant include a scope? An empty scope column counts as missing. */
    public static function has_scope($row, $scope): bool {
        $granted = preg_split('/\s+/', trim((string) ($row['scope'] ?? '')));
        return in_array($scope, $granted, true);
    }

    /** Inbox automation needs every scope in FanvueService::INBOX_SCOPES (granted only by a re-consent after 2026-09-08). */
    public static function has_chat_scope($row): bool {
        if (!is_array($row)) { return false; }
        foreach (FanvueService::INBOX_SCOPES as $sc) {
            if (!self::has_scope($row, $sc)) { return false; }
        }
        return true;
    }

    /** The user's Fanvue row only if currently connected, else null. */
    public function get_connected_for_user($user_id){
        $row = $this->get_for_user($user_id);
        return ($row && ($row['status'] ?? '') === 'connected') ? $row : null;
    }

    /**
     * Store (or replace) the connection after a successful OAuth exchange.
     * $tokens: access_token, refresh_token, expires_in, scope. $me: Fanvue /users/me.
     */
    public function connect($user_id, array $tokens, array $me){
        $now  = date('Y-m-d H:i:s');
        $data = array(
            'fanvue_user_uuid' => (string) ($me['uuid'] ?? ''),
            'handle'           => mb_substr((string) ($me['handle'] ?? ''), 0, 255),
            'display_name'     => mb_substr((string) ($me['displayName'] ?? ''), 0, 255),
            'access_token'     => self::encrypt((string) ($tokens['access_token'] ?? '')),
            'refresh_token'    => self::encrypt((string) ($tokens['refresh_token'] ?? '')),
            'expires_at'       => date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600)),
            'scope'            => mb_substr((string) ($tokens['scope'] ?? ''), 0, 512),
            'status'           => 'connected',
            'last_error'       => null,
            'connected_at'     => $now,
            'disconnected_at'  => null,
            'updated_at'       => $now,
        );
        if ($this->get_for_user($user_id)) {
            return parent::update('user_fanvue_accounts', $data, 'user_id = :uid', array('uid' => (int) $user_id));
        }
        $data['user_id']    = (int) $user_id;
        $data['created_at'] = $now;
        return parent::insert('user_fanvue_accounts', $data);
    }

    /** Persist rotated tokens after a refresh. */
    public function save_tokens($user_id, array $tokens){
        $data = array(
            'access_token' => self::encrypt((string) ($tokens['access_token'] ?? '')),
            'expires_at'   => date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600)),
            'last_error'   => null,
            'updated_at'   => date('Y-m-d H:i:s'),
        );
        if (!empty($tokens['refresh_token'])) {
            $data['refresh_token'] = self::encrypt((string) $tokens['refresh_token']);
        }
        return parent::update('user_fanvue_accounts', $data, 'user_id = :uid', array('uid' => (int) $user_id));
    }

    /** Remember the last failure so Settings can show why posting stopped. */
    public function set_error($user_id, $message){
        return parent::update('user_fanvue_accounts',
            array('last_error' => mb_substr((string) $message, 0, 500), 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :uid', array('uid' => (int) $user_id));
    }

    /** Disconnect: drop the tokens entirely, keep the row for history. */
    public function disconnect($user_id){
        return parent::update('user_fanvue_accounts', array(
            'access_token'    => null,
            'refresh_token'   => null,
            'expires_at'      => null,
            'status'          => 'disconnected',
            'disconnected_at' => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ), 'user_id = :uid', array('uid' => (int) $user_id));
    }

    // ---- token encryption -------------------------------------------------

    private static function key(): string {
        $cfg = Main::get_config();
        $env = Main::get_environment();
        $hex = (string) ($cfg[$env]['fanvue_token_key'] ?? ($cfg['global']['fanvue_token_key'] ?? ''));
        if (strlen($hex) === 64 && ctype_xdigit($hex)) { return hex2bin($hex); }
        // Fallback: a key derived from the DB password, so the feature still works
        // on an environment whose app.ini has no fanvue_token_key yet.
        return hash('sha256', (string) Main::config(Main::get_environment(), 'db_pass') . '|fanvue-tokens', true);
    }

    public static function encrypt($plain): string {
        if ($plain === '' || $plain === null) { return ''; }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt((string) $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt($stored): string {
        $stored = (string) $stored;
        if ($stored === '' || strpos($stored, 'v1:') !== 0) { return ''; }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 28) { return ''; }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return ($pt === false) ? '' : $pt;
    }

}
