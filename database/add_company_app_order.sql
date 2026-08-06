CREATE TABLE IF NOT EXISTS company_app_order (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    app_key    VARCHAR(40) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_company_app_order (company_id, app_key),
    KEY idx_company_app_order (company_id, sort_order),
    CONSTRAINT fk_company_app_order_company
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_company_app_order_user
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);
