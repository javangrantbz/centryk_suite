-- Rewrite the Centryk Business package descriptions on business.php from
-- feature lists into benefit-led one-liners. Copy only — no schema change.
--
-- The card on public/business.php renders business_packages.description as a
-- single sentence under the package name, so each stays ~1 line.
--
-- Idempotent. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_business_package_copy.sql

UPDATE `business_packages` SET `description` =
    'Know where the money went. A proper double-entry ledger with your P&L and balance sheet on demand, and sales, expenses and payroll posted for you automatically.'
    WHERE `key` = 'accounting';

UPDATE `business_packages` SET `description` =
    'Know exactly who owes you and how late they are. Send statements in one click, chase overdue accounts, and stop extending credit to slow payers.'
    WHERE `key` = 'receivables';

UPDATE `business_packages` SET `description` =
    'Tie every bank deposit to the invoice it paid in minutes, not days — so the cash position you report is one you can actually trust.'
    WHERE `key` = 'reconciliation';

UPDATE `business_packages` SET `description` =
    'See what is on every truck and what each driver owes you at the end of the day. No more guessing where the stock and the cash went.'
    WHERE `key` = 'routes';

UPDATE `business_packages` SET `description` =
    'Run all your companies as one — consolidated receivables, cash and reconciliation in a single view, with maker-checker approvals that keep control as you grow.'
    WHERE `key` = 'enterprise';
