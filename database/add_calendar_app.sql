-- Add Calendar app to Centryk's apps registry.
-- Run via phpMyAdmin (or `mysql`) against the centryk_core database.
-- Idempotent: safe to run multiple times.

-- 1. Register the Calendar app
INSERT INTO apps (`key`, label, description, url_local, url_production, icon, color, sort_order)
VALUES (
    'calendar',
    'Calendar',
    'Schedule events and track team availability',
    'http://localhost/centryk/public/calendar.php',
    'https://centryk.net/calendar.php',
    '📅',
    '#14b8a6',
    3
)
ON DUPLICATE KEY UPDATE
    label          = VALUES(label),
    description    = VALUES(description),
    url_local      = VALUES(url_local),
    url_production = VALUES(url_production),
    icon           = VALUES(icon),
    color          = VALUES(color),
    sort_order     = VALUES(sort_order);

-- 2. Grant Calendar access to all existing Centryk admins
--    (Non-admins won't see Calendar in the launcher until granted.)
INSERT IGNORE INTO user_app_access (user_id, app_id)
SELECT u.id, a.id
FROM users u, apps a
WHERE u.is_admin = 1
  AND a.`key` = 'calendar';
