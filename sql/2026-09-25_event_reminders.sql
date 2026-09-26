-- Scheduled event reminder emails. The creator picks when (minutes before the start, comma list, e.g. '10080,1440,60');
-- each registration gets each reminder at most once (event_reminder_sends is the ledger + the mutex).
ALTER TABLE events ADD COLUMN reminders VARCHAR(64) NOT NULL DEFAULT '1440' AFTER timezone;
CREATE TABLE event_reminder_sends (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id INT UNSIGNED NOT NULL,
  offset_minutes INT UNSIGNED NOT NULL,
  sent_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_reg_offset (registration_id, offset_minutes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
