-- Pay-per-event access for 'paid' channel visibility (docs/streaming-server.md's
-- "Known gaps" list). 'subscription' visibility is deliberately NOT covered
-- here - recurring billing (renewal, cancellation, dunning) is a materially
-- different problem and needs its own design; it still falls through to the
-- same private-grant check tv_can_watch_event() already has, which is safe
-- (fails closed) even though it isn't full-featured yet.
--
-- Charges go straight to OneLink using Centryk's own onelink_credentials
-- (company-level, populated by OneLinkProvisioning::provision()) rather than
-- bridging to OnePay's payment_settings - that table only ever mirrors these
-- same credentials out to a company's OnePay stores for its POS to use, it
-- isn't the source of truth, and a company doesn't need an OnePay store at
-- all to have OneLink provisioned.
--
-- Safe to run more than once.

SET @sql := IF (
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_events' AND COLUMN_NAME = 'price_amount'),
    'SELECT "price_amount exists" AS info',
    'ALTER TABLE tv_events ADD COLUMN price_amount DECIMAL(10,2) DEFAULT NULL AFTER visibility'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_events' AND COLUMN_NAME = 'price_currency'),
    'SELECT "price_currency exists" AS info',
    "ALTER TABLE tv_events ADD COLUMN price_currency VARCHAR(3) NOT NULL DEFAULT 'BZD' AFTER price_amount"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS tv_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'BZD',
    status ENUM('pending','succeeded','failed') NOT NULL DEFAULT 'pending',
    provider VARCHAR(40) NOT NULL DEFAULT 'onelink',
    provider_reference VARCHAR(120) DEFAULT NULL,
    -- Never store the full card number or CVV, only what's needed to show a
    -- receipt ("Visa ending 4242") - onelink_pos_charge()'s response shape
    -- in OnePay already returns exactly these two fields, nothing more.
    card_last4 VARCHAR(4) DEFAULT NULL,
    card_brand VARCHAR(20) DEFAULT NULL,
    failure_message VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tv_payments_event (event_id),
    INDEX idx_tv_payments_user (user_id),
    INDEX idx_tv_payments_org (organization_id),
    CONSTRAINT fk_tv_payments_org FOREIGN KEY (organization_id) REFERENCES tv_organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_payments_event FOREIGN KEY (event_id) REFERENCES tv_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
