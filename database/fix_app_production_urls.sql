-- Fix wrong production SSO URLs for OnePay and MyPay.
-- The original seed pointed these at *.centryk.com subdomains that no longer
-- resolve; the live apps are served from onepay.bz and mypay.bz. launchApp()
-- redirects to url_production on the live server, so users were being sent to
-- dead domains.
--
-- Run via phpMyAdmin (or `mysql`) against the production centryk_core database.
-- Idempotent: safe to run multiple times.

UPDATE apps
   SET url_production = 'https://onepay.bz/sso.php'
 WHERE `key` = 'onepay';

UPDATE apps
   SET url_production = 'https://mypay.bz/sso.php'
 WHERE `key` = 'mypay';

-- Verify
SELECT `key`, url_local, url_production FROM apps WHERE `key` IN ('onepay', 'mypay');
