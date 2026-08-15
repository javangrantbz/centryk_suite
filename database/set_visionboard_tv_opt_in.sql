-- ============================================================
-- Vision Board and Centryk TV are now real onboarding choices
-- (public/onboarding.php) instead of being auto-granted to every
-- company member via AuthService::syncCompanyAppAccess() (removed).
-- Marking them opt_in=1 lets anyone who skipped them at onboarding
-- self-enable later from the dashboard's "other apps" section, the
-- same flow already used by other opt-in apps.
--
-- Run via phpMyAdmin against centryk_core. Safe to re-run.
-- ============================================================

USE centryk_core;

UPDATE apps SET opt_in = 1 WHERE `key` IN ('visionboard', 'tv');
