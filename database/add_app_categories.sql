-- Add a `category` to the apps registry so the dashboard app grid (and any
-- future surface) can group tools by purpose instead of showing one flat list.
--
-- Canonical categories: business | finance | marketing | insights
--   business  — run day-to-day operations (OnePay, MyPay, Invoices)
--   finance   — money in / money out (OneLink Payments — not yet a DB app)
--   marketing — reach and sell to customers (Vision Board, Centryk TV, Store)
--   insights  — see what's happening across the business (Calendar, future Analytics)
--
-- Idempotent: safe to run multiple times. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_app_categories.sql

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'apps'
      AND COLUMN_NAME = 'category'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE apps ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT ''business'' AFTER description',
    'SELECT "apps.category already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Classify the apps that currently live in the registry.
UPDATE apps SET category = 'business'  WHERE `key` IN ('onepay', 'mypay', 'invoice');
UPDATE apps SET category = 'marketing' WHERE `key` IN ('visionboard', 'tv');
UPDATE apps SET category = 'insights'  WHERE `key` IN ('calendar');
