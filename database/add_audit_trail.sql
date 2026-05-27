-- ============================================================
-- Centryk Audit Trail
--
-- What this does:
--   1. Adds audit_events for admin-visible change history
--   2. Keeps login_events as the login-specific audit source
--
-- Run via phpMyAdmin against centryk_core database.
-- ============================================================

USE centryk_core;

CREATE TABLE IF NOT EXISTS audit_events (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT UNSIGNED NULL,
    target_user_id INT UNSIGNED NULL,
    company_id    INT UNSIGNED NULL,
    event_type    VARCHAR(80)  NOT NULL,
    summary       VARCHAR(255) NOT NULL,
    metadata_json LONGTEXT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type (event_type),
    INDEX idx_actor_user (actor_user_id),
    INDEX idx_target_user (target_user_id),
    INDEX idx_company (company_id),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_audit_actor_user
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_target_user
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_company
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
