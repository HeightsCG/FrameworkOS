<?php
/**
 * Per-creator API tokens — used to authenticate the remote MCP connector
 * (Settings > Integrations). The raw token is shown to the creator ONCE at
 * creation; only its SHA-256 hash is stored, so a DB read never reveals a
 * usable credential. Tokens are revocable and scoped to a single user_id.
 */
class ApiTokensModel extends Model {

    const PREFIX = 'cls_';

    public function __construct(){
        parent::__construct();
    }

    /**
     * Mint a new token for a user, storing only its hash. Returns the RAW token
     * (the only time it is ever available). Existing tokens are left intact;
     * call revoke_for_user() first if you want single-token semantics.
     */
    public function create_for_user($user_id, $label = 'Claude MCP connector'){
        $raw  = self::PREFIX . bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        parent::insert('api_tokens', array(
            'user_id'    => (int) $user_id,
            'token_hash' => $hash,
            'label'      => mb_substr((string) $label, 0, 80),
            'revoked'    => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ));
        return $raw;
    }

    /**
     * Resolve a raw token to its owner's user_id, or null if unknown/revoked.
     * Touches last_used_at on a hit. Constant-shape lookup by hash.
     */
    public function resolve($raw_token){
        $raw = trim((string) $raw_token);
        if ($raw === '') { return null; }
        $hash = hash('sha256', $raw);
        $rows = parent::select(
            "SELECT t.id, t.user_id FROM api_tokens t
             JOIN user_accounts u ON u.user_id = t.user_id AND u.deleted = 0 AND u.user_status = 'Active'
             WHERE t.token_hash = :h AND t.revoked = 0",   // a suspended/deleted owner's token stops working
            array('h' => $hash));
        if (!is_array($rows) || count($rows) !== 1) { return null; }
        parent::update('api_tokens',
            array('last_used_at' => date('Y-m-d H:i:s')),
            'id = :id', array('id' => (int) $rows[0]['id']));
        return (int) $rows[0]['user_id'];
    }

    /** Revoke every active token for a user (used to rotate). */
    public function revoke_for_user($user_id){
        return parent::update('api_tokens',
            array('revoked' => 1),
            'user_id = :uid AND revoked = 0', array('uid' => (int) $user_id));
    }

    /** Does the user currently have an active token? */
    public function has_active_for_user($user_id){
        $rows = parent::select(
            "SELECT id FROM api_tokens WHERE user_id = :uid AND revoked = 0 LIMIT 1",
            array('uid' => (int) $user_id));
        return is_array($rows) && count($rows) === 1;
    }

}
