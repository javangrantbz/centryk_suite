-- Centryk Forms — reviews & ratings: themes, share-consent moderation, Facebook Page posting.
--
-- Everything is per company: any company can turn a form into a review/ratings
-- collector, moderate what customers submit, and (optionally) post approved
-- reviews to its own Facebook Page.
--
--   form_forms     : theme preset, review-sharing switch, optional
--                    "recommend us on Facebook" link shown after submitting
--   form_responses : the respondent's share consent + first name, a moderation
--                    state, the text that would be posted, and Facebook post result
--   form_facebook_pages : one connected Facebook Page per company
--
-- Idempotent (MariaDB). Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_forms_reviews_facebook.sql

ALTER TABLE `form_forms`
    ADD COLUMN IF NOT EXISTS `theme`             VARCHAR(30)  NOT NULL DEFAULT 'default' AFTER `confirmation_message`,
    ADD COLUMN IF NOT EXISTS `reviews_enabled`   TINYINT(1)   NOT NULL DEFAULT 0 AFTER `theme`,
    ADD COLUMN IF NOT EXISTS `fb_recommend_url`  VARCHAR(500) NOT NULL DEFAULT '' AFTER `reviews_enabled`;

ALTER TABLE `form_responses`
    ADD COLUMN IF NOT EXISTS `share_consent`     TINYINT(1)   NOT NULL DEFAULT 0 AFTER `respondent_key`,
    ADD COLUMN IF NOT EXISTS `display_name`      VARCHAR(60)  NOT NULL DEFAULT '' AFTER `share_consent`,
    ADD COLUMN IF NOT EXISTS `moderation_status` ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' AFTER `display_name`,
    ADD COLUMN IF NOT EXISTS `flagged`           TINYINT(1)   NOT NULL DEFAULT 0 AFTER `moderation_status`,
    ADD COLUMN IF NOT EXISTS `post_text`         TEXT NULL AFTER `flagged`,
    ADD COLUMN IF NOT EXISTS `moderated_by`      INT NULL AFTER `post_text`,
    ADD COLUMN IF NOT EXISTS `moderated_at`      DATETIME NULL AFTER `moderated_by`,
    ADD COLUMN IF NOT EXISTS `fb_post_id`        VARCHAR(100) NULL AFTER `moderated_at`,
    ADD COLUMN IF NOT EXISTS `fb_posted_at`      DATETIME NULL AFTER `fb_post_id`,
    ADD COLUMN IF NOT EXISTS `fb_error`          VARCHAR(255) NULL AFTER `fb_posted_at`;

-- Moderation queue lookup: a form's responses by state.
ALTER TABLE `form_responses`
    ADD INDEX IF NOT EXISTS `idx_fr_moderation` (`form_id`, `moderation_status`, `submitted_at`);

CREATE TABLE IF NOT EXISTS `form_facebook_pages` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`   INT NOT NULL,
    `page_id`      VARCHAR(40)  NOT NULL,
    `page_name`    VARCHAR(200) NOT NULL DEFAULT '',
    `access_token` TEXT NOT NULL,
    `connected_by` INT NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ffp_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
