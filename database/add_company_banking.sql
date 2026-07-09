-- Company settlement banking + card-acceptance requests.
--
-- company_bank_accounts: the company's OWN bank account (where OneLink settles
--   their money). Set by the company admin themselves. One row per company.
-- banking_requests: a company admin's request for a Centryk platform admin to
--   set up OneLink card acceptance for their company.

CREATE TABLE IF NOT EXISTS company_bank_accounts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id     INT UNSIGNED NOT NULL,
    bank_name      VARCHAR(120) NOT NULL DEFAULT '',
    account_holder VARCHAR(160) NOT NULL DEFAULT '',
    account_number VARCHAR(64)  NOT NULL DEFAULT '',
    branch         VARCHAR(120) NOT NULL DEFAULT '',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_company_bank (company_id),
    CONSTRAINT fk_bank_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banking_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id   INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    status       ENUM('pending','done','dismissed') NOT NULL DEFAULT 'pending',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bankreq_company (company_id),
    INDEX idx_bankreq_status (status),
    CONSTRAINT fk_bankreq_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
