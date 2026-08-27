-- Audit + rate-limit log for AI logo generation (api/companies/generate_logo.php).
-- One row per successful generation request; the endpoint caps a company at a
-- fixed number of generations per rolling 24h.
CREATE TABLE IF NOT EXISTS ai_logo_events (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_company_created (company_id, created_at)
);
