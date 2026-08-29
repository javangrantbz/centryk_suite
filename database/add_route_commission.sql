-- ============================================================
-- Centryk Business — Field Sales & Routes: per-driver commission
--
-- A company defines commission rules; commission is computed from settled
-- trips. Rules resolve most-specific-first: a rule for the driver wins over a
-- rule for the route, which wins over the company default.
--
--   basis = collections_total       -> rate% of (cash + electronic) collected
--         = collections_cash        -> rate% of cash collected
--         = collections_electronic  -> rate% of electronic collected
--         = stops_delivered         -> flat 'rate' BZD per delivered/paid stop
--
-- Additive + idempotent. Run against centryk_core BEFORE pulling the code.
-- ============================================================

USE centryk_core;

CREATE TABLE IF NOT EXISTS route_commission_rules (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      INT UNSIGNED NOT NULL,
    scope           ENUM('company','route','driver') NOT NULL,
    route_id        INT UNSIGNED NULL,
    driver_user_id  INT UNSIGNED NULL,
    basis           ENUM('collections_total','collections_cash','collections_electronic','stops_delivered')
                        NOT NULL DEFAULT 'collections_total',
    rate            DECIMAL(10,4) NOT NULL,
    note            VARCHAR(255) NOT NULL DEFAULT '',
    active          TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rcr_lookup (company_id, active, scope),
    KEY idx_rcr_route (route_id),
    KEY idx_rcr_driver (driver_user_id),
    CONSTRAINT fk_rcr_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT fk_rcr_route   FOREIGN KEY (route_id)   REFERENCES routes (id)    ON DELETE CASCADE,
    CONSTRAINT fk_rcr_driver  FOREIGN KEY (driver_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_rcr_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
