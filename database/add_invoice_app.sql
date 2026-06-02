-- Invoice app integrated into Centryk (company-scoped).
-- Table names kept as the app's code uses them (also avoids risky renames of
-- route-key words). Scoped by company_id (FK -> companies) instead of user_id.
-- Settings move from the old per-user `users` columns to invoice_settings.
-- Run via phpMyAdmin against centryk_core.
--
-- NOTE: these table names (customers, invoices, documents, payments…) are
-- generic; if Centryk later needs its own such tables, prefix these then.

CREATE TABLE IF NOT EXISTS invoice_settings (
    company_id          INT UNSIGNED NOT NULL,
    business_name       VARCHAR(150) NULL,
    business_email      VARCHAR(150) NULL,
    business_phone      VARCHAR(50)  NULL,
    business_address    TEXT         NULL,
    business_tax_number VARCHAR(100) NULL,
    business_logo       VARCHAR(255) NULL,
    logo_position       VARCHAR(10)  NOT NULL DEFAULT 'left',
    currency_symbol     VARCHAR(10)  NOT NULL DEFAULT '$',
    invoice_terms       TEXT         NULL,
    quote_terms         TEXT         NULL,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    CONSTRAINT fk_invoice_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    name       VARCHAR(150) NOT NULL,
    company    VARCHAR(150) NULL,
    email      VARCHAR(150) NULL,
    phone      VARCHAR(50)  NULL,
    address    TEXT         NULL,
    tax_number VARCHAR(100) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customers_company (company_id),
    CONSTRAINT fk_customers_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id     INT UNSIGNED NOT NULL,
    customer_id    INT UNSIGNED NOT NULL,
    invoice_number VARCHAR(50)  NOT NULL,
    quote_id       INT UNSIGNED NULL,
    status         ENUM('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
    issue_date     DATE         NOT NULL,
    due_date       DATE         NULL,
    subtotal       DECIMAL(10,2) DEFAULT 0.00,
    tax            DECIMAL(10,2) DEFAULT 0.00,
    discount       DECIMAL(10,2) DEFAULT 0.00,
    total          DECIMAL(10,2) DEFAULT 0.00,
    amount_paid    DECIMAL(10,2) DEFAULT 0.00,
    notes          TEXT          NULL,
    share_token    CHAR(40)      NULL,
    created_by     INT UNSIGNED  NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoices_share (share_token),
    INDEX idx_invoices_company (company_id),
    CONSTRAINT fk_invoices_company  FOREIGN KEY (company_id)  REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    description TEXT        NOT NULL,
    quantity   DECIMAL(10,2) DEFAULT 1.00,
    unit_price DECIMAL(10,2) DEFAULT 0.00,
    total      DECIMAL(10,2) DEFAULT 0.00,
    INDEX idx_invoice_items_invoice (invoice_id),
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id   INT UNSIGNED NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,
    payment_date DATE          NOT NULL,
    method       VARCHAR(100)  NULL,
    notes        TEXT          NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payments_invoice (invoice_id),
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotes (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id   INT UNSIGNED NOT NULL,
    customer_id  INT UNSIGNED NOT NULL,
    quote_number VARCHAR(50)  NOT NULL,
    status       ENUM('draft','sent','accepted','rejected','expired') DEFAULT 'draft',
    issue_date   DATE         NOT NULL,
    expiry_date  DATE         NULL,
    subtotal     DECIMAL(10,2) DEFAULT 0.00,
    tax          DECIMAL(10,2) DEFAULT 0.00,
    discount     DECIMAL(10,2) DEFAULT 0.00,
    total        DECIMAL(10,2) DEFAULT 0.00,
    notes        TEXT          NULL,
    share_token  CHAR(40)      NULL,
    created_by   INT UNSIGNED  NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quotes_share (share_token),
    INDEX idx_quotes_company (company_id),
    CONSTRAINT fk_quotes_company  FOREIGN KEY (company_id)  REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_quotes_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_id   INT UNSIGNED NOT NULL,
    description TEXT        NOT NULL,
    quantity   DECIMAL(10,2) DEFAULT 1.00,
    unit_price DECIMAL(10,2) DEFAULT 0.00,
    total      DECIMAL(10,2) DEFAULT 0.00,
    INDEX idx_quote_items_quote (quote_id),
    CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id  INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    invoice_id  INT UNSIGNED NULL,
    quote_id    INT UNSIGNED NULL,
    title       VARCHAR(150) NOT NULL,
    file_name   VARCHAR(255) NOT NULL,
    file_path   VARCHAR(255) NOT NULL,
    file_type   VARCHAR(100) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_documents_company (company_id),
    CONSTRAINT fk_documents_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Register the Invoices app (built-in, like Calendar) ──────────────────────
INSERT INTO apps (`key`, label, description, url_local, url_production, icon, color, sort_order, opt_in)
VALUES (
    'invoice', 'Invoices', 'Create and track invoices & quotes',
    'http://localhost/centryk/public/invoice-maker/',
    'https://centryk.net/invoice-maker/',
    '🧾', '#6366f1', 4, 0
)
ON DUPLICATE KEY UPDATE
    label = VALUES(label), description = VALUES(description),
    url_local = VALUES(url_local), url_production = VALUES(url_production),
    icon = VALUES(icon), color = VALUES(color), sort_order = VALUES(sort_order), opt_in = VALUES(opt_in);

INSERT IGNORE INTO user_app_access (user_id, app_id)
SELECT u.id, a.id FROM users u, apps a WHERE a.`key` = 'invoice';
