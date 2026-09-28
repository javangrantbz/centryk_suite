-- Centryk Sales Leads — a free, native hub feature for companies whose sales
-- agents work the field (e.g. a meat shop selling packaged meats to other
-- businesses). An agent records who they sold to on their phone; admin/
-- manager get a roster of every lead to follow up on (reorder calls,
-- "how's it selling" check-ins).
--
-- Named `sales_leads`, NOT `customers` — invoice-maker/receivables already
-- owns a `customers` table on this hub (billing records: tax_number,
-- credit_limit, ar_status — see add_invoice_app.sql). A sales lead is a
-- prospect/contact an agent met in the field, not a billing party; keeping
-- separate tables avoids silently colliding with that schema (an earlier
-- draft of this migration used `CREATE TABLE IF NOT EXISTS customers` and
-- it silently no-op'd against the existing AR table instead of creating a
-- new one — caught before any data was written).
--
-- RBAC is plain company_members.role, same as Case Management/Calendar: any
-- active member can add a lead and see the ones they added; admin/manager
-- see and manage the whole company roster and log follow-ups.
--
-- Idempotent. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_sales_leads.sql

CREATE TABLE IF NOT EXISTS `sales_leads` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`          INT UNSIGNED NOT NULL,
    `business_name`       VARCHAR(200) NOT NULL,
    `contact_name`        VARCHAR(150) NOT NULL DEFAULT '',
    `phone`               VARCHAR(40) NOT NULL DEFAULT '',
    `email`               VARCHAR(190) NOT NULL DEFAULT '',
    `address`             VARCHAR(300) NOT NULL DEFAULT '',
    `order_details`       VARCHAR(300) NOT NULL DEFAULT '',
    `order_value`         DECIMAL(10,2) NULL,
    `status`              ENUM('new','contacted','reordered','inactive') NOT NULL DEFAULT 'new',
    `next_follow_up_date` DATE NULL,
    `created_by`          INT UNSIGNED NOT NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sl_company_status` (`company_id`, `status`),
    KEY `idx_sl_created_by` (`created_by`),
    CONSTRAINT `fk_sl_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sl_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Follow-up activity log: every call/email/visit an admin or agent logs
-- against a lead, plus status changes. Oldest-first timeline per lead.
CREATE TABLE IF NOT EXISTS `sales_lead_follow_ups` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lead_id`        INT UNSIGNED NOT NULL,
    `actor_user_id`  INT UNSIGNED NOT NULL,
    `note`           TEXT NULL,
    `from_status`    VARCHAR(20) NULL,
    `to_status`      VARCHAR(20) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_slf_lead` (`lead_id`, `created_at`),
    CONSTRAINT `fk_slf_lead` FOREIGN KEY (`lead_id`) REFERENCES `sales_leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
