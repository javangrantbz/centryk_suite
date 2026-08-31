-- Centryk Forms — a free-core app for surveys, polls and feedback forms.
--
-- A company admin/manager builds a form (ordered questions of a few types),
-- opens it, and shares a tokenised public link. Responses land in
-- form_responses / form_answers; the builder sees a summary + individual
-- responses + CSV export.
--
-- Free core (not entitlement-gated): registered in `apps` as opt-in and
-- granted to every existing user, same as the other in-hub apps.
--
-- Idempotent. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_forms.sql

CREATE TABLE IF NOT EXISTS `form_forms` (
    `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`              INT NOT NULL,
    `created_by`              INT NOT NULL,
    `title`                   VARCHAR(200) NOT NULL,
    `description`             TEXT NULL,
    `status`                  ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
    `access`                  ENUM('public','login_required') NOT NULL DEFAULT 'public',
    `one_response_per_person` TINYINT(1) NOT NULL DEFAULT 0,
    `confirmation_message`    VARCHAR(500) NOT NULL DEFAULT '',
    `share_token`             CHAR(32) NOT NULL,
    `response_count`          INT NOT NULL DEFAULT 0,
    `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `closed_at`               DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_form_share` (`share_token`),
    KEY `idx_form_company` (`company_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_questions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_id`    INT UNSIGNED NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `type`       ENUM('short_text','long_text','single_choice','multiple_choice','dropdown','rating','yes_no','number','date','section') NOT NULL,
    `label`      VARCHAR(500) NOT NULL,
    `help_text`  VARCHAR(500) NOT NULL DEFAULT '',
    `required`   TINYINT(1) NOT NULL DEFAULT 0,
    `options`    LONGTEXT NULL,
    `config`     LONGTEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fq_form` (`form_id`, `sort_order`),
    CONSTRAINT `fk_fq_form` FOREIGN KEY (`form_id`) REFERENCES `form_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_responses` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_id`            INT UNSIGNED NOT NULL,
    `respondent_user_id` INT NULL,
    `respondent_key`     CHAR(64) NULL,
    `submitted_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fr_form` (`form_id`, `submitted_at`),
    KEY `idx_fr_dedupe` (`form_id`, `respondent_key`),
    CONSTRAINT `fk_fr_form` FOREIGN KEY (`form_id`) REFERENCES `form_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_answers` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `response_id` INT UNSIGNED NOT NULL,
    `question_id` INT UNSIGNED NOT NULL,
    `answer_text` TEXT NULL,
    `answer_json` LONGTEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_fa_response` (`response_id`),
    KEY `idx_fa_question` (`question_id`),
    CONSTRAINT `fk_fa_response` FOREIGN KEY (`response_id`) REFERENCES `form_responses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Register the app ──────────────────────────────────────────────────────
INSERT INTO `apps` (`key`, `label`, `description`, `category`, `url_local`, `url_production`, `icon`, `color`, `sort_order`, `opt_in`, `status`)
SELECT 'forms', 'Centryk Forms',
       'Build surveys, polls and feedback forms; share a link and collect responses.',
       'insights',
       'http://localhost/centryk/public/forms.php',
       'https://centryk.net/forms.php',
       'clipboard-list', '#4f46e5', 7, 1, 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `apps` WHERE `key` = 'forms');

-- Grant it to every existing user (new users get it through provisioning).
INSERT IGNORE INTO `user_app_access` (`user_id`, `app_id`)
SELECT u.id, a.id FROM `users` u CROSS JOIN `apps` a WHERE a.`key` = 'forms';
