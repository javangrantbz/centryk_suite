-- Shared campaign bundles between accepted Centryk Connect companies.
ALTER TABLE company_connections
    ADD COLUMN can_share_campaigns TINYINT(1) NOT NULL DEFAULT 0 AFTER can_share_events;

CREATE TABLE IF NOT EXISTS company_connection_campaign_shares (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    owner_company_id INT UNSIGNED NOT NULL,
    recipient_company_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    summary TEXT NULL,
    offer_text VARCHAR(255) NULL,
    cta_label VARCHAR(80) NULL,
    cta_url VARCHAR(500) NULL,
    starts_on DATE NULL,
    ends_on DATE NULL,
    audience_notes TEXT NULL,
    recipient_notes TEXT NULL,
    status ENUM('pending','accepted','declined','revoked') NOT NULL DEFAULT 'pending',
    created_by_user_id INT UNSIGNED NULL,
    responded_by_user_id INT UNSIGNED NULL,
    responded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cccs_owner (owner_company_id, status, created_at),
    INDEX idx_cccs_recipient (recipient_company_id, status, created_at),
    INDEX idx_cccs_connection (connection_id, created_at),
    CONSTRAINT fk_cccs_connection FOREIGN KEY (connection_id) REFERENCES company_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_cccs_owner_company FOREIGN KEY (owner_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_cccs_recipient_company FOREIGN KEY (recipient_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_cccs_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_cccs_responded_by FOREIGN KEY (responded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
