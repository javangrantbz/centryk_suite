-- Belize BTS Electronic Invoicing — foundation.
--
-- BTS (Belize Tax Service) is rolling out mandatory e-invoicing: invoices get
-- built as signed UBL 2.1 XML, submitted to BTS in real time, and only the
-- BTS-authorized document (with its authorization code + QR) is legally
-- valid. See gitignore/bts integration information.txt for the intake email;
-- the actual Orientation Manual + XSD schemas + sample XMLs were not
-- attached and are still pending a resend as of 2026-09-04.
--
-- This schema is deliberately decoupled from that eventual wire format so it
-- can be built now: a canonical fiscal-document model any Centryk app can
-- write to. A document's lifecycle is
--   draft -> built -> signed -> submitted -> authorized | rejected
-- and today nothing progresses past 'built' - there is no UBL mapper, XAdES
-- signer, or BTS transmitter yet. Those get added once the schema is in
-- hand; only they change, this model shouldn't need to.
--
-- Run via phpMyAdmin against centryk_core. Safe to run more than once.

CREATE TABLE IF NOT EXISTS company_fiscal_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,

    -- Identity BTS asks for when registering a company for test access
    -- (matches the "please provide" list in the intake email).
    legal_name VARCHAR(180) NULL,
    tin VARCHAR(40) NULL,
    address TEXT NULL,
    economic_activity_code VARCHAR(40) NULL,
    establishment_code VARCHAR(40) NULL,
    contact_name VARCHAR(150) NULL,
    contact_position VARCHAR(100) NULL,
    contact_email VARCHAR(150) NULL,
    contact_phone VARCHAR(50) NULL,
    tech_contact_name VARCHAR(150) NULL,
    tech_contact_email VARCHAR(150) NULL,

    environment ENUM('test','production') NOT NULL DEFAULT 'test',
    -- not_started:    nothing sent to BTS yet
    -- info_sent:      registration info sent, awaiting sandbox access
    -- sandbox_access: BTS has set up a VERTX-BZ test account
    -- live:           authorized issuer in production
    -- suspended:      was live, currently not submitting
    status ENUM('not_started','info_sent','sandbox_access','live','suspended') NOT NULL DEFAULT 'not_started',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    effective_date DATE NULL,

    -- Path to the encrypted certificate blob, not the certificate itself.
    certificate_path VARCHAR(255) NULL,
    certificate_expires_on DATE NULL,
    api_base_url VARCHAR(255) NULL,

    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_fiscal_profile_company (company_id),
    CONSTRAINT fk_fiscal_profile_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fiscal_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    document_uuid CHAR(36) NOT NULL,
    document_type ENUM('invoice','credit_note','debit_note','cancellation') NOT NULL DEFAULT 'invoice',
    status ENUM('draft','built','signed','submitted','authorized','rejected','cancelled','error') NOT NULL DEFAULT 'draft',

    -- Which Centryk app this came from, and its own record id/number for it
    -- (e.g. source_app='invoice-maker', source_ref=the invoices.id it was
    -- built from). Both nullable - a fiscal document can exist without one
    -- once other spokes issue directly.
    source_app VARCHAR(20) NULL,
    source_ref VARCHAR(64) NULL,

    -- Credit/debit notes and cancellations point back at the original.
    reference_document_id INT UNSIGNED NULL,

    our_number VARCHAR(50) NULL,

    -- Seller/buyer identity frozen at issue time, so a later edit to the
    -- company profile or customer record never rewrites history.
    seller_snapshot_json TEXT NULL,
    buyer_snapshot_json TEXT NULL,

    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Populated once a transmitter exists and BTS actually authorizes it.
    authorization_code VARCHAR(100) NULL,
    authorized_at DATETIME NULL,
    qr_payload TEXT NULL,
    signed_xml_path VARCHAR(255) NULL,
    bts_response_json TEXT NULL,
    error_message TEXT NULL,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    submitted_at DATETIME NULL,

    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_fiscal_document_uuid (document_uuid),
    INDEX idx_fiscal_documents_company_status (company_id, status, created_at),
    INDEX idx_fiscal_documents_source (source_app, source_ref),
    CONSTRAINT fk_fiscal_documents_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_fiscal_documents_reference FOREIGN KEY (reference_document_id) REFERENCES fiscal_documents(id) ON DELETE SET NULL,
    CONSTRAINT fk_fiscal_documents_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fiscal_document_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fiscal_document_id INT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL DEFAULT 1,
    item_code VARCHAR(80) NULL,
    description TEXT NOT NULL,
    quantity DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
    unit_of_measure VARCHAR(20) NOT NULL DEFAULT 'unit',
    unit_price DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    line_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_category ENUM('standard','zero_rated','exempt') NOT NULL DEFAULT 'standard',
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12.50,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    INDEX idx_fiscal_document_lines_doc (fiscal_document_id, line_number),
    CONSTRAINT fk_fiscal_document_lines_doc FOREIGN KEY (fiscal_document_id) REFERENCES fiscal_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- UBL wants tax broken out by category/rate at the document level too (its
-- own TaxTotal/TaxSubtotal), not just summed from lines.
CREATE TABLE IF NOT EXISTS fiscal_document_taxes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fiscal_document_id INT UNSIGNED NOT NULL,
    tax_category ENUM('standard','zero_rated','exempt') NOT NULL DEFAULT 'standard',
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12.50,
    taxable_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    INDEX idx_fiscal_document_taxes_doc (fiscal_document_id),
    CONSTRAINT fk_fiscal_document_taxes_doc FOREIGN KEY (fiscal_document_id) REFERENCES fiscal_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Status history / audit trail per document - the admin log reads this so a
-- rejection or a retry has a paper trail once BTS is actually in the loop.
CREATE TABLE IF NOT EXISTS fiscal_document_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fiscal_document_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    detail TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_fiscal_document_events_doc (fiscal_document_id, created_at),
    CONSTRAINT fk_fiscal_document_events_doc FOREIGN KEY (fiscal_document_id) REFERENCES fiscal_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_fiscal_document_events_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
