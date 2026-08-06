-- Link Centryk Store listings back to source inventory records.

USE centryk_core;

ALTER TABLE store_listings
    ADD COLUMN IF NOT EXISTS source_app VARCHAR(40) NULL AFTER company_id,
    ADD COLUMN IF NOT EXISTS source_item_id BIGINT UNSIGNED NULL AFTER source_app,
    ADD UNIQUE KEY IF NOT EXISTS uniq_store_listing_source (company_id, source_app, source_item_id),
    ADD KEY IF NOT EXISTS idx_store_listing_source (source_app, source_item_id);
