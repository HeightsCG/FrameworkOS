-- Support / help desk (/support for every signed-in user, Support tab in /admin for staff).
--   support_tickets   one request: who opened it, topic, subject and status
--     status: open (waiting on staff) | answered (staff replied, waiting on the user) | closed
--   support_messages  the conversation on a request, oldest first; is_staff = 1 for staff replies
-- Apply by hand:
--   mysql -h127.0.0.1 -ucasivo -p'...' contentos --protocol=TCP < sql/2026-09-21_support.sql
-- Applied on dev 2026-09-21. Prod: pending.

CREATE TABLE IF NOT EXISTS support_tickets (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  category        VARCHAR(32)  NOT NULL DEFAULT 'other',
  subject         VARCHAR(190) NOT NULL,
  status          ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  last_message_at DATETIME NOT NULL,
  closed_at       DATETIME NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_support_user (user_id, last_message_at),
  KEY idx_support_status (status, last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  is_staff   TINYINT(1)   NOT NULL DEFAULT 0,
  body       TEXT         NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_support_msg_ticket (ticket_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
