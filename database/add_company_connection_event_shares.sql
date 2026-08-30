-- Shared event distribution between accepted Centryk Connect companies.
ALTER TABLE company_connections
    ADD COLUMN can_share_events TINYINT(1) NOT NULL DEFAULT 0 AFTER can_share_signage;

CREATE TABLE IF NOT EXISTS company_connection_event_shares (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    owner_company_id INT UNSIGNED NOT NULL,
    recipient_company_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    event_date DATE NOT NULL,
    event_type VARCHAR(40) NOT NULL DEFAULT 'other',
    color VARCHAR(20) NOT NULL DEFAULT 'slate',
    status ENUM('pending','accepted','declined','revoked') NOT NULL DEFAULT 'pending',
    created_by_user_id INT UNSIGNED NULL,
    responded_by_user_id INT UNSIGNED NULL,
    accepted_event_id INT UNSIGNED NULL,
    responded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cces_owner (owner_company_id, status, event_date),
    INDEX idx_cces_recipient (recipient_company_id, status, event_date),
    INDEX idx_cces_connection (connection_id, created_at),
    CONSTRAINT fk_cces_connection FOREIGN KEY (connection_id) REFERENCES company_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_cces_owner_company FOREIGN KEY (owner_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_cces_recipient_company FOREIGN KEY (recipient_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_cces_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_cces_responded_by FOREIGN KEY (responded_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_cces_accepted_event FOREIGN KEY (accepted_event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE events
    ADD COLUMN source_connection_event_share_id INT UNSIGNED NULL AFTER created_by,
    ADD INDEX idx_events_source_connection_share (source_connection_event_share_id),
    ADD CONSTRAINT fk_events_source_connection_share
        FOREIGN KEY (source_connection_event_share_id) REFERENCES company_connection_event_shares(id) ON DELETE SET NULL;
