-- Assign the default Store/profile banner to companies without a theme.
-- Repeatable and safe for existing companies that already selected a theme.

USE centryk_core;

UPDATE companies
SET store_theme = 'assets/store_theme/default01.png'
WHERE store_theme IS NULL OR store_theme = '';
