-- 002: Events.
-- The system is designed around a single ACTIVE event at a time.
-- `active_lock` is a stored generated column that is 1 when status = 'active'
-- and NULL otherwise. Because UNIQUE indexes allow many NULLs, the unique key
-- guarantees at the database level that at most one event can be active.

CREATE TABLE IF NOT EXISTS events (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name            VARCHAR(200)     NOT NULL,
    description     TEXT             NULL,
    event_date      DATE             NOT NULL,
    status          ENUM('draft', 'active', 'completed', 'archived') NOT NULL DEFAULT 'draft',
    active_lock     TINYINT UNSIGNED AS (IF(status = 'active', 1, NULL)) STORED,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_events_single_active (active_lock),
    KEY idx_events_status (status),
    KEY idx_events_event_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
