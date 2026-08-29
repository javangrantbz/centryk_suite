-- ============================================================
-- Centryk Business — Receivables: write-offs & credit adjustments
--
-- A maker-checker workflow for reducing what a customer owes on an invoice:
-- a bad debt written off, damaged/expired goods credited back, a price
-- adjustment. A manager PROPOSES; a company admin APPROVES (self-approval is
-- allowed but hard-flagged in the audit trail). On approval the invoice's
-- amount_paid is increased by the write-off amount — so every existing balance
-- calc (total - amount_paid) reflects it with no further change — and a full
-- write-off also flips the invoice to the new 'written_off' status so it drops
-- out of the aging.
--
-- Additive + idempotent. Run against centryk_core BEFORE pulling the code.
-- ============================================================

USE centryk_core;

-- New terminal invoice status. Additive to the enum — nothing else writes it,
-- and every `status IN ('sent','overdue',...)` filter simply won't match it,
-- so the invoice-maker (which shares this table) keeps working unchanged.
ALTER TABLE invoices
    MODIFY COLUMN status ENUM('draft','sent','paid','overdue','cancelled','written_off')
    NULL DEFAULT 'draft';

CREATE TABLE IF NOT EXISTS ar_writeoffs (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id     INT UNSIGNED NOT NULL,
    customer_id    INT UNSIGNED NOT NULL,
    invoice_id     INT UNSIGNED NOT NULL,
    amount         DECIMAL(12,2) NOT NULL,
    kind           ENUM('bad_debt','damaged_goods','price_adjustment','other') NOT NULL DEFAULT 'bad_debt',
    reason         VARCHAR(255) NOT NULL DEFAULT '',
    status         ENUM('pending','approved','rejected','void') NOT NULL DEFAULT 'pending',
    proposed_by    INT UNSIGNED NULL,
    approved_by    INT UNSIGNED NULL,
    proposed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at     DATETIME NULL,
    decision_note  VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_wo_company_status (company_id, status),
    KEY idx_wo_invoice (invoice_id),
    KEY idx_wo_customer (customer_id),
    CONSTRAINT fk_wo_company  FOREIGN KEY (company_id)  REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT fk_wo_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_wo_invoice  FOREIGN KEY (invoice_id)  REFERENCES invoices (id)  ON DELETE CASCADE,
    CONSTRAINT fk_wo_proposer FOREIGN KEY (proposed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_wo_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
