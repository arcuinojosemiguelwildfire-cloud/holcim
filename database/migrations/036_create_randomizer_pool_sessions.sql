-- 036: Randomizer pool sessions.
-- The single Randomizer (pre-drawn winner reveal) shows the names of the
-- attendees registered in the registration sessions an admin selects, per
-- event day. One row = one selected session for one event day.
--
-- The composite foreign keys make it impossible to select a session of
-- ANOTHER event day (registration_session_id + event_day_id must match a
-- registration_sessions row) or another event (event_day_id + event_id must
-- match an event_days row). Rows are configuration only: registrations, scan
-- history and pre-drawn winners are never changed by them.
-- Additive only: no existing table or row is changed.

CREATE TABLE IF NOT EXISTS randomizer_pool_sessions (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                 INT UNSIGNED NOT NULL,
    event_day_id             INT UNSIGNED NOT NULL,
    registration_session_id  INT UNSIGNED NOT NULL,
    selected_by              INT UNSIGNED NULL DEFAULT NULL,
    selected_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_randomizer_pool_sessions_day_session (event_day_id, registration_session_id),
    KEY idx_randomizer_pool_sessions_session_day (registration_session_id, event_day_id),
    KEY idx_randomizer_pool_sessions_event (event_id),
    KEY idx_randomizer_pool_sessions_selected_by (selected_by),
    CONSTRAINT fk_randomizer_pool_sessions_day_event
        FOREIGN KEY (event_day_id, event_id) REFERENCES event_days (id, event_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_randomizer_pool_sessions_session_day
        FOREIGN KEY (registration_session_id, event_day_id) REFERENCES registration_sessions (id, event_day_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_randomizer_pool_sessions_selected_by
        FOREIGN KEY (selected_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
