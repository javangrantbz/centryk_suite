-- Per-company OneLink payment gateway credentials.
--
-- Each company that collects payments through OneLink stores its own
-- terminal id, salt and token here. Managed from the Centryk profile page
-- under Connected Apps → Banking. One row per company.
CREATE TABLE IF NOT EXISTS onelink_credentials (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id  INT UNSIGNED NOT NULL,
    base_url    VARCHAR(255) NOT NULL DEFAULT 'https://op.onelink.bz',
    terminal_id VARCHAR(64)  NOT NULL DEFAULT '',
    salt        VARCHAR(191) NOT NULL DEFAULT '',
    token       VARCHAR(255) NOT NULL DEFAULT '',
    enabled     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_onelink_company (company_id),
    CONSTRAINT fk_onelink_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
