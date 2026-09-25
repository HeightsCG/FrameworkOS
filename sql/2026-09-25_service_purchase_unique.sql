-- One purchase row per (service, buyer). The app already treats a service as bought once per buyer
-- (ServicesModel::has_purchased); this key makes the purchase row the mutex against a double charge:
-- service_purchase now inserts the row FIRST and only debits credits if the insert won (same as PPV).
--
-- Before applying on prod, check for duplicates (must return no rows):
--   SELECT service_id, buyer_id, COUNT(*) n FROM service_purchases GROUP BY service_id, buyer_id HAVING n > 1;
--
-- Apply by hand (before deploying the code):
--   mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-25_service_purchase_unique.sql
-- Already applied on dev (2026-09-25). Not yet on prod.

ALTER TABLE service_purchases
    ADD UNIQUE KEY uq_service_buyer (service_id, buyer_id);
