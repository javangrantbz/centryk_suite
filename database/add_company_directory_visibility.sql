-- Centryk Directory visibility preference.
-- Businesses are listed by default; company admins can opt out from
-- Centryk Account -> Business Profile.

USE centryk_core;

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS directory_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER opening_hours;

CREATE INDEX IF NOT EXISTS idx_companies_directory
    ON companies (status, directory_visible, name);
