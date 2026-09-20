-- Forms: a custom message printed on the QR cards (replaces the fixed
-- "Scan to tell us what you think" when set). Idempotent (MariaDB). Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_form_qr_message.sql

ALTER TABLE `form_forms`
    ADD COLUMN IF NOT EXISTS `qr_message` VARCHAR(200) NOT NULL DEFAULT '' AFTER `fb_auto_redirect`;
