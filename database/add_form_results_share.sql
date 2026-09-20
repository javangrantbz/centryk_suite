-- Forms: a public, passcode-protected page showing a form's summary (charts and
-- totals only; no names, phone numbers or emails) at /results/<token>.
--
--   form_results_shares   : one row per shared form (token, hashed 4-6 digit code, on/off)
--   form_results_attempts : failed code attempts, used to lock guessing for 15 minutes
--
-- Idempotent. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_form_results_share.sql

CREATE TABLE IF NOT EXISTS `form_results_shares` (
    `form_id`    INT UNSIGNED NOT NULL,
    `token`      CHAR(32) NOT NULL,
    `pin_hash`   VARCHAR(255) NOT NULL,
    `enabled`    TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`form_id`),
    UNIQUE KEY `uq_frs_token` (`token`),
    CONSTRAINT `fk_frs_form` FOREIGN KEY (`form_id`) REFERENCES `form_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_results_attempts` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_id`      INT UNSIGNED NOT NULL,
    `ip_hash`      CHAR(64) NOT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fra_form_time` (`form_id`, `attempted_at`),
    KEY `idx_fra_ip_time` (`form_id`, `ip_hash`, `attempted_at`),
    CONSTRAINT `fk_fra_form` FOREIGN KEY (`form_id`) REFERENCES `form_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
