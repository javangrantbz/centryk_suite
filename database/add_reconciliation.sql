-- ============================================================
-- Centryk Business — Reconciliation package
--
-- Import-based bank reconciliation: finance uploads a bank statement, the
-- lines land in bank_transactions, and the workbench matches deposit lines
-- to open invoices (posting a receipt through the Receivables ledger).
--
-- Works today with a CSV upload; swap the importer for a live feed when
-- Centryk Bank exposes one — the matching tables don't change.
--
-- Expects add_receivables.sql to have run (matching a line to an invoice
-- writes an ar_payments row). Additive + idempotent. Run against centryk_core.
-- ============================================================

USE centryk_core;

CREATE TABLE IF NOT EXISTS bank_statement_imports (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    company_id  INT UNSIGNED  NOT NULL,
    filename    VARCHAR(190)  NOT NULL DEFAULT '',
    row_count   INT UNSIGNED  NOT NULL DEFAULT 0,
    skipped     INT UNSIGNED  NOT NULL DEFAULT 0,
    imported_by INT UNSIGNED  NULL,
    imported_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bsi_company (company_id),
    CONSTRAINT fk_bsi_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_bsi_user    FOREIGN KEY (imported_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_transactions (
    id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    company_id   INT UNSIGNED  NOT NULL,
    import_id    INT UNSIGNED  NULL,
    txn_date     DATE          NOT NULL,
    description  VARCHAR(255)  NOT NULL DEFAULT '',
    reference    VARCHAR(190)  NOT NULL DEFAULT '',
    amount       DECIMAL(12,2) NOT NULL,                       -- signed: + money in, - money out
    direction    ENUM('credit','debit') NOT NULL,
    dedupe_hash  CHAR(40)      NOT NULL,                       -- sha1(company|date|amount|desc|ref)
    status       ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
    match_type   ENUM('invoice','ar_payment','manual') NULL,
    match_id     INT UNSIGNED  NULL,                           -- invoice id, or ar_payments id
    ar_payment_id INT UNSIGNED NULL,                           -- receipt this line created, if any
    note         VARCHAR(255)  NOT NULL DEFAULT '',
    matched_by   INT UNSIGNED  NULL,
    matched_at   DATETIME      NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bt_dedupe (company_id, dedupe_hash),
    INDEX idx_bt_company_status (company_id, status),
    INDEX idx_bt_date (txn_date),
    CONSTRAINT fk_bt_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_bt_import  FOREIGN KEY (import_id)  REFERENCES bank_statement_imports(id) ON DELETE SET NULL,
    CONSTRAINT fk_bt_arpay   FOREIGN KEY (ar_payment_id) REFERENCES ar_payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_bt_matcher FOREIGN KEY (matched_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
