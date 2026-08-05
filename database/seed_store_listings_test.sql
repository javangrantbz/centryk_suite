-- Repeatable test inventory for Centryk Store.
-- Deletes only rows created by this seed, then recreates listings for the
-- active directory businesses in the local test database.

USE centryk_core;

DELETE FROM store_listings
WHERE sku LIKE 'TEST-%';

SET @blue_skies_id := (
    SELECT id FROM companies
    WHERE uuid = '9cec00ad-5f7a-449a-9ac4-544382443208'
    LIMIT 1
);

SET @j_bells_id := (
    SELECT id FROM companies
    WHERE uuid = '52120784-509f-11f1-ba2e-a44cc840fb88'
    LIMIT 1
);

SET @miss_bella_id := (
    SELECT id FROM companies
    WHERE uuid = '02be9149-172b-422b-96f8-65633685cecf'
    LIMIT 1
);

INSERT INTO store_listings
    (company_id, title, sku, price, summary, audience, enabled)
SELECT @blue_skies_id, 'School Exercise Book Pack', 'TEST-BLUE-001', '$12.50',
       'Five ruled exercise books for classwork and homework.', 'market', 1
WHERE @blue_skies_id IS NOT NULL;

INSERT INTO store_listings
    (company_id, title, sku, price, summary, audience, enabled)
SELECT @blue_skies_id, 'Student ID Replacement', 'TEST-BLUE-002', '$8.00',
       'Replacement printed ID card for registered students.', 'employee', 1
WHERE @blue_skies_id IS NOT NULL;

INSERT INTO store_listings
    (company_id, title, sku, price, summary, audience, enabled)
SELECT @blue_skies_id, 'Blue Polo Uniform Shirt', 'TEST-BLUE-003', '$24.00',
       'Short-sleeve school polo shirt in youth and adult sizes.', 'market', 1
WHERE @blue_skies_id IS NOT NULL;

INSERT INTO store_listings
    (company_id, title, sku, price, summary, audience, enabled)
SELECT @j_bells_id, 'Grocery Starter Basket', 'TEST-JBELLS-001', '$35.00',
       'Rice, flour, beans, sugar, and cooking oil for household restocking.', 'market', 1
WHERE @j_bells_id IS NOT NULL;

INSERT INTO store_listings
    (company_id, title, sku, price, summary, audience, enabled)
SELECT @j_bells_id, 'Fresh Produce Bundle', 'TEST-JBELLS-002', '$18.00',
       'Seasonal fruits and vegetables packed for same-day pickup.', 'market', 1
WHERE @j_bells_id IS NOT NULL;

INSERT INTO store_listings
    (company_id, title, sku, price, summary, audience, enabled)
SELECT @j_bells_id, 'Staff Meal Credit', 'TEST-JBELLS-003', '$10.00',
       'Internal meal credit for approved team members.', 'employee', 1
WHERE @j_bells_id IS NOT NULL;

INSERT INTO store_listings
    (company_id, title, sku, price, summary, audience, enabled)
SELECT @miss_bella_id, 'Everyday Essentials Kit', 'TEST-MBELLA-001', '$22.00',
       'Soap, toothpaste, detergent, and paper goods for weekly shopping.', 'market', 1
WHERE @miss_bella_id IS NOT NULL;

INSERT INTO store_listings
    (company_id, title, sku, price, summary, audience, enabled)
SELECT @miss_bella_id, 'Gift Wrap Service', 'TEST-MBELLA-002', '$5.00',
       'Gift bag, tissue, ribbon, and quick wrapping for store purchases.', 'market', 1
WHERE @miss_bella_id IS NOT NULL;

INSERT INTO store_listings
    (company_id, title, sku, price, summary, audience, enabled)
SELECT @miss_bella_id, 'Team Discount Voucher', 'TEST-MBELLA-003', '$15.00',
       'Internal voucher for staff purchase testing.', 'employee', 1
WHERE @miss_bella_id IS NOT NULL;
