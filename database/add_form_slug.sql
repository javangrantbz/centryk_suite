-- Short public review links: /review/<company-slug>/<form-slug> (routed by the
-- root .htaccess to public/review-link.php). The company part is the existing
-- companies.store_slug; this adds the per-form part, unique within a company.
-- Filled in automatically from the title the first time the builder opens a form.
--
-- Idempotent (MariaDB). Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_form_slug.sql

ALTER TABLE `form_forms`
    ADD COLUMN IF NOT EXISTS `slug` VARCHAR(80) NULL AFTER `share_token`;

ALTER TABLE `form_forms`
    ADD UNIQUE INDEX IF NOT EXISTS `uq_form_slug` (`company_id`, `slug`);
