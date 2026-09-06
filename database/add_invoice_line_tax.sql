-- Per-line tax on invoice-maker invoices, for BTS e-invoicing (B2B).
--
-- A real business invoice mixes standard-rated (12.5%), zero-rated and
-- exempt lines. The single invoices.tax lump sum can't express that, so
-- FiscalInvoicingService::fromInvoice() had to blend everything into one
-- 'standard' category - wrong for BTS, which wants a TaxSubtotal per
-- category. These columns let each line carry its own treatment.
--
-- Run via phpMyAdmin against centryk_core. Safe to run more than once.
-- Existing rows default to standard-rated at 12.5%, matching the old
-- blended behaviour closely enough for anything already issued.

ALTER TABLE invoice_items
    ADD COLUMN IF NOT EXISTS tax_category ENUM('standard','zero_rated','exempt') NOT NULL DEFAULT 'standard' AFTER unit_price,
    ADD COLUMN IF NOT EXISTS tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12.50 AFTER tax_category;
