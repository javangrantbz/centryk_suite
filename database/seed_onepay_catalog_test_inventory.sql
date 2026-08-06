-- Repeatable OnePay catalog inventory for Centryk Store testing.
-- Deletes only rows created by this seed, then recreates active catalog items
-- for the linked OnePay stores.

USE onepay;

DELETE FROM catalog_items
WHERE sku LIKE 'TEST-OP-%';

INSERT INTO catalog_items
    (store_id, item_type, name, description, sku, price, track_inventory, stock_qty, active)
SELECT s.id, 'product', 'School Exercise Book Pack',
       'Five ruled exercise books for classwork and homework.',
       'TEST-OP-BLUE-001', 12.50, 1, 40, 1
FROM stores s
JOIN companies c ON c.id = s.company_id
WHERE c.centryk_uuid = '9cec00ad-5f7a-449a-9ac4-544382443208'
LIMIT 1;

INSERT INTO catalog_items
    (store_id, item_type, name, description, sku, price, track_inventory, stock_qty, active)
SELECT s.id, 'service', 'Student ID Replacement',
       'Replacement printed ID card for registered students.',
       'TEST-OP-BLUE-002', 8.00, 0, 0, 1
FROM stores s
JOIN companies c ON c.id = s.company_id
WHERE c.centryk_uuid = '9cec00ad-5f7a-449a-9ac4-544382443208'
LIMIT 1;

INSERT INTO catalog_items
    (store_id, item_type, name, description, sku, price, track_inventory, stock_qty, active)
SELECT s.id, 'product', 'Blue Polo Uniform Shirt',
       'Short-sleeve school polo shirt in youth and adult sizes.',
       'TEST-OP-BLUE-003', 24.00, 1, 28, 1
FROM stores s
JOIN companies c ON c.id = s.company_id
WHERE c.centryk_uuid = '9cec00ad-5f7a-449a-9ac4-544382443208'
LIMIT 1;

INSERT INTO catalog_items
    (store_id, item_type, name, description, sku, price, track_inventory, stock_qty, active)
SELECT s.id, 'product', 'Grocery Starter Basket',
       'Rice, flour, beans, sugar, and cooking oil for household restocking.',
       'TEST-OP-JBELLS-001', 35.00, 1, 18, 1
FROM stores s
JOIN companies c ON c.id = s.company_id
WHERE c.centryk_uuid = '52120784-509f-11f1-ba2e-a44cc840fb88'
LIMIT 1;

INSERT INTO catalog_items
    (store_id, item_type, name, description, sku, price, track_inventory, stock_qty, active)
SELECT s.id, 'product', 'Fresh Produce Bundle',
       'Seasonal fruits and vegetables packed for same-day pickup.',
       'TEST-OP-JBELLS-002', 18.00, 1, 25, 1
FROM stores s
JOIN companies c ON c.id = s.company_id
WHERE c.centryk_uuid = '52120784-509f-11f1-ba2e-a44cc840fb88'
LIMIT 1;

INSERT INTO catalog_items
    (store_id, item_type, name, description, sku, price, track_inventory, stock_qty, active)
SELECT s.id, 'service', 'Staff Meal Credit',
       'Internal meal credit for approved team members.',
       'TEST-OP-JBELLS-003', 10.00, 0, 0, 1
FROM stores s
JOIN companies c ON c.id = s.company_id
WHERE c.centryk_uuid = '52120784-509f-11f1-ba2e-a44cc840fb88'
LIMIT 1;

INSERT INTO catalog_items
    (store_id, item_type, name, description, sku, price, track_inventory, stock_qty, active)
SELECT s.id, 'product', 'Everyday Essentials Kit',
       'Soap, toothpaste, detergent, and paper goods for weekly shopping.',
       'TEST-OP-MBELLA-001', 22.00, 1, 32, 1
FROM stores s
JOIN companies c ON c.id = s.company_id
WHERE c.centryk_uuid = '02be9149-172b-422b-96f8-65633685cecf'
LIMIT 1;

INSERT INTO catalog_items
    (store_id, item_type, name, description, sku, price, track_inventory, stock_qty, active)
SELECT s.id, 'service', 'Gift Wrap Service',
       'Gift bag, tissue, ribbon, and quick wrapping for store purchases.',
       'TEST-OP-MBELLA-002', 5.00, 0, 0, 1
FROM stores s
JOIN companies c ON c.id = s.company_id
WHERE c.centryk_uuid = '02be9149-172b-422b-96f8-65633685cecf'
LIMIT 1;

INSERT INTO catalog_items
    (store_id, item_type, name, description, sku, price, track_inventory, stock_qty, active)
SELECT s.id, 'product', 'Countertop Card Reader',
       'Compact payment reader for checkout counters.',
       'TEST-OP-BHI-001', 310.00, 1, 6, 1
FROM stores s
JOIN companies c ON c.id = s.company_id
WHERE c.centryk_uuid = '521239cb-509f-11f1-ba2e-a44cc840fb88'
LIMIT 1;
