<?php
/**
 * Multi-factor authentication state and one-time secrets.
 *
 * Flags + TOTP secret live on user_accounts; backup codes and emailed codes
 * live in their own tables. Backup codes and email codes are only ever stored
 * hashed — plaintext is returned once, at generation, for the user to save/enter.
 */
class MfaModel extends Model {

    const BACKUP_CODE_COUNT = 10;
    const EMAIL_CODE_TTL_MIN = 10;

    public function __construct(){
        parent::__construct();
    }

    /* ---- method flags ---------------------------------------------------- */

    public function set_totp($user_id, $secret){
        return parent::update(
            'user_accounts',
            array('mfa_totp_enabled' => 1, 'mfa_totp_secret' => $secret, 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    public function disable_totp($user_id){
        return parent::update(
            'user_accounts',
            array('mfa_totp_enabled' => 0, 'mfa_totp_secret' => null, 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    public function set_email_enabled($user_id, $enabled){
        return parent::update(
            'user_accounts',
            array('mfa_email_enabled' => $enabled ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    /* ---- backup codes ---------------------------------------------------- */

    /** Replace any existing codes with a fresh set; returns the plaintext codes once. */
    public function generate_backup_codes($user_id){
        parent::delete_all('mfa_backup_codes', 'user_id = :user_id', array('user_id' => (int) $user_id));

        $codes = array();
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $raw   = strtolower(bin2hex(random_bytes(4))); // 8 hex chars
            $plain = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $codes[] = $plain;
            parent::insert('mfa_backup_codes', array(
                'user_id'    => (int) $user_id,
                'code_hash'  => password_hash($this->normalize_backup($plain), PASSWORD_DEFAULT),
                'created_at' => date('Y-m-d H:i:s'),
            ));
        }
        return $codes;
    }

    public function clear_backup_codes($user_id){
        return parent::delete_all('mfa_backup_codes', 'user_id = :user_id', array('user_id' => (int) $user_id));
    }

    public function count_unused_backup($user_id){
        $rows = parent::select(
            "SELECT COUNT(*) AS c FROM mfa_backup_codes WHERE user_id = :user_id AND used_at IS NULL",
            array('user_id' => (int) $user_id)
        );
        return is_array($rows) && count($rows) ? (int) $rows[0]['c'] : 0;
    }

    /** Consume a backup code (single use). Returns true when a match was burned. */
    public function verify_and_consume_backup($user_id, $code){
        $norm = $this->normalize_backup($code);
        if ($norm === '') {
            return false;
        }
        $rows = parent::select(
            "SELECT id, code_hash FROM mfa_backup_codes WHERE user_id = :user_id AND used_at IS NULL",
            array('user_id' => (int) $user_id)
        );
        if (!is_array($rows)) {
            return false;
        }
        foreach ($rows as $row) {
            if (password_verify($norm, $row['code_hash'])) {
                parent::update(
                    'mfa_backup_codes',
                    array('used_at' => date('Y-m-d H:i:s')),
                    'id = :id',
                    array('id' => (int) $row['id'])
                );
                return true;
            }
        }
        return false;
    }

    private function normalize_backup($code){
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $code));
    }

    /* ---- emailed codes --------------------------------------------------- */

    /** Issue a 6-digit code for a purpose ('login' | 'enroll'); returns the plaintext to email. */
    public function issue_email_code($user_id, $purpose){
        // Invalidate any outstanding codes for this purpose first.
        parent::delete_all(
            'mfa_email_codes',
            'user_id = :user_id AND purpose = :purpose AND consumed = 0',
            array('user_id' => (int) $user_id, 'purpose' => $purpose)
        );

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        parent::insert('mfa_email_codes', array(
            'user_id'    => (int) $user_id,
            'code_hash'  => password_hash($code, PASSWORD_DEFAULT),
            'purpose'    => $purpose,
            'expires_at' => date('Y-m-d H:i:s', time() + self::EMAIL_CODE_TTL_MIN * 60),
            'created_at' => date('Y-m-d H:i:s'),
        ));
        return $code;
    }

    /** Verify and consume an emailed code for a purpose. */
    public function verify_email_code($user_id, $code, $purpose){
        $code = preg_replace('/\s+/', '', (string) $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $rows = parent::select(
            "SELECT id, code_hash FROM mfa_email_codes
             WHERE user_id = :user_id AND purpose = :purpose AND consumed = 0 AND expires_at > :now
             ORDER BY id DESC",
            array('user_id' => (int) $user_id, 'purpose' => $purpose, 'now' => date('Y-m-d H:i:s'))
        );
        if (!is_array($rows)) {
            return false;
        }
        foreach ($rows as $row) {
            if (password_verify($code, $row['code_hash'])) {
                parent::update(
                    'mfa_email_codes',
                    array('consumed' => 1),
                    'id = :id',
                    array('id' => (int) $row['id'])
                );
                return true;
            }
        }
        return false;
    }
}
