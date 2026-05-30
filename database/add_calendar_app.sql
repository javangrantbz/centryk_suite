-- Add Calendar app to Centryk's apps registry, and add an opt_in flag so
-- users can self-enable certain apps from their dashboard.
-- Run via phpMyAdmin (or `mysql`) against the centryk_core database.
-- Idempotent: safe to run multiple times.

-- 1. Add opt_in column to apps (default 0 = admin-controlled, existing behaviour)
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'apps'
      AND COLUMN_NAME = 'opt_in'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE apps ADD COLUMN opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order',
    'SELECT "apps.opt_in already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Register the Calendar app as opt-in (users self-enable from the dashboard)
INSERT INTO apps (`key`, label, description, url_local, url_production, icon, color, sort_order, opt_in)
VALUES (
    'calendar',
    'Calendar',
    'Schedule events and track team availability',
    'http://localhost/centryk/public/calendar.php',
    'https://centryk.net/calendar.php',
    '📅',
    '#14b8a6',
    3,
    1
)
ON DUPLICATE KEY UPDATE
    label          = VALUES(label),
    description    = VALUES(description),
    url_local      = VALUES(url_local),
    url_production = VALUES(url_production),
    icon           = VALUES(icon),
    color          = VALUES(color),
    sort_order     = VALUES(sort_order),
    opt_in         = VALUES(opt_in);
