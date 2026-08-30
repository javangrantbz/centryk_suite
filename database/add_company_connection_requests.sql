-- Lightweight partner request workflow for accepted Centryk Connect relationships.
CREATE TABLE IF NOT EXISTS company_connection_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    requester_company_id INT UNSIGNED NOT NULL,
    recipient_company_id INT UNSIGNED NOT NULL,
    request_type VARCHAR(40) NOT NULL DEFAULT 'general',
    subject VARCHAR(160) NOT NULL,
    details TEXT NULL,
    status ENUM('open','fulfilled','declined') NOT NULL DEFAULT 'open',
    created_by_user_id INT UNSIGNED NULL,
    handled_by_user_id INT UNSIGNED NULL,
    handled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ccr_recipient (recipient_company_id, status, created_at),
    INDEX idx_ccr_requester (requester_company_id, status, created_at),
    INDEX idx_ccr_connection (connection_id, created_at),
    CONSTRAINT fk_ccr_connection FOREIGN KEY (connection_id) REFERENCES company_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccr_requester_company FOREIGN KEY (requester_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccr_recipient_company FOREIGN KEY (recipient_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccr_created_by_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ccr_handled_by_user FOREIGN KEY (handled_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
