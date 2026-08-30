-- Lightweight admin-to-admin messages for accepted Centryk Connect relationships.
CREATE TABLE IF NOT EXISTS company_connection_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    sender_company_id INT UNSIGNED NOT NULL,
    recipient_company_id INT UNSIGNED NOT NULL,
    sender_user_id INT UNSIGNED NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ccm_connection_created (connection_id, created_at),
    INDEX idx_ccm_recipient_created (recipient_company_id, created_at),
    INDEX idx_ccm_sender_created (sender_company_id, created_at),
    CONSTRAINT fk_ccm_connection FOREIGN KEY (connection_id) REFERENCES company_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccm_sender_company FOREIGN KEY (sender_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccm_recipient_company FOREIGN KEY (recipient_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_ccm_sender_user FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
