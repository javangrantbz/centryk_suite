-- ============================================================
-- Invoices app accent color -> emerald
--
-- The dashboard apps grid (public/partials/dashboard.php) renders the
-- Invoices card with a green icon + "Quotes & Invoicing" label; the
-- accent bar / status dots use apps.color, so set it to emerald to match.
--
-- Run via phpMyAdmin against centryk_core. Safe to re-run.
-- ============================================================

USE centryk_core;

UPDATE apps SET color = '#10b981' WHERE `key` = 'invoice';
