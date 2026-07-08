-- OnePay ↔ Invoices integration (company-scoped, in centryk_core).
-- Links Centryk invoices back to their originating OnePay sale, and adds the
-- grouping + send-log tables needed for batch invoicing and bulk reminders.
-- Additive and idempotent (safe to re-run). Run against centryk_core.
--
-- Design notes:
--  * source_app + source_ref make POS→invoice pushes idempotent: an OnePay sale
--    maps to exactly one invoice per company. UNIQUE (company_id, source_app,
--    source_ref) — multiple NULLs are allowed, so manually-created invoices
--    (no source) are unaffected.
--  * batch_id groups invoices created by "invoice everyone" so a bulk reminder
--    run can target a batch.
--  * invoice_reminders is the send-log: it prevents double-sends and records the
--    outcome of each (bulk) reminder run. No email is sent by this migration.

-- ── Source linkage + batch grouping on invoices ──────────────────────────────
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS source_app VARCHAR(20)  NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS source_ref VARCHAR(64)  NULL AFTER source_app,
    ADD COLUMN IF NOT EXISTS batch_id   INT UNSIGNED NULL AFTER source_ref;

ALTER TABLE invoices
    ADD UNIQUE INDEX IF NOT EXISTS uq_invoices_source (company_id, source_app, source_ref);

-- ── Batches: one row per "invoice everyone" run ──────────────────────────────
CREATE TABLE IF NOT EXISTS invoice_batches (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id    INT UNSIGNED NOT NULL,
    title         VARCHAR(150) NOT NULL,
    description   TEXT         NULL,
    status        ENUM('draft','issued') NOT NULL DEFAULT 'draft',
    invoice_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice_batches_company (company_id),
    CONSTRAINT fk_invoice_batches_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Reminder send-log: prevents double-sends, records bulk-run outcomes ───────
CREATE TABLE IF NOT EXISTS invoice_reminders (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id  INT UNSIGNED NOT NULL,
    invoice_id  INT UNSIGNED NOT NULL,
    run_id      CHAR(40)     NULL,          -- groups one bulk reminder run
    channel     ENUM('email') NOT NULL DEFAULT 'email',
    to_email    VARCHAR(150) NULL,
    status      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    error       VARCHAR(255) NULL,
    sent_at     DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice_reminders_invoice (invoice_id),
    INDEX idx_invoice_reminders_run (run_id),
    CONSTRAINT fk_invoice_reminders_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_reminders_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
