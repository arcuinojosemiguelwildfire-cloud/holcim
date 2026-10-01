-- 014: Event days (Phase 8). An event can run over several days.
-- Exactly one day per event can be 'active'; the system works on the active
-- day of the active event. active_lock = event_id while active, NULL otherwise
-- (UNIQUE allows many NULLs). The FK uses RESTRICT because MariaDB/MySQL do
-- not allow a generated column on a cascading FK column; event ids never change.

CREATE TABLE IF NOT EXISTS event_days (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id      INT UNSIGNED  NOT NULL,
    day_number    SMALLINT UNSIGNED NOT NULL,
    event_date    DATE          NOT NULL,
    label         VARCHAR(100)  NULL DEFAULT NULL,
    status        ENUM('upcoming', 'active', 'completed') NOT NULL DEFAULT 'upcoming',
    active_lock   INT UNSIGNED  AS (IF(status = 'active', event_id, NULL)) STORED,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_event_days_event_number (event_id, day_number),
    UNIQUE KEY uq_event_days_id_event (id, event_id),
    UNIQUE KEY uq_event_days_one_active (active_lock),
    CONSTRAINT fk_event_days_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
