-- Expand Centryk Connect from a binary relationship into a managed business
-- partnership with notes and per-connection scopes.
ALTER TABLE company_connections
    ADD COLUMN relationship_type VARCHAR(40) NOT NULL DEFAULT 'partner' AFTER status,
    ADD COLUMN relationship_note TEXT NULL AFTER relationship_type,
    ADD COLUMN can_share_signage TINYINT(1) NOT NULL DEFAULT 1 AFTER relationship_note,
    ADD COLUMN can_request_assets TINYINT(1) NOT NULL DEFAULT 0 AFTER can_share_signage,
    ADD COLUMN can_message_admins TINYINT(1) NOT NULL DEFAULT 0 AFTER can_request_assets,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER responded_at;
