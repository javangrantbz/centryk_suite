-- Refine the dashboard app taxonomy (see add_app_categories.sql for the base).
--
-- Changes:
--   * finance and insights are now shown as separate headings on the grid
--     (they were merged as "Finance & Insights"). No data change needed —
--     the split is in dashboard.php's CAT_LABELS.
--   * new "operations" category — run-the-business tools. Vision Board
--     (digital signage) moves here from marketing.
--
-- Canonical categories now:
--   business    — core apps (OnePay, MyPay, Invoices)
--   finance     — money in / money out (OneLink Payments)
--   insights    — see what's happening (Calendar, future Analytics)
--   operations  — run day-to-day operations (Vision Board / signage)
--   marketing   — reach and sell to customers (Centryk TV, Store)
--
-- The "Centryk Business" heading over the AR/GL/routes module cards is a
-- dashboard-only grouping (those cards are not rows in `apps`).
--
-- Idempotent: safe to run multiple times. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_operations_category.sql

UPDATE apps SET category = 'operations' WHERE `key` = 'visionboard';
UPDATE apps SET category = 'marketing'  WHERE `key` = 'tv';
