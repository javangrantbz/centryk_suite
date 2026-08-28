-- ============================================================
-- Centryk Business — paid tier: packages, subscriptions, entitlements
--
-- What this does:
--   1. business_packages         — catalog of purchasable capability packages
--   2. company_subscriptions     — the commercial / billing agreement per company+package
--   3. company_entitlements      — the ENFORCEMENT record: what is live for a company
--   4. business_package_requests — inbound "Explore more services" leads
--
-- The free core is untouched. There is NO backfill: existing companies get zero
-- rows here, and the absence of an `active` company_entitlements row IS
-- "not entitled". Only companies a Centryk admin explicitly grants get access.
--
-- Run via phpMyAdmin against centryk_core. Additive + idempotent
-- (IF NOT EXISTS guards; seed uses ON DUPLICATE KEY UPDATE).
-- ============================================================

USE centryk_core;

-- ── 1. Catalog (admin-managed, mirrors `apps`) ────────────────────────────
CREATE TABLE IF NOT EXISTS business_packages (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `key`         VARCHAR(40)   NOT NULL,                 -- stable identifier used by code
    label         VARCHAR(80)   NOT NULL,
    description   VARCHAR(255)  NOT NULL DEFAULT '',
    monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0,       -- indicative only; the paid amount is snapshotted on the subscription
    currency      CHAR(3)       NOT NULL DEFAULT 'BZD',
    is_app        TINYINT(1)    NOT NULL DEFAULT 0,       -- 1 = granting this also provisions a spoke app
    app_key       VARCHAR(40)   NOT NULL DEFAULT '',      -- apps.key when is_app = 1
    sort_order    INT           NOT NULL DEFAULT 0,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bpkg_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO business_packages (`key`, label, description, is_app, app_key, sort_order) VALUES
    ('receivables',    'Receivables',          'Customer ledger, statements, aging, collections, credit hold',   0, '',       1),
    ('reconciliation', 'Reconciliation',       'Bank feed import, payment references, matching workbench',        0, '',       2),
    ('routes',         'Field Sales & Routes', 'Route delivery, driver settlement, cash-in-transit',              1, 'routes', 3),
    ('enterprise',     'Enterprise',           'Org hierarchy, maker-checker approvals, consolidated analytics',  0, '',       4)
ON DUPLICATE KEY UPDATE
    label       = VALUES(label),
    description = VALUES(description),
    is_app      = VALUES(is_app),
    app_key     = VALUES(app_key),
    sort_order  = VALUES(sort_order);

-- ── 2. Commercial agreement ───────────────────────────────────────────────
-- History table: canceled rows stay. At most one non-terminal subscription
-- (trialing / active / past_due / paused) per (company, package) — enforced in
-- app logic, since MySQL has no filtered-unique index.
CREATE TABLE IF NOT EXISTS company_subscriptions (
    id                   INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    company_id           INT UNSIGNED  NOT NULL,
    package_key          VARCHAR(40)   NOT NULL,
    status               ENUM('trialing','active','past_due','paused','canceled') NOT NULL DEFAULT 'active',
    price                DECIMAL(10,2) NOT NULL DEFAULT 0,     -- snapshot at sign-up; negotiated deals differ from catalog
    currency             CHAR(3)       NOT NULL DEFAULT 'BZD',
    billing_interval     ENUM('monthly','annual') NOT NULL DEFAULT 'monthly',
    current_period_start DATE          NULL,
    current_period_end   DATE          NULL,
    trial_ends_at        DATETIME      NULL,
    contract_ref         VARCHAR(120)  NOT NULL DEFAULT '',    -- MSA / quote number, for finance
    cancel_reason        VARCHAR(255)  NULL,
    started_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    canceled_at          DATETIME      NULL,
    created_by           INT UNSIGNED  NULL,                   -- Centryk admin who opened the agreement
    updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sub_company (company_id),
    INDEX idx_sub_status  (status),
    CONSTRAINT fk_sub_company FOREIGN KEY (company_id)  REFERENCES companies(id)              ON DELETE CASCADE,
    CONSTRAINT fk_sub_package FOREIGN KEY (package_key) REFERENCES business_packages(`key`)   ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_sub_creator FOREIGN KEY (created_by)  REFERENCES users(id)                 ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Enforcement record (the only thing hot-path code reads) ────────────
-- One row per (company, package). `state` is the runtime truth; a billing
-- hiccup flips it to 'suspended' (read-only) without touching the subscription.
CREATE TABLE IF NOT EXISTS company_entitlements (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    company_id      INT UNSIGNED  NOT NULL,
    package_key     VARCHAR(40)   NOT NULL,
    state           ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active',
    source          ENUM('admin_grant','trial','migration') NOT NULL DEFAULT 'admin_grant',
    subscription_id INT UNSIGNED  NULL,                    -- what pays for it (NULL = comp / support grant)
    granted_by      INT UNSIGNED  NULL,                    -- Centryk admin user
    granted_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME      NULL,                    -- trials / fixed term; past this the level resolves to NONE
    suspended_at    DATETIME      NULL,
    revoked_at      DATETIME      NULL,
    notes           VARCHAR(255)  NOT NULL DEFAULT '',
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ent_company_package (company_id, package_key),
    INDEX idx_ent_state   (state),
    INDEX idx_ent_package (package_key),
    CONSTRAINT fk_ent_company      FOREIGN KEY (company_id)      REFERENCES companies(id)              ON DELETE CASCADE,
    CONSTRAINT fk_ent_package      FOREIGN KEY (package_key)     REFERENCES business_packages(`key`)   ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ent_subscription FOREIGN KEY (subscription_id) REFERENCES company_subscriptions(id) ON DELETE SET NULL,
    CONSTRAINT fk_ent_granted_by   FOREIGN KEY (granted_by)      REFERENCES users(id)                 ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Inbound leads (mirrors banking_requests) ──────────────────────────
CREATE TABLE IF NOT EXISTS business_package_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id   INT UNSIGNED NOT NULL,
    package_key  VARCHAR(40)  NULL,                        -- NULL = generic "tell me more"
    requested_by INT UNSIGNED NOT NULL,
    message      VARCHAR(500) NOT NULL DEFAULT '',
    status       ENUM('pending','contacted','converted','declined') NOT NULL DEFAULT 'pending',
    handled_by   INT UNSIGNED NULL,
    handled_at   DATETIME     NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pkgreq_company (company_id),
    INDEX idx_pkgreq_status  (status),
    CONSTRAINT fk_pkgreq_company FOREIGN KEY (company_id)   REFERENCES companies(id)            ON DELETE CASCADE,
    CONSTRAINT fk_pkgreq_user    FOREIGN KEY (requested_by) REFERENCES users(id)                ON DELETE CASCADE,
    CONSTRAINT fk_pkgreq_package FOREIGN KEY (package_key)  REFERENCES business_packages(`key`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_pkgreq_handler FOREIGN KEY (handled_by)   REFERENCES users(id)                ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
