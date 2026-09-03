-- A user's request for access to an app they aren't enrolled in. Raised from
-- the dashboard's "Available Through Your Organization" section; a company
-- admin grants it from companies.php (the per-member app pills). One live
-- request per user+app.
--
-- Idempotent. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_app_access_requests.sql

CREATE TABLE IF NOT EXISTS app_access_requests (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    app_key     VARCHAR(40)  NOT NULL,
    company_id  INT UNSIGNED NULL,
    status      ENUM('pending','granted','dismissed') NOT NULL DEFAULT 'pending',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at  DATETIME NULL,
    decided_by  INT UNSIGNED NULL,
    UNIQUE KEY uq_user_app (user_id, app_key),
    KEY idx_status (status),
    CONSTRAINT fk_aar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
