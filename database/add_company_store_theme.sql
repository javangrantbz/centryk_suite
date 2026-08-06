-- Centryk Store theme selection for company profile/store headers.
-- Stores a relative path under public/assets/store_theme, e.g.
-- assets/store_theme/nexal01.png.

USE centryk_core;

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS store_theme VARCHAR(255) NULL AFTER logo;
