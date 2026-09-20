-- Forms: optionally send diners to the company's Facebook page (fb_recommend_url)
-- a few seconds after they submit, so they can like the page and leave a review.
-- Idempotent (MariaDB). Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_form_fb_redirect.sql

ALTER TABLE `form_forms`
    ADD COLUMN IF NOT EXISTS `fb_auto_redirect` TINYINT(1) NOT NULL DEFAULT 0 AFTER `fb_recommend_url`;
