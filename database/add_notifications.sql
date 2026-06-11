-- Cross-application notifications, owned by Centryk.
-- Every Centryk app (mypay, onepay, calendar, invoice, ...) writes here, and the
-- shared header bell + notifications page in each app read from here.
USE centryk_core;

CREATE TABLE IF NOT EXISTS notifications (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid        CHAR(36)        NOT NULL DEFAULT (UUID()),
    user_id     INT UNSIGNED    NOT NULL,                 -- recipient (centryk users.id)
    company_id  INT UNSIGNED    NULL,                     -- optional company scope
    app_key     VARCHAR(40)     NOT NULL DEFAULT 'centryk', -- source app (apps.key)
    type        VARCHAR(60)     NOT NULL DEFAULT 'general',  -- e.g. task.assigned
    title       VARCHAR(180)    NOT NULL,
    body        VARCHAR(500)    NOT NULL DEFAULT '',
    url         VARCHAR(500)    NOT NULL DEFAULT '',        -- absolute deep link
    icon        VARCHAR(40)     NOT NULL DEFAULT '',        -- lucide icon name
    color       VARCHAR(7)      NOT NULL DEFAULT '',        -- accent hex
    read_at     DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_unread  (user_id, read_at),
    INDEX idx_user_created (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
