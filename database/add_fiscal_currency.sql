-- BTS e-invoicing: per-document currency.
--
-- The UBL builder hard-coded BZD. Belize B2B is almost always BZD, but an
-- export invoice can be in USD. This column lets a fiscal document carry
-- its own ISO code; it defaults to BZD and fromInvoice() leaves it at the
-- default until invoice-maker itself carries a currency.
--
-- Run via phpMyAdmin against centryk_core. Safe to run more than once.

ALTER TABLE fiscal_documents
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'BZD' AFTER total;
