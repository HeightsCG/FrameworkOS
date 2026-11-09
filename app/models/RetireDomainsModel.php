<?php
/**
 * SQL for the one-off cron/retire_domains_once.php (custom domains removed 2026-11-09).
 * Reads the legacy creator_domains table directly: the classes that used to own it are gone, and the
 * table is dropped afterwards (sql/2026-11-09_drop_custom_domains.sql).
 */
class RetireDomainsModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** Every domain row not removed yet (connected, verified, pending, failed, disabled), with its owner's handle. */
    public function open_domains(): array {
        return (array) parent::select(
            "SELECT d.id, d.user_id, d.hostname, d.status, d.is_primary, d.verified_at, d.date_created, u.u_name
             FROM creator_domains d
             LEFT JOIN user_accounts u ON u.user_id = d.user_id
             WHERE d.deleted = 0
             ORDER BY d.user_id, d.is_primary DESC, d.id");
    }

    /** Mark one domain removed (idempotent: only a row not removed yet changes). */
    public function remove_domain($id): bool {
        $sth = $this->db->prepare(
            "UPDATE creator_domains SET deleted = 1, is_primary = 0, status = 'disabled', date_updated = :n
             WHERE id = :id AND deleted = 0");
        $sth->execute(array(':n' => gmdate('Y-m-d H:i:s'), ':id' => (int) $id));
        return $sth->rowCount() === 1;
    }
}
