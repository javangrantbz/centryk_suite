-- ============================================================
-- Centryk Business — Reconciliation: settlement (batch) matching
--
-- A card / OnePay settlement deposit covers many small receipts. To reconcile
-- one bank credit against a batch of receipts we need the reverse of the
-- existing bank_transactions.ar_payment_id link (which is 1:1): a receipt
-- records which bank line it was settled by.
--
-- Also lets the OnePay ingestion record an electronic payment as a first-class
-- ar_payments receipt carrying the sale + settlement reference.
--
-- Additive + idempotent. Run against centryk_core BEFORE pulling the code.
-- ============================================================

USE centryk_core;

ALTER TABLE ar_payments
    ADD COLUMN IF NOT EXISTS source          VARCHAR(20)  NOT NULL DEFAULT 'manual' AFTER method,
    ADD COLUMN IF NOT EXISTS source_ref      VARCHAR(120) NOT NULL DEFAULT ''       AFTER source,
    ADD COLUMN IF NOT EXISTS settlement_ref  VARCHAR(120) NOT NULL DEFAULT ''       AFTER source_ref,
    ADD COLUMN IF NOT EXISTS bank_txn_id     INT UNSIGNED NULL                       AFTER settlement_ref;

-- (bank_transactions already has match_type ENUM — extend it to name settlements.)
ALTER TABLE bank_transactions
    MODIFY COLUMN match_type ENUM('invoice','ar_payment','manual','settlement') NULL;

CREATE INDEX IF NOT EXISTS idx_arp_bank_txn ON ar_payments (bank_txn_id);
CREATE INDEX IF NOT EXISTS idx_arp_source   ON ar_payments (company_id, source, bank_txn_id);
