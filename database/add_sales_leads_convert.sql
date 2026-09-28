-- Sales Leads -> Invoice Maker bridge: lets admin/manager turn a sales lead
-- into a billable client (invoice-maker's `customers` table) with one click,
-- then go straight to drafting a quote for them.
--
-- `converted_customer_id` is nullable and only ever set once per lead (the
-- convert action is idempotent — converting twice reuses the same customer
-- row instead of creating a duplicate). No FK to `customers`: invoice-maker
-- customers can be deleted independently and this is a soft pointer, not a
-- referential-integrity requirement.
--
-- Additive + idempotent. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_sales_leads_convert.sql

ALTER TABLE `sales_leads`
    ADD COLUMN IF NOT EXISTS `converted_customer_id` INT UNSIGNED NULL AFTER `next_follow_up_date`;
