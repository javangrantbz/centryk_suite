-- ============================================================
-- Centryk Business — Receivables package
--
-- Extends the existing invoicing tables (customers / invoices / invoice_items)
-- with the account-level layer an AR ledger needs:
--   customers.*            — credit limit, payment terms, credit hold, opening balance
--   ar_payments            — money received against a customer account
--   ar_payment_allocations — how each receipt was applied across invoices
--
-- Additive + idempotent. The new customers columns are nullable / defaulted so
-- the invoice-maker keeps working unchanged. Run against centryk_core.
-- ============================================================

USE centryk_core;

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS credit_limit        DECIMAL(12,2)     NULL              AFTER tax_number,
    ADD COLUMN IF NOT EXISTS payment_terms_days  SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER credit_limit,
    ADD COLUMN IF NOT EXISTS on_hold             TINYINT(1)        NOT NULL DEFAULT 0 AFTER payment_terms_days,
    ADD COLUMN IF NOT EXISTS opening_balance     DECIMAL(12,2)     NOT NULL DEFAULT 0 AFTER on_hold,
    ADD COLUMN IF NOT EXISTS ar_status           ENUM('active','archived') NOT NULL DEFAULT 'active' AFTER opening_balance;

CREATE TABLE IF NOT EXISTS ar_payments (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    company_id  INT UNSIGNED  NOT NULL,
    customer_id INT UNSIGNED  NOT NULL,
    received_on DATE          NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    method      ENUM('cash','card','bank_transfer','xfer','cheque','other') NOT NULL DEFAULT 'other',
    reference   VARCHAR(120)  NOT NULL DEFAULT '',
    notes       VARCHAR(255)  NOT NULL DEFAULT '',
    created_by  INT UNSIGNED  NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_arpay_company  (company_id),
    INDEX idx_arpay_customer (customer_id),
    CONSTRAINT fk_arpay_company  FOREIGN KEY (company_id)  REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_arpay_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_arpay_creator  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ar_payment_allocations (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    ar_payment_id INT UNSIGNED  NOT NULL,
    invoice_id    INT UNSIGNED  NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aralloc_payment (ar_payment_id),
    INDEX idx_aralloc_invoice (invoice_id),
    CONSTRAINT fk_aralloc_payment FOREIGN KEY (ar_payment_id) REFERENCES ar_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_aralloc_invoice FOREIGN KEY (invoice_id)    REFERENCES invoices(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
