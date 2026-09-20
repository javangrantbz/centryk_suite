-- Very short public form links: /r/<short_code> (routed by the root .htaccess to
-- public/review-link.php). Unlike the per-company /review/<company>/<name> link,
-- the code is unique across ALL companies. Generated automatically (6 characters)
-- the first time a form's builder is opened; a company can rename it.
--
-- Idempotent (MariaDB). Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_form_short_code.sql

ALTER TABLE `form_forms`
    ADD COLUMN IF NOT EXISTS `short_code` VARCHAR(30) NULL AFTER `slug`;

ALTER TABLE `form_forms`
    ADD UNIQUE INDEX IF NOT EXISTS `uq_form_short_code` (`short_code`);
