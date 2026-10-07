-- 033: Registration sessions (Phase 10).
-- An event day can have several registration sessions (e.g. Oct 15 8:00 AM
-- and Oct 15 5:00 PM). An attendee - ONE attendee record with ONE QR code -
-- registers once per session. Raffle eligibility is derived from these rows:
--   Minor (day) = an active registration in ANY session of that day
--   Major (day) = an active registration in a session with counts_for_major = 1
-- (plus the existing manual "+ Add Participant" entries of each randomizer).
--
-- Additive and non-destructive:
--   * every existing event day gets ONE default session ("Registration",
--     no start time, counts for Major, current) - exactly today's rule
--     "registered that day = eligible for Minor and Major";
--   * every existing registration_scans row is copied to that session
--     (source 'scan'). registration_scans itself is kept unchanged as the
--     day-level check-in log.
-- No attendee, QR code, draw or scan log row is changed.
-- Times are Asia/Manila wall-clock time, like every DATETIME in this schema.

CREATE TABLE IF NOT EXISTS registration_sessions (
    id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    event_id          INT UNSIGNED      NOT NULL,
    event_day_id      INT UNSIGNED      NOT NULL,
    label             VARCHAR(100)      NOT NULL,
    session_date      DATE              NOT NULL,
    start_time        TIME              NULL DEFAULT NULL,
    description       VARCHAR(255)      NULL DEFAULT NULL,
    sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_enabled        TINYINT(1)        NOT NULL DEFAULT 1,
    counts_for_major  TINYINT(1)        NOT NULL DEFAULT 1,
    is_current        TINYINT(1)        NOT NULL DEFAULT 0,
    -- One current session per event day (same pattern as event_days.active_lock).
    current_lock      INT UNSIGNED      AS (IF(is_current = 1, event_day_id, NULL)) STORED,
    created_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_registration_sessions_id_day (id, event_day_id),
    UNIQUE KEY uq_registration_sessions_day_label (event_day_id, label),
    UNIQUE KEY uq_registration_sessions_one_current (current_lock),
    KEY idx_registration_sessions_day_order (event_day_id, sort_order),
    KEY idx_registration_sessions_event (event_id),
    CONSTRAINT chk_registration_sessions_current_enabled CHECK (is_current = 0 OR is_enabled = 1),
    CONSTRAINT chk_registration_sessions_flags CHECK (is_enabled IN (0, 1) AND counts_for_major IN (0, 1) AND is_current IN (0, 1)),
    -- RESTRICT: a generated column's base column cannot be on a cascading FK.
    CONSTRAINT fk_registration_sessions_day_event
        FOREIGN KEY (event_day_id, event_id) REFERENCES event_days (id, event_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per attendee per session. Never deleted by the application:
-- an admin removal sets status = 'removed' (who / when / why are kept) and
-- every change is also written to session_registration_changes.
CREATE TABLE IF NOT EXISTS session_registrations (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                 INT UNSIGNED NOT NULL,
    event_day_id             INT UNSIGNED NOT NULL,
    registration_session_id  INT UNSIGNED NOT NULL,
    attendee_id              INT UNSIGNED NOT NULL,
    status                   ENUM('active', 'removed') NOT NULL DEFAULT 'active',
    source                   ENUM('scan', 'manual') NOT NULL,
    registered_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    registered_by            INT UNSIGNED NULL DEFAULT NULL,
    removed_at               DATETIME     NULL DEFAULT NULL,
    removed_by               INT UNSIGNED NULL DEFAULT NULL,
    removal_reason           VARCHAR(200) NULL DEFAULT NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_session_registrations_attendee_session (attendee_id, registration_session_id),
    KEY idx_session_registrations_day_status (event_day_id, status, attendee_id),
    KEY idx_session_registrations_session_status (registration_session_id, status, registered_at),
    KEY idx_session_registrations_attendee_event (attendee_id, event_id),
    KEY idx_session_registrations_registered_by (registered_by),
    KEY idx_session_registrations_removed_by (removed_by),
    KEY fk_session_registrations_session_day (registration_session_id, event_day_id),
    KEY fk_session_registrations_day_event (event_day_id, event_id),
    CONSTRAINT fk_session_registrations_session_day
        FOREIGN KEY (registration_session_id, event_day_id) REFERENCES registration_sessions (id, event_day_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_session_registrations_attendee_event
        FOREIGN KEY (attendee_id, event_id) REFERENCES attendees (id, event_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_session_registrations_day_event
        FOREIGN KEY (event_day_id, event_id) REFERENCES event_days (id, event_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_session_registrations_registered_by
        FOREIGN KEY (registered_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_session_registrations_removed_by
        FOREIGN KEY (removed_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only history of every registration change (scan, manual add,
-- restore, removal). Never updated or deleted by the application.
CREATE TABLE IF NOT EXISTS session_registration_changes (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_registration_id  INT UNSIGNED    NOT NULL,
    event_id                 INT UNSIGNED    NOT NULL,
    action                   ENUM('scanned', 'manually_added', 'restored', 'removed') NOT NULL,
    status_after             ENUM('active', 'removed') NOT NULL,
    performed_by             INT UNSIGNED    NULL DEFAULT NULL,
    reason                   VARCHAR(200)    NULL DEFAULT NULL,
    created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_session_registration_changes_registration (session_registration_id, created_at),
    KEY idx_session_registration_changes_event (event_id),
    KEY idx_session_registration_changes_performed_by (performed_by),
    CONSTRAINT fk_session_registration_changes_registration
        FOREIGN KEY (session_registration_id) REFERENCES session_registrations (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_session_registration_changes_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_session_registration_changes_performed_by
        FOREIGN KEY (performed_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scan log: which session a scan was made in (NULL for scans made before
-- sessions existed) and a new result for a registration an admin removed.
ALTER TABLE scan_logs
    MODIFY COLUMN result ENUM('registered', 'already_registered', 'invalid_qr', 'wrong_event', 'attendee_inactive', 'registration_removed') NOT NULL,
    ADD COLUMN registration_session_id INT UNSIGNED NULL DEFAULT NULL AFTER event_day_id,
    ADD KEY idx_scan_logs_session (registration_session_id),
    ADD CONSTRAINT fk_scan_logs_session
        FOREIGN KEY (registration_session_id) REFERENCES registration_sessions (id)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- Backfill: one default session per existing day (keeps current behaviour).
INSERT INTO registration_sessions
    (event_id, event_day_id, label, session_date, start_time, description, sort_order, is_enabled, counts_for_major, is_current)
SELECT d.event_id, d.id, 'Registration', d.event_date, NULL,
       'Created automatically for registrations made before registration sessions existed.', 1, 1, 1, 1
FROM event_days d
WHERE NOT EXISTS (SELECT 1 FROM registration_sessions s WHERE s.event_day_id = d.id);

-- Backfill: every existing check-in becomes a registration in its day's default session.
INSERT INTO session_registrations
    (event_id, event_day_id, registration_session_id, attendee_id, status, source, registered_at, registered_by, created_at, updated_at)
SELECT r.event_id, r.event_day_id, s.id, r.attendee_id, 'active', 'scan', r.scanned_at, r.scanner_user_id, r.created_at, r.created_at
FROM registration_scans r
JOIN registration_sessions s ON s.event_day_id = r.event_day_id AND s.is_current = 1
WHERE NOT EXISTS (
    SELECT 1 FROM session_registrations x
    WHERE x.attendee_id = r.attendee_id AND x.registration_session_id = s.id
);
