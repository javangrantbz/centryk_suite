USE centryk_core;

CREATE TABLE IF NOT EXISTS email_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient  VARCHAR(190) NOT NULL,
    type       VARCHAR(60)  NOT NULL DEFAULT '',
    subject    VARCHAR(255) NOT NULL DEFAULT '',
    status     ENUM('sent','logged','failed') NOT NULL,
    error      TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient (recipient),
    INDEX idx_type (type),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
);
