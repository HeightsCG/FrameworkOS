<?php
/**
 * A creator's Eromify (Creator Studio) connection: their own API key, stored
 * encrypted (AES-256-GCM, see key() below). One row per creator.
 */
class EromifyAccountsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** Row (any status) with the key decrypted, or null. */
    public function get_for_user($user_id){
        $rows = parent::select("SELECT * FROM user_eromify_accounts WHERE user_id = :u", array('u' => (int) $user_id));
        if (!is_array($rows) || count($rows) !== 1) { return null; }
        $row = $rows[0];
        $row['api_key'] = self::decrypt((string) $row['api_key']);
        return $row;
    }

    public function get_connected_for_user($user_id){
        $row = $this->get_for_user($user_id);
        return ($row && ($row['status'] ?? '') === 'connected' && $row['api_key'] !== '') ? $row : null;
    }

    public function connect($user_id, $api_key, $plan = '', $credits = null){
        $now  = date('Y-m-d H:i:s');
        $data = array(
            'api_key'      => self::encrypt((string) $api_key),
            'status'       => 'connected',
            'plan'         => mb_substr((string) $plan, 0, 32),
            'credits'      => ($credits !== null) ? (int) $credits : null,
            'last_error'   => null,
            'connected_at' => $now,
            'updated_at'   => $now,
        );
        if ($this->get_for_user($user_id)) {
            return parent::update('user_eromify_accounts', $data, 'user_id = :u', array('u' => (int) $user_id));
        }
        $data['user_id'] = (int) $user_id; $data['created_at'] = $now;
        return parent::insert('user_eromify_accounts', $data);
    }

    public function set_credits($user_id, $credits){
        return parent::update('user_eromify_accounts', array('credits' => (int) $credits, 'last_error' => null, 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :u', array('u' => (int) $user_id));
    }

    public function set_error($user_id, $message){
        return parent::update('user_eromify_accounts', array('last_error' => mb_substr((string) $message, 0, 500), 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :u', array('u' => (int) $user_id));
    }

    public function disconnect($user_id){
        return parent::update('user_eromify_accounts', array('api_key' => null, 'status' => 'disconnected', 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :u', array('u' => (int) $user_id));
    }


    // ---- key encryption -----------------------------------------------------
    // The key setting and the derivation salt keep their original names: keys stored
    // before must still decrypt.

    private static function key(): string {
        $cfg = Main::get_config();
        $env = Main::get_environment();
        $hex = (string) ($cfg[$env]['fanvue_token_key'] ?? ($cfg['global']['fanvue_token_key'] ?? ''));
        if (strlen($hex) === 64 && ctype_xdigit($hex)) { return hex2bin($hex); }
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
