-- Centryk Connect: mutual company-to-company connections, gating
-- cross-company features like Vision Board playlist sharing.
CREATE TABLE IF NOT EXISTS company_connections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_company_id INT UNSIGNED NOT NULL,
    recipient_company_id INT UNSIGNED NOT NULL,
    status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_company_connection (requester_company_id, recipient_company_id),
    FOREIGN KEY (requester_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_conn_recipient (recipient_company_id, status),
    INDEX idx_conn_requester (requester_company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
