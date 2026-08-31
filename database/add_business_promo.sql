-- ============================================================
-- Centryk Business — free preview promo
--
-- Adds a 'promo' source so a company can opt into the limited-time free
-- preview (all packages, expiring 2027-12-31) from business.php. A promo
-- entitlement is an ordinary active grant with an expiry — every existing
-- level() check keeps working; the promo is only recognised specially for
-- the on-page notice (Entitlements::promoInfo).
--
-- Additive + idempotent. Run against centryk_core.
-- ============================================================

USE centryk_core;

ALTER TABLE company_entitlements
    MODIFY COLUMN source ENUM('admin_grant','trial','migration','promo')
    NOT NULL DEFAULT 'admin_grant';
