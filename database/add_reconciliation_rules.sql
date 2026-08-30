-- ============================================================
-- Centryk Business — Reconciliation: auto-ignore rules
--
-- Finance defines rules that automatically set recurring bank-statement noise
-- (bank charges, interest, inter-account transfers) to 'ignored' so it never
-- clutters the matching queue. Rules apply on import and can be re-run on
-- demand against the existing backlog. A rule matches a line when EVERY
-- condition it specifies is true.
--
-- Additive + idempotent. Run against centryk_core BEFORE pulling the code.
-- ============================================================

USE centryk_core;

CREATE TABLE IF NOT EXISTS reconciliation_rules (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id       INT UNSIGNED NOT NULL,
    description_like VARCHAR(190) NOT NULL DEFAULT '',
    reference_like   VARCHAR(190) NOT NULL DEFAULT '',
    amount_exact     DECIMAL(12,2) NULL,
    direction        ENUM('any','credit','debit') NOT NULL DEFAULT 'any',
    note             VARCHAR(255) NOT NULL DEFAULT '',
    active           TINYINT(1) NOT NULL DEFAULT 1,
    hits             INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit_at      DATETIME NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recrule_company (company_id, active),
    CONSTRAINT fk_recrule_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT fk_recrule_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
