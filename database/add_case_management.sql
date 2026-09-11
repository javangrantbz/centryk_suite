-- Centryk Case Management — a free, native hub feature (no entitlement gate,
-- no `apps` registry row / opt-in — same pattern as Calendar and Store).
--
-- A "case" is a generic trackable work item: intake -> assignment ->
-- resolution, with a status/priority, an activity timeline (status changes +
-- comments) and file attachments. `case_categories` / `case_services` are
-- NOT hardcoded to any one line of business — every company defines its own
-- (adapted from a land-services case tracker built for one company; the
-- case/category/service shape is kept, the land-services specifics,
-- KYC/AML, payments/invoicing and the separate client-login portal are not).
--
-- RBAC is company_members.role, same as Calendar: any active member can open
-- and track their own cases; admin/manager see and manage every case for the
-- company, and manage the categories/services.
--
-- Idempotent. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_case_management.sql

CREATE TABLE IF NOT EXISTS `case_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED NOT NULL,
    `name`       VARCHAR(150) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cc_company` (`company_id`, `is_active`),
    CONSTRAINT `fk_cc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `case_services` (
    `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`                INT UNSIGNED NOT NULL,
    `category_id`               INT UNSIGNED NULL,
    `name`                      VARCHAR(200) NOT NULL,
    `description`               VARCHAR(500) NOT NULL DEFAULT '',
    `estimated_turnaround_days` INT NULL,
    `is_active`                 TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cs_company` (`company_id`, `is_active`),
    KEY `idx_cs_category` (`category_id`),
    CONSTRAINT `fk_cs_company`  FOREIGN KEY (`company_id`)  REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cs_category` FOREIGN KEY (`category_id`) REFERENCES `case_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Named `case_records`, not `cases` — CASE is a SQL keyword; sidestepping it
-- avoids relying on every query remembering to backtick-quote the table.
CREATE TABLE IF NOT EXISTS `case_records` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`        INT UNSIGNED NOT NULL,
    `case_number`       VARCHAR(20) NOT NULL DEFAULT '',
    `category_id`       INT UNSIGNED NULL,
    `service_id`        INT UNSIGNED NULL,
    `subject`           VARCHAR(200) NOT NULL,
    `description`       TEXT NULL,
    `priority`          ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `status`            ENUM('open','in_progress','waiting','resolved','closed') NOT NULL DEFAULT 'open',
    `requester_user_id` INT UNSIGNED NOT NULL,
    `assignee_user_id`  INT UNSIGNED NULL,
    `due_date`          DATE NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `resolved_at`       DATETIME NULL,
    `closed_at`         DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_case_number` (`company_id`, `case_number`),
    KEY `idx_case_company_status` (`company_id`, `status`),
    KEY `idx_case_requester` (`requester_user_id`),
    KEY `idx_case_assignee` (`assignee_user_id`),
    CONSTRAINT `fk_case_company`    FOREIGN KEY (`company_id`)        REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_case_category`   FOREIGN KEY (`category_id`)       REFERENCES `case_categories` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_case_service`    FOREIGN KEY (`service_id`)        REFERENCES `case_services` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_case_requester`  FOREIGN KEY (`requester_user_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_case_assignee`   FOREIGN KEY (`assignee_user_id`)  REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unified activity timeline: created / status changes / assignment / comments.
CREATE TABLE IF NOT EXISTS `case_events` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id`       INT UNSIGNED NOT NULL,
    `event_type`    ENUM('created','status_change','assigned','comment') NOT NULL,
    `actor_user_id` INT UNSIGNED NULL,
    `from_value`    VARCHAR(60) NULL,
    `to_value`      VARCHAR(60) NULL,
    `note`          TEXT NULL,
    `is_internal`   TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ce_case` (`case_id`, `created_at`),
    CONSTRAINT `fk_ce_case` FOREIGN KEY (`case_id`) REFERENCES `case_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `case_documents` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id`           INT UNSIGNED NOT NULL,
    `uploaded_by`       INT UNSIGNED NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `stored_filename`   VARCHAR(255) NOT NULL,
    `mime_type`         VARCHAR(100) NULL,
    `file_size`         INT NOT NULL DEFAULT 0,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cd_case` (`case_id`),
    CONSTRAINT `fk_cd_case` FOREIGN KEY (`case_id`) REFERENCES `case_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
