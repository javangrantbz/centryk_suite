CREATE DATABASE IF NOT EXISTS centryk_core CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE centryk_core;

-- Unified user identity table
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid           CHAR(36)        NOT NULL DEFAULT (UUID()),
    first_name     VARCHAR(80)     NOT NULL,
    last_name      VARCHAR(80)     NOT NULL,
    email          VARCHAR(180)    NOT NULL UNIQUE,
    phone          VARCHAR(30)     NULL,
    password_hash  VARCHAR(255)    NOT NULL,
    mfa_enabled    TINYINT(1)      NOT NULL DEFAULT 0,
    status         ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    last_login_at  DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Registered apps (onepay, mypay, future apps)
CREATE TABLE IF NOT EXISTS apps (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`          VARCHAR(40)     NOT NULL UNIQUE,
    label          VARCHAR(80)     NOT NULL,
    description    VARCHAR(200)    NOT NULL DEFAULT '',
    url_local      VARCHAR(255)    NOT NULL,
    url_production VARCHAR(255)    NOT NULL DEFAULT '',
    icon           VARCHAR(10)     NOT NULL DEFAULT '📦',
    color          VARCHAR(7)      NOT NULL DEFAULT '#1a1a2e',
    sort_order     INT             NOT NULL DEFAULT 0,
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Which users can access which apps
CREATE TABLE IF NOT EXISTS user_app_access (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    app_id     INT UNSIGNED NOT NULL,
    granted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_app (user_id, app_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id)  REFERENCES apps(id)  ON DELETE CASCADE
);

-- One-time SSO tokens issued by Centryk for cross-app login
CREATE TABLE IF NOT EXISTS sso_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token      CHAR(64)     NOT NULL UNIQUE,
    app_key    VARCHAR(40)  NOT NULL,
    used       TINYINT(1)   NOT NULL DEFAULT 0,
    expires_at DATETIME     NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Login audit log
CREATE TABLE IF NOT EXISTS login_events (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    email      VARCHAR(180) NOT NULL,
    ip_address VARCHAR(45)  NULL,
    user_agent VARCHAR(255) NULL,
    success    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id  (user_id),
    INDEX idx_email    (email),
    INDEX idx_created  (created_at)
);

-- ── Seed data ──────────────────────────────────────────────────────────────

INSERT INTO apps (`key`, label, description, url_local, url_production, icon, color, sort_order) VALUES
('onepay', 'OnePay',  'Inventory & Point of Sale', 'http://onepay.local/sso.php', 'https://onepay.centryk.com/sso.php',  '🏪', '#0ea5e9', 1),
('mypay',  'MyPay',   'HR & Payroll',              'http://localhost/myPay/sso.php',          'https://mypay.centryk.com/sso.php',   '👥', '#8b5cf6', 2);

-- Default admin user  (password: password123)
INSERT INTO users (first_name, last_name, email, phone, password_hash) VALUES
('Admin', 'User', 'admin@centryk.com', NULL, '$2y$10$6PF8v2gMZTuQw0ShLY4OeONypfDGJLSveE/ww4p8mSBf2g6l831OS');

-- Grant the admin access to both apps
INSERT INTO user_app_access (user_id, app_id)
SELECT u.id, a.id FROM users u, apps a WHERE u.email = 'admin@centryk.com';
