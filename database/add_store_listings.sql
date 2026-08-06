-- Centryk Store listings.
-- Audience controls whether a listing is available only to active company
-- members, the wider Centryk Market, or both.

USE centryk_core;

CREATE TABLE IF NOT EXISTS store_listings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id  INT UNSIGNED NOT NULL,
    source_app  VARCHAR(40) NULL,
    source_item_id BIGINT UNSIGNED NULL,
    title       VARCHAR(180) NOT NULL,
    sku         VARCHAR(80) NULL,
    price       VARCHAR(40) NULL,
    summary     TEXT NULL,
    audience    ENUM('employee','market','both') NOT NULL DEFAULT 'employee',
    enabled     TINYINT(1) NOT NULL DEFAULT 1,
    starts_at   DATETIME NULL,
    ends_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_store_company_audience (company_id, enabled, audience),
    KEY idx_store_listing_source (source_app, source_item_id),
    UNIQUE KEY uniq_store_listing_source (company_id, source_app, source_item_id),
    CONSTRAINT fk_store_listings_company
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
