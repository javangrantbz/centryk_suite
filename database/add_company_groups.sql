-- ============================================================
-- Centryk Business — Enterprise package: company groups (org hierarchy)
--
-- A company group is a parent that owns several companies (subsidiaries /
-- divisions). Group admins get a consolidated view across the members and can
-- hold Centryk Business packages once for the whole group.
--
--   company_groups            — the parent entity
--   companies.group_id        — nullable link; NULL = standalone (unchanged behaviour)
--   company_group_members     — who administers / can view the group
--   company_group_entitlements — packages granted at group level; member companies
--                                inherit them (resolved in Entitlements::level)
--
-- Additive + idempotent. No backfill — every existing company stays standalone.
-- Run against centryk_core.
-- ============================================================

USE centryk_core;

CREATE TABLE IF NOT EXISTS company_groups (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    uuid          CHAR(36)      NOT NULL,
    name          VARCHAR(160)  NOT NULL,
    owner_user_id INT UNSIGNED  NULL,
    status        ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by    INT UNSIGNED  NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_uuid (uuid),
    CONSTRAINT fk_group_owner   FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_group_creator FOREIGN KEY (created_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS group_id INT UNSIGNED NULL AFTER owner_id;

-- FK added separately so re-runs don't choke on a duplicate constraint.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'centryk_core' AND TABLE_NAME = 'companies'
      AND CONSTRAINT_NAME = 'fk_companies_group'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE companies ADD CONSTRAINT fk_companies_group FOREIGN KEY (group_id) REFERENCES company_groups(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS company_group_members (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id   INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    role       ENUM('group_admin','group_viewer') NOT NULL DEFAULT 'group_viewer',
    status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cgm_group_user (group_id, user_id),
    CONSTRAINT fk_cgm_group FOREIGN KEY (group_id) REFERENCES company_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_cgm_user  FOREIGN KEY (user_id)  REFERENCES users(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_group_entitlements (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id    INT UNSIGNED NOT NULL,
    package_key VARCHAR(40)  NOT NULL,
    state       ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active',
    granted_by  INT UNSIGNED NULL,
    granted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at  DATETIME     NULL,
    notes       VARCHAR(255) NOT NULL DEFAULT '',
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cge_group_package (group_id, package_key),
    INDEX idx_cge_package (package_key),
    CONSTRAINT fk_cge_group   FOREIGN KEY (group_id)    REFERENCES company_groups(id)       ON DELETE CASCADE,
    CONSTRAINT fk_cge_package FOREIGN KEY (package_key) REFERENCES business_packages(`key`) ON UPDATE CASCADE,
    CONSTRAINT fk_cge_grantor FOREIGN KEY (granted_by)  REFERENCES users(id)                ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
