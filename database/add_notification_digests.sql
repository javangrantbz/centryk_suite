-- Idempotency ledger for batch notification jobs (DailyPulse). One row per
-- (event, date) so a batch fires exactly once no matter how many times the
-- pulse runs in a day.
--
-- Keys: pulse:<Y-m-d>, holiday:<date>, holiday-soon:<date>,
--       bday:<date>:<email>, anniv:<date>:<email>
--
-- Idempotent. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_notification_digests.sql

CREATE TABLE IF NOT EXISTS notification_digests (
    digest_key VARCHAR(160) NOT NULL PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
