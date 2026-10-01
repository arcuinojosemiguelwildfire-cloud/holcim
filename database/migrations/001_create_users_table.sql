-- 001: System users (staff who operate the event system).
-- Roles are intentionally a fixed ENUM for Phase 1; a full permission
-- matrix can be layered on later without changing this table's shape.

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name            VARCHAR(150)     NOT NULL,
    email           VARCHAR(190)     NOT NULL,
    password_hash   VARCHAR(255)     NOT NULL,
    role            ENUM('admin', 'registration_staff', 'event_operator') NOT NULL DEFAULT 'event_operator',
    status          ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login_at   DATETIME         NULL DEFAULT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
