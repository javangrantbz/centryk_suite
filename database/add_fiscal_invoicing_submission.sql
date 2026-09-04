-- Belize BTS Electronic Invoicing — submission fields.
--
-- Adds what the real UBL mapper / XAdES signer / BTS transmitter need on top
-- of the batch-one foundation (add_fiscal_invoicing.sql): the ETDUI, the
-- issuer's own serial-number/series numbering (BTS explicitly leaves
-- numbering to the issuer - see FiscalEtdui.php), the sequenceInfo hash
-- chain state, and which environment a document was actually sent to.
--
-- Run via phpMyAdmin against centryk_core. Safe to run more than once.

ALTER TABLE company_fiscal_profiles
    ADD COLUMN IF NOT EXISTS default_series VARCHAR(3) NOT NULL DEFAULT '001' AFTER establishment_code,
    ADD COLUMN IF NOT EXISTS last_serial_number INT UNSIGNED NOT NULL DEFAULT 0 AFTER default_series,
    -- Base64 sequenceInfoThisETD of the last document actually built for
    -- submission - the next one's sequenceInfoPreviousETD. NULL until the
    -- company's first submission.
    ADD COLUMN IF NOT EXISTS last_sequence_hash VARCHAR(40) NULL AFTER last_serial_number;

-- Batch one's document_type enum didn't include tax_receipt (its own BTS
-- reception service, api/taxreceipt/v1, ETDType 02) - add it now that the
-- UBL mapper supports all four BETDSM Invoice-2 sub-types.
ALTER TABLE fiscal_documents
    MODIFY COLUMN document_type ENUM('invoice','tax_receipt','credit_note','debit_note','cancellation') NOT NULL DEFAULT 'invoice';

ALTER TABLE fiscal_documents
    -- The BTS-facing identifier (44 digits) - distinct from document_uuid,
    -- our own internal reference minted at issue() time before a serial
    -- number/ETDUI is ever consumed. Only set once the document is actually
    -- prepared for submission (see FiscalInvoicingService::submitToBts()).
    ADD COLUMN IF NOT EXISTS etdui CHAR(44) NULL AFTER document_uuid,
    ADD COLUMN IF NOT EXISTS serial_number INT UNSIGNED NULL AFTER etdui,
    ADD COLUMN IF NOT EXISTS series VARCHAR(3) NULL AFTER serial_number,
    ADD COLUMN IF NOT EXISTS security_code CHAR(9) NULL AFTER series,
    ADD COLUMN IF NOT EXISTS environment ENUM('test','production') NULL AFTER security_code,
    -- The exact date/time embedded in the ETDUI and in <cbc:IssueDate>/
    -- <cbc:IssueTime> - must never be recomputed after the fact (rule
    -- ETDUI05 requires an exact match), so it's persisted once at
    -- submission time rather than derived from created_at later.
    ADD COLUMN IF NOT EXISTS issue_date DATE NULL AFTER environment,
    ADD COLUMN IF NOT EXISTS issue_time VARCHAR(20) NULL AFTER issue_date,
    ADD UNIQUE KEY IF NOT EXISTS uq_fiscal_documents_etdui (etdui);
