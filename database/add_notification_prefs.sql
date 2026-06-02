-- Per-user notification preferences (Profile → Notifications).
-- Key/value so new notification types can be added without schema changes.
-- Run via phpMyAdmin against centryk_core. Idempotent.

CREATE TABLE IF NOT EXISTS user_notification_prefs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    pref_key   VARCHAR(60)  NOT NULL,
    enabled    TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_pref (user_id, pref_key),
    INDEX idx_unp_user (user_id),
    CONSTRAINT fk_unp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
