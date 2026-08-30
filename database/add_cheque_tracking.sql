-- ============================================================
-- Centryk Business — Receivables: cheque tracking
--
-- A cheque isn't money until it clears. Belize runs on cash and cheques,
-- including post-dated ones, and a bounced cheque means the customer still
-- owes. This gives a cheque receipt a lifecycle:
--
--   pending  -> received, not yet confirmed by the bank (default for a cheque)
--   cleared  -> the bank confirmed the funds
--   bounced  -> returned; the receipt is reversed and the customer owes again
--
-- The receipt still reduces the balance when recorded (the customer has paid);
-- the register is the risk view. A post-dated cheque is a pending cheque whose
-- cheque_date is in the future.
--
-- Additive + idempotent. Run against centryk_core BEFORE pulling the code.
-- ============================================================

USE centryk_core;

ALTER TABLE ar_payments
    ADD COLUMN IF NOT EXISTS cheque_number    VARCHAR(50)  NOT NULL DEFAULT ''  AFTER settlement_ref,
    ADD COLUMN IF NOT EXISTS cheque_bank      VARCHAR(120) NOT NULL DEFAULT ''  AFTER cheque_number,
    ADD COLUMN IF NOT EXISTS cheque_date      DATE NULL                          AFTER cheque_bank,
    ADD COLUMN IF NOT EXISTS clearance_status ENUM('n/a','pending','cleared','bounced') NOT NULL DEFAULT 'n/a' AFTER cheque_date,
    ADD COLUMN IF NOT EXISTS cleared_on       DATE NULL                          AFTER clearance_status,
    ADD COLUMN IF NOT EXISTS bounce_reason    VARCHAR(190) NOT NULL DEFAULT ''   AFTER cleared_on;

CREATE INDEX IF NOT EXISTS idx_arp_clearance ON ar_payments (company_id, clearance_status);
