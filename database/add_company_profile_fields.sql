-- ============================================================
-- Centryk Company Profile fields
--
-- Adds the business-profile columns to the central `companies`
-- table so company identity (email, phones, address, TIN, logo,
-- opening hours) is captured once in Centryk and read by every
-- app (e.g. Invoice Maker) as a single source of truth.
--
-- Captured/edited in Centryk: profile.php#companies.
-- Read-only consumers: invoice-maker (Header page + PDFs).
--
-- `logo` stores a path relative to the Centryk public web root
-- (no leading slash), e.g. 'uploads/companies/abc.png'. Legacy
-- Invoice-Maker logos are backfilled with an 'invoice-maker/'
-- prefix so they still resolve.
--
-- Run via phpMyAdmin against centryk_core. Safe to re-run.
-- ============================================================

USE centryk_core;

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS email         VARCHAR(255) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS phone         VARCHAR(50)  NULL AFTER email,
    ADD COLUMN IF NOT EXISTS phone2        VARCHAR(50)  NULL AFTER phone,
    ADD COLUMN IF NOT EXISTS phone3        VARCHAR(50)  NULL AFTER phone2,
    ADD COLUMN IF NOT EXISTS address       TEXT         NULL AFTER phone3,
    ADD COLUMN IF NOT EXISTS tax_number    VARCHAR(100) NULL AFTER address,
    ADD COLUMN IF NOT EXISTS logo          VARCHAR(255) NULL AFTER tax_number,
    ADD COLUMN IF NOT EXISTS opening_hours TEXT         NULL AFTER logo;

-- Backfill identity fields from the existing per-app invoice settings so
-- nothing on current documents goes blank before Centryk capture is built.
UPDATE companies c
JOIN invoice_settings s ON s.company_id = c.id
SET
    c.email      = COALESCE(NULLIF(c.email, ''),      NULLIF(s.business_email, '')),
    c.phone      = COALESCE(NULLIF(c.phone, ''),      NULLIF(s.business_phone, '')),
    c.address    = COALESCE(NULLIF(c.address, ''),    NULLIF(s.business_address, '')),
    c.tax_number = COALESCE(NULLIF(c.tax_number, ''), NULLIF(s.business_tax_number, '')),
    -- legacy logo paths are relative to /invoice-maker; prefix so they resolve
    -- against the Centryk public root used by the new resolver.
    c.logo       = COALESCE(NULLIF(c.logo, ''),
                            CASE WHEN NULLIF(s.business_logo, '') IS NULL THEN NULL
                                 ELSE CONCAT('invoice-maker/', s.business_logo) END);
