-- ============================================================
-- Centryk Business — indicative package prices (BZD / month)
--
-- business.php shows these as "from BZD X/mo"; 0 shows "Custom pricing".
-- Entry prices only — each subscription still snapshots its own negotiated
-- price on company_subscriptions.price.
--
--   Receivables            BZD   300 /mo
--   Reconciliation         BZD   400 /mo
--   Field Sales & Routes   BZD   600 /mo
--   Enterprise             BZD 1,200 /mo
--
-- Run via phpMyAdmin against centryk_core. Safe to re-run.
-- ============================================================

USE centryk_core;

UPDATE business_packages SET monthly_price = 300.00,  currency = 'BZD' WHERE `key` = 'receivables';
UPDATE business_packages SET monthly_price = 400.00,  currency = 'BZD' WHERE `key` = 'reconciliation';
UPDATE business_packages SET monthly_price = 600.00,  currency = 'BZD' WHERE `key` = 'routes';
UPDATE business_packages SET monthly_price = 1200.00, currency = 'BZD' WHERE `key` = 'enterprise';
