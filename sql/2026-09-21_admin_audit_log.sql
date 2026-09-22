-- Audit trail of every staff (is_admin) action: who did what to whom, with the request details.
-- Written automatically by AuditModel::record() from the admin, blog-content and support API controllers
-- whenever a staff action succeeds (see AuditTrail trait). Read in /admin > Audit Log and on /admin/user/<id>.
--   action          the API action, e.g. admin_adjust_credits
--   target_user_id  the account the action affected, when there is one
--   target_type/id  the object acted on (user, membership, asset, report, verification, article, keyword, ticket)
--   details         JSON: the submitted fields (secrets and long text trimmed) and the result message
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-21_admin_audit_log.sql
-- Applied on dev 2026-09-21. Prod: pending.

CREATE TABLE IF NOT EXISTS admin_audit_log (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id       INT UNSIGNED NOT NULL,
  action         VARCHAR(64)  NOT NULL,
  target_user_id INT UNSIGNED NULL,
  target_type    VARCHAR(32)  NULL,
  target_id      INT UNSIGNED NULL,
  details        TEXT         NULL,
  ip_address     VARCHAR(45)  NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_time (created_at),
  KEY idx_audit_target (target_user_id, created_at),
  KEY idx_audit_admin (admin_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
