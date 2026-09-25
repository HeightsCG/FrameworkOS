-- Store what the creator actually earned on each sale, so a refund (or an event cancellation refund)
-- claws back exactly that amount at the fee rate that applied WHEN IT SOLD, not the creator's current
-- plan rate. Written at sale time next to the *_earning ledger row. 0 = legacy row (refund falls back
-- to the current rate).
--
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-25_unlock_net_credits.sql
-- Already applied on dev (2026-09-25). Not yet on prod.

ALTER TABLE ppv_unlocks         ADD COLUMN net_credits INT NOT NULL DEFAULT 0;
ALTER TABLE bundle_unlocks      ADD COLUMN net_credits INT NOT NULL DEFAULT 0;
ALTER TABLE message_unlocks     ADD COLUMN net_credits INT NOT NULL DEFAULT 0;
ALTER TABLE event_registrations ADD COLUMN net_credits INT NOT NULL DEFAULT 0;
