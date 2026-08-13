-- Retires the Centryk-native time-off request/approval feature. Requesting
-- and approving time off is now exclusively a MyPay feature; the calendar
-- only displays MyPay's (approved and pending) leave data, read-only.
-- Run against centryk_core. Idempotent.

DELETE FROM events WHERE time_off_request_id IS NOT NULL;

ALTER TABLE events
    DROP INDEX IF EXISTS idx_events_time_off,
    DROP COLUMN IF EXISTS time_off_request_id;

DROP TABLE IF EXISTS time_off_requests;
