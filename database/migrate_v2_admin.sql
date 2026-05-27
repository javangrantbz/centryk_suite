-- ============================================================
-- Centryk v2 — admin + email migration
-- Run against centryk_core via phpMyAdmin.
-- Safe to run more than once.
-- ============================================================

USE centryk_core;

-- 1. Add is_admin flag to users
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Extend account_requests with review columns
ALTER TABLE account_requests ADD COLUMN IF NOT EXISTS notes TEXT NULL;
ALTER TABLE account_requests ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL;
ALTER TABLE account_requests ADD COLUMN IF NOT EXISTS reviewed_by INT UNSIGNED NULL;

-- 3. Upsert webdevelopment@bhilimited.com as a super-admin
--    Password = password123 (change immediately on live)
INSERT INTO users (first_name, last_name, email, password_hash, is_admin, status)
VALUES (
    'BHI', 'Admin',
    'webdevelopment@bhilimited.com',
    '$2y$10$6PF8v2gMZTuQw0ShLY4OeONypfDGJLSveE/ww4p8mSBf2g6l831OS',
    1,
    'active'
)
ON DUPLICATE KEY UPDATE is_admin = 1, status = 'active';

-- 4. Grant that admin access to all active apps
INSERT IGNORE INTO user_app_access (user_id, app_id)
SELECT u.id, a.id
FROM users u
JOIN apps a ON a.status = 'active'
WHERE u.email = 'webdevelopment@bhilimited.com';
