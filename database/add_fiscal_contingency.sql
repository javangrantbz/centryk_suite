-- BTS e-invoicing: contingency operating mode (manual 3.3.6).
--
-- Previous Authorization (operMode 1) is mandatory for tax invoices / debit
-- notes / credit notes in normal operation. When BTS is unreachable, the
-- issuer may switch to Contingency (operMode 2 = Subsequent Authorization):
-- the ETD is produced and signed now so the sale can proceed, with a
-- different series + serial number and a contingencyInfo block, and
-- transmitted once BTS is back.
--
-- Run via phpMyAdmin against centryk_core. Safe to run more than once.

ALTER TABLE company_fiscal_profiles
    -- A separate numbering stream for contingency ETDs, so they never
    -- collide with the normal series. '900' by default.
    ADD COLUMN IF NOT EXISTS contingency_series VARCHAR(3) NOT NULL DEFAULT '900' AFTER last_sequence_hash,
    ADD COLUMN IF NOT EXISTS last_contingency_serial INT UNSIGNED NOT NULL DEFAULT 0 AFTER contingency_series;

ALTER TABLE fiscal_documents
    -- 1 = Normal (Previous Authorization), 2 = Contingency (Subsequent).
    ADD COLUMN IF NOT EXISTS oper_mode TINYINT NOT NULL DEFAULT 1 AFTER environment,
    ADD COLUMN IF NOT EXISTS contingency_reason VARCHAR(255) NULL AFTER oper_mode,
    ADD COLUMN IF NOT EXISTS contingency_started_at DATETIME NULL AFTER contingency_reason,
    -- When a failed normal-mode attempt is re-issued in contingency, the
    -- original row points here at the contingency document that replaced it.
    ADD COLUMN IF NOT EXISTS superseded_by_document_id INT UNSIGNED NULL AFTER contingency_started_at;
