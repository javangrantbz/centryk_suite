-- Cache source item image URLs on Centryk Store listings.

USE centryk_core;

ALTER TABLE store_listings
    ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NULL AFTER summary;
