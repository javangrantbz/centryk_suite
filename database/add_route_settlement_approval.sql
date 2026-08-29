-- ============================================================
-- Centryk Business — Field Sales & Routes: settlement approval
--
-- Two-step driver cash settlement (maker-checker): whoever runs the route
-- SUBMITS the declared cash (trip stays 'settling', stops lock); a company
-- ADMIN then APPROVES, which moves the trip to 'settled'. Either can be
-- reopened by an admin.
--
-- Additive + idempotent. Run against centryk_core.
-- ============================================================

USE centryk_core;

ALTER TABLE route_trips
    ADD COLUMN IF NOT EXISTS settlement_submitted_at DATETIME     NULL AFTER cash_variance,
    ADD COLUMN IF NOT EXISTS settlement_submitted_by INT UNSIGNED NULL AFTER settlement_submitted_at,
    ADD COLUMN IF NOT EXISTS settlement_approved_at  DATETIME     NULL AFTER settlement_submitted_by,
    ADD COLUMN IF NOT EXISTS settlement_approved_by  INT UNSIGNED NULL AFTER settlement_approved_at;

-- FKs added guardedly (re-run safe).
SET @has1 := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA='centryk_core' AND TABLE_NAME='route_trips' AND CONSTRAINT_NAME='fk_trips_settle_submitter');
SET @s1 := IF(@has1=0, 'ALTER TABLE route_trips ADD CONSTRAINT fk_trips_settle_submitter FOREIGN KEY (settlement_submitted_by) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE p1 FROM @s1; EXECUTE p1; DEALLOCATE PREPARE p1;

SET @has2 := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA='centryk_core' AND TABLE_NAME='route_trips' AND CONSTRAINT_NAME='fk_trips_settle_approver');
SET @s2 := IF(@has2=0, 'ALTER TABLE route_trips ADD CONSTRAINT fk_trips_settle_approver FOREIGN KEY (settlement_approved_by) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE p2 FROM @s2; EXECUTE p2; DEALLOCATE PREPARE p2;
