-- Allow a Centryk Store listing to appear to both company members and
-- the wider Centryk Market.

USE centryk_core;

ALTER TABLE store_listings
    MODIFY audience ENUM('employee','market','both') NOT NULL DEFAULT 'employee';
