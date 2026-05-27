USE centryk_core;

CREATE TABLE IF NOT EXISTS account_requests (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(180) NOT NULL,
    company    VARCHAR(180) NOT NULL,
    status     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email  (email),
    INDEX idx_status (status)
);
