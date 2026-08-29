-- ============================================================
-- Centryk Business — subscription billing
--
-- One charge row per active subscription per calendar month. All
-- subscriptions bill monthly; an "annual" billing_interval just means the
-- customer committed to a year — the monthly charge is price / 12.
--
-- runCycle() (BillingService) creates the month's charges; a person marks
-- them paid / waived / void from admin-business-billing.php. Wire runCycle
-- to a monthly cron later.
--
-- Additive + idempotent. Run against centryk_core.
-- ============================================================

USE centryk_core;

CREATE TABLE IF NOT EXISTS company_subscription_charges (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT UNSIGNED  NOT NULL,
    company_id      INT UNSIGNED  NOT NULL,
    package_key     VARCHAR(40)   NOT NULL,
    period_start    DATE          NOT NULL,
    period_end      DATE          NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    currency        CHAR(3)       NOT NULL DEFAULT 'BZD',
    status          ENUM('due','paid','waived','void') NOT NULL DEFAULT 'due',
    due_on          DATE          NOT NULL,
    invoice_ref     VARCHAR(120)  NOT NULL DEFAULT '',
    paid_on         DATE          NULL,
    paid_method     VARCHAR(40)   NOT NULL DEFAULT '',
    note            VARCHAR(255)  NOT NULL DEFAULT '',
    created_by      INT UNSIGNED  NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_charge_sub_period (subscription_id, period_start),
    INDEX idx_charge_company (company_id),
    INDEX idx_charge_status (status),
    CONSTRAINT fk_charge_sub     FOREIGN KEY (subscription_id) REFERENCES company_subscriptions(id) ON DELETE CASCADE,
    CONSTRAINT fk_charge_company FOREIGN KEY (company_id)      REFERENCES companies(id)             ON DELETE CASCADE,
    CONSTRAINT fk_charge_creator FOREIGN KEY (created_by)      REFERENCES users(id)                 ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
