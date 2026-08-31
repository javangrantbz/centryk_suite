-- ============================================================
-- Centryk Business — Accounting package: double-entry general ledger
--
-- Gives a company its own books: chart of accounts, accounting periods,
-- journal entries (manual + auto-posted from AR / expenses / payroll / POS),
-- and the reports built on them (trial balance, P&L, balance sheet, GL detail).
--
-- The existing subledgers stay the source of detail; every posting lands in
-- gl_journals / gl_journal_lines against a GL account, and the subledger
-- reconciles to its control account (gl_account_map slots 'ar', 'gst_output', …).
--
-- Additive + idempotent (IF NOT EXISTS guards; seed uses ON DUPLICATE KEY).
-- Run via phpMyAdmin against centryk_core BEFORE pulling the code.
-- ============================================================

USE centryk_core;

-- ── 1. Per-company accounting configuration ───────────────────────────────
CREATE TABLE IF NOT EXISTS company_accounting (
    company_id              INT UNSIGNED     NOT NULL PRIMARY KEY,
    base_currency           CHAR(3)          NOT NULL DEFAULT 'BZD',
    fiscal_year_start_month TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- 1..12
    activated_at            DATETIME         NULL,                   -- NULL = books not set up yet
    activated_by            INT UNSIGNED     NULL,
    lock_before             DATE             NULL,                   -- hard lock: nothing posts on/before this date
    ar_started_on           DATE             NULL,                   -- AR auto-posting go-live; NULL = off. Everything
                                                                    -- dated before this is in the opening-balance journal.
    created_at              DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_coacct_company FOREIGN KEY (company_id)  REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_coacct_user    FOREIGN KEY (activated_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Chart of accounts ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS gl_accounts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id     INT UNSIGNED NOT NULL,
    code           VARCHAR(20)  NOT NULL,
    name           VARCHAR(120) NOT NULL,
    type           ENUM('asset','liability','equity','income','cogs','expense') NOT NULL,
    subtype        VARCHAR(40)  NOT NULL DEFAULT '',      -- current_asset, fixed_asset, current_liability, …
    parent_id      INT UNSIGNED NULL,                     -- grouping / rollup
    normal_balance ENUM('debit','credit') NOT NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    is_system      TINYINT(1)   NOT NULL DEFAULT 0,       -- seeded; cannot be deleted, type/code locked
    is_control     TINYINT(1)   NOT NULL DEFAULT 0,       -- subledger-owned; no manual journal lines
    description    VARCHAR(255) NOT NULL DEFAULT '',
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gl_acct_code (company_id, code),
    INDEX idx_gl_acct_company_type (company_id, type),
    CONSTRAINT fk_gl_acct_company FOREIGN KEY (company_id) REFERENCES companies(id)  ON DELETE CASCADE,
    CONSTRAINT fk_gl_acct_parent  FOREIGN KEY (parent_id)  REFERENCES gl_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Named control-account bindings (slot -> account) ───────────────────
-- The engine posts to accounts by slot so a company can rename / renumber its
-- chart without breaking auto-posting. Seeded when the template is applied.
CREATE TABLE IF NOT EXISTS gl_account_map (
    company_id INT UNSIGNED NOT NULL,
    slot       VARCHAR(40)  NOT NULL,   -- ar, ap, bank_default, undeposited_funds, gst_output, gst_input,
                                        -- sales_default, cogs_default, sales_returns, bad_debt,
                                        -- opening_balance_equity, retained_earnings, rounding, bank_charges,
                                        -- payroll_clearing, payroll_deductions, paye_payable, ssb_payable,
                                        -- payroll_wages_expense, payroll_employer_ss_expense, pos_clearing
    account_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (company_id, slot),
    CONSTRAINT fk_glmap_company FOREIGN KEY (company_id) REFERENCES companies(id)   ON DELETE CASCADE,
    CONSTRAINT fk_glmap_account FOREIGN KEY (account_id) REFERENCES gl_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Accounting periods ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS gl_periods (
    id          INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    company_id  INT UNSIGNED     NOT NULL,
    fiscal_year SMALLINT UNSIGNED NOT NULL,   -- calendar year the fiscal year starts in
    period_no   TINYINT UNSIGNED NOT NULL,    -- 1..12
    start_date  DATE             NOT NULL,
    end_date    DATE             NOT NULL,
    status      ENUM('open','closed','locked') NOT NULL DEFAULT 'open',
    closed_at   DATETIME         NULL,
    closed_by   INT UNSIGNED     NULL,
    UNIQUE KEY uq_gl_period (company_id, fiscal_year, period_no),
    INDEX idx_gl_period_dates (company_id, start_date, end_date),
    CONSTRAINT fk_gl_period_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_gl_period_user    FOREIGN KEY (closed_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Journal entry header ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS gl_journals (
    id                     INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    company_id             INT UNSIGNED  NOT NULL,
    journal_no             INT UNSIGNED  NOT NULL,             -- per-company running number
    entry_date             DATE          NOT NULL,
    period_id              INT UNSIGNED  NULL,
    memo                   VARCHAR(255)  NOT NULL DEFAULT '',
    source                 ENUM('manual','opening','ar_invoice','ar_receipt','ar_writeoff',
                                'ar_cheque_bounce','expense','expense_payment','payroll','pos',
                                'fx','closing','adjustment') NOT NULL DEFAULT 'manual',
    source_ref             VARCHAR(64)   NOT NULL DEFAULT '',  -- e.g. invoice id, payment id
    status                 ENUM('draft','posted','void') NOT NULL DEFAULT 'posted',
    is_reversal            TINYINT(1)    NOT NULL DEFAULT 0,
    reverses_journal_id    INT UNSIGNED  NULL,
    reversed_by_journal_id INT UNSIGNED  NULL,
    total_debit            DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_credit           DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_by             INT UNSIGNED  NULL,
    created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    posted_at             DATETIME      NULL,
    UNIQUE KEY uq_gl_journal_no (company_id, journal_no),
    INDEX idx_gl_journal_company_date (company_id, entry_date),
    INDEX idx_gl_journal_source (company_id, source, source_ref),
    CONSTRAINT fk_gl_journal_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_gl_journal_period  FOREIGN KEY (period_id)  REFERENCES gl_periods(id) ON DELETE SET NULL,
    CONSTRAINT fk_gl_journal_creator FOREIGN KEY (created_by) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Journal lines ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS gl_journal_lines (
    id          INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    journal_id  INT UNSIGNED     NOT NULL,
    company_id  INT UNSIGNED     NOT NULL,          -- denormalised for per-account queries
    line_no     SMALLINT UNSIGNED NOT NULL,
    account_id  INT UNSIGNED     NOT NULL,
    debit       DECIMAL(14,2)    NOT NULL DEFAULT 0,
    credit      DECIMAL(14,2)    NOT NULL DEFAULT 0,
    memo        VARCHAR(255)     NOT NULL DEFAULT '',
    customer_id INT UNSIGNED     NULL,
    vendor_id   INT UNSIGNED     NULL,
    entry_date  DATE             NOT NULL,          -- denormalised from the journal for range scans
    INDEX idx_gll_journal (journal_id),
    INDEX idx_gll_account_date (company_id, account_id, entry_date),
    CONSTRAINT fk_gll_journal FOREIGN KEY (journal_id) REFERENCES gl_journals(id)  ON DELETE CASCADE,
    CONSTRAINT fk_gll_account FOREIGN KEY (account_id) REFERENCES gl_accounts(id)  ON DELETE RESTRICT,
    CONSTRAINT chk_gll_amounts CHECK (debit >= 0 AND credit >= 0 AND NOT (debit > 0 AND credit > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. Per-company counters (journal numbering) ─────────────────────────
CREATE TABLE IF NOT EXISTS gl_counters (
    company_id INT UNSIGNED NOT NULL,
    name       VARCHAR(30)  NOT NULL,
    next_val   INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (company_id, name),
    CONSTRAINT fk_glcnt_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. Vendors (AP master — mirrors customers) ──────────────────────────
CREATE TABLE IF NOT EXISTS vendors (
    id                         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id                 INT UNSIGNED NOT NULL,
    name                       VARCHAR(150) NOT NULL,
    email                      VARCHAR(150) NULL,
    phone                      VARCHAR(50)  NULL,
    address                    TEXT         NULL,
    tax_number                 VARCHAR(100) NULL,
    default_expense_account_id INT UNSIGNED NULL,
    status                     ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by                 INT UNSIGNED NULL,
    created_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vendors_company (company_id),
    CONSTRAINT fk_vendors_company FOREIGN KEY (company_id)                 REFERENCES companies(id)   ON DELETE CASCADE,
    CONSTRAINT fk_vendors_acct    FOREIGN KEY (default_expense_account_id) REFERENCES gl_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. Expenses / bills (AP-lite) ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS expenses (
    id                   INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    company_id           INT UNSIGNED  NOT NULL,
    vendor_id            INT UNSIGNED  NULL,
    vendor_name          VARCHAR(150)  NOT NULL DEFAULT '',    -- free text when no vendor record
    expense_date         DATE          NOT NULL,
    account_id           INT UNSIGNED  NOT NULL,               -- expense / COGS / asset account debited
    description          VARCHAR(255)  NOT NULL DEFAULT '',
    net_amount           DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_amount           DECIMAL(14,2) NOT NULL DEFAULT 0,     -- recoverable GST input
    total_amount         DECIMAL(14,2) NOT NULL DEFAULT 0,
    status               ENUM('unpaid','paid','void') NOT NULL DEFAULT 'paid',
    paid_from_account_id INT UNSIGNED  NULL,                   -- bank / cash when paid
    reference            VARCHAR(120)  NOT NULL DEFAULT '',
    journal_id           INT UNSIGNED  NULL,                   -- the bill JE
    payment_journal_id   INT UNSIGNED  NULL,                   -- the payment JE
    created_by           INT UNSIGNED  NULL,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expenses_company_date (company_id, expense_date),
    CONSTRAINT fk_exp_company FOREIGN KEY (company_id) REFERENCES companies(id)   ON DELETE CASCADE,
    CONSTRAINT fk_exp_vendor  FOREIGN KEY (vendor_id)  REFERENCES vendors(id)     ON DELETE SET NULL,
    CONSTRAINT fk_exp_account FOREIGN KEY (account_id) REFERENCES gl_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_exp_journal FOREIGN KEY (journal_id) REFERENCES gl_journals(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10. Register the Centryk Business package ──────────────────────────
INSERT INTO business_packages (`key`, label, description, is_app, app_key, sort_order) VALUES
    ('accounting', 'Accounting',
     'General ledger, chart of accounts, journals, expenses, P&L and balance sheet', 0, '', 5)
ON DUPLICATE KEY UPDATE
    label       = VALUES(label),
    description = VALUES(description),
    sort_order  = VALUES(sort_order);
