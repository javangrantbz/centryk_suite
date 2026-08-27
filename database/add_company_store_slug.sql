-- Short, memorable public-store links: centryk.bz/s/<store_slug> (routed by the
-- root .htaccess to public/store-link.php, which 301s to the canonical
-- store.php?company_uuid=... URL). Slug is derived from the company name
-- (lowercased, apostrophes dropped, everything else -> hyphen) and is globally
-- unique because /s/<slug> is one flat namespace. Filled lazily on first store
-- view (app/services/StoreLink.php); backfill existing companies by visiting
-- public/admin-backfill-store-slugs.php once as a platform admin.
ALTER TABLE companies
    ADD COLUMN store_slug VARCHAR(64) NULL UNIQUE AFTER uuid;
