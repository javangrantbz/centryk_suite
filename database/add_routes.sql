-- ============================================================
-- Centryk Business — Field Sales & Routes package
--
-- Delivery runs, per-stop collections, and end-of-day driver settlement
-- (expected vs declared cash, variance) so a company can see how much cash
-- is on its trucks and whether every dollar is accounted for.
--
-- Per-stop collections post a receipt through the Receivables ledger, so
-- this expects add_receivables.sql to have run. Additive + idempotent.
-- Run against centryk_core.
-- ============================================================

USE centryk_core;

-- Routes v1 is an in-hub page, not a separate spoke app yet.
UPDATE business_packages SET is_app = 0, app_key = '' WHERE `key` = 'routes';

CREATE TABLE IF NOT EXISTS routes (
    id                     INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    company_id             INT UNSIGNED  NOT NULL,
    name                   VARCHAR(120)  NOT NULL,
    notes                  VARCHAR(255)  NOT NULL DEFAULT '',
    default_driver_name    VARCHAR(120)  NOT NULL DEFAULT '',
    default_driver_user_id INT UNSIGNED  NULL,
    status                 ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by             INT UNSIGNED  NULL,
    created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_routes_company (company_id),
    CONSTRAINT fk_routes_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_routes_driver  FOREIGN KEY (default_driver_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_routes_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_trips (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    company_id       INT UNSIGNED  NOT NULL,
    route_id         INT UNSIGNED  NOT NULL,
    trip_date        DATE          NOT NULL,
    driver_name      VARCHAR(120)  NOT NULL DEFAULT '',
    driver_user_id   INT UNSIGNED  NULL,
    status           ENUM('planned','out','settling','settled') NOT NULL DEFAULT 'planned',
    cash_expected    DECIMAL(12,2) NOT NULL DEFAULT 0,   -- recomputed from stops
    electronic_total DECIMAL(12,2) NOT NULL DEFAULT 0,   -- recomputed from stops
    cash_declared    DECIMAL(12,2) NULL,
    cash_variance    DECIMAL(12,2) NULL,                 -- declared - expected
    notes            VARCHAR(255)  NOT NULL DEFAULT '',
    settled_by       INT UNSIGNED  NULL,
    settled_at       DATETIME      NULL,
    created_by       INT UNSIGNED  NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_trip_route_date (route_id, trip_date),
    INDEX idx_trips_company_status (company_id, status),
    CONSTRAINT fk_trips_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_trips_route   FOREIGN KEY (route_id)   REFERENCES routes(id)    ON DELETE CASCADE,
    CONSTRAINT fk_trips_driver  FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_trips_settler FOREIGN KEY (settled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_stops (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    trip_id          INT UNSIGNED  NOT NULL,
    company_id       INT UNSIGNED  NOT NULL,
    customer_id      INT UNSIGNED  NULL,
    customer_name    VARCHAR(150)  NOT NULL DEFAULT '',
    seq              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status           ENUM('pending','delivered','paid','skipped') NOT NULL DEFAULT 'pending',
    amount_collected DECIMAL(12,2) NOT NULL DEFAULT 0,
    method           ENUM('cash','card','bank_transfer','xfer','cheque','none') NOT NULL DEFAULT 'none',
    ar_payment_id    INT UNSIGNED  NULL,
    note             VARCHAR(255)  NOT NULL DEFAULT '',
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stops_trip (trip_id),
    CONSTRAINT fk_stops_trip     FOREIGN KEY (trip_id)     REFERENCES route_trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_stops_company  FOREIGN KEY (company_id)  REFERENCES companies(id)   ON DELETE CASCADE,
    CONSTRAINT fk_stops_customer FOREIGN KEY (customer_id) REFERENCES customers(id)   ON DELETE SET NULL,
    CONSTRAINT fk_stops_arpay    FOREIGN KEY (ar_payment_id) REFERENCES ar_payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
