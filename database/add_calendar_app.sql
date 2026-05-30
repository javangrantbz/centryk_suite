-- Register Calendar in Centryk's apps registry as a built-in (not opt-in).
-- Run via phpMyAdmin (or `mysql`) against the centryk_core database.
-- Idempotent: safe to run multiple times.
--
-- The apps.opt_in column is added here too so it exists for any future app
-- that does want self-enrollment, but Calendar itself ships as opt_in = 0
-- and is auto-granted to every user.

-- 1. Add opt_in column to apps (default 0 = admin-controlled / built-in)
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

-- 2. Register the Calendar app as built-in (not opt-in)
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
    0
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

-- 3. Grant Calendar to all existing users (built-in, available to everyone)
INSERT IGNORE INTO user_app_access (user_id, app_id)
SELECT u.id, a.id
FROM users u, apps a
WHERE a.`key` = 'calendar';
