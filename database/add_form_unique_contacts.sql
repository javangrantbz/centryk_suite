-- Forms: optional "one entry per phone number / email" per form. When on, a
-- response is rejected if the same phone number or email address (normalised) was
-- already submitted to that form. Off by default. Idempotent (MariaDB). Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_form_unique_contacts.sql

ALTER TABLE `form_forms`
    ADD COLUMN IF NOT EXISTS `unique_contacts` TINYINT(1) NOT NULL DEFAULT 0 AFTER `one_response_per_person`;
