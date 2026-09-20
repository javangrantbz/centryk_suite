-- Forms: the shared results page can optionally show every response (names, phone
-- numbers, emails) so a raffle organiser can read them on a phone. Off by default, and
-- only allowed with a code of 6 digits or more (pin_length records the length, since the
-- code itself is stored hashed). Existing shares keep working as charts-only; their
-- pin_length defaults to 4 so turning responses on forces a new, longer code first.
-- Idempotent (MariaDB). Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_form_results_share_responses.sql

ALTER TABLE `form_results_shares`
    ADD COLUMN IF NOT EXISTS `show_responses` TINYINT(1) NOT NULL DEFAULT 0 AFTER `enabled`,
    ADD COLUMN IF NOT EXISTS `pin_length` TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER `pin_hash`;
