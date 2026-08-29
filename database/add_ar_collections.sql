-- ============================================================
-- Centryk Business — Receivables: collections log
--
-- A record of every time a customer was chased about an overdue balance
-- (drafted or actually sent). Drives the reminder history on the statement
-- and the "last contacted" column in the collections view.
--
-- Additive + idempotent. Run against centryk_core.
-- ============================================================

USE centryk_core;

CREATE TABLE IF NOT EXISTS ar_reminders (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    company_id  INT UNSIGNED  NOT NULL,
    customer_id INT UNSIGNED  NOT NULL,
    kind        ENUM('statement','due_soon','overdue','final_notice') NOT NULL DEFAULT 'overdue',
    channel     ENUM('email','phone','in_person','other') NOT NULL DEFAULT 'email',
    subject     VARCHAR(190)  NOT NULL DEFAULT '',
    body        TEXT          NULL,
    balance_at  DECIMAL(12,2) NOT NULL DEFAULT 0,
    overdue_at  DECIMAL(12,2) NOT NULL DEFAULT 0,
    sent_at     DATETIME      NULL,       -- NULL = drafted / logged, not marked sent
    created_by  INT UNSIGNED  NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rem_company  (company_id),
    INDEX idx_rem_customer (customer_id),
    CONSTRAINT fk_rem_company  FOREIGN KEY (company_id)  REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_rem_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_rem_creator  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
