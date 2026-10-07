<?php
/**
 * Creator custom domains (PRD §39) and the one-time session handoffs that sign a fan in on them.
 * A domain serves its creator's profile while status is 'verified' or 'active', the creator's account is active,
 * and their plan includes custom domains (checked by CustomDomains::current).
 */
class CreatorDomainsModel extends Model {

    const HANDOFF_TTL = 60;   // seconds a handoff token lives

    public function __construct(){
        parent::__construct();
    }

    /**
     * The live domain for a hostname plus its creator's handle, or null. Only 'active' counts: its DNS points at
     * the gateway, so sessions are never handed to a host that resolves elsewhere ('verified' = TXT only).
     */
    public function get_live_by_hostname($host){
        $rows = parent::select(
            "SELECT d.*, u.u_name, u.role_id FROM creator_domains d
             JOIN user_accounts u ON u.user_id = d.user_id AND u.deleted = 0 AND u.user_status = 'Active'
             WHERE d.hostname = :h AND d.status = 'active' AND d.deleted = 0",
            array('h' => strtolower((string) $host)));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function list_for_user($user_id): array{
        $rows = parent::select(
            "SELECT * FROM creator_domains WHERE user_id = :u AND deleted = 0 ORDER BY is_primary DESC, id ASC",
            array('u' => (int) $user_id));
        return is_array($rows) ? $rows : array();
    }

    public function get_for_user($user_id, $id){
        $rows = parent::select(
            "SELECT * FROM creator_domains WHERE id = :id AND user_id = :u AND deleted = 0",
            array('id' => (int) $id, 'u' => (int) $user_id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** The creator's active primary domain (where their profile lives), or null. */
    public function primary_for_user($user_id){
        $rows = parent::select(
            "SELECT * FROM creator_domains WHERE user_id = :u AND status IN ('verified','active') AND deleted = 0
             ORDER BY is_primary DESC, id ASC LIMIT 1",
            array('u' => (int) $user_id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function hostname_taken($host): bool{
        $rows = parent::select("SELECT id FROM creator_domains WHERE hostname_active = :h", array('h' => strtolower((string) $host)));
        return is_array($rows) && count($rows) > 0;
    }

    public function add($user_id, $host, $host_type, $token, $is_primary): int{
        $now = date('Y-m-d H:i:s');
        return (int) parent::insert('creator_domains', array(
            'user_id'            => (int) $user_id,
            'hostname'           => strtolower((string) $host),
            'host_type'          => (string) $host_type,
            'verification_token' => (string) $token,
            'status'             => 'pending',
            'is_primary'         => $is_primary ? 1 : 0,
            'date_created'       => $now,
            'date_updated'       => $now,
            'deleted'            => 0,
        ));
    }

    public function set_status($id, $status, $error = null): void{
        $now  = date('Y-m-d H:i:s');
        $data = array('status' => (string) $status, 'last_error' => $error === null ? null : mb_substr((string) $error, 0, 255),
            'last_checked_at' => $now, 'date_updated' => $now);
        if (in_array($status, array('verified', 'active'), true)) {
            parent::sql("UPDATE creator_domains SET verified_at = COALESCE(verified_at, :now) WHERE id = :id", array(':now' => $now, ':id' => (int) $id));
        }
        parent::update('creator_domains', $data, 'id = :id', array('id' => (int) $id));
    }

    /** Make one domain the primary; the creator's others become secondary (they 301 to it). */
    public function set_primary($user_id, $id): void{
        parent::sql("UPDATE creator_domains SET is_primary = IF(id = :id, 1, 0), date_updated = :now WHERE user_id = :u AND deleted = 0",
            array(':id' => (int) $id, ':now' => date('Y-m-d H:i:s'), ':u' => (int) $user_id));
    }

    public function remove($user_id, $id): void{
        parent::update('creator_domains', array('deleted' => 1, 'is_primary' => 0, 'date_updated' => date('Y-m-d H:i:s')),
            'id = :id AND user_id = :u', array('id' => (int) $id, 'u' => (int) $user_id));
    }

    /** Every live domain, for the daily DNS re-check. */
    public function list_to_recheck(): array{
        $rows = parent::select("SELECT * FROM creator_domains WHERE deleted = 0 AND status IN ('pending','verified','active','failed')");
        return is_array($rows) ? $rows : array();
    }

    /* ---- session handoffs ---- */

    /** Mint a single-use token that signs $user_id in on $host. Returns the raw token (stored hashed). */
    public function create_handoff($user_id, $host, $path): string{
        $raw = bin2hex(random_bytes(32));
        $now = time();
        parent::insert('domain_handoffs', array(
            'token_hash'   => hash('sha256', $raw),
            'user_id'      => (int) $user_id,
            'hostname'     => strtolower((string) $host),
            'path'         => mb_substr((string) $path, 0, 512),
            'expires_at'   => date('Y-m-d H:i:s', $now + self::HANDOFF_TTL),
            'date_created' => date('Y-m-d H:i:s', $now),
        ));
        return $raw;
    }

    /**
     * Redeem a token on $host: returns ['user_id', 'path'] once, or null (unknown, used, expired, other host).
     * The UPDATE claims it atomically, so two requests racing on one token can't both sign in.
     */
    public function redeem_handoff($raw, $host){
        $hash = hash('sha256', (string) $raw);
        $rows = parent::select("SELECT * FROM domain_handoffs WHERE token_hash = :h", array('h' => $hash));
        if (!is_array($rows) || count($rows) !== 1) { return null; }
        $row = $rows[0];
        if ($row['used_at'] !== null || strtotime((string) $row['expires_at']) < time() || $row['hostname'] !== strtolower((string) $host)) { return null; }
        $sth = $this->db->prepare("UPDATE domain_handoffs SET used_at = :now WHERE id = :id AND used_at IS NULL");
        $sth->execute(array(':now' => date('Y-m-d H:i:s'), ':id' => (int) $row['id']));
        if ($sth->rowCount() !== 1) { return null; }
        return array('user_id' => (int) $row['user_id'], 'path' => (string) $row['path']);
    }

    /** Drop spent and expired handoff rows (daily cron). */
    public function purge_handoffs(): void{
        parent::sql("DELETE FROM domain_handoffs WHERE expires_at < :cut", array(':cut' => date('Y-m-d H:i:s', time() - 86400)));
    }
}
