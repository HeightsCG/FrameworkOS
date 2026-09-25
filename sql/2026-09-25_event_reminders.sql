-- Event reminder email: each registration is reminded once, about 24 hours before the start (cron/scheduler.php → EventReminders).
ALTER TABLE event_registrations ADD COLUMN reminded_at DATETIME NULL DEFAULT NULL AFTER created_at;
